<?php

namespace App\Platform\Messaging\Console;

use App\Platform\Messaging\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs as the `outbox-relay` ECS service (ADR-001). Stops cleanly on SIGTERM. */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Publish everything that is pending, then exit}
        {--batch=50 : Rows per transaction}';

    protected $description = 'Publish committed outbox messages to SNS';

    private const IDLE_SLEEP_MS = 500;

    private const MAX_BACKOFF_MS = 30_000;

    private bool $stopping = false;

    public function handle(OutboxRelay $relay): int
    {
        $this->trap([SIGTERM, SIGINT], fn () => $this->stopping = true);
        $backoffMs = 0;

        while (! $this->stopping) {
            try {
                $published = $relay->relayBatch((int) $this->option('batch'));
                $backoffMs = 0;
            } catch (Throwable $e) {
                $backoffMs = min(self::MAX_BACKOFF_MS, max(1000, $backoffMs * 2));
                Log::error('Outbox relay failed; retrying', ['error' => $e->getMessage(), 'retry_in_ms' => $backoffMs]);
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                usleep($backoffMs * 1000);

                continue;
            }

            if ($published === 0) {
                if ($this->option('once')) {
                    break;
                }
                usleep(self::IDLE_SLEEP_MS * 1000);
            }
        }

        return self::SUCCESS;
    }
}
