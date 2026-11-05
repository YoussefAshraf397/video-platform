<?php

/*
 * Captions publishing (S4-06): subtitle tracks → MediaCaptionsRequested (the whole set, next
 * revision) → the worker's VideoCaptionsPublished → what playback serves.
 */

use App\Modules\Processing\Consumers\MediaDispatcher;
use App\Modules\Processing\Consumers\MediaResultsConsumer;
use App\Modules\Processing\Contracts\PlayableMedia;
use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Subtitle;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\Subtitles;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Auth;
use Tests\Support\Contracts;
use Tests\Support\WorkerResults;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = Auth::createUser('ada@example.com');
    $this->video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'uploading']);
    $this->results = app(MediaResultsConsumer::class);

    /** Uploads and dispatches like production: a queued job for the next processing version. */
    $this->dispatch = function (): ProcessingJob {
        UploadSession::query()->create([
            'video_id' => $this->video->id, 'user_id' => $this->owner->id, 'bucket' => 'uploads',
            'object_key' => "uploads/{$this->video->id}/".Str::uuid7().'/source', 's3_upload_id' => Str::random(20),
            'declared_size_bytes' => 1000, 'content_type' => 'video/mp4', 'part_size_bytes' => 1000, 'total_parts' => 1,
            'status' => 'completed', 'completed_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        DB::transaction(fn () => app(VideoLifecycle::class)->transition($this->video->id, VideoStatus::Uploaded, 'upload_completed'));
        app(MediaDispatcher::class)->handle(json_decode(DB::table('outbox_messages')->where('event_type', 'VideoUploaded')->latest('id')->value('envelope'), true));

        return ProcessingJob::query()->where('video_id', $this->video->id)->orderByDesc('processing_version')->firstOrFail();
    };
    $this->processed = function (): ProcessingJob {
        $job = ($this->dispatch)();
        $this->results->handle(WorkerResults::completed($job));

        return $job->fresh();
    };

    /** A track the creator uploaded and completed (the file really is in S3). */
    $this->track = function (string $language, string $kind = 'subtitles'): Subtitle {
        $subtitle = Subtitle::query()->create([
            'video_id' => $this->video->id, 'language' => $language, 'label' => strtoupper($language), 'kind' => $kind, 'format' => 'srt',
            'size_bytes' => 5, 'source_bucket' => 'uploads', 'source_key' => "captions/{$this->video->id}/".Str::uuid7().'/source',
            'status' => 'awaiting_upload', 'upload_expires_at' => now()->addMinutes(15),
        ]);
        app(S3Client::class)->putObject(['Bucket' => 'uploads', 'Key' => $subtitle->source_key, 'Body' => 'hello']);

        return app(Subtitles::class)->complete($this->video->fresh(), $subtitle->id);
    };
    $this->delete = fn (Subtitle $s) => app(Subtitles::class)->delete($this->video->fresh(), $s->id);

    /** The MediaCaptionsRequested commands sent so far, each checked against its contract. */
    $this->requests = fn () => DB::table('outbox_messages')->where('event_type', 'MediaCaptionsRequested')->orderBy('id')->pluck('envelope')
        ->map(function (string $json) {
            expect(Contracts::violations('media-captions-requested.v1', $json))->toBeNull();

            return json_decode($json, true)['payload'];
        })->all();
    $this->trackIds = fn (array $request) => array_column($request['tracks'], 'subtitle_id');
});

it('publishes a track completed after processing finished', function () {
    $job = ($this->processed)();

    $en = ($this->track)('en', 'captions');

    [$request] = ($this->requests)();
    expect($request)->toMatchArray([
        'job_id' => $job->id, 'processing_version' => 1, 'captions_revision' => 1,
        'output' => ['bucket' => 'media', 'prefix' => $job->output_prefix], 'base_master_key' => $job->output_prefix.'master.m3u8',
    ])->and($request['tracks'])->toBe([[
        'subtitle_id' => $en->id, 'language' => 'en', 'label' => 'EN', 'kind' => 'captions', 'format' => 'srt',
        'source' => ['bucket' => 'uploads', 'key' => $en->source_key],
    ]])->and(DB::table('outbox_messages')->where('event_type', 'MediaCaptionsRequested')->value('topic'))->toBe('media-commands');
});

it('waits for processing to finish, then publishes everything uploaded meanwhile', function () {
    $job = ($this->dispatch)();
    $en = ($this->track)('en');
    $this->results->handle(WorkerResults::ready($job, 360));   // playable, but the master isn't final yet
    $fr = ($this->track)('fr');
    expect(($this->requests)())->toBe([])->and($en->fresh()->status)->toBe('processing');

    $this->results->handle(WorkerResults::completed($job));

    [$request] = ($this->requests)();
    expect($request['captions_revision'])->toBe(1)->and(($this->trackIds)($request))->toBe([$en->id, $fr->id]);
});

it('sends the whole set on every change, under the next revision', function () {
    ($this->processed)();

    $en = ($this->track)('en');
    $fr = ($this->track)('fr');
    ($this->delete)($en);
    ($this->delete)($fr);

    $requests = ($this->requests)();
    expect(array_column($requests, 'captions_revision'))->toBe([1, 2, 3, 4])
        ->and(array_map($this->trackIds, $requests))->toBe([[$en->id], [$en->id, $fr->id], [$fr->id], []]);
});

it('sends nothing for a video that never had captions', function () {
    ($this->processed)();
    $abandoned = ($this->track)('en');
    DB::table('outbox_messages')->where('event_type', 'MediaCaptionsRequested')->delete();
    Subtitle::query()->whereKey($abandoned->id)->update(['status' => 'awaiting_upload']);

    ($this->delete)($abandoned);   // it was never published: nothing changes for players

    expect(($this->requests)())->toBe([]);
});

it('serves the published revision: its master playlist and its tracks', function () {
    $job = ($this->processed)();
    $en = ($this->track)('en', 'captions');
    $broken = ($this->track)('de');

    $this->results->handle(WorkerResults::captionsPublished($job->fresh(), 2, [$en->id => null, $broken->id => 'CAPTIONS_INVALID']));

    $output = app(PlayableMedia::class)->current($this->video->id);
    expect($output->masterKey)->toBe($job->output_prefix.'master.c2.m3u8')
        ->and($output->captions)->toEqual([[   // (jsonb keeps its own key order)
            'subtitle_id' => $en->id, 'language' => 'en', 'label' => 'EN', 'kind' => 'captions',
            'playlist_key' => $job->output_prefix."subs/{$en->id}.m3u8", 'vtt_key' => $job->output_prefix."subs/{$en->id}.vtt",
        ]])
        ->and($en->fresh()->status)->toBe('ready')
        ->and($broken->fresh()->only('status', 'error_code', 'error_detail'))->toBe(['status' => 'failed', 'error_code' => 'CAPTIONS_INVALID', 'error_detail' => 'line 3: expected a cue timing line']);

    // The failed track isn't sent again; the creator fixes the file and adds it anew.
    ($this->track)('fr');
    expect(($this->trackIds)(last(($this->requests)())))->toBe([$en->id, Subtitle::where('language', 'fr')->value('id')]);
});

it('never goes back to an older revision when results arrive out of order', function () {
    $job = ($this->processed)();
    $en = ($this->track)('en');
    $fr = ($this->track)('fr');

    $this->results->handle(WorkerResults::captionsPublished($job->fresh(), 2, [$en->id => null, $fr->id => null]));
    $this->results->handle(WorkerResults::captionsPublished($job->fresh(), 1, [$en->id => null]));

    $output = app(PlayableMedia::class)->current($this->video->id);
    expect($output->masterKey)->toEndWith('master.c2.m3u8')->and(array_column($output->captions, 'subtitle_id'))->toBe([$en->id, $fr->id]);
});

it('leaves a track deleted meanwhile deleted', function () {
    $job = ($this->processed)();
    $en = ($this->track)('en');
    ($this->delete)($en);

    $this->results->handle(WorkerResults::captionsPublished($job->fresh(), 1, [$en->id => null]));   // revision 1 still had it

    expect($en->fresh()->status)->toBe('deleted');
});

it('attaches the captions to a reprocessed version', function () {
    $v1 = ($this->processed)();
    $en = ($this->track)('en');
    $this->results->handle(WorkerResults::captionsPublished($v1->fresh(), 1, [$en->id => null]));

    Video::query()->whereKey($this->video->id)->update(['status' => 'uploading']);   // the creator re-uploads
    $v2 = ($this->dispatch)();
    $this->results->handle(WorkerResults::completed($v2));

    $request = last(($this->requests)());
    expect($request)->toMatchArray(['job_id' => $v2->id, 'processing_version' => 2, 'captions_revision' => 1])
        ->and(($this->trackIds)($request))->toBe([$en->id]);
    // Until the new version's captions are published, it plays without them (its own base master).
    expect(app(PlayableMedia::class)->current($this->video->id)->masterKey)->toBe($v2->output_prefix.'master.m3u8');
});

it('publishes for a partially succeeded job, whose playable output is final', function () {
    $job = ($this->dispatch)();
    $en = ($this->track)('en');
    $this->results->handle(WorkerResults::ready($job, 360));

    $this->results->handle(WorkerResults::failed($job, 'RETRIES_EXHAUSTED', 'transcode', true));

    expect($job->fresh()->status)->toBe('partially_succeeded')
        ->and(($this->trackIds)(($this->requests)()[0]))->toBe([$en->id]);
});
