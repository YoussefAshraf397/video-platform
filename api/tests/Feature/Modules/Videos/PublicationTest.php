<?php

use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth;
use Tests\Support\Contracts;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = Auth::createUser('ada@example.com');
    $this->asOwner = ['Authorization' => 'Bearer '.Auth::login($this, 'ada@example.com')['access_token']];
    $this->video = fn (array $state = []) => Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'ready', ...$state]);
    $this->events = fn (Video $v) => DB::table('outbox_messages')->where('aggregate_id', $v->id)->orderBy('id')
        ->get(['event_type', 'envelope'])->map(fn ($r) => [$r->event_type, json_decode($r->envelope, true)])->all();
});

describe('publish', function () {
    it('publishes a READY video and emits VideoPublished', function () {
        $video = ($this->video)();

        $this->postJson("/v1/videos/{$video->public_id}:publish", [], $this->asOwner)
            ->assertOk()->assertJson(['status' => 'published', 'visibility' => 'private'])->assertJsonPath('published_at', fn ($v) => $v !== null);

        [[$type, $envelope]] = ($this->events)($video);
        expect($type)->toBe('VideoPublished')
            ->and($envelope['payload'])->toMatchArray(['from' => 'ready', 'to' => 'published', 'reason' => 'owner_published'])
            ->and(Contracts::violations('video-state-changed.v1', json_encode($envelope)))->toBeNull();
    });

    it('sets the visibility in the same step', function () {
        $video = ($this->video)();

        $this->postJson("/v1/videos/{$video->public_id}:publish", ['visibility' => 'unlisted'], $this->asOwner)
            ->assertOk()->assertJson(['status' => 'published', 'visibility' => 'unlisted']);

        $this->getJson("/v1/videos/{$video->public_id}")->assertOk();   // unlisted: anyone with the link
        expect(($this->events)($video))->toHaveCount(1);
    });

    it('refuses until every guard passes, listing them all', function (array $state, array $codes) {
        $video = ($this->video)($state);

        $this->postJson("/v1/videos/{$video->public_id}:publish", [], $this->asOwner)
            ->assertConflict()->assertJsonPath('code', 'NOT_PUBLISHABLE')->assertJsonPath('errors.*.code', $codes);

        expect($video->fresh()->status)->toBe($state['status'] ?? 'ready')->and(($this->events)($video))->toBe([]);
    })->with([
        'still a draft' => [['status' => 'draft'], ['NOT_READY']],
        'still processing' => [['status' => 'processing'], ['NOT_READY']],
        'processing failed' => [['status' => 'processing_failed'], ['NOT_READY']],
        'no title' => [['title' => '  '], ['TITLE_REQUIRED']],
        'blocked by moderation' => [['moderation_status' => 'blocked'], ['BLOCKED']],
        'several at once' => [['status' => 'uploading', 'title' => '', 'moderation_status' => 'blocked'], ['NOT_READY', 'TITLE_REQUIRED', 'BLOCKED']],
    ]);

    it('is a no-op when already published', function () {
        $video = ($this->video)();
        $this->postJson("/v1/videos/{$video->public_id}:publish", [], $this->asOwner)->assertOk();

        $this->postJson("/v1/videos/{$video->public_id}:publish", [], $this->asOwner)->assertOk()->assertJsonPath('status', 'published');

        expect(($this->events)($video))->toHaveCount(1);
    });

    it('changes only the visibility of a published video', function () {
        $video = ($this->video)();
        $this->postJson("/v1/videos/{$video->public_id}:publish", [], $this->asOwner)->assertOk();

        $this->postJson("/v1/videos/{$video->public_id}:publish", ['visibility' => 'public'], $this->asOwner)
            ->assertOk()->assertJson(['status' => 'published', 'visibility' => 'public']);
        expect(($this->events)($video))->toHaveCount(1);   // no second VideoPublished
    });

    it('honours If-Match', function () {
        $video = ($this->video)(['state_version' => 5]);

        $this->postJson("/v1/videos/{$video->public_id}:publish", [], [...$this->asOwner, 'If-Match' => '"4"'])->assertStatus(412);
        $this->postJson("/v1/videos/{$video->public_id}:publish", ['visibility' => 'public'], [...$this->asOwner, 'If-Match' => '"4"'])->assertStatus(412);
        expect($video->fresh())->status->toBe('ready')->visibility->toBe('private');

        $this->postJson("/v1/videos/{$video->public_id}:publish", ['visibility' => 'public'], [...$this->asOwner, 'If-Match' => '"5"'])
            ->assertOk()->assertHeader('ETag', '"7"');   // visibility, then status
    });

    it('validates the visibility and rejects other fields', function () {
        $video = ($this->video)();

        $this->postJson("/v1/videos/{$video->public_id}:publish", ['visibility' => 'secret'], $this->asOwner)->assertStatus(422);
        $this->postJson("/v1/videos/{$video->public_id}:publish", ['title' => 'x'], $this->asOwner)
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');
    });

    it('is owner-only', function () {
        $draft = ($this->video)();
        $public = ($this->video)(['status' => 'published', 'visibility' => 'public', 'published_at' => now()]);
        Auth::createUser('eve@example.com');
        $asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];

        $this->postJson("/v1/videos/{$draft->public_id}:publish", [], $asEve)->assertNotFound();
        $this->postJson("/v1/videos/{$public->public_id}:unpublish", [], $asEve)->assertForbidden();
        $this->postJson("/v1/videos/{$draft->public_id}:publish")->assertUnauthorized();
        expect($draft->fresh()->status)->toBe('ready')->and($public->fresh()->status)->toBe('published');
    });
});

describe('unpublish', function () {
    it('takes a video down and lets the owner republish it with its first publication date', function () {
        $video = ($this->video)(['visibility' => 'public']);
        $first = $this->postJson("/v1/videos/{$video->public_id}:publish", [], $this->asOwner)->json('published_at');

        $this->postJson("/v1/videos/{$video->public_id}:unpublish", [], $this->asOwner)->assertOk()->assertJsonPath('status', 'unpublished');
        $this->getJson("/v1/videos/{$video->public_id}")->assertNotFound();   // gone for everyone else
        $this->postJson("/v1/videos/{$video->public_id}:unpublish", [], $this->asOwner)->assertOk();   // no-op

        $this->travel(10)->minutes();   // a republish must keep the first date, not take a new one
        $this->postJson("/v1/videos/{$video->public_id}:publish", [], $this->asOwner)
            ->assertOk()->assertJson(['status' => 'published', 'published_at' => $first]);

        expect(array_column(($this->events)($video), 0))->toBe(['VideoPublished', 'VideoUnpublished', 'VideoPublished']);
    });

    it('refuses a video that is not published', function () {
        $video = ($this->video)();

        $this->postJson("/v1/videos/{$video->public_id}:unpublish", [], $this->asOwner)->assertConflict()->assertJsonPath('code', 'NOT_PUBLISHED');
    });
});

describe('publish_on_ready', function () {
    beforeEach(function () {
        $this->ready = fn (Video $v) => DB::transaction(fn () => app(VideoLifecycle::class)->transition($v->id, VideoStatus::Ready, 'first_rendition_ready'));
    });

    it('publishes the moment processing makes the video READY', function () {
        $video = ($this->video)(['status' => 'processing', 'publish_on_ready' => true, 'visibility' => 'public']);

        ($this->ready)($video);

        expect($video->fresh())->status->toBe('published')->published_at->not->toBeNull();
        $events = ($this->events)($video);
        expect(array_column($events, 0))->toBe(['VideoStateChanged', 'VideoPublished'])
            ->and($events[1][1]['payload']['reason'])->toBe('publish_on_ready');
        $this->getJson("/v1/videos/{$video->public_id}")->assertOk();
    });

    it('leaves the video READY without the flag', function () {
        $video = ($this->video)(['status' => 'processing']);

        ($this->ready)($video);

        expect($video->fresh()->status)->toBe('ready');
    });

    it('leaves the video READY when a guard fails', function () {
        $video = ($this->video)(['status' => 'processing', 'publish_on_ready' => true, 'moderation_status' => 'blocked']);

        ($this->ready)($video);

        expect($video->fresh()->status)->toBe('ready')->and(array_column(($this->events)($video), 0))->toBe(['VideoStateChanged']);
    });

    it('can be chosen when creating or editing the video', function () {
        $id = $this->postJson('/v1/videos', ['title' => 'x', 'publish_on_ready' => true], withKey($this->asOwner))
            ->assertCreated()->assertJsonPath('publish_on_ready', true)->json('id');

        $this->patchJson("/v1/videos/{$id}", ['publish_on_ready' => false], [...$this->asOwner, 'If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('publish_on_ready', false);
        $this->patchJson("/v1/videos/{$id}", ['publish_on_ready' => 'yes'], [...$this->asOwner, 'If-Match' => '"2"'])->assertStatus(422);
    });
});
