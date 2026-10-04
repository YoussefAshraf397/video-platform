<?php

namespace App\Platform\Messaging\Console;

use App\Platform\Messaging\IdempotentConsumer;
use App\Platform\Messaging\SqsConsumerRunner;
use Illuminate\Console\Command;

/** Runs one consumer against its SQS queue. Stops cleanly on SIGTERM. */
final class ConsumeMessagesCommand extends Command
{
    protected $signature = 'messages:consume
        {consumer : Consumer name, as listed in config/messaging.php}
        {--once : Process what is in the queue, then exit}';

    protected $description = 'Consume domain events from SQS with an idempotent consumer';

    private bool $stopping = false;

    public function handle(SqsConsumerRunner $runner): int
    {
        $consumer = $this->findConsumer((string) $this->argument('consumer'));

        if ($consumer === null) {
            $this->error("Unknown consumer [{$this->argument('consumer')}]. Register it in config/messaging.php.");

            return self::FAILURE;
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });
        $queueUrl = $runner->queueUrl($consumer->name());

        while (! $this->stopping) {
            $received = $runner->pollOnce($consumer, $queueUrl, $this->option('once') ? 1 : 20);
            if ($received === 0 && $this->option('once')) {
                break;
            }
        }

        return self::SUCCESS;
    }

    private function findConsumer(string $name): ?IdempotentConsumer
    {
        foreach ((array) config('messaging.consumers') as $class) {
            $consumer = is_string($class) ? app($class) : null;
            if ($consumer instanceof IdempotentConsumer && $consumer->name() === $name) {
                return $consumer;
            }
        }

        return null;
    }
}
