<?php

namespace App\Modules\Videos\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An uploaded subtitle track (S4-06). Internal to the Videos module.
 *
 * awaiting_upload → (client PUTs the file, then completes) → processing → ready | failed; any → deleted.
 * `processing` covers "waiting for the video to finish processing" as well as the conversion.
 *
 * @property string $id
 * @property string $video_id
 * @property string $language
 * @property string $label
 * @property string $kind
 * @property string $format
 * @property int $size_bytes
 * @property string $source_bucket
 * @property string $source_key
 * @property string $status
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property Carbon $upload_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Subtitle extends Model
{
    use HasUuids;

    /** Statuses that hold the (video, language, kind) slot. */
    public const LIVE = ['awaiting_upload', 'processing', 'ready'];

    /** Statuses whose track goes into the next captions revision. */
    public const PUBLISHABLE = ['processing', 'ready'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'upload_expires_at' => 'datetime'];
    }
}
