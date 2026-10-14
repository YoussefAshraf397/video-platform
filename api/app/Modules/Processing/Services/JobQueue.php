<?php

namespace App\Modules\Processing\Services;

use App\Modules\Processing\Events\MediaProcessRequested;
use App\Modules\Processing\Models\ProcessingJob;
use App\Platform\Messaging\OutboxPublisher;
use Illuminate\Support\Facades\Log;

/**
 * Creates a processing job and sends MediaProcessRequested for it, in the caller's transaction:
 * the worker hears about a job if and only if its row commits. Used by the dispatcher (a new
 * upload) and by admin retries.
 */
final class JobQueue
{
    /** The encoding ladder every upload gets at the MVP (ADR-004). */
    public const PROFILE = 'h264-sdr-v1';

    public function __construct(private readonly OutboxPublisher $outbox) {}

    /**
     * @param  int  $videoVersion  the video's state_version after the transition that queued it
     */
    public function queue(string $videoId, string $uploadSessionId, string $sourceBucket, string $sourceKey, int $videoVersion, ?string $retryOf = null): ProcessingJob
    {
        // Each job gets the next version, so its outputs (media/{id}/v{n}/) and its result event
        // ids never collide with an earlier job's.
        $version = (int) ProcessingJob::query()->where('video_id', $videoId)->max('processing_version') + 1;
        $job = ProcessingJob::query()->create([
            'video_id' => $videoId,
            'upload_session_id' => $uploadSessionId,
            'retry_of_job_id' => $retryOf,
            'processing_version' => $version,
            'profile' => self::PROFILE,
            'status' => 'queued',
            'source_bucket' => $sourceBucket,
            'source_key' => $sourceKey,
            'output_bucket' => (string) config('processing.output_bucket'),
            'output_prefix' => "media/{$videoId}/v{$version}/",
        ]);

        $this->outbox->publish(new MediaProcessRequested(
            $job->id, $videoId, $version, $sourceBucket, $sourceKey, $job->output_bucket, $job->output_prefix, self::PROFILE, $videoVersion,
        ));
        Log::info('processing.queued', ['video_id' => $videoId, 'job_id' => $job->id, 'processing_version' => $version, 'retry_of' => $retryOf]);

        return $job;
    }
}
