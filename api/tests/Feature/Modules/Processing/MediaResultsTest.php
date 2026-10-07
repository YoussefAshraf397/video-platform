<?php

use App\Modules\Processing\Consumers\MediaDispatcher;
use App\Modules\Processing\Consumers\MediaResultsConsumer;
use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Auth;
use Tests\Support\Contracts;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = Auth::createUser('ada@example.com');
    $this->video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'uploading']);
    $this->consumer = app(MediaResultsConsumer::class);

    /** Uploads and dispatches like production: the video ends up `validating` with a queued job. */
    $this->dispatch = function (): ProcessingJob {
        UploadSession::query()->create([
            'video_id' => $this->video->id, 'user_id' => $this->owner->id, 'bucket' => 'uploads',
            'object_key' => "uploads/{$this->video->id}/".Str::uuid7().'/source', 's3_upload_id' => Str::random(20),
            'declared_size_bytes' => 1000, 'content_type' => 'video/mp4', 'part_size_bytes' => 1000, 'total_parts' => 1,
            'status' => 'completed', 'completed_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        DB::transaction(fn () => app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::Uploaded, 'upload_completed'));
        $uploaded = json_decode(DB::table('outbox_messages')->where('event_type', 'VideoUploaded')->latest('id')->value('envelope'), true);
        app(MediaDispatcher::class)->handle($uploaded);

        return ProcessingJob::query()->where('video_id', $this->video->id)->orderByDesc('processing_version')->firstOrFail();
    };
    $this->states = fn () => DB::table('outbox_messages')->where('aggregate_id', $this->video->id)->where('topic', 'video-events')->orderBy('id')
        ->pluck('envelope')->map(fn ($e) => json_decode($e, true)['payload']['to'])->all();
});

/** A result envelope as the worker builds it, checked against its contract. */
function result(ProcessingJob $job, string $type, array $fields, ?string $eventId = null): array
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

function rendition(ProcessingJob $job, int $height): array
{
    $width = intdiv($height * 16, 9) + (intdiv($height * 16, 9) % 2);

    return ['codec' => 'h264', 'width' => $width, 'height' => $height, 'frame_rate' => 30, 'bitrate_avg' => 800_000 * $height / 360,
        'bitrate_peak' => 1_100_000 * $height / 360, 'playlist_key' => $job->output_prefix."h264_{$height}p30/playlist.m3u8", 'segment_duration_ms' => 4000];
}

function ready(ProcessingJob $job, int $height, ?string $eventId = null): array
{
    return result($job, 'VideoRenditionReady', ['rendition' => rendition($job, $height), 'master_playlist_key' => $job->output_prefix.'master.m3u8'], $eventId);
}

function completed(ProcessingJob $job, array $heights = [360, 720]): array
{
    $thumbs = [];
    foreach ([[25, 2500], [50, 5000], [75, 7500]] as [$pct, $at]) {
        foreach ([[1280, 720], [640, 360]] as [$w, $h]) {
            foreach (['jpg', 'webp'] as $f) {
                $thumbs[] = ['key' => $job->output_prefix."thumbs/{$pct}_{$w}x{$h}.{$f}", 'width' => $w, 'height' => $h, 'time_offset_ms' => $at];
            }
        }
    }

    return result($job, 'VideoProcessingCompleted', [
        'duration_ms' => 10_000, 'source' => ['width' => 1920, 'height' => 1080, 'frame_rate' => 30, 'has_audio' => true],
        'renditions' => array_map(fn ($h) => rendition($job, $h), $heights),
        'master_playlist_key' => $job->output_prefix.'master.m3u8', 'thumbnails' => $thumbs,
    ]);
}

function failed(ProcessingJob $job, string $code = 'NOT_A_VIDEO', string $step = 'validate', bool $retryable = false): array
{
    return result($job, 'VideoProcessingFailed', ['failed_step' => $step, 'error' => ['code' => $code, 'message' => 'internal detail', 'retryable' => $retryable]]);
}

it('makes the video READY on its first rendition', function () {
    $job = ($this->dispatch)();

    $this->consumer->handle(ready($job, 360));

    expect($this->video->fresh()->status)->toBe('ready')
        ->and(($this->states)())->toBe(['uploaded', 'validating', 'queued_for_processing', 'processing', 'ready'])
        ->and($job->fresh())->status->toBe('running')->master_playlist_key->toBe($job->output_prefix.'master.m3u8')->started_at->not->toBeNull()
        ->and(DB::table('video_variants')->where('video_id', $this->video->id)->pluck('height')->all())->toBe([360])
        ->and(DB::table('video_assets')->where('video_id', $this->video->id)->orderBy('asset_type')->pluck('asset_type')->all())->toBe(['hls', 'source']);
});

it('adds later renditions without moving the video again', function () {
    $job = ($this->dispatch)();
    $this->consumer->handle(ready($job, 360));
    $events = count(($this->states)());

    $this->consumer->handle(ready($job, 720));

    expect(DB::table('video_variants')->where('video_id', $this->video->id)->orderBy('height')->pluck('height')->all())->toBe([360, 720])
        ->and(($this->states)())->toHaveCount($events);
});

it('records everything on completion', function () {
    $job = ($this->dispatch)();
    $this->consumer->handle(ready($job, 360));

    $this->consumer->handle(completed($job));

    $video = $this->video->fresh();
    expect($video)->status->toBe('ready')->duration_ms->toBe(10_000)->source_width->toBe(1920)->source_height->toBe(1080)
        ->and($job->fresh())->status->toBe('succeeded')->finished_at->not->toBeNull()
        ->and(DB::table('video_variants')->where('video_id', $video->id)->count())->toBe(2);   // the 360p from before isn't duplicated

    $thumbs = DB::table('thumbnails')->where('video_id', $video->id)->orderBy('time_offset_ms')->get();
    expect($thumbs)->toHaveCount(3)
        ->and($thumbs->pluck('is_primary')->all())->toBe([false, true, false])   // the middle frame
        ->and(json_decode($thumbs[1]->files, true))->toHaveCount(4);              // 2 sizes x 2 formats
});

describe('duplicates and ordering', function () {
    it('skips a redelivered result', function () {
        $job = ($this->dispatch)();
        $message = ready($job, 360);

        expect($this->consumer->handle($message))->toBeTrue()
            ->and($this->consumer->handle($message))->toBeFalse();
    });

    it('handles completion arriving before the renditions it lists', function () {
        $job = ($this->dispatch)();

        $this->consumer->handle(completed($job));
        $this->consumer->handle(ready($job, 360));   // late, with its own event id

        expect($this->video->fresh()->status)->toBe('ready')
            ->and($job->fresh()->status)->toBe('succeeded')
            ->and(DB::table('video_variants')->where('video_id', $this->video->id)->count())->toBe(2);
    });

    it('drops results of an older processing version', function () {
        $old = ($this->dispatch)();
        Video::whereKey($this->video->id)->update(['status' => 'uploading']);   // re-uploaded
        $new = ($this->dispatch)();
        expect($new->processing_version)->toBe(2);

        $this->consumer->handle(completed($old));

        expect(DB::table('video_variants')->where('video_id', $this->video->id)->count())->toBe(0)
            ->and($this->video->fresh())->status->toBe('validating')->duration_ms->toBeNull()
            ->and($old->fresh()->status)->toBe('queued');
    });

    it('ignores results for jobs it does not know', function () {
        $job = ($this->dispatch)();
        $message = ready($job, 360);
        $message['payload']['job_id'] = (string) Str::uuid();

        $this->consumer->handle($message);

        expect(DB::table('video_variants')->count())->toBe(0)->and($this->video->fresh()->status)->toBe('validating');
    });
});

describe('failures', function () {
    it('fails the video with the reason code when nothing is playable', function () {
        $job = ($this->dispatch)();

        $this->consumer->handle(failed($job, 'NOT_A_VIDEO', 'validate'));

        expect($this->video->fresh()->status)->toBe('processing_failed')
            ->and($job->fresh())->status->toBe('failed')->error_code->toBe('NOT_A_VIDEO')->failed_step->toBe('validate')->failure_retryable->toBeFalse();
        $last = json_decode(DB::table('outbox_messages')->where('aggregate_id', $this->video->id)->latest('id')->value('envelope'), true);
        expect($last['payload'])->toMatchArray(['from' => 'validating', 'to' => 'processing_failed', 'reason' => 'not_a_video']);
    });

    it('fails from PROCESSING too', function () {
        $job = ($this->dispatch)();
        DB::transaction(function () {
            app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::QueuedForProcessing, 'x');
            app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::Processing, 'x');
        });

        $this->consumer->handle(failed($job, 'RETRIES_EXHAUSTED', 'transcode', true));

        expect($this->video->fresh()->status)->toBe('processing_failed');
    });

    it('keeps a playable video READY when it fails later', function () {
        $job = ($this->dispatch)();
        $this->consumer->handle(ready($job, 360));

        $this->consumer->handle(failed($job, 'ENCODER_FAILED', 'transcode', true));

        expect($this->video->fresh()->status)->toBe('ready')
            ->and($job->fresh())->status->toBe('partially_succeeded')->error_code->toBe('ENCODER_FAILED');
    });

    it('lets a later completion of the same job win', function () {
        $job = ($this->dispatch)();
        $this->consumer->handle(failed($job, 'RETRIES_EXHAUSTED', 'upload', true));

        $this->consumer->handle(completed($job));

        expect($this->video->fresh()->status)->toBe('ready')
            ->and($job->fresh())->status->toBe('succeeded')->error_code->toBeNull();
    });

    it('never un-completes a job', function () {
        $job = ($this->dispatch)();
        $this->consumer->handle(completed($job));

        $this->consumer->handle(failed($job));

        expect($job->fresh()->status)->toBe('succeeded')->and($this->video->fresh()->status)->toBe('ready');
    });
});

it('records results for a video deleted meanwhile, without moving it', function () {
    $job = ($this->dispatch)();
    DB::transaction(fn () => app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::Deleted, 'owner_deleted'));

    $this->consumer->handle(completed($job));

    expect($this->video->fresh()->status)->toBe('deleted')
        ->and(DB::table('video_variants')->where('video_id', $this->video->id)->count())->toBe(2)
        ->and(DB::table('video_assets')->where('video_id', $this->video->id)->count())->toBe(2);   // so the purge finds the files
});

it('moves the primary thumbnail to a newer processing version', function () {
    $v1 = ($this->dispatch)();
    $this->consumer->handle(completed($v1));
    Video::whereKey($this->video->id)->update(['status' => 'uploading']);
    $v2 = ($this->dispatch)();

    $this->consumer->handle(completed($v2));

    expect(DB::table('thumbnails')->where('video_id', $this->video->id)->where('is_primary', true)->value('processing_version'))->toBe(2)
        ->and(DB::table('thumbnails')->where('video_id', $this->video->id)->where('is_primary', true)->count())->toBe(1);
});

describe('GET /v1/videos/{id}/processing', function () {
    beforeEach(function () {
        $this->asOwner = ['Authorization' => 'Bearer '.Auth::login($this, 'ada@example.com')['access_token']];
    });

    it('shows the creator how processing is going, without internal details', function () {
        $job = ($this->dispatch)();
        $this->consumer->handle(ready($job, 360));
        $this->consumer->handle(failed($job, 'ENCODER_FAILED', 'transcode', true));

        $this->getJson("/v1/videos/{$this->video->public_id}/processing", $this->asOwner)
            ->assertOk()
            ->assertJson([
                'video_status' => 'ready',
                'job' => [
                    'processing_version' => 1, 'status' => 'partially_succeeded',
                    'renditions' => [['width' => 640, 'height' => 360, 'frame_rate' => 30, 'bitrate' => 800000]],
                    'error' => ['code' => 'ENCODER_FAILED', 'step' => 'transcode', 'retryable' => true],
                ],
            ])
            ->assertJsonMissing(['internal detail']);
    });

    it('has no job before dispatch', function () {
        $this->getJson("/v1/videos/{$this->video->public_id}/processing", $this->asOwner)
            ->assertOk()->assertJson(['video_status' => 'uploading', 'job' => null]);
    });

    it('is only for the owner', function () {
        Auth::createUser('eve@example.com');
        $asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];

        $this->getJson("/v1/videos/{$this->video->public_id}/processing", $asEve)->assertNotFound();
        $this->getJson("/v1/videos/{$this->video->public_id}/processing")->assertUnauthorized();
    });
});

it('publishes on the first rendition when the creator chose publish_on_ready', function () {
    Video::whereKey($this->video->id)->update(['publish_on_ready' => true, 'visibility' => 'public']);
    $job = ($this->dispatch)();

    $this->consumer->handle(ready($job, 360));

    expect($this->video->fresh()->status)->toBe('published')
        ->and(array_slice(($this->states)(), -2))->toBe(['ready', 'published']);
});
