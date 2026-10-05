<?php

namespace App\Modules\Processing\Consumers;

use App\Modules\Processing\Events\MediaProcessRequested;
use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Uploads\Contracts\UploadedSources;
use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Platform\Messaging\IdempotentConsumer;
use App\Platform\Messaging\OutboxPublisher;
use Illuminate\Support\Facades\Log;

/**
 * Starts processing (design doc §11, S3-06). Reads VideoUploaded from the `media-dispatcher`
 * queue and, in the transaction IdempotentConsumer opens:
 *
 *   video uploaded → validating, + one video_processing_jobs row, + MediaProcessRequested (outbox).
 *
 * A redelivered event is skipped by IdempotentConsumer's (consumer, event_id) marker. A second,
 * different VideoUploaded for the same upload finds the video no longer `uploaded` (the state
 * machine serializes concurrent attempts), and the unique (upload_session_id, profile) index is the
 * final backstop. Either way: one job and one command per upload.
 */
final class MediaDispatcher extends IdempotentConsumer
{
    /** The encoding ladder every upload gets at the MVP (ADR-004). */
    public const PROFILE = 'h264-sdr-v1';

    public function __construct(
        private readonly UploadedSources $sources,
        private readonly VideoLifecycle $lifecycle,
        private readonly OutboxPublisher $outbox,
    ) {}

    public function name(): string
    {
        return 'media-dispatcher';
    }

    protected function process(array $envelope): void
    {
        if (($envelope['event_type'] ?? null) !== 'VideoUploaded') {
            return;   // the subscription filters on event_type; this is a second line of defence
        }
        $videoId = (string) ($envelope['payload']['video_id'] ?? '');

        $source = $this->sources->latestCompletedFor($videoId);
        if ($source === null) {
            Log::error('processing.no_completed_upload', ['video_id' => $videoId]);

            return;
        }
        try {
            $videoVersion = $this->lifecycle->transition($videoId, VideoStatus::Validating, 'processing_job_created');
        } catch (IllegalVideoTransition $e) {
            // Already dispatched by another VideoUploaded for this upload, or deleted/blocked meanwhile.
            Log::info('processing.not_dispatched', ['video_id' => $videoId, 'video_status' => $e->from->value]);

            return;
        }

        $version = (int) ProcessingJob::query()->where('video_id', $videoId)->max('processing_version') + 1;
        $job = ProcessingJob::query()->create([
            'video_id' => $videoId,
            'upload_session_id' => $source->uploadSessionId,
            'processing_version' => $version,
            'profile' => self::PROFILE,
            'status' => 'queued',
            'source_bucket' => $source->bucket,
            'source_key' => $source->key,
            'output_bucket' => (string) config('processing.output_bucket'),
            'output_prefix' => "media/{$videoId}/v{$version}/",
        ]);

        $this->outbox->publish(new MediaProcessRequested(
            $job->id, $videoId, $version, $job->source_bucket, $job->source_key, $job->output_bucket, $job->output_prefix, self::PROFILE, $videoVersion,
        ));
        Log::info('processing.dispatched', ['video_id' => $videoId, 'job_id' => $job->id, 'processing_version' => $version]);
    }
}
