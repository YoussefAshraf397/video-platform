<?php

use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\Videos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ada = Auth::createUser('ada@example.com');
    $this->eve = Auth::createUser('eve@example.com');
    $this->asAda = ['Authorization' => 'Bearer '.Auth::login($this, 'ada@example.com')['access_token']];
    $this->asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];
});

function adaVideo(array $state = []): Video
{
    return Video::factory()->create(['uploader_user_id' => test()->ada->id, ...$state]);
}

describe('create', function () {
    it('creates a private draft with defaults', function () {
        $response = $this->postJson('/v1/videos', ['title' => 'My first video'], withKey($this->asAda))
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJson([
                'title' => 'My first video', 'description' => null, 'tags' => [], 'category' => null, 'language' => null,
                'visibility' => 'private', 'status' => 'draft', 'age_restricted' => false, 'made_for_kids' => false,
                'comments_enabled' => true, 'published_at' => null, 'etag' => '"1"',
            ]);

        $id = $response->json('id');
        expect($id)->toMatch('/^[A-Za-z0-9_-]{11}$/');
        $response->assertHeader('Location', url("/v1/videos/{$id}"));
        expect(Video::where('public_id', $id)->value('uploader_user_id'))->toBe($this->ada->id);
    });

    it('stores all metadata', function () {
        $this->postJson('/v1/videos', [
            'title' => 'Lo-fi mix', 'description' => "Line one\nLine two", 'tags' => ['Lo-Fi  Beats', 'study'],
            'category' => 'music', 'language' => 'en-GB', 'visibility' => 'unlisted',
            'age_restricted' => true, 'made_for_kids' => false, 'comments_enabled' => false,
        ], withKey($this->asAda))
            ->assertCreated()
            ->assertJson([
                'description' => "Line one\nLine two", 'tags' => ['Lo-Fi  Beats', 'study'],
                'category' => ['slug' => 'music', 'name' => 'Music'], 'language' => 'en-GB', 'visibility' => 'unlisted',
                'age_restricted' => true, 'comments_enabled' => false,
            ]);
    });

    it('requires authentication', function () {
        $this->postJson('/v1/videos', ['title' => 'x'])->assertStatus(401);
    });

    it('rejects fields the client cannot set', function () {
        $this->postJson('/v1/videos', ['title' => 'x', 'status' => 'published', 'uploader_user_id' => $this->eve->id], withKey($this->asAda))
            ->assertStatus(422)
            ->assertJsonPath('errors.*.field', ['status', 'uploader_user_id'])
            ->assertJsonPath('errors.*.code', ['UNKNOWN_FIELD', 'UNKNOWN_FIELD']);

        expect(Video::count())->toBe(0);
    });

    it('rejects invalid input', function (array $body, string $field, string $code) {
        $response = $this->postJson('/v1/videos', ['title' => 'ok', ...$body], withKey($this->asAda))->assertStatus(422);

        expect($response->json('errors'))->toHaveCount(1)
            ->and($response->json('errors.0'))->toMatchArray(['field' => $field, 'code' => $code]);
    })->with([
        'missing title' => [['title' => null], 'title', 'REQUIRED'],
        'long title' => [['title' => str_repeat('a', 101)], 'title', 'MAX'],
        'newline in title' => [['title' => "a\nb"], 'title', 'NOT_REGEX'],
        'long description' => [['description' => str_repeat('a', 5001)], 'description', 'MAX'],
        'too many tags' => [['tags' => array_map(fn ($i) => "t{$i}", range(1, 31))], 'tags', 'MAX'],
        'tags not a list' => [['tags' => ['a' => 'x']], 'tags', 'LIST'],
        'long tag' => [['tags' => [str_repeat('a', 51)]], 'tags.0', 'MAX'],
        'blank tag' => [['tags' => ['ok', '   ']], 'tags.1', 'REQUIRED'],
        'tag too long once normalized' => [['tags' => [str_repeat('ﷺ', 3)]], 'tags.0', 'INVALID_TAG'],
        'unknown category' => [['category' => 'cooking'], 'category', 'EXISTS'],
        'bad visibility' => [['visibility' => 'secret'], 'visibility', 'IN'],
        'bad language' => [['language' => 'english'], 'language', 'REGEX'],
        'non-boolean flag' => [['made_for_kids' => 'yes'], 'made_for_kids', 'BOOLEAN'],
    ]);

    it('rejects inactive categories', function () {
        DB::table('categories')->where('slug', 'music')->update(['is_active' => false]);

        $this->postJson('/v1/videos', ['title' => 'x', 'category' => 'music'], withKey($this->asAda))
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'EXISTS');
    });

    it('replays a retried create instead of creating a second video', function () {
        $headers = [...$this->asAda, 'Idempotency-Key' => 'f2c9d1a4-8a5b-4b6e-9f0e-0c1d2e3f4a5b'];

        $first = $this->postJson('/v1/videos', ['title' => 'x'], $headers)->assertCreated();
        $this->postJson('/v1/videos', ['title' => 'x'], $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeader('ETag', '"1"')
            ->assertHeader('Location', $first->headers->get('Location'))
            ->assertJsonPath('id', $first->json('id'));

        $this->postJson('/v1/videos', ['title' => 'x'], $this->asAda)->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        expect(Video::count())->toBe(1);
    });
});

describe('tags', function () {
    it('normalizes for matching but keeps the creator\'s spelling', function () {
        $this->postJson('/v1/videos', ['title' => 'a', 'tags' => ['Lo-Fi  Beats', 'lo-fi beats', 'ＬＯ-ＦＩ BEATS', 'Study']], withKey($this->asAda))
            ->assertCreated()->assertJsonPath('tags', ['Lo-Fi  Beats', 'Study']);
        $this->postJson('/v1/videos', ['title' => 'b', 'tags' => ['LO-FI BEATS']], withKey($this->asEve))
            ->assertCreated()->assertJsonPath('tags', ['LO-FI BEATS']);

        expect(DB::table('tags')->orderBy('normalized_name')->pluck('normalized_name')->all())->toBe(['lo-fi beats', 'study']);
    });

    it('normalizes case, Unicode form and whitespace', function (string $label, string $normalized) {
        expect(Videos::normalizeTag($label))->toBe($normalized);
    })->with([
        ['PHP', 'php'],
        ["  two\t spaces ", 'two spaces'],
        ['ＦＵＬＬ', 'full'],          // full-width
        ['Cafe'."\u{0301}", 'café'],  // combining accent → precomposed
        ['ÄÖÜ', 'äöü'],
    ]);
});

describe('read', function () {
    it('shows owners their own draft, and hides it from everyone else', function () {
        $video = adaVideo();

        $this->getJson("/v1/videos/{$video->public_id}", $this->asAda)->assertOk()->assertJsonPath('status', 'draft')->assertHeader('ETag', '"1"');
        $this->getJson("/v1/videos/{$video->public_id}", $this->asEve)->assertNotFound()->assertJsonPath('code', 'VIDEO_NOT_FOUND');
        $this->getJson("/v1/videos/{$video->public_id}")->assertNotFound();
    });

    it('applies visibility to published videos', function (string $visibility, string $moderation, bool $publicCanSee) {
        $video = adaVideo(['status' => 'published', 'visibility' => $visibility, 'moderation_status' => $moderation, 'published_at' => now()]);

        $this->getJson("/v1/videos/{$video->public_id}")->assertStatus($publicCanSee ? 200 : 404);
        $this->getJson("/v1/videos/{$video->public_id}", $this->asEve)->assertStatus($publicCanSee ? 200 : 404);
        $this->getJson("/v1/videos/{$video->public_id}", $this->asAda)->assertOk();
    })->with([
        'public' => ['public', 'none', true],
        'unlisted (link is the permission)' => ['unlisted', 'none', true],
        'private' => ['private', 'none', false],
        'blocked by moderation' => ['public', 'blocked', false],
    ]);

    it('hides deleted videos from everyone, including the owner', function () {
        $video = adaVideo(['status' => 'deleted', 'deleted_at' => now()]);

        $this->getJson("/v1/videos/{$video->public_id}", $this->asAda)->assertNotFound();
    });

    it('treats a bad token on a public read as anonymous', function () {
        $video = Video::factory()->published()->create(['uploader_user_id' => $this->ada->id]);

        $this->getJson("/v1/videos/{$video->public_id}", ['Authorization' => 'Bearer nonsense'])->assertOk();
    });

    it('404s for ids that are not public ids', function () {
        $this->getJson('/v1/videos/short')->assertNotFound();
        $this->getJson('/v1/videos/'.adaVideo()->id, $this->asAda)->assertNotFound();   // internal uuid
    });
});

describe('update', function () {
    it('changes metadata with If-Match and returns the new ETag', function () {
        $video = adaVideo(['title' => 'Old']);

        $this->patchJson("/v1/videos/{$video->public_id}", ['title' => 'New', 'tags' => ['a', 'b'], 'category' => 'education'], [...$this->asAda, 'If-Match' => '"1"'])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJson(['title' => 'New', 'tags' => ['a', 'b'], 'category' => ['slug' => 'education'], 'etag' => '"2"']);

        $this->patchJson("/v1/videos/{$video->public_id}", ['tags' => [], 'category' => null, 'description' => null], [...$this->asAda, 'If-Match' => '"2"'])
            ->assertOk()->assertJson(['title' => 'New', 'tags' => [], 'category' => null]);
    });

    it('returns 412 for a stale ETag and keeps the newer change', function () {
        $video = adaVideo(['title' => 'Old']);
        $stale = [...$this->asAda, 'If-Match' => '"1"'];

        $this->patchJson("/v1/videos/{$video->public_id}", ['title' => 'From phone'], $stale)->assertOk();
        $this->patchJson("/v1/videos/{$video->public_id}", ['title' => 'From laptop', 'tags' => ['lost']], $stale)
            ->assertStatus(412)->assertJsonPath('code', 'PRECONDITION_FAILED');

        expect($video->fresh())->title->toBe('From phone')->state_version->toBe(2);
        expect(DB::table('video_tags')->count())->toBe(0);
    });

    it('requires If-Match', function () {
        $video = adaVideo();

        $this->patchJson("/v1/videos/{$video->public_id}", ['title' => 'x'], $this->asAda)
            ->assertStatus(428)->assertJsonPath('code', 'PRECONDITION_REQUIRED');
    });

    it('never matches an If-Match that is not one of our ETags', function (string $ifMatch) {
        $video = adaVideo();

        $this->patchJson("/v1/videos/{$video->public_id}", ['title' => 'x'], [...$this->asAda, 'If-Match' => $ifMatch])->assertStatus(412);
        expect($video->fresh()->state_version)->toBe(1);
    })->with(['*', 'W/"1"', '1', '"1", "2"', '"abc"']);

    it('lets only the owner edit', function () {
        $draft = adaVideo();
        $public = Video::factory()->published()->create(['uploader_user_id' => $this->ada->id]);
        $headers = [...$this->asEve, 'If-Match' => '"1"'];

        $this->patchJson("/v1/videos/{$draft->public_id}", ['title' => 'pwned'], $headers)->assertNotFound();
        $this->patchJson("/v1/videos/{$public->public_id}", ['title' => 'pwned'], $headers)->assertForbidden()->assertJsonPath('code', 'NOT_VIDEO_OWNER');
        $this->patchJson("/v1/videos/{$draft->public_id}", ['title' => 'pwned'], ['If-Match' => '"1"'])->assertStatus(401);

        expect([$draft->fresh()->title, $public->fresh()->title])->not->toContain('pwned');
    });

    it('rejects fields that cannot be changed and validates the rest', function () {
        $video = adaVideo();
        $headers = [...$this->asAda, 'If-Match' => '"1"'];

        $this->patchJson("/v1/videos/{$video->public_id}", ['status' => 'published'], $headers)->assertStatus(422)->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');
        $this->patchJson("/v1/videos/{$video->public_id}", ['title' => ''], $headers)->assertStatus(422)->assertJsonPath('errors.0.code', 'REQUIRED');
        $this->patchJson("/v1/videos/{$video->public_id}", ['visibility' => null], $headers)->assertStatus(422)->assertJsonPath('errors.0.code', 'REQUIRED');

        expect($video->fresh())->status->toBe('draft')->state_version->toBe(1);
    });
});

describe('delete', function () {
    it('soft-deletes for the owner', function () {
        $video = adaVideo();

        $this->deleteJson("/v1/videos/{$video->public_id}", [], $this->asAda)->assertNoContent();
        $this->getJson("/v1/videos/{$video->public_id}", $this->asAda)->assertNotFound();
        $this->deleteJson("/v1/videos/{$video->public_id}", [], $this->asAda)->assertNotFound();

        expect($video->fresh())->status->toBe('deleted')->deleted_at->not->toBeNull();
    });

    it('honours If-Match when given', function () {
        $video = adaVideo(['state_version' => 3]);

        $this->deleteJson("/v1/videos/{$video->public_id}", [], [...$this->asAda, 'If-Match' => '"2"'])->assertStatus(412);
        $this->deleteJson("/v1/videos/{$video->public_id}", [], [...$this->asAda, 'If-Match' => '"3"'])->assertNoContent();
    });

    it('lets only the owner delete', function () {
        $draft = adaVideo();
        $public = Video::factory()->published()->create(['uploader_user_id' => $this->ada->id]);

        $this->deleteJson("/v1/videos/{$draft->public_id}", [], $this->asEve)->assertNotFound();
        $this->deleteJson("/v1/videos/{$public->public_id}", [], $this->asEve)->assertForbidden();

        expect(Video::whereNotNull('deleted_at')->count())->toBe(0);
    });
});

describe('my videos', function () {
    it('lists the caller\'s videos in every status, newest first, with tags', function () {
        $this->travelTo(now()->subMinutes(3));
        $old = adaVideo(['title' => 'old']);
        $this->travelBack();
        $this->postJson('/v1/videos', ['title' => 'new', 'tags' => ['x']], withKey($this->asAda))->assertCreated();
        adaVideo(['title' => 'gone', 'deleted_at' => now(), 'status' => 'deleted']);
        Video::factory()->create(['uploader_user_id' => $this->eve->id, 'title' => 'eve']);

        $this->getJson('/v1/me/videos?limit=1', $this->asAda)
            ->assertOk()->assertJsonPath('items.0.title', 'new')->assertJsonPath('items.0.tags', ['x'])->assertJsonPath('has_more', true);

        $cursor = $this->getJson('/v1/me/videos?limit=1', $this->asAda)->json('next_cursor');
        $this->getJson("/v1/me/videos?limit=1&cursor={$cursor}", $this->asAda)
            ->assertJsonPath('items.0.id', $old->public_id)->assertJsonPath('has_more', false);
    });

    it('loads tags for a whole page in one query', function () {
        foreach (range(1, 2) as $i) {
            $this->postJson('/v1/videos', ['title' => "v{$i}", 'tags' => ['a', 'b']], withKey($this->asAda));
        }
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/v1/me/videos', $this->asAda)->assertOk();

            return count(DB::getQueryLog());
        };

        $withTwo = $count();
        foreach (range(3, 10) as $i) {
            $this->postJson('/v1/videos', ['title' => "v{$i}", 'tags' => ['a', 'b']], withKey($this->asAda));
        }

        expect($count())->toBe($withTwo);
    });
});

it('lists active categories in order', function () {
    DB::table('categories')->where('slug', 'gaming')->update(['is_active' => false]);

    $items = $this->getJson('/v1/categories')->assertOk()->json('items');

    expect($items)->toHaveCount(14)
        ->and($items[0])->toBe(['slug' => 'film-animation', 'name' => 'Film & Animation'])
        ->and(array_column($items, 'slug'))->not->toContain('gaming');
});
