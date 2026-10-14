<?php

namespace App\Modules\Processing\Consumers;

use App\Modules\Processing\Services\JobQueue;
use App\Modules\Uploads\Contracts\UploadedSources;
use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Platform\Messaging\IdempotentConsumer;
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
    public function __construct(
        private readonly UploadedSources $sources,
        private readonly VideoLifecycle $lifecycle,
        private readonly JobQueue $jobs,
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

        $this->jobs->queue($videoId, $source->uploadSessionId, $source->bucket, $source->key, $videoVersion);
    }
}
