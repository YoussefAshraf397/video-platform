<?php

namespace App\Platform\Messaging;

use Aws\Sqs\SqsClient;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Feeds SQS messages (raw JSON envelopes, ADR-005) to an IdempotentConsumer. A message is
 * deleted only after it was handled. On failure it is left in the queue, becomes visible again
 * after the visibility timeout, and moves to the queue's DLQ after 5 receives.
 */
final class SqsConsumerRunner
{
    public function __construct(private readonly SqsClient $sqs) {}

    public function queueUrl(string $queueName): string
    {
        return $this->sqs->getQueueUrl(['QueueName' => $queueName])->get('QueueUrl');
    }

    /** @return int how many messages were received */
    public function pollOnce(IdempotentConsumer $consumer, string $queueUrl, int $waitSeconds = 20): int
    {
        $messages = $this->sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => $waitSeconds,
        ])->get('Messages') ?? [];

        foreach ($messages as $message) {
            try {
                $envelope = json_decode($message['Body'], true, flags: JSON_THROW_ON_ERROR);
                Context::add(['event_id' => $envelope['event_id'] ?? null, 'trace_id' => $envelope['trace_id'] ?? null]);

                $consumer->handle($envelope);
                $this->sqs->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
            } catch (Throwable $e) {
                Log::error('Message processing failed; SQS will redeliver it, then move it to the DLQ', [
                    'consumer' => $consumer->name(),
                    'sqs_message_id' => $message['MessageId'],
                    'error' => $e->getMessage(),
                ]);
            } finally {
                Context::forget(['event_id', 'trace_id']);
            }
        }

        return count($messages);
    }
}
