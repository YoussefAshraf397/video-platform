<?php

namespace App\Modules\Processing\Consumers;

use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Processing\Services\CaptionPublisher;
use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoMedia;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Contracts\VideoSubtitles;
use App\Platform\Messaging\IdempotentConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The media worker's results (`media-results` queue, S4-01): VideoRenditionReady,
 * VideoProcessingCompleted, VideoProcessingFailed and VideoCaptionsPublished (S4-06). The worker
 * reports; Laravel decides state.
 *
 * - The first playable rendition makes the video READY (stepping through the states the worker
 *   doesn't report: validating → queued_for_processing → processing → ready).
 * - Completion records every rendition, the media facts and the thumbnails.
 * - Failure before anything is playable makes the video PROCESSING_FAILED with the reason code
 *   (a later completion of the same job still wins);
 *   after a rendition is playable, the job is `partially_succeeded` and the video stays READY.
 *
 * - Once a job's output is final (succeeded, or partially succeeded), its captions are published
 *   (CaptionPublisher). A captions result replaces the job's current captions only if its revision
 *   is newer.
 *
 * Duplicates: the worker's event ids are deterministic, so IdempotentConsumer skips redeliveries
 * and a rerun job's repeats. Out of order: SQS doesn't keep order, so every write is an upsert,
 * states only move forward, and results of an older processing version than the video's latest
 * job (`aggregate_version` = processing_version) are dropped. Results for a video that was
 * deleted or blocked meanwhile are recorded, but don't move it (design doc §12.3).
 */
final class MediaResultsConsumer extends IdempotentConsumer
{
    public function __construct(
        private readonly VideoLifecycle $lifecycle,
        private readonly VideoDirectory $videos,
        private readonly VideoMedia $media,
        private readonly VideoSubtitles $subtitles,
        private readonly CaptionPublisher $captions,
    ) {}

    public function name(): string
    {
        return 'media-results';
    }

    protected function process(array $envelope): void
    {
        $type = (string) ($envelope['event_type'] ?? '');
        $payload = (array) ($envelope['payload'] ?? []);
        $job = ProcessingJob::query()->whereKey((string) ($payload['job_id'] ?? ''))
            ->where('video_id', (string) ($payload['video_id'] ?? ''))
            ->where('processing_version', (int) ($payload['processing_version'] ?? 0))
            ->lockForUpdate()   // results of one job are applied one at a time
            ->first();

        if ($job === null) {
            Log::warning('processing.result_for_unknown_job', ['event_type' => $type, 'job_id' => $payload['job_id'] ?? null]);

            return;
        }
        $latest = (int) ProcessingJob::query()->where('video_id', $job->video_id)->max('processing_version');
        if ($job->processing_version < $latest) {
            Log::info('processing.stale_result', ['job_id' => $job->id, 'processing_version' => $job->processing_version, 'latest' => $latest]);

            return;
        }

        match ($type) {
            'VideoRenditionReady' => $this->renditionReady($job, $payload),
            'VideoProcessingCompleted' => $this->completed($job, $payload),
            'VideoProcessingFailed' => $this->failed($job, $payload),
            'VideoCaptionsPublished' => $this->captionsPublished($job, $payload),
            default => Log::warning('processing.unknown_result', ['event_type' => $type]),
        };
    }

    /** @param array<string, mixed> $payload */
    private function renditionReady(ProcessingJob $job, array $payload): void
    {
        $this->recordOutputs($job, [(array) $payload['rendition']], (string) $payload['master_playlist_key']);
        if (! in_array($job->status, ProcessingJob::SETTLED, true)) {
            $job->update(['status' => 'running', 'started_at' => $job->started_at ?? now()]);
        }
        $this->advance($job->video_id, [VideoStatus::QueuedForProcessing, VideoStatus::Processing, VideoStatus::Ready]);
    }

    /** @param array<string, mixed> $payload */
    private function completed(ProcessingJob $job, array $payload): void
    {
        /** @var list<array<string, mixed>> $renditions */
        $renditions = array_values((array) $payload['renditions']);
        $this->recordOutputs($job, $renditions, (string) $payload['master_playlist_key']);
        // Everything exists now, so completion settles the job even if a failure was recorded first.
        $job->update([
            'status' => 'succeeded', 'started_at' => $job->started_at ?? now(), 'finished_at' => now(),
            'error_code' => null, 'error_detail' => null, 'failed_step' => null, 'failure_retryable' => null,
        ]);
        $this->advance($job->video_id, [VideoStatus::QueuedForProcessing, VideoStatus::Processing, VideoStatus::Ready]);

        $source = (array) $payload['source'];
        $this->media->recordProcessed($job->video_id, $job->processing_version, (int) $payload['duration_ms'],
            (int) $source['width'], (int) $source['height'],
            array_map(fn ($t) => [
                'key' => (string) $t['key'], 'width' => (int) $t['width'], 'height' => (int) $t['height'],
                'time_offset_ms' => (int) $t['time_offset_ms'],
            ], array_values((array) $payload['thumbnails'])));

        $this->captions->publish($job);   // tracks uploaded before or during processing
    }

    /** @param array<string, mixed> $payload */
    private function failed(ProcessingJob $job, array $payload): void
    {
        if ($job->status === 'succeeded') {
            return;   // completion is final
        }
        $error = (array) $payload['error'];
        $playable = DB::table('video_variants')->where('processing_job_id', $job->id)->exists();

        $job->update([
            'status' => $playable ? 'partially_succeeded' : 'failed',
            'error_code' => (string) $error['code'],
            'error_detail' => mb_substr((string) $error['message'], 0, 1000),
            'failed_step' => (string) $payload['failed_step'],
            'failure_retryable' => (bool) $error['retryable'],
            'finished_at' => now(),
        ]);
        Log::warning('processing.failed', ['job_id' => $job->id, 'code' => $error['code'], 'step' => $payload['failed_step'], 'playable' => $playable]);

        if ($playable) {
            $this->captions->publish($job);   // what is playable is final now
        } else {
            $this->advance($job->video_id, [VideoStatus::QueuedForProcessing, VideoStatus::Processing, VideoStatus::ProcessingFailed],
                Str::lower((string) $error['code']));
        }
    }

    /** @param array<string, mixed> $payload */
    private function captionsPublished(ProcessingJob $job, array $payload): void
    {
        $revision = (int) $payload['captions_revision'];
        if ($revision <= $job->captions_published_revision) {
            Log::info('processing.stale_captions', ['job_id' => $job->id, 'captions_revision' => $revision, 'published' => $job->captions_published_revision]);

            return;
        }

        /** @var list<array<string, mixed>> $tracks */
        $tracks = array_values((array) $payload['tracks']);
        $ready = array_values(array_filter($tracks, fn (array $t) => $t['status'] === 'ready'));
        $known = $this->subtitles->find($job->video_id, array_map(fn (array $t) => (string) $t['subtitle_id'], $ready));
        $job->update([
            'captions_published_revision' => $revision,
            'captions_master_key' => (string) $payload['master_playlist_key'],
            // What playback lists, in the master playlist's order.
            'captions' => array_values(array_filter(array_map(fn (array $t) => isset($known[$t['subtitle_id']]) ? [
                'subtitle_id' => (string) $t['subtitle_id'],
                'language' => $known[$t['subtitle_id']]->language,
                'label' => $known[$t['subtitle_id']]->label,
                'kind' => $known[$t['subtitle_id']]->kind,
                'playlist_key' => (string) $t['playlist_key'],
                'vtt_key' => (string) $t['vtt_key'],
            ] : null, $ready))),
        ]);
        $this->subtitles->recordConverted($job->video_id, array_map(fn (array $t) => [
            'subtitle_id' => (string) $t['subtitle_id'],
            'status' => (string) $t['status'],
            'error_code' => $t['error'] === null ? null : (string) $t['error']['code'],
            'error_detail' => $t['error'] === null ? null : (string) $t['error']['message'],
        ], $tracks));
    }

    /**
     * Moves the video along `$path` from wherever it is on it. A video that isn't on the path
     * (deleted, blocked, already further) is left alone. From VALIDATING, failing goes straight
     * to PROCESSING_FAILED (§12.3).
     *
     * @param  list<VideoStatus>  $path
     */
    private function advance(string $videoId, array $path, ?string $failureReason = null): void
    {
        $status = $this->videos->find($videoId)?->status;
        $start = match ($status) {
            VideoStatus::Validating => 0,
            VideoStatus::QueuedForProcessing => 1,
            VideoStatus::Processing => 2,
            // A completion that arrives after a failure of the same job: everything exists after all.
            VideoStatus::ProcessingFailed => end($path) === VideoStatus::Ready ? 0 : null,
            default => null,   // not on the path: leave it alone
        };
        if ($start === null) {
            return;
        }
        $steps = array_slice($path, $start);
        if ($status === VideoStatus::Validating && end($path) === VideoStatus::ProcessingFailed) {
            $steps = [VideoStatus::ProcessingFailed];
        }

        foreach ($steps as $next) {
            try {
                $this->lifecycle->transition($videoId, $next, match ($next) {
                    VideoStatus::QueuedForProcessing => 'media_validated',
                    VideoStatus::Processing => 'processing_started',
                    VideoStatus::Ready => 'first_rendition_ready',
                    default => $failureReason ?? 'processing_failed',
                });
            } catch (IllegalVideoTransition) {
                return;   // moved on concurrently (e.g. deleted); the state machine logged it
            }
        }
    }

    /**
     * Upserts the renditions and the job's master playlist, and records the job's files for purging.
     *
     * @param  list<array<string, mixed>>  $renditions
     */
    private function recordOutputs(ProcessingJob $job, array $renditions, string $masterKey): void
    {
        foreach ($renditions as $r) {
            DB::table('video_variants')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'video_id' => $job->video_id, 'processing_job_id' => $job->id,
                'processing_version' => $job->processing_version, 'codec' => (string) $r['codec'],
                'width' => (int) $r['width'], 'height' => (int) $r['height'], 'frame_rate' => (float) $r['frame_rate'],
                'bitrate_avg' => (int) $r['bitrate_avg'], 'bitrate_peak' => (int) $r['bitrate_peak'],
                'playlist_key' => (string) $r['playlist_key'], 'segment_duration_ms' => (int) $r['segment_duration_ms'],
                'status' => 'ready', 'created_at' => now(),
            ]);
        }
        if ($job->master_playlist_key !== $masterKey) {
            $job->update(['master_playlist_key' => $masterKey]);
        }
        DB::table('video_assets')->insertOrIgnore([
            ['id' => (string) Str::uuid7(), 'video_id' => $job->video_id, 'asset_type' => 'source', 'bucket' => $job->source_bucket,
                'key_prefix' => $job->source_key, 'processing_version' => null, 'created_at' => now()],
            ['id' => (string) Str::uuid7(), 'video_id' => $job->video_id, 'asset_type' => 'hls', 'bucket' => $job->output_bucket,
                'key_prefix' => $job->output_prefix, 'processing_version' => $job->processing_version, 'created_at' => now()],
        ]);
    }
}
