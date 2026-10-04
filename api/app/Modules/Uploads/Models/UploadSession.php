<?php

namespace App\Modules\Uploads\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Internal to the Uploads module.
 *
 * @property string $id
 * @property string $video_id
 * @property string $user_id
 * @property string $bucket
 * @property string $object_key
 * @property string $s3_upload_id
 * @property int $declared_size_bytes
 * @property string $content_type
 * @property string|null $declared_sha256
 * @property int $part_size_bytes
 * @property int $total_parts
 * @property string $status
 * @property string|null $failure_reason
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class UploadSession extends Model
{
    use HasUuids;

    /** Statuses in which parts can still be uploaded. */
    public const ACTIVE = ['initiated', 'in_progress'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'declared_size_bytes' => 'integer',
            'part_size_bytes' => 'integer',
            'total_parts' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Size of one part: every part is part_size_bytes except the last, which holds the rest. */
    public function partSize(int $partNumber): int
    {
        return $partNumber < $this->total_parts
            ? $this->part_size_bytes
            : $this->declared_size_bytes - ($this->total_parts - 1) * $this->part_size_bytes;
    }
}
