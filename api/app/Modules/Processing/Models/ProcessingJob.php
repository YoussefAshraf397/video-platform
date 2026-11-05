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
 * @property string|null $retry_of_job_id
 * @property int $processing_version
 * @property string $profile
 * @property string $status
 * @property int $attempt
 * @property string $source_bucket
 * @property string $source_key
 * @property string $output_bucket
 * @property string $output_prefix
 * @property string|null $master_playlist_key
 * @property string|null $error_code
 * @property string|null $error_detail
 * @property string|null $failed_step
 * @property bool|null $failure_retryable
 * @property int $captions_revision
 * @property int $captions_published_revision
 * @property string|null $captions_master_key
 * @property list<array{subtitle_id: string, language: string, label: string, kind: string, playlist_key: string, vtt_key: string}>|null $captions
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 */
final class ProcessingJob extends Model
{
    use HasUuids;

    protected $table = 'video_processing_jobs';

    /** Statuses after which a job's outcome is settled. */
    public const SETTLED = ['succeeded', 'partially_succeeded', 'failed', 'cancelled'];

    /** Statuses in which the version's base master playlist is final, so captions can be published for it. */
    public const FINISHED_OUTPUT = ['succeeded', 'partially_succeeded'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'processing_version' => 'integer', 'attempt' => 'integer', 'failure_retryable' => 'boolean',
            'captions_revision' => 'integer', 'captions_published_revision' => 'integer', 'captions' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }
}
