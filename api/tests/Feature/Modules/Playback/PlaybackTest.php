<?php

use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoMedia;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

const MEDIA = 'https://media.test.example';

beforeAll(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $GLOBALS['cdnPrivateKey'] = $private;
    $GLOBALS['cdnPublicKey'] = openssl_pkey_get_details($key)['key'];
});

beforeEach(function () {
    config([
        'playback.media_base_url' => MEDIA, 'playback.cookie_domain' => '.test.example',
        'playback.key_pair_id' => 'KTESTKEY', 'playback.private_key' => base64_encode($GLOBALS['cdnPrivateKey']),
    ]);
    $this->owner = Auth::createUser('ada@example.com');
    Auth::createUser('eve@example.com');
    $this->viewers = [
        'anonymous' => [],
        'owner' => ['Authorization' => 'Bearer '.Auth::login($this, 'ada@example.com')['access_token']],
        'someone else' => ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']],
    ];

    /** A video with a playable processing output (v1), in the given state. */
    $this->video = function (array $state = [], bool $playable = true): Video {
        $video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'published', 'visibility' => 'public', 'published_at' => now(), ...$state]);
        if ($playable) {
            $session = UploadSession::query()->create([
                'video_id' => $video->id, 'user_id' => $this->owner->id, 'bucket' => 'uploads', 'object_key' => "uploads/{$video->id}/s/source",
                's3_upload_id' => Str::random(20), 'declared_size_bytes' => 1, 'content_type' => 'video/mp4', 'part_size_bytes' => 1,
                'total_parts' => 1, 'status' => 'completed', 'expires_at' => now()->addDay(),
            ]);
            ProcessingJob::query()->create([
                'video_id' => $video->id, 'upload_session_id' => $session->id, 'processing_version' => 1, 'profile' => 'h264-sdr-v1',
                'status' => 'succeeded', 'source_bucket' => 'uploads', 'source_key' => $session->object_key, 'output_bucket' => 'media',
                'output_prefix' => "media/{$video->id}/v1/", 'master_playlist_key' => "media/{$video->id}/v1/master.m3u8",
            ]);
        }

        return $video;
    };
    $this->play = fn (Video $v, string $as = 'anonymous'): TestResponse => $this->getJson("/v1/videos/{$v->public_id}/playback", $this->viewers[$as]);
});

/*
 * S4-04 acceptance: the authorization matrix. Every state a video can be in, for an anonymous
 * viewer, its owner and another signed-in user. 404 means "you can't know it exists".
 */
it('authorizes playback', function (array $state, array $expected) {
    $video = ($this->video)($state);

    foreach ($expected as $viewer => $status) {
        ($this->play)($video, $viewer)->assertStatus($status);
    }
})->with([
    'published, public' => [[], ['anonymous' => 200, 'owner' => 200, 'someone else' => 200]],
    'published, unlisted (the link is the permission)' => [['visibility' => 'unlisted'], ['anonymous' => 200, 'owner' => 200, 'someone else' => 200]],
    'published, private' => [['visibility' => 'private'], ['anonymous' => 404, 'owner' => 200, 'someone else' => 404]],
    'ready, never published (owner preview)' => [['status' => 'ready', 'published_at' => null], ['anonymous' => 404, 'owner' => 200, 'someone else' => 404]],
    'unpublished' => [['status' => 'unpublished'], ['anonymous' => 404, 'owner' => 200, 'someone else' => 404]],
    'draft' => [['status' => 'draft', 'published_at' => null], ['anonymous' => 404, 'owner' => 409, 'someone else' => 404]],
    'still processing' => [['status' => 'processing', 'published_at' => null], ['anonymous' => 404, 'owner' => 409, 'someone else' => 404]],
    'blocked' => [['status' => 'blocked', 'pre_block_status' => 'published'], ['anonymous' => 404, 'owner' => 403, 'someone else' => 404]],
    'rejected' => [['status' => 'rejected'], ['anonymous' => 404, 'owner' => 403, 'someone else' => 404]],
    'published but moderation-blocked' => [['moderation_status' => 'blocked'], ['anonymous' => 404, 'owner' => 403, 'someone else' => 404]],
    'deleted' => [['status' => 'deleted', 'deleted_at' => now()], ['anonymous' => 404, 'owner' => 404, 'someone else' => 404]],
    'age-restricted' => [['age_restricted' => true], ['anonymous' => 403, 'owner' => 200, 'someone else' => 403]],
    'age-restricted by moderation' => [['moderation_status' => 'age_restricted'], ['anonymous' => 403, 'owner' => 200, 'someone else' => 403]],
    'private and unlisted look the same as missing' => [['visibility' => 'private', 'status' => 'unpublished'], ['anonymous' => 404, 'owner' => 200, 'someone else' => 404]],
]);

it('answers 404 for videos that do not exist', function () {
    $this->getJson('/v1/videos/AAAAAAAAAAA/playback')->assertNotFound()->assertJsonPath('code', 'VIDEO_NOT_FOUND');
});

it('tells the owner why they cannot play', function () {
    ($this->play)(($this->video)(['status' => 'blocked']), 'owner')->assertJsonPath('code', 'VIDEO_BLOCKED');
    ($this->play)(($this->video)(['status' => 'uploading']), 'owner')->assertJsonPath('code', 'VIDEO_NOT_PLAYABLE');
    ($this->play)(($this->video)(['age_restricted' => true]), 'someone else')->assertJsonPath('code', 'AGE_RESTRICTED');
});

it('refuses a video with nothing playable yet', function () {
    ($this->play)(($this->video)([], playable: false))->assertStatus(409)->assertJsonPath('code', 'VIDEO_NOT_PLAYABLE');
});

describe('the response', function () {
    it('returns the stream, the video and the primary thumbnail', function () {
        $video = ($this->video)(['title' => 'Trip']);
        app(VideoMedia::class)->recordProcessed($video->id, 1, 125_000, 1920, 1080, [
            ['key' => "media/{$video->id}/v1/thumbs/50_1280x720.jpg", 'width' => 1280, 'height' => 720, 'time_offset_ms' => 62_000],
        ]);

        ($this->play)($video)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson([
                'video' => ['id' => $video->public_id, 'title' => 'Trip', 'duration_ms' => 125_000, 'width' => 1920, 'height' => 1080],
                'streams' => ['hls' => MEDIA."/media/{$video->id}/v1/master.m3u8"],
                'auth' => ['type' => 'cookie'],
                'thumbnails' => ['primary' => [['url' => MEDIA."/media/{$video->id}/v1/thumbs/50_1280x720.jpg", 'width' => 1280, 'format' => 'jpg']]],
                'captions' => [],
                'resume_position_ms' => null,
            ])
            ->assertJsonPath('playback_session_id', fn ($id) => Str::isUuid($id));
    });

    it('lasts the video\'s length plus 30 minutes, between 1 and 6 hours', function (?int $durationMs, int $expectedSeconds) {
        $video = ($this->video)(['duration_ms' => $durationMs]);
        $this->freezeSecond();

        $expiresAt = CarbonImmutable::parse(($this->play)($video)->json('auth.expires_at'));

        expect((int) now()->diffInSeconds($expiresAt))->toBe($expectedSeconds);
    })->with([
        'a 10 s clip gets the 1 h minimum' => [10_000, 3600],
        'a 2 h film gets 2 h 30 m' => [7_200_000, 9000],
        'a 10 h stream is capped at 6 h' => [36_000_000, 21600],
        'unknown length (still processing the rest): 1 h, then refresh' => [null, 3600],
    ]);

    it('sets signed cookies scoped to this video version only', function () {
        $video = ($this->video)();

        $response = ($this->play)($video)->assertOk();

        $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
        expect($cookies->keys()->sort()->values()->all())->toBe(['CloudFront-Hash-Algorithm', 'CloudFront-Key-Pair-Id', 'CloudFront-Policy', 'CloudFront-Signature']);
        foreach ($cookies as $cookie) {
            expect($cookie->getPath())->toBe("/media/{$video->id}/v1/")
                ->and($cookie->getDomain())->toBe('.test.example')
                ->and($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue()
                ->and($cookie->getSameSite())->toBe('lax')
                ->and($cookie->getExpiresTime())->toBe(CarbonImmutable::parse($response->json('auth.expires_at'))->getTimestamp());
        }
        expect($response->json('auth.cookies.CloudFront-Key-Pair-Id'))->toBe('KTESTKEY');
    });

    it('signs a policy CloudFront will accept: this prefix, this expiry, our key', function () {
        $video = ($this->video)();

        $auth = ($this->play)($video)->json('auth');

        // CloudFront's URL-safe base64: + → -, = → _, / → ~
        $decode = fn (string $v) => base64_decode(strtr($v, '-_~', '+=/'), true);
        $policy = $decode($auth['cookies']['CloudFront-Policy']);
        expect(json_decode($policy, true))->toBe(['Statement' => [[
            'Resource' => MEDIA."/media/{$video->id}/v1/*",
            'Condition' => ['DateLessThan' => ['AWS:EpochTime' => CarbonImmutable::parse($auth['expires_at'])->getTimestamp()]],
        ]]])
            ->and($auth['cookies']['CloudFront-Hash-Algorithm'])->toBe('SHA256')
            ->and(openssl_verify($policy, $decode($auth['cookies']['CloudFront-Signature']), $GLOBALS['cdnPublicKey'], OPENSSL_ALGO_SHA256))->toBe(1);
    });

    it('is unavailable, not broken, when signing keys are missing', function () {
        config(['playback.private_key' => null]);

        ($this->play)(($this->video)())->assertStatus(503)->assertJsonPath('code', 'PLAYBACK_UNAVAILABLE');
    });
});

describe('the policy cache', function () {
    it('serves repeat requests from the cache', function () {
        $video = ($this->video)();
        ($this->play)($video)->assertOk();
        expect(Cache::get("vpolicy:{$video->public_id}"))->toBeArray();

        // A change that bypasses the application isn't seen until the entry expires: proof the cache is used.
        DB::table('videos')->where('id', $video->id)->update(['visibility' => 'private']);
        ($this->play)($video)->assertOk();
    });

    it('applies changes made through the application at once', function (Closure $change, int $anonymous) {
        $video = ($this->video)();
        ($this->play)($video)->assertOk();   // cached

        $change($this, $video);

        ($this->play)($video)->assertStatus($anonymous);
    })->with([
        'unpublished' => [fn ($t, $v) => $t->postJson("/v1/videos/{$v->public_id}:unpublish", [], $t->viewers['owner'])->assertOk(), 404],
        'made private' => [fn ($t, $v) => $t->patchJson("/v1/videos/{$v->public_id}", ['visibility' => 'private'], [...$t->viewers['owner'], 'If-Match' => '"1"'])->assertOk(), 404],
        'age-restricted' => [fn ($t, $v) => $t->patchJson("/v1/videos/{$v->public_id}", ['age_restricted' => true], [...$t->viewers['owner'], 'If-Match' => '"1"'])->assertOk(), 403],
        'deleted' => [fn ($t, $v) => $t->deleteJson("/v1/videos/{$v->public_id}", [], $t->viewers['owner'])->assertNoContent(), 404],
        'blocked' => [fn ($t, $v) => DB::transaction(fn () => app(VideoLifecycle::class)->transition($v->id, VideoStatus::Blocked, 'policy_violation')), 404],
    ]);

    it('picks up processing results (duration) at once', function () {
        $video = ($this->video)(['duration_ms' => null]);
        $this->freezeSecond();
        expect(($this->play)($video)->json('video.duration_ms'))->toBeNull();

        app(VideoMedia::class)->recordProcessed($video->id, 1, 7_200_000, 1920, 1080, []);

        expect(($this->play)($video)->json('video.duration_ms'))->toBe(7_200_000);
    });
});
