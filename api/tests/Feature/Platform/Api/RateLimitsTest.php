<?php

/*
 * S4-09: rate limits for upload creation, part signing, playback authorization and the admin API
 * (design doc §24.3). Each test spends a budget with cheap requests (failed ones count too) and
 * checks the next one is a 429 problem with Retry-After and the X-RateLimit headers.
 */

use App\Modules\Users\Models\User;
use App\Modules\Videos\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ada = Auth::createUser('ada@example.com');
    $this->asAda = ['Authorization' => 'Bearer '.Auth::login($this, 'ada@example.com')['access_token']];
    Auth::createUser('eve@example.com');
    $this->asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];
    $this->from = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip]);
});

function expectLimited(TestResponse $response): void
{
    $response->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertHeader('X-RateLimit-Remaining', '0');
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
}

/** Sends $n requests and checks none of them was limited. */
function spend(int $n, Closure $request): void
{
    for ($i = 0; $i < $n; $i++) {
        expect($request($i)->status())->not->toBe(429, "request {$i} was limited too early");
    }
}

describe('upload creation', function () {
    it('allows 20 sessions an hour per user', function () {
        $video = Video::factory()->create(['uploader_user_id' => $this->ada->id]);
        $create = fn ($headers) => $this->postJson("/v1/videos/{$video->public_id}/uploads", ['size_bytes' => 0], withKey($headers));   // invalid on purpose: no S3 calls

        spend(20, fn ($i) => ($this->from)("203.0.113.{$i}")->postJson("/v1/videos/{$video->public_id}/uploads", ['size_bytes' => 0], withKey($this->asAda)));

        expectLimited(($this->from)('198.51.100.1')->postJson("/v1/videos/{$video->public_id}/uploads", ['size_bytes' => 0], withKey($this->asAda)));
        $create($this->asEve)->assertStatus(422);   // another user has their own budget (and isn't limited)
    });

    it('allows 30 an hour per IP, whoever is signed in', function () {
        $users = [];
        foreach (range(1, 2) as $n) {
            Auth::createUser("u{$n}@example.com");
            $users[] = ['Authorization' => 'Bearer '.Auth::login($this, "u{$n}@example.com")['access_token']];
        }
        $video = Video::factory()->create(['uploader_user_id' => $this->ada->id]);
        $create = fn (array $headers) => ($this->from)('203.0.113.9')->postJson("/v1/videos/{$video->public_id}/uploads", [], withKey($headers));

        spend(30, fn ($i) => $create($users[$i % 2]));

        expectLimited($create($this->asEve));
    });

    it('shows the remaining budget on every response', function () {
        $video = Video::factory()->create(['uploader_user_id' => $this->ada->id]);

        $this->postJson("/v1/videos/{$video->public_id}/uploads", [], withKey($this->asAda))
            ->assertStatus(422)->assertHeader('X-RateLimit-Limit')->assertHeader('X-RateLimit-Remaining');
    });
});

it('allows 600 part-URL requests a minute per user, then recovers', function () {
    $sign = fn (array $headers) => $this->postJson('/v1/uploads/'.Str::uuid().'/parts:sign', ['part_numbers' => [1]], $headers);

    spend(600, fn () => $sign($this->asAda));

    expectLimited($sign($this->asAda));
    ($this->from)('198.51.100.7')->postJson('/v1/uploads/'.Str::uuid().'/parts:sign', ['part_numbers' => [1]], $this->asEve)
        ->assertNotFound();   // another user from another IP (the per-IP budget is shared)
    $this->travel(61)->seconds();
    $sign($this->asAda)->assertNotFound();
});

describe('playback authorization', function () {
    it('allows 30 a minute for one viewer and video, so cookies can\'t be farmed', function () {
        $video = Video::factory()->published()->create(['uploader_user_id' => $this->ada->id]);
        $other = Video::factory()->published()->create(['uploader_user_id' => $this->ada->id]);
        $play = fn (Video $v, array $headers = []) => $this->getJson("/v1/videos/{$v->public_id}/playback", $headers);

        spend(30, fn () => $play($video, $this->asEve));

        expectLimited($play($video, $this->asEve));
        $play($other, $this->asEve)->assertStatus(409);     // another video: fine (nothing playable yet)
        $play($video, $this->asAda)->assertStatus(409);     // another viewer: fine
    });

    it('allows 300 a minute per IP for anonymous viewers', function () {
        spend(300, fn ($i) => ($this->from)('203.0.113.50')->getJson('/v1/videos/'.Str::padLeft((string) $i, 11, 'A').'/playback'));

        expectLimited(($this->from)('203.0.113.50')->getJson('/v1/videos/BBBBBBBBBBB/playback'));
        ($this->from)('203.0.113.51')->getJson('/v1/videos/BBBBBBBBBBB/playback')->assertNotFound();
    });

    it('allows 300 a minute per user, across IPs', function () {
        spend(300, fn ($i) => ($this->from)('10.0.'.intdiv($i, 250).'.'.($i % 250))->getJson('/v1/videos/'.Str::padLeft((string) $i, 11, 'C').'/playback', $this->asEve));

        expectLimited(($this->from)('10.9.9.9')->getJson('/v1/videos/DDDDDDDDDDD/playback', $this->asEve));
    });
});

it('allows 600 admin API requests a minute per staff member', function () {
    User::where('email', 'ada@example.com')->update(['role' => 'admin']);

    spend(600, fn () => $this->getJson('/admin/v1/processing/jobs?limit=1', $this->asAda));

    expectLimited($this->getJson('/admin/v1/processing/jobs?limit=1', $this->asAda));
});
