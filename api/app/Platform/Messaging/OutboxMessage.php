<?php

namespace App\Platform\Messaging;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

final class OutboxMessage extends Model
{
    use MassPrunable;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * Published rows are kept 7 days for debugging, then deleted by `model:prune`.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('published_at', '<', now()->subDays(7));
    }
}
