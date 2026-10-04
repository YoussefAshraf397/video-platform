<?php

/*
 * S3-04 acceptance: concurrent completes of one upload produce exactly one VideoUploaded.
 *
 * Real concurrency needs separate processes and committed rows, so this test doesn't use
 * RefreshDatabase: it commits its own user/video/session and deletes them afterwards.
 */

use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Uploads\Services\UploadSessions;
use App\Modules\Users\Models\User;
use App\Modules\Videos\Models\Video;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->artisan('migrate');
    $this->s3 = app(S3Client::class);
    $this->bucket = 'test-'.Str::lower(Str::random(12));
    $this->s3->createBucket(['Bucket' => $this->bucket]);
    config(['uploads.bucket' => $this->bucket]);

    $this->user = User::factory()->create(['email' => Str::random(8).'@race.example', 'email_verified_at' => now()]);
    $this->video = Video::factory()->create(['uploader_user_id' => $this->user->id]);
});

afterEach(function () {
    UploadSession::where('video_id', $this->video->id)->delete();
    DB::table('outbox_messages')->where('aggregate_id', $this->video->id)->delete();
    Video::whereKey($this->video->id)->delete();
    $this->user->delete();

    foreach ($this->s3->listObjectsV2(['Bucket' => $this->bucket])['Contents'] ?? [] as $o) {
        $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $o['Key']]);
    }
    $this->s3->deleteBucket(['Bucket' => $this->bucket]);
});

it('emits one VideoUploaded when several completes race', function () {
    $sessions = app(UploadSessions::class);
    $session = $sessions->create($this->user->id, $this->video->public_id, 20 * 1024 * 1024, 'video/mp4', null);
    $parts = array_map(fn (array $u) => [
        'part_number' => $u['part_number'],
        'etag' => Http::withBody(str_repeat('v', $u['size_bytes']), 'application/octet-stream')->put($u['url'])->header('ETag'),
    ], $sessions->urls($session, range(1, $session->total_parts)));

    $startAt = sprintf('%.3F', microtime(true) + 2);
    $processes = array_map(function () use ($session, $parts, $startAt) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/complete-upload.php'), $this->user->id, $session->id, json_encode($parts), $startAt],
            env: ['UPLOADS_BUCKET' => $this->bucket],
            timeout: 60,
        );
        $process->start();

        return $process;
    }, range(1, 6));

    $outcomes = array_map(function (Process $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput());

        return trim($p->getOutput());
    }, $processes);

    expect(array_diff($outcomes, ['ok:completed', 'problem:UPLOAD_COMPLETING']))->toBe([])
        ->and($outcomes)->toContain('ok:completed');

    $uploaded = DB::table('outbox_messages')->where('aggregate_id', $this->video->id)->where('event_type', 'VideoUploaded')->count();
    expect($uploaded)->toBe(1)
        ->and($session->fresh()->status)->toBe('completed')
        ->and($this->video->fresh()->status)->toBe('uploaded');
});
