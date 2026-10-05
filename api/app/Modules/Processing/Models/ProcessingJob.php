<?php

namespace App\Modules\Processing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Internal to the Processing module.
 *
 * @property string $id
 * @property string $video_id
 * @property string $upload_session_id
 * @property int $processing_version
 * @property string $profile
 * @property string $status
 * @property int $attempt
 * @property string $source_bucket
 * @property string $source_key
 * @property string $output_bucket
 * @property string $output_prefix
 * @property Carbon|null $created_at
 */
final class ProcessingJob extends Model
{
    use HasUuids;

    protected $table = 'video_processing_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['processing_version' => 'integer', 'attempt' => 'integer'];
    }
}
