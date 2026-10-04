<?php

namespace App\Platform\Messaging;

use Aws\Exception\AwsException;
use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\DB;

/**
 * Moves committed outbox rows to SNS. Delivery is at-least-once: if the process dies after
 * SNS accepted a message but before its row is marked published, the message is sent again
 * on the next run. Consumers deduplicate by event_id (IdempotentConsumer).
 *
 * Several relays can run at once: rows are claimed with FOR UPDATE SKIP LOCKED.
 */
final class OutboxRelay
{
    public function __construct(
        private readonly SnsClient $sns,
        private readonly string $topicArnPrefix,
    ) {}

    /**
     * Publishes up to $limit unpublished rows, oldest first.
     *
     * @return int how many rows were published
     *
     * @throws AwsException when SNS rejects a message. Rows sent before it are still marked
     *                      published, and the failing row records the error and is retried.
     */
    public function relayBatch(int $limit = 50): int
    {
        $failure = null;

        $published = DB::transaction(function () use ($limit, &$failure) {
            $rows = DB::table('outbox_messages')
                ->select(['id', 'topic', 'event_type', 'envelope'])
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            $published = [];
            foreach ($rows as $row) {
                try {
                    $this->sns->publish([
                        'TopicArn' => $this->topicArnPrefix.$row->topic,
                        'Message' => $row->envelope,
                        'MessageAttributes' => [
                            'event_type' => ['DataType' => 'String', 'StringValue' => $row->event_type],
                        ],
                    ]);
                } catch (AwsException $e) {
                    $failure = $e;
                    DB::table('outbox_messages')->where('id', $row->id)->update([
                        'attempts' => DB::raw('attempts + 1'),
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                    break;
                }
                $published[] = $row->id;
            }

            if ($published !== []) {
                DB::table('outbox_messages')->whereIn('id', $published)->update(['published_at' => now()]);
            }

            return count($published);
        });

        if ($failure !== null) {
            throw $failure;
        }

        return $published;
    }
}
