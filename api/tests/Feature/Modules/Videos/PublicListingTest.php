<?php

use App\Modules\Videos\Models\Category;
use App\Modules\Videos\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    config([
        'playback.media_base_url' => 'https://media.test.example',
        'playback.key_pair_id' => 'KTESTKEY', 'playback.private_key' => base64_encode($private),
    ]);
    $this->creator = Auth::createUser('mona@example.com');
    $this->creator->forceFill(['handle' => 'mona.haddad', 'display_name' => 'Mona Haddad'])->save();
    $this->video = fn (array $state = []) => Video::factory()->published()->create([
        'uploader_user_id' => $this->creator->id, 'duration_ms' => 61000, ...$state,
    ]);
    $this->ids = fn (array $items) => array_column($items, 'id');
});

it('lists only published, public, non-age-restricted, unblocked, undeleted videos', function () {
    $shown = ($this->video)(['title' => 'Shown']);
    ($this->video)(['visibility' => 'unlisted']);
    ($this->video)(['visibility' => 'private']);
    ($this->video)(['status' => 'ready']);
    ($this->video)(['status' => 'unpublished']);
    ($this->video)(['deleted_at' => now()]);
    ($this->video)(['age_restricted' => true]);
    ($this->video)(['moderation_status' => 'blocked']);

    $this->getJson('/v1/videos')->assertOk()
        ->assertJsonPath('items.*.id', [$shown->public_id])
        ->assertJsonPath('has_more', false);
});

it('does not list a video that moderation marked age-restricted, though its flag is off', function () {
    $shown = ($this->video)(['title' => 'Shown']);
    ($this->video)(['age_restricted' => false, 'moderation_status' => 'age_restricted']);

    $this->getJson('/v1/videos')->assertOk()->assertJsonPath('items.*.id', [$shown->public_id]);
    $this->getJson('/v1/users/mona.haddad/videos')->assertOk()->assertJsonPath('items.*.id', [$shown->public_id]);
});

it('orders newest published first', function () {
    $old = ($this->video)(['published_at' => now()->subDays(3)]);
    $new = ($this->video)(['published_at' => now()->subDay()]);
    $mid = ($this->video)(['published_at' => now()->subDays(2)]);

    $this->getJson('/v1/videos')->assertJsonPath('items.*.id', [$new->public_id, $mid->public_id, $old->public_id]);
});

it('pages with next_cursor and no repeats', function () {
    foreach ([1, 2, 3] as $i) {
        ($this->video)(['published_at' => now()->subDays($i)]);
    }

    $first = $this->getJson('/v1/videos?limit=2')->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('has_more', true);
    $second = $this->getJson('/v1/videos?limit=2&cursor='.$first->json('next_cursor'))->assertOk()->assertJsonCount(1, 'items')
        ->assertJsonPath('has_more', false)->assertJsonPath('next_cursor', null);

    $all = [...($this->ids)($first->json('items')), ...($this->ids)($second->json('items'))];
    expect($all)->toHaveCount(3)->and(array_unique($all))->toHaveCount(3);
});

it('filters by category and gives an empty list for an unknown one', function () {
    $music = ($this->video)(['category_id' => Category::query()->where('slug', 'music')->value('id')]);
    ($this->video)();

    $this->getJson('/v1/videos?category=music')->assertOk()->assertJsonPath('items.*.id', [$music->public_id]);
    $this->getJson('/v1/videos?category=nope-nope')->assertOk()->assertExactJson(['items' => [], 'next_cursor' => null, 'has_more' => false]);
});

it('shows the creator and duration on each item', function () {
    ($this->video)(['title' => 'Hello']);

    $this->getJson('/v1/videos')->assertOk()->assertJsonPath('items.0.title', 'Hello')
        ->assertJsonPath('items.0.duration_ms', 61000)
        ->assertJsonPath('items.0.creator', ['handle' => 'mona.haddad', 'display_name' => 'Mona Haddad'])
        ->assertJsonPath('items.0.published_at', fn ($v) => $v !== null);
});

it('returns signed WebP thumbnails sorted by width, and [] when there is none', function () {
    $with = ($this->video)(['published_at' => now()->subDay()]);
    $without = ($this->video)(['published_at' => now()->subDays(2)]);
    DB::table('thumbnails')->insert([
        'id' => (string) Str::uuid7(), 'video_id' => $with->id, 'processing_version' => 1, 'is_primary' => true, 'created_at' => now(),
        'files' => json_encode([
            ['key' => 'media/x/thumb_1280.webp', 'width' => 1280, 'height' => 720, 'format' => 'webp'],
            ['key' => 'media/x/thumb_1280.jpg', 'width' => 1280, 'height' => 720, 'format' => 'jpg'],
            ['key' => 'media/x/thumb_320.webp', 'width' => 320, 'height' => 180, 'format' => 'webp'],
        ]),
    ]);

    $response = $this->getJson('/v1/videos')->assertOk();
    $items = collect($response->json('items'))->keyBy('id');

    $thumbs = $items[$with->public_id]['thumbnails'];
    expect($thumbs)->toHaveCount(2)
        ->and([$thumbs[0]['width'], $thumbs[0]['height'], $thumbs[1]['width']])->toBe([320, 180, 1280])
        ->and($thumbs[0]['url'])->toStartWith('https://media.test.example/media/x/thumb_320.webp?')->toContain('Key-Pair-Id=')
        ->and($thumbs[1]['url'])->toContain('thumb_1280.webp')
        ->and($items[$without->public_id]['thumbnails'])->toBe([]);
});

it('lists a creator\'s videos by handle, case-insensitively', function () {
    $mine = ($this->video)();
    $other = Auth::createUser('other@example.com');
    Video::factory()->published()->create(['uploader_user_id' => $other->id]);
    ($this->video)(['visibility' => 'private']);

    $this->getJson('/v1/users/MONA.HADDAD/videos')->assertOk()->assertJsonPath('items.*.id', [$mine->public_id]);
});

it('answers 404 USER_NOT_FOUND for an unknown or suspended handle', function () {
    $suspended = Auth::createUser('gone@example.com', status: 'suspended');
    $suspended->forceFill(['handle' => 'gone.user'])->save();

    $this->getJson('/v1/users/nobody.here/videos')->assertNotFound()->assertJsonPath('code', 'USER_NOT_FOUND');
    $this->getJson('/v1/users/gone.user/videos')->assertNotFound()->assertJsonPath('code', 'USER_NOT_FOUND');
});

it('does not need an Authorization header', function () {
    ($this->video)();

    $this->getJson('/v1/videos')->assertOk();
    $this->getJson('/v1/users/mona.haddad/videos')->assertOk();
});

it('keeps the query count constant however many videos are on the page', function () {
    for ($i = 0; $i < 20; $i++) {
        $video = ($this->video)(['published_at' => now()->subMinutes($i)]);
        DB::table('thumbnails')->insert([
            'id' => (string) Str::uuid7(), 'video_id' => $video->id, 'processing_version' => 1, 'is_primary' => true, 'created_at' => now(),
            'files' => json_encode([['key' => "media/{$i}/t.webp", 'width' => 320, 'height' => 180, 'format' => 'webp']]),
        ]);
    }

    DB::enableQueryLog();
    $this->getJson('/v1/videos')->assertOk()->assertJsonCount(20, 'items');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(5);
});

it('hides videos by suspended creators, as their profile is hidden', function () {
    $suspended = Auth::createUser('gone@example.com', status: 'suspended');
    Video::factory()->published()->create(['uploader_user_id' => $suspended->id]);
    $active = ($this->video)();

    $this->getJson('/v1/videos')->assertOk()->assertJsonPath('items.*.id', [$active->public_id]);
});
