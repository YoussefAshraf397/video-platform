<?php

namespace App\Platform\Messaging;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Dedupe markers written by IdempotentConsumer. Only used for pruning. */
final class ProcessedMessage extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * SQS keeps messages (and DLQ redrives) for at most 14 days, so a duplicate can't
     * arrive after 15 days and older markers can go.
     */
    public function prunable(): Builder
    {
        return self::query()->where('processed_at', '<', now()->subDays(15));
    }
}
