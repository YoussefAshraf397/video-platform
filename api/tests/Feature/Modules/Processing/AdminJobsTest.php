<?php

use App\Modules\Processing\Consumers\MediaDispatcher;
use App\Modules\Processing\Consumers\MediaResultsConsumer;
use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Users\Models\User;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Auth;
use Tests\Support\Contracts;
use Tests\Support\WorkerResults;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->creator = Auth::createUser('creator@example.com');
    Auth::createUser('admin@example.com');
    User::where('email', 'admin@example.com')->update(['role' => 'admin']);
    $this->asAdmin = ['Authorization' => 'Bearer '.Auth::login($this, 'admin@example.com')['access_token']];
    $this->asCreator = ['Authorization' => 'Bearer '.Auth::login($this, 'creator@example.com')['access_token']];
    $this->results = app(MediaResultsConsumer::class);

    /** An uploaded, dispatched video whose job then failed with $code. */
    $this->failedJob = function (string $code = 'RETRIES_EXHAUSTED', bool $retryable = true): ProcessingJob {
        $video = Video::factory()->create(['uploader_user_id' => $this->creator->id, 'status' => 'uploading']);
        UploadSession::query()->create([
            'video_id' => $video->id, 'user_id' => $this->creator->id, 'bucket' => 'uploads', 'object_key' => "uploads/{$video->id}/s/source",
            's3_upload_id' => Str::random(20), 'declared_size_bytes' => 1, 'content_type' => 'video/mp4', 'part_size_bytes' => 1,
            'total_parts' => 1, 'status' => 'completed', 'completed_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        DB::transaction(fn () => app(VideoLifecycle::class)->transition($video->id, VideoStatus::Uploaded, 'upload_completed'));
        app(MediaDispatcher::class)->handle(json_decode(DB::table('outbox_messages')->where('aggregate_id', $video->id)->where('event_type', 'VideoUploaded')->value('envelope'), true));
        $job = ProcessingJob::where('video_id', $video->id)->sole();
        $this->results->handle(WorkerResults::failed($job, $code, 'transcode', $retryable));

        return $job->fresh();
    };
    $this->retry = fn (ProcessingJob $job, ?array $headers = null) => $this->postJson("/admin/v1/processing/jobs/{$job->id}:retry", [], withKey($headers ?? $this->asAdmin));
});

describe('access', function () {
    it('is for admins only', function () {
        $job = ($this->failedJob)();
        Auth::createUser('mod@example.com');
        User::where('email', 'mod@example.com')->update(['role' => 'moderator']);
        $asModerator = ['Authorization' => 'Bearer '.Auth::login($this, 'mod@example.com')['access_token']];

        $this->getJson('/admin/v1/processing/jobs')->assertUnauthorized();
        $this->getJson('/admin/v1/processing/jobs', $this->asCreator)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->getJson('/admin/v1/processing/jobs', $asModerator)->assertForbidden();
        ($this->retry)($job, $this->asCreator)->assertForbidden();
        $this->getJson('/admin/v1/processing/jobs', $this->asAdmin)->assertOk();
    });

    it('takes effect the moment the role is removed, with the same token', function () {
        $this->getJson('/admin/v1/processing/jobs', $this->asAdmin)->assertOk();

        $this->artisan('users:role', ['email' => 'admin@example.com', 'role' => 'user'])->assertSuccessful();

        $this->getJson('/admin/v1/processing/jobs', $this->asAdmin)->assertForbidden();
    });
});

describe('listing', function () {
    it('lists failed jobs, newest first, with the worker\'s message', function () {
        $older = ($this->failedJob)('ENCODER_FAILED');
        $this->travel(1)->minutes();
        $newer = ($this->failedJob)('RETRIES_EXHAUSTED');

        $this->getJson('/admin/v1/processing/jobs', $this->asAdmin)->assertOk()
            ->assertJsonPath('items.*.id', [$newer->id, $older->id])
            ->assertJsonPath('items.0.error', ['code' => 'RETRIES_EXHAUSTED', 'step' => 'transcode', 'retryable' => true, 'message' => 'internal detail'])
            ->assertJsonPath('items.0.video.status', 'processing_failed');
    });

    it('filters by status and paginates', function () {
        foreach (range(1, 3) as $i) {
            ($this->failedJob)();
        }

        $this->getJson('/admin/v1/processing/jobs?status=succeeded', $this->asAdmin)->assertOk()->assertJsonPath('items', []);
        $this->getJson('/admin/v1/processing/jobs?limit=2', $this->asAdmin)->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('has_more', true);
        $this->getJson('/admin/v1/processing/jobs?status=exploded', $this->asAdmin)->assertStatus(422);
    });
});

describe('retry', function () {
    it('queues the video again as a new job at the next version', function () {
        $job = ($this->failedJob)();

        $response = ($this->retry)($job)->assertCreated()
            ->assertJson(['processing_version' => 2, 'status' => 'queued', 'retry_of' => $job->id, 'video' => ['status' => 'queued_for_processing']]);

        $retry = ProcessingJob::findOrFail($response->json('id'));
        expect($retry)->output_prefix->toBe("media/{$job->video_id}/v2/")->source_key->toBe($job->source_key);
        $command = json_decode(DB::table('outbox_messages')->where('event_type', 'MediaProcessRequested')->latest('id')->value('envelope'), true);
        expect($command['payload'])->toMatchArray(['job_id' => $retry->id, 'processing_version' => 2])
            ->and(Contracts::violations('media-process-requested.v1', json_encode($command)))->toBeNull();
        $last = json_decode(DB::table('outbox_messages')->where('aggregate_id', $job->video_id)->where('topic', 'video-events')->latest('id')->value('envelope'), true);
        expect($last['payload'])->toMatchArray(['from' => 'processing_failed', 'to' => 'queued_for_processing', 'reason' => 'admin_retry']);
    });

    it('lets the retried job finish the video, and drops late results of the failed one', function () {
        $job = ($this->failedJob)();
        $retry = ProcessingJob::findOrFail(($this->retry)($job)->json('id'));

        $this->results->handle(WorkerResults::ready($job, 360));          // late, from the failed job: stale
        expect(Video::find($job->video_id)->status)->toBe('queued_for_processing');

        $this->results->handle(WorkerResults::ready($retry, 360));
        $this->results->handle(WorkerResults::completed($retry));
        expect(Video::find($job->video_id)->status)->toBe('ready')->and($retry->fresh()->status)->toBe('succeeded');
    });

    it('can retry a retry that failed too', function () {
        $job = ($this->failedJob)();
        $retry = ProcessingJob::findOrFail(($this->retry)($job)->json('id'));
        $this->results->handle(WorkerResults::failed($retry, 'ENCODER_FAILED', 'transcode', true));

        ($this->retry)($retry->fresh())->assertCreated()->assertJsonPath('processing_version', 3);
    });

    it('creates one job however often the request is repeated', function () {
        $job = ($this->failedJob)();
        $headers = withKey($this->asAdmin);

        $first = $this->postJson("/admin/v1/processing/jobs/{$job->id}:retry", [], $headers)->assertCreated();
        $this->postJson("/admin/v1/processing/jobs/{$job->id}:retry", [], $headers)->assertCreated()->assertJsonPath('id', $first->json('id'));
        ($this->retry)($job)->assertConflict()->assertJsonPath('code', 'NOT_LATEST_JOB');   // a new key: the job isn't the latest any more

        expect(ProcessingJob::where('video_id', $job->video_id)->count())->toBe(2);
    });

    it('refuses jobs that are not failed, not the latest, or whose video moved on', function () {
        $job = ($this->failedJob)();
        $partial = ($this->failedJob)();
        DB::table('video_processing_jobs')->where('id', $partial->id)->update(['status' => 'partially_succeeded']);
        $gone = ($this->failedJob)();
        DB::transaction(fn () => app(VideoLifecycle::class)->transition($gone->video_id, VideoStatus::Deleted, 'owner_deleted'));

        ($this->retry)($partial)->assertConflict()->assertJsonPath('code', 'JOB_NOT_FAILED');
        ($this->retry)($gone)->assertConflict()->assertJsonPath('code', 'VIDEO_NOT_RETRYABLE');
        $this->postJson('/admin/v1/processing/jobs/'.Str::uuid().':retry', [], withKey($this->asAdmin))->assertNotFound();
        expect(ProcessingJob::where('video_id', $gone->video_id)->count())->toBe(1);

        ($this->retry)($job)->assertCreated();
        ($this->retry)($job)->assertConflict()->assertJsonPath('code', 'NOT_LATEST_JOB');
    });

    it('keeps the dispatcher\'s one-job-per-upload backstop', function () {
        $job = ($this->failedJob)();

        expect(fn () => ProcessingJob::query()->create([
            ...$job->only(['video_id', 'upload_session_id', 'profile', 'source_bucket', 'source_key', 'output_bucket']),
            'processing_version' => 9, 'output_prefix' => 'x/', 'status' => 'queued',
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});
