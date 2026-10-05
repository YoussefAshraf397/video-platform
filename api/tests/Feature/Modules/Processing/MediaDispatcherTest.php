<?php

use App\Modules\Processing\Consumers\MediaDispatcher;
use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Auth;
use Tests\Support\Contracts;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = Auth::createUser('ada@example.com');
    $this->video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'uploading']);
    $this->dispatcher = app(MediaDispatcher::class);

    /** A completed upload for the video, then the real VideoUploaded the state machine emits. */
    $this->upload = function (): array {
        $session = UploadSession::query()->create([
            'video_id' => $this->video->id, 'user_id' => $this->owner->id, 'bucket' => 'uploads',
            'object_key' => "uploads/{$this->video->id}/".Str::uuid7().'/source', 's3_upload_id' => Str::random(20),
            'declared_size_bytes' => 1000, 'content_type' => 'video/mp4', 'part_size_bytes' => 1000, 'total_parts' => 1,
            'status' => 'completed', 'completed_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        DB::transaction(fn () => app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::Uploaded, 'upload_completed'));

        return [$session, json_decode(DB::table('outbox_messages')->where('event_type', 'VideoUploaded')->latest('id')->value('envelope'), true)];
    };
    $this->commands = fn () => DB::table('outbox_messages')->where('event_type', 'MediaProcessRequested')->orderBy('id')
        ->pluck('envelope')->map(fn ($e) => json_decode($e, true))->all();
});

it('creates a job, moves the video to validating and asks the worker to process it', function () {
    [$session, $event] = ($this->upload)();

    expect($this->dispatcher->handle($event))->toBeTrue();

    $job = ProcessingJob::sole();
    expect($job)->video_id->toBe($this->video->id)->upload_session_id->toBe($session->id)
        ->processing_version->toBe(1)->profile->toBe('h264-sdr-v1')->status->toBe('queued')
        ->source_key->toBe($session->object_key)->output_prefix->toBe("media/{$this->video->id}/v1/")
        ->and($this->video->fresh()->status)->toBe('validating');

    $commands = ($this->commands)();
    expect($commands)->toHaveCount(1)
        ->and($commands[0]['payload'])->toBe([
            'video_id' => $this->video->id, 'job_id' => $job->id, 'processing_version' => 1,
            'source' => ['bucket' => 'uploads', 'key' => $session->object_key],
            'output' => ['bucket' => 'media', 'prefix' => "media/{$this->video->id}/v1/"],
            'profile' => 'h264-sdr-v1',
        ])
        ->and($commands[0]['aggregate_version'])->toBe($this->video->fresh()->state_version)
        ->and(Contracts::violations('media-process-requested.v1', json_encode($commands[0])))->toBeNull()
        ->and(DB::table('outbox_messages')->where('event_type', 'MediaProcessRequested')->value('topic'))->toBe('media-commands');
});

it('creates one job when the same event is delivered twice', function () {
    [, $event] = ($this->upload)();

    expect($this->dispatcher->handle($event))->toBeTrue()
        ->and($this->dispatcher->handle($event))->toBeFalse();

    expect(ProcessingJob::count())->toBe(1)->and(($this->commands)())->toHaveCount(1);
});

it('creates one job when a different VideoUploaded arrives for the same upload', function () {
    [, $event] = ($this->upload)();

    $this->dispatcher->handle($event);
    expect($this->dispatcher->handle([...$event, 'event_id' => (string) Str::uuid7()]))->toBeTrue();   // processed, but nothing to do

    expect(ProcessingJob::count())->toBe(1)->and(($this->commands)())->toHaveCount(1);
});

it('has a database backstop against two jobs for one upload', function () {
    [$session, $event] = ($this->upload)();
    $this->dispatcher->handle($event);

    expect(fn () => ProcessingJob::query()->create([
        ...ProcessingJob::sole()->only(['video_id', 'upload_session_id', 'profile', 'source_bucket', 'source_key', 'output_bucket']),
        'processing_version' => 2, 'output_prefix' => 'other/',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('gives a later upload of the same video the next processing version', function () {
    [, $first] = ($this->upload)();
    $this->dispatcher->handle($first);
    Video::whereKey($this->video->id)->update(['status' => 'uploading']);   // e.g. re-uploaded after processing failed

    [$second, $event] = ($this->upload)();
    $this->dispatcher->handle($event);

    $job = ProcessingJob::where('upload_session_id', $second->id)->sole();
    expect($job->processing_version)->toBe(2)->and($job->output_prefix)->toBe("media/{$this->video->id}/v2/");
});

it('does not process a video that was deleted before dispatch', function () {
    [, $event] = ($this->upload)();
    DB::transaction(fn () => app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::Deleted, 'owner_deleted'));

    expect($this->dispatcher->handle($event))->toBeTrue();   // consumed, so it isn't retried
    expect(ProcessingJob::count())->toBe(0)->and(($this->commands)())->toBe([]);
});

it('ignores other video events', function () {
    $event = ['event_id' => (string) Str::uuid7(), 'event_type' => 'VideoStateChanged', 'payload' => ['video_id' => $this->video->id]];

    $this->dispatcher->handle($event);

    expect(ProcessingJob::count())->toBe(0)->and($this->video->fresh()->status)->toBe('uploading');
});

it('leaves the video alone when it has no completed upload', function () {
    DB::transaction(fn () => app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::Uploaded, 'upload_completed'));
    $event = json_decode(DB::table('outbox_messages')->where('event_type', 'VideoUploaded')->value('envelope'), true);

    $this->dispatcher->handle($event);

    expect(ProcessingJob::count())->toBe(0)->and($this->video->fresh()->status)->toBe('uploaded');
});

it('is registered for messages:consume', function () {
    expect(config('messaging.consumers'))->toContain(MediaDispatcher::class)
        ->and($this->dispatcher->name())->toBe('media-dispatcher');
});
