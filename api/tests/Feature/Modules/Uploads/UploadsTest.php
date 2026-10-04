<?php

/*
 * Integration tests against the local stack (`make up`): real PostgreSQL and the S3 emulator,
 * with a throwaway bucket per test. Parts are PUT to the presigned URLs exactly as a browser would.
 */

use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Uploads\Services\ObjectStore;
use App\Modules\Uploads\Services\UploadSessions;
use App\Modules\Users\Models\User;
use App\Modules\Videos\Models\Video;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth;
use Tests\Support\Contracts;

uses(RefreshDatabase::class);

const MIB = 1024 * 1024;

beforeEach(function () {
    $this->s3 = app(S3Client::class);
    $this->bucket = 'test-'.Str::lower(Str::random(12));
    $this->s3->createBucket(['Bucket' => $this->bucket]);
    config(['uploads.bucket' => $this->bucket]);

    $this->ada = Auth::createUser('ada@example.com');
    $this->asAda = ['Authorization' => 'Bearer '.Auth::login($this, 'ada@example.com')['access_token']];
    $this->video = Video::factory()->create(['uploader_user_id' => $this->ada->id]);
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

function startUpload(array $body = [], ?array $headers = null, ?string $videoId = null): TestResponse
{
    $t = test();

    return $t->postJson('/v1/videos/'.($videoId ?? $t->video->public_id).'/uploads',
        ['size_bytes' => 20 * MIB, 'content_type' => 'video/mp4', ...$body],
        [...($headers ?? $t->asAda), 'Idempotency-Key' => (string) Str::uuid()]);
}

function putPart(string $url, int $bytes): Response
{
    return Http::withBody(str_repeat('v', $bytes), 'application/octet-stream')->put($url);
}

/** Uploads every part of a session and returns the part list for :complete. */
function uploadAll(string $sessionId, array $sizes = []): array
{
    $session = UploadSession::findOrFail($sessionId);
    $urls = app(UploadSessions::class)->urls($session, range(1, $session->total_parts));

    return array_map(fn (array $u) => [
        'part_number' => $u['part_number'],
        'etag' => putPart($u['url'], $sizes[$u['part_number']] ?? $u['size_bytes'])->header('ETag'),
    ], $urls);
}

function events(): array
{
    return DB::table('outbox_messages')->orderBy('id')->get(['event_type', 'envelope'])
        ->map(fn ($r) => json_decode($r->envelope, true)['payload']['to'])->all();
}

describe('part plan', function () {
    it('picks part sizes that stay under 9,000 parts', function (int $size, int $partSize, int $parts) {
        expect(UploadSessions::partSizeFor($size))->toBe($partSize)
            ->and((int) ceil($size / $partSize))->toBe($parts);
    })->with([
        'tiny' => [1, 8 * MIB, 1],
        'exactly 8 MiB' => [8 * MIB, 8 * MIB, 1],
        '20 MiB' => [20 * MIB, 8 * MIB, 3],
        '10 GiB (MVP max)' => [10 * 1024 * MIB, 8 * MIB, 1280],
        '100 GiB' => [100 * 1024 * MIB, 12 * MIB, 8534],
    ]);
});

describe('create', function () {
    it('starts a multipart upload and hands out the first URLs', function () {
        $response = startUpload()->assertCreated()->assertJson([
            'video_id' => $this->video->public_id, 'status' => 'initiated', 'size_bytes' => 20 * MIB,
            'content_type' => 'video/mp4', 'part_size_bytes' => 8 * MIB, 'total_parts' => 3, 'uploaded_parts' => [],
        ]);

        $session = UploadSession::findOrFail($response->json('id'));
        $response->assertHeader('Location', url("/v1/uploads/{$session->id}"));
        expect($session->object_key)->toBe("uploads/{$this->video->id}/{$session->id}/source")
            ->and($response->json('next_parts.*.part_number'))->toBe([1, 2, 3])
            ->and($response->json('next_parts.*.size_bytes'))->toBe([8 * MIB, 8 * MIB, 4 * MIB])
            ->and($this->video->fresh()->status)->toBe('upload_pending')
            ->and(events())->toBe(['upload_pending']);

        // The S3 upload exists, under the server-chosen key.
        $uploads = $this->s3->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'];
        expect($uploads)->toHaveCount(1)->and($uploads[0]['Key'])->toBe($session->object_key);
    });

    it('signs URLs bound to this bucket, key, upload and part', function () {
        $response = startUpload()->assertCreated();
        $session = UploadSession::findOrFail($response->json('id'));
        $url = parse_url($response->json('next_parts.1.url'));
        parse_str($url['query'], $query);

        expect($url['path'])->toBe("/{$this->bucket}/{$session->object_key}")
            ->and($query)->toMatchArray(['partNumber' => '2', 'uploadId' => $session->s3_upload_id, 'X-Amz-Expires' => '1800', 'X-Amz-SignedHeaders' => 'host'])
            ->and($query)->toHaveKey('X-Amz-Signature');
    });

    it('requires a verified email', function () {
        Auth::createUser('new@example.com', verified: false);
        $headers = ['Authorization' => 'Bearer '.Auth::login($this, 'new@example.com')['access_token']];
        $video = Video::factory()->create(['uploader_user_id' => User::where('email', 'new@example.com')->value('id')]);

        startUpload([], $headers, $video->public_id)->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
        expect($this->s3->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? [])->toBe([]);
    });

    it('lets only the owner upload', function () {
        Auth::createUser('eve@example.com');
        $asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];
        $published = Video::factory()->published()->create(['uploader_user_id' => $this->ada->id]);

        startUpload([], $asEve)->assertNotFound()->assertJsonPath('code', 'VIDEO_NOT_FOUND');
        startUpload([], $asEve, $published->public_id)->assertForbidden()->assertJsonPath('code', 'NOT_VIDEO_OWNER');
    });

    it('validates the declared file', function (array $body, string $field, string $code) {
        $response = startUpload($body)->assertStatus(422);

        expect($response->json('errors'))->toHaveCount(1)->and($response->json('errors.0'))->toMatchArray(['field' => $field, 'code' => $code]);
    })->with([
        'over 10 GiB' => [['size_bytes' => 10 * 1024 * MIB + 1], 'size_bytes', 'MAX'],
        'empty file' => [['size_bytes' => 0], 'size_bytes', 'MIN'],
        'not a number' => [['size_bytes' => 'big'], 'size_bytes', 'INTEGER'],
        'not a video type' => [['content_type' => 'application/zip'], 'content_type', 'IN'],
        'bad checksum' => [['sha256' => 'ABC'], 'sha256', 'REGEX'],
        'client-chosen key' => [['key' => 'uploads/someone-else/source'], 'key', 'UNKNOWN_FIELD'],
    ]);

    it('allows one live upload per video', function () {
        $first = startUpload()->assertCreated();

        startUpload()->assertConflict()->assertJsonPath('code', 'UPLOAD_IN_PROGRESS')
            ->assertJsonPath('detail', fn ($d) => str_contains($d, $first->json('id')));
    });

    it('refuses videos that already have their file', function (string $status) {
        $this->video->update(['status' => $status]);

        startUpload()->assertConflict()->assertJsonPath('code', 'VIDEO_NOT_UPLOADABLE');
    })->with(['uploaded', 'processing', 'published']);

    it('enforces the daily quota with Retry-After', function () {
        config(['uploads.daily_session_quota' => 2]);
        foreach (range(1, 2) as $i) {
            $video = Video::factory()->create(['uploader_user_id' => $this->ada->id]);
            startUpload([], null, $video->public_id)->assertCreated();
        }

        $retryAfter = (int) startUpload()->assertStatus(429)->assertJsonPath('code', 'UPLOAD_QUOTA_EXCEEDED')->headers->get('Retry-After');
        expect($retryAfter)->toBeGreaterThan(86_000)->toBeLessThanOrEqual(86_400);
    });

    it('aborts the S3 upload when a concurrent create wins the race', function () {
        // Another session for the same video appears between the checks and the insert.
        $sneaked = false;
        DB::listen(function ($q) use (&$sneaked) {
            if (! $sneaked && str_contains($q->sql, 'count(*)') && str_contains($q->sql, 'upload_sessions')) {
                $sneaked = true;
                DB::table('upload_sessions')->insert([
                    'id' => (string) Str::uuid7(), 'video_id' => test()->video->id, 'user_id' => test()->ada->id,
                    'bucket' => test()->bucket, 'object_key' => 'other', 's3_upload_id' => 'other', 'declared_size_bytes' => 1,
                    'content_type' => 'video/mp4', 'part_size_bytes' => 1, 'total_parts' => 1, 'status' => 'initiated',
                    'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        startUpload()->assertConflict()->assertJsonPath('code', 'UPLOAD_IN_PROGRESS');
        expect($this->s3->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? [])->toBe([])
            ->and($this->video->fresh()->status)->toBe('draft');
    });

    it('replays a retried create', function () {
        $headers = [...$this->asAda, 'Idempotency-Key' => 'k-1'];
        $body = ['size_bytes' => 20 * MIB, 'content_type' => 'video/mp4'];
        $first = $this->postJson("/v1/videos/{$this->video->public_id}/uploads", $body, $headers)->assertCreated();

        $this->postJson("/v1/videos/{$this->video->public_id}/uploads", $body, $headers)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $first->json('id'));
        expect(UploadSession::count())->toBe(1);
    });
});

describe('upload, resume and complete', function () {
    it('runs the whole multipart flow against S3', function () {
        $created = startUpload()->assertCreated();
        $id = $created->json('id');
        [$p1, $p2] = $created->json('next_parts');

        $r1 = putPart($p1['url'], $p1['size_bytes']);
        $r2 = putPart($p2['url'], $p2['size_bytes']);
        expect($r1->successful())->toBeTrue()->and($r2->successful())->toBeTrue();

        // Resume: S3 reports what it has, and the client gets a fresh URL for what's missing.
        $status = $this->getJson("/v1/uploads/{$id}", $this->asAda)->assertOk()
            ->assertJsonPath('status', 'in_progress')
            ->assertJsonPath('uploaded_parts.*.part_number', [1, 2])
            ->assertJsonPath('uploaded_parts.*.size_bytes', [8 * MIB, 8 * MIB])
            ->assertJsonPath('next_parts.*.part_number', [3]);
        expect($this->video->fresh()->status)->toBe('uploading')->and(events())->toBe(['upload_pending', 'uploading']);

        $p3 = putPart($status->json('next_parts.0.url'), 4 * MIB);
        expect($p3->successful())->toBeTrue();

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => [
            ['part_number' => 1, 'etag' => $r1->header('ETag')], ['part_number' => 2, 'etag' => $r2->header('ETag')], ['part_number' => 3, 'etag' => $p3->header('ETag')],
        ]], withKey($this->asAda))
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('video_status', 'uploaded');

        $session = UploadSession::findOrFail($id);
        $object = app(ObjectStore::class)->head($session->bucket, $session->object_key);
        expect($object['size_bytes'])->toBe(20 * MIB)->and($object['etag'])->toEndWith('-3"')
            ->and(events())->toBe(['upload_pending', 'uploading', 'uploaded']);
    });

    it('signs more parts on request and marks the upload started once', function () {
        $id = startUpload(['size_bytes' => 100 * MIB])->assertCreated()->json('id');   // 13 parts

        $this->postJson("/v1/uploads/{$id}/parts:sign", ['part_numbers' => [13, 5]], $this->asAda)
            ->assertOk()
            ->assertJsonPath('parts.*.part_number', [13, 5])
            ->assertJsonPath('parts.*.size_bytes', [4 * MIB, 8 * MIB]);
        $this->postJson("/v1/uploads/{$id}/parts:sign", ['part_numbers' => [1]], $this->asAda)->assertOk();

        expect(UploadSession::find($id)->status)->toBe('in_progress')->and(events())->toBe(['upload_pending', 'uploading']);
    });

    it('validates part numbers', function (array $numbers, string $field, string $code) {
        $id = startUpload()->assertCreated()->json('id');

        $this->postJson("/v1/uploads/{$id}/parts:sign", ['part_numbers' => $numbers], $this->asAda)
            ->assertStatus(422)->assertJsonPath('errors.0.field', $field)->assertJsonPath('errors.0.code', $code);
    })->with([
        'beyond the last part' => [[1, 4], 'part_numbers.1', 'MAX'],
        'zero' => [[0], 'part_numbers.0', 'MIN'],
        'duplicate' => [[2, 2], 'part_numbers.0', 'DISTINCT'],
        'empty' => [[], 'part_numbers', 'REQUIRED'],
        'too many at once' => [range(1, 101), 'part_numbers', 'MAX'],
    ]);

    it('keeps sessions private to their owner', function () {
        $id = startUpload()->assertCreated()->json('id');
        Auth::createUser('eve@example.com');
        $asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];

        $this->getJson("/v1/uploads/{$id}", $asEve)->assertNotFound()->assertJsonPath('code', 'UPLOAD_NOT_FOUND');
        $this->postJson("/v1/uploads/{$id}/parts:sign", ['part_numbers' => [1]], $asEve)->assertNotFound();
        $this->deleteJson("/v1/uploads/{$id}", [], $asEve)->assertNotFound();
        $this->getJson('/v1/uploads/not-a-uuid', $this->asAda)->assertNotFound();
    });
});

describe('cancel and expiry', function () {
    it('cancels: aborts in S3, fails the video, and allows a new upload', function () {
        $created = startUpload()->assertCreated();
        $id = $created->json('id');
        putPart($created->json('next_parts.0.url'), 8 * MIB);

        $this->deleteJson("/v1/uploads/{$id}", [], $this->asAda)->assertNoContent();

        $session = UploadSession::findOrFail($id);
        expect($session)->status->toBe('aborted')->failure_reason->toBe('upload_aborted')
            ->and($this->video->fresh()->status)->toBe('upload_failed')
            ->and(fn () => $this->s3->listParts(['Bucket' => $this->bucket, 'Key' => $session->object_key, 'UploadId' => $session->s3_upload_id]))
            ->toThrow(S3Exception::class);
        expect(putPart($created->json('next_parts.1.url'), 8 * MIB)->successful())->toBeFalse();   // old URLs are dead

        $this->deleteJson("/v1/uploads/{$id}", [], $this->asAda)->assertConflict()->assertJsonPath('code', 'UPLOAD_NOT_ACTIVE');
        $this->getJson("/v1/uploads/{$id}", $this->asAda)->assertOk()->assertJsonPath('status', 'aborted')->assertJsonPath('next_parts', []);

        startUpload()->assertCreated();
        expect($this->video->fresh()->status)->toBe('upload_pending')
            ->and(events())->toBe(['upload_pending', 'upload_failed', 'upload_pending']);
    });

    it('expires a session whose time is up', function () {
        $id = startUpload()->assertCreated()->json('id');
        UploadSession::whereKey($id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/v1/uploads/{$id}/parts:sign", ['part_numbers' => [1]], $this->asAda)
            ->assertStatus(410)->assertJsonPath('code', 'UPLOAD_EXPIRED');

        expect(UploadSession::find($id))->status->toBe('expired')->failure_reason->toBe('upload_expired')
            ->and($this->video->fresh()->status)->toBe('upload_failed');
    });

    it('replaces an expired live session when a new upload starts', function () {
        $old = startUpload()->assertCreated()->json('id');
        UploadSession::whereKey($old)->update(['expires_at' => now()->subMinute()]);

        startUpload()->assertCreated();

        expect(UploadSession::find($old)->status)->toBe('expired')
            ->and(events())->toBe(['upload_pending', 'upload_failed', 'upload_pending']);
    });

    it('slides the expiry forward on activity', function () {
        $id = startUpload()->assertCreated()->json('id');
        UploadSession::whereKey($id)->update(['expires_at' => now()->addMinutes(5)]);

        $this->getJson("/v1/uploads/{$id}", $this->asAda)->assertOk();

        expect(UploadSession::find($id)->expires_at->greaterThan(now()->addHours(23)))->toBeTrue();
    });

    it('stops handing out URLs once the video is deleted', function () {
        $id = startUpload()->assertCreated()->json('id');
        $this->deleteJson("/v1/videos/{$this->video->public_id}", [], $this->asAda)->assertNoContent();

        $this->postJson("/v1/uploads/{$id}/parts:sign", ['part_numbers' => [1]], $this->asAda)
            ->assertConflict()->assertJsonPath('code', 'VIDEO_NOT_UPLOADABLE');
        expect(UploadSession::find($id)->status)->toBe('initiated');   // rolled back; the sweeper ends it (S3-05)
    });
});

describe('complete', function () {
    it('completes from upload_pending when the first URLs were enough', function () {
        $id = startUpload()->assertCreated()->json('id');

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => uploadAll($id)], withKey($this->asAda))
            ->assertOk()->assertJson(['status' => 'completed', 'video_status' => 'uploaded'])
            ->assertJsonPath('completed_at', fn ($v) => $v !== null);

        $envelopes = DB::table('outbox_messages')->orderBy('id')->pluck('envelope')->map(fn ($e) => json_decode($e, true));
        expect($envelopes->pluck('event_type')->all())->toBe(['VideoStateChanged', 'VideoStateChanged', 'VideoUploaded'])
            ->and($envelopes->last()['payload'])->toMatchArray(['from' => 'uploading', 'to' => 'uploaded', 'reason' => 'upload_completed'])
            ->and(Contracts::violations('video-state-changed.v1', json_encode($envelopes->last())))->toBeNull();
    });

    it('returns the same result to every retry, with one VideoUploaded', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        $key = withKey($this->asAda);

        $first = $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], $key)->assertOk();
        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))   // a new key: answered from state
            ->assertOk()->assertJsonPath('completed_at', $first->json('completed_at'));

        expect(events())->toBe(['upload_pending', 'uploading', 'uploaded']);
    });

    it('hands the session back when parts are missing', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        $session = UploadSession::findOrFail($id);
        $this->s3->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $session->object_key, 'UploadId' => $session->s3_upload_id]);
        $session->update(['s3_upload_id' => $this->s3->createMultipartUpload(['Bucket' => $this->bucket, 'Key' => $session->object_key])['UploadId']]);
        $partial = app(UploadSessions::class)->urls($session->fresh(), [1]);
        $parts[0]['etag'] = putPart($partial[0]['url'], 8 * MIB)->header('ETag');

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertConflict()->assertJsonPath('code', 'PARTS_MISSING')->assertJsonPath('detail', fn ($d) => str_contains($d, 'Missing parts: 2, 3.'));
        expect(UploadSession::find($id)->status)->toBe('initiated')->and(events())->toBe(['upload_pending']);

        $parts = uploadAll($id);
        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))->assertOk();
    });

    it('fails the upload when a part has the wrong size', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id, [3 => 3 * MIB]);   // last part should be 4 MiB

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertStatus(422)->assertJsonPath('code', 'UPLOAD_FAILED')
            ->assertJsonPath('detail', fn ($d) => str_starts_with($d, 'upload_size_mismatch: Part 3 is 3145728 bytes, expected 4194304.'));

        $session = UploadSession::findOrFail($id);
        expect($session)->status->toBe('failed')->failure_reason->toBe('upload_size_mismatch')
            ->and($this->video->fresh()->status)->toBe('upload_failed')
            ->and(events())->toBe(['upload_pending', 'upload_failed'])
            ->and($this->s3->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? [])->toBe([]);

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertConflict()->assertJsonPath('code', 'UPLOAD_NOT_ACTIVE');
        startUpload()->assertCreated();   // the creator starts again
    });

    it('fails the upload when S3 has a different part than the client sent', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        $parts[1]['etag'] = '"0123456789abcdef0123456789abcdef"';

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_starts_with($d, 'upload_etag_mismatch: Part 2'));
        expect(UploadSession::find($id)->failure_reason)->toBe('upload_etag_mismatch');
    });

    it('accepts ETags with or without quotes and in any order', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = array_reverse(array_map(fn ($p) => [...$p, 'etag' => strtoupper(trim($p['etag'], '"'))], uploadAll($id)));

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))->assertOk();
    });

    it('requires every part exactly once', function (Closure $mutate) {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $mutate($parts)], withKey($this->asAda))
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_PART_LIST');
        expect(UploadSession::find($id)->status)->toBe('initiated');
    })->with([
        'one missing' => [fn ($p) => array_slice($p, 0, 2)],
        'duplicate' => [fn ($p) => [...$p, $p[0]]],
        'beyond the last part' => [fn ($p) => [...$p, ['part_number' => 4, 'etag' => 'x']]],
    ]);

    it('tells a concurrent call that completion is under way, and lets a retry take over a stale claim', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        UploadSession::whereKey($id)->update(['status' => 'completing', 'updated_at' => now()]);

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertConflict()->assertJsonPath('code', 'UPLOAD_COMPLETING');

        UploadSession::whereKey($id)->update(['updated_at' => now()->subMinutes(5)]);   // its owner crashed
        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))->assertOk();
        expect(events())->toBe(['upload_pending', 'uploading', 'uploaded']);
    });

    it('recovers when a crash happened after S3 completed the object', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        $session = UploadSession::findOrFail($id);
        app(ObjectStore::class)->completeMultipartUpload($session->bucket, $session->object_key, $session->s3_upload_id,
            app(ObjectStore::class)->listParts($session->bucket, $session->object_key, $session->s3_upload_id));
        $session->update(['status' => 'completing', 'updated_at' => now()->subMinutes(5)]);

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertOk()->assertJsonPath('video_status', 'uploaded');
        expect(events())->toBe(['upload_pending', 'uploading', 'uploaded']);
    });

    it('fails a recovered object whose size is wrong, and deletes it', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        $session = UploadSession::findOrFail($id);
        $this->s3->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $session->object_key, 'UploadId' => $session->s3_upload_id]);
        $this->s3->putObject(['Bucket' => $this->bucket, 'Key' => $session->object_key, 'Body' => 'too small']);
        $session->update(['status' => 'completing', 'updated_at' => now()->subMinutes(5)]);

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_starts_with($d, 'upload_size_mismatch: The file is 9 bytes'));
        expect(app(ObjectStore::class)->head($this->bucket, $session->object_key))->toBeNull();
    });

    it('completes the upload but leaves a deleted video alone', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        $this->deleteJson("/v1/videos/{$this->video->public_id}", [], $this->asAda)->assertNoContent();

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertOk()->assertJson(['status' => 'completed', 'video_status' => 'deleted']);
        expect(events())->toBe(['upload_pending', 'deleted']);
    });

    it('refuses an expired upload', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        UploadSession::whereKey($id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertStatus(410)->assertJsonPath('code', 'UPLOAD_EXPIRED');
        expect(UploadSession::find($id)->status)->toBe('expired');
    });

    it('is owner-only and refuses ended sessions', function () {
        $id = startUpload()->assertCreated()->json('id');
        $parts = uploadAll($id);
        Auth::createUser('eve@example.com');
        $asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];

        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($asEve))->assertNotFound();
        $this->deleteJson("/v1/uploads/{$id}", [], $this->asAda)->assertNoContent();
        $this->postJson("/v1/uploads/{$id}:complete", ['parts' => $parts], withKey($this->asAda))
            ->assertConflict()->assertJsonPath('code', 'UPLOAD_NOT_ACTIVE');
    });
});
