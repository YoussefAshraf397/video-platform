<?php

namespace Tests\Support;

use App\Modules\Processing\Models\ProcessingJob;
use Illuminate\Support\Str;

/** Media worker result messages (media-results), built as the worker builds them and checked against the contracts. */
final class WorkerResults
{
    /** A result envelope as the worker builds it, checked against its contract. */
    public static function result(ProcessingJob $job, string $type, array $fields, ?string $eventId = null): array
    {
        $schemas = ['VideoRenditionReady' => 'video-rendition-ready.v1', 'VideoProcessingCompleted' => 'video-processing-completed.v1', 'VideoProcessingFailed' => 'video-processing-failed.v1'];
        $envelope = [
            'event_id' => $eventId ?? (string) Str::uuid(), 'event_type' => $type, 'schema_version' => 1,
            'occurred_at' => '2026-10-06T12:00:00.000Z', 'producer' => 'media-worker', 'aggregate_type' => 'video',
            'aggregate_id' => $job->video_id, 'aggregate_version' => $job->processing_version, 'trace_id' => null,
            'payload' => ['video_id' => $job->video_id, 'job_id' => $job->id, 'processing_version' => $job->processing_version, ...$fields],
        ];
        expect(Contracts::violations($schemas[$type], json_encode($envelope)))->toBeNull();

        return $envelope;
    }

    public static function rendition(ProcessingJob $job, int $height): array
    {
        $width = intdiv($height * 16, 9) + (intdiv($height * 16, 9) % 2);

        return ['codec' => 'h264', 'width' => $width, 'height' => $height, 'frame_rate' => 30, 'bitrate_avg' => 800_000 * $height / 360,
            'bitrate_peak' => 1_100_000 * $height / 360, 'playlist_key' => $job->output_prefix."h264_{$height}p30/playlist.m3u8", 'segment_duration_ms' => 4000];
    }

    public static function ready(ProcessingJob $job, int $height, ?string $eventId = null): array
    {
        return self::result($job, 'VideoRenditionReady', ['rendition' => self::rendition($job, $height), 'master_playlist_key' => $job->output_prefix.'master.m3u8'], $eventId);
    }

    public static function completed(ProcessingJob $job, array $heights = [360, 720]): array
    {
        $thumbs = [];
        foreach ([[25, 2500], [50, 5000], [75, 7500]] as [$pct, $at]) {
            foreach ([[1280, 720], [640, 360]] as [$w, $h]) {
                foreach (['jpg', 'webp'] as $f) {
                    $thumbs[] = ['key' => $job->output_prefix."thumbs/{$pct}_{$w}x{$h}.{$f}", 'width' => $w, 'height' => $h, 'time_offset_ms' => $at];
                }
            }
        }

        return self::result($job, 'VideoProcessingCompleted', [
            'duration_ms' => 10_000, 'source' => ['width' => 1920, 'height' => 1080, 'frame_rate' => 30, 'has_audio' => true],
            'renditions' => array_map(fn ($h) => self::rendition($job, $h), $heights),
            'master_playlist_key' => $job->output_prefix.'master.m3u8', 'thumbnails' => $thumbs,
        ]);
    }

    public static function failed(ProcessingJob $job, string $code = 'NOT_A_VIDEO', string $step = 'validate', bool $retryable = false): array
    {
        return self::result($job, 'VideoProcessingFailed', ['failed_step' => $step, 'error' => ['code' => $code, 'message' => 'internal detail', 'retryable' => $retryable]]);
    }
}
