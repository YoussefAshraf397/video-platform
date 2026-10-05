<?php

/*
 * S3-05: the expiry sweeper and S3 ObjectCreated reconciliation, against the local stack with a
 * throwaway bucket (and queue) per test.
 */

use App\Modules\Uploads\Consumers\UploadObjectCreatedConsumer;
use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Uploads\Services\ObjectStore;
use App\Modules\Uploads\Services\UploadSessions;
use App\Modules\Videos\Models\Video;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Messaging\SqsConsumerRunner;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->s3 = app(S3Client::class);
    $this->bucket = 'test-'.Str::lower(Str::random(12));
    $this->s3->createBucket(['Bucket' => $this->bucket]);
    config(['uploads.bucket' => $this->bucket]);
    $this->owner = Auth::createUser('ada@example.com');

    /** A session for a new video, with `$upload` parts (of 3) already in S3. */
    $this->session = function (int $upload = 3): UploadSession {
        $video = Video::factory()->create(['uploader_user_id' => $this->owner->id]);
        $sessions = app(UploadSessions::class);
        $session = $sessions->create($this->owner->id, $video->public_id, 20 * 1024 * 1024, 'video/mp4', null);
        foreach ($upload > 0 ? $sessions->urls($session, range(1, $upload)) : [] as $u) {
            Http::withBody(str_repeat('v', $u['size_bytes']), 'application/octet-stream')->put($u['url'])->throw();
        }

        return $session;
    };
    /** As if a :complete call crashed: claimed long ago, never finished. */
    $this->crashedWhileCompleting = fn (UploadSession $s) => UploadSession::whereKey($s->id)
        ->update(['status' => 'completing', 'updated_at' => now()->subMinutes(10)]);
    $this->uploadedEvents = fn () => DB::table('outbox_messages')->where('event_type', 'VideoUploaded')->count();
});

afterEach(function () {
    foreach ($this->s3->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? [] as $u) {
        $this->s3->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $u['Key'], 'UploadId' => $u['UploadId']]);
    }
    foreach ($this->s3->listObjectsV2(['Bucket' => $this->bucket])['Contents'] ?? [] as $o) {
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $o['Key']]);
    }
    $this->s3->deleteBucket(['Bucket' => $this->bucket]);
});

describe('sweeper', function () {
    it('expires abandoned sessions and leaves live ones alone', function () {
        [$old, $older, $live] = [($this->session)(1), ($this->session)(0), ($this->session)(1)];
        UploadSession::whereKey([$old->id, $older->id])->update(['expires_at' => now()->subMinute()]);

        $this->artisan('uploads:sweep')->expectsOutput('expired: 2, completed: 0, released: 0, failed: 0, errors: 0')->assertSuccessful();

        expect(UploadSession::find($old->id))->status->toBe('expired')->failure_reason->toBe('upload_expired')
            ->and(UploadSession::find($older->id)->status)->toBe('expired')
            ->and(UploadSession::find($live->id)->status)->toBe('initiated')
            ->and(Video::find($old->video_id)->status)->toBe('upload_failed')
            ->and(collect($this->s3->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'])->pluck('Key')->all())->toBe([$live->object_key]);

        $this->artisan('uploads:sweep')->expectsOutput('expired: 0, completed: 0, released: 0, failed: 0, errors: 0');
    });

    it('finishes a session whose completing call crashed', function () {
        $session = ($this->session)();
        ($this->crashedWhileCompleting)($session);

        $this->artisan('uploads:sweep')->expectsOutput('expired: 0, completed: 1, released: 0, failed: 0, errors: 0');

        expect(UploadSession::find($session->id)->status)->toBe('completed')
            ->and(Video::find($session->video_id)->status)->toBe('uploaded')
            ->and(($this->uploadedEvents)())->toBe(1);
    });

    it('finishes it even if S3 had already assembled the object', function () {
        $session = ($this->session)();
        $store = app(ObjectStore::class);
        $store->completeMultipartUpload($this->bucket, $session->object_key, $session->s3_upload_id,
            $store->listParts($this->bucket, $session->object_key, $session->s3_upload_id));
        ($this->crashedWhileCompleting)($session);

        $this->artisan('uploads:sweep')->expectsOutput('expired: 0, completed: 1, released: 0, failed: 0, errors: 0');
        expect(UploadSession::find($session->id)->status)->toBe('completed');
    });

    it('hands back a crashed completion whose parts are not all there', function () {
        $session = ($this->session)(2);
        UploadSession::whereKey($session->id)->update(['status' => 'in_progress']);
        ($this->crashedWhileCompleting)($session);

        $this->artisan('uploads:sweep')->expectsOutput('expired: 0, completed: 0, released: 1, failed: 0, errors: 0');

        // Back to in_progress: the client can still finish, or the session expires on schedule.
        expect(UploadSession::find($session->id)->status)->toBe('in_progress');
    });

    it('leaves a completion that is still running alone', function () {
        $session = ($this->session)();
        UploadSession::whereKey($session->id)->update(['status' => 'completing', 'updated_at' => now()]);

        $this->artisan('uploads:sweep')->expectsOutput('expired: 0, completed: 0, released: 0, failed: 0, errors: 0');
        expect(UploadSession::find($session->id)->status)->toBe('completing');
    });

    it('is scheduled every 15 minutes', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'uploads:sweep'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/15 * * * *');
    });
});

describe('ObjectCreated reconciliation', function () {
    function objectCreated(UploadSession $s, string $event = 'ObjectCreated:CompleteMultipartUpload'): array
    {
        return ['Records' => [['eventName' => $event, 's3' => ['bucket' => ['name' => $s->bucket], 'object' => ['key' => $s->object_key, 'size' => 1]]]]];
    }

    it('completes the session of an object whose completing call crashed', function () {
        $session = ($this->session)();
        ($this->crashedWhileCompleting)($session);

        expect(app(UploadObjectCreatedConsumer::class)->handle(objectCreated($session)))->toBeTrue()
            ->and(UploadSession::find($session->id)->status)->toBe('completed')
            ->and(($this->uploadedEvents)())->toBe(1);

        // S3 sends duplicates; they change nothing.
        expect(app(UploadObjectCreatedConsumer::class)->handle(objectCreated($session->fresh())))->toBeFalse()
            ->and(($this->uploadedEvents)())->toBe(1);
    });

    it('asks for redelivery while a live completing call holds the session', function () {
        $session = ($this->session)();
        UploadSession::whereKey($session->id)->update(['status' => 'completing', 'updated_at' => now()]);

        expect(fn () => app(UploadObjectCreatedConsumer::class)->handle(objectCreated($session)))
            ->toThrow(fn (ApiProblem $p) => expect($p->errorCode)->toBe('UPLOAD_COMPLETING'));
    });

    it('ignores messages that are not about a session object', function (Closure $message) {
        $session = ($this->session)(0);

        expect(app(UploadObjectCreatedConsumer::class)->handle($message($session)))->toBeFalse()
            ->and(UploadSession::find($session->id)->status)->toBe('initiated');
    })->with([
        'S3 test event' => [fn ($s) => ['Service' => 'Amazon S3', 'Event' => 's3:TestEvent']],
        'a delete' => [fn ($s) => objectCreated($s, 'ObjectRemoved:Delete')],
        'another key' => [fn ($s) => ['Records' => [['eventName' => 'ObjectCreated:Put', 's3' => ['object' => ['key' => 'uploads/x/y/thumb']]]]]],
        'no such session' => [fn ($s) => ['Records' => [['eventName' => 'ObjectCreated:Put', 's3' => ['object' => ['key' => str_replace($s->id, (string) Str::uuid(), $s->object_key)]]]]]],
    ]);

    it('runs end to end: S3 notification → SQS → consumer', function () {
        $sqs = app(SqsClient::class);
        $queueUrl = $sqs->createQueue(['QueueName' => $this->bucket, 'Attributes' => ['VisibilityTimeout' => '0']])->get('QueueUrl');
        $queueArn = $sqs->getQueueAttributes(['QueueUrl' => $queueUrl, 'AttributeNames' => ['QueueArn']])->get('Attributes')['QueueArn'];
        $this->s3->putBucketNotificationConfiguration(['Bucket' => $this->bucket, 'NotificationConfiguration' => [
            'QueueConfigurations' => [['QueueArn' => $queueArn, 'Events' => ['s3:ObjectCreated:*']]],
        ]]);

        // The crash: S3 assembled the file, but the session never got past `completing`.
        $session = ($this->session)();
        $store = app(ObjectStore::class);
        $store->completeMultipartUpload($this->bucket, $session->object_key, $session->s3_upload_id,
            $store->listParts($this->bucket, $session->object_key, $session->s3_upload_id));
        ($this->crashedWhileCompleting)($session);

        $runner = app(SqsConsumerRunner::class);
        for ($i = 0; $i < 5 && UploadSession::find($session->id)->status !== 'completed'; $i++) {
            $runner->pollOnce(app(UploadObjectCreatedConsumer::class), $queueUrl, 1);
        }

        expect(UploadSession::find($session->id)->status)->toBe('completed')
            ->and(Video::find($session->video_id)->status)->toBe('uploaded');
        $sqs->deleteQueue(['QueueUrl' => $queueUrl]);
    });
});
