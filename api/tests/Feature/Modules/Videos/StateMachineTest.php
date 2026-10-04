<?php

use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\Videos;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\Auth;
use Tests\Support\Contracts;

uses(RefreshDatabase::class);

/**
 * The legal transitions, written out from the design doc §12.3 independently of VideoStatus::next(),
 * so the code is checked against the spec rather than against itself.
 */
const SPEC = [
    'draft' => ['upload_pending'],
    'upload_pending' => ['uploading', 'upload_failed'],
    'uploading' => ['uploaded', 'upload_failed'],
    'upload_failed' => ['upload_pending'],
    'uploaded' => ['validating'],
    'validating' => ['queued_for_processing', 'processing_failed', 'rejected'],
    'queued_for_processing' => ['processing'],
    'processing' => ['ready', 'processing_failed'],
    'processing_failed' => ['queued_for_processing'],
    'ready' => ['published'],
    'published' => ['unpublished'],
    'unpublished' => ['published'],
    'rejected' => [],
    'blocked' => [],        // + its pre_block_status, see specAllows()
    'deleted' => ['purged'],
    'purged' => [],
];

function specAllows(string $from, string $to, ?string $preBlock = null): bool
{
    if ($from === 'blocked') {
        return $to === 'deleted' || $to === $preBlock;
    }
    if ($to === 'blocked' && ! in_array($from, ['blocked', 'deleted', 'purged'], true)) {
        return true;   // moderation block from any live state
    }
    if ($to === 'deleted' && ! in_array($from, ['deleted', 'purged'], true)) {
        return true;   // owner/admin delete from anywhere
    }

    return in_array($to, SPEC[$from], true);
}

function statuses(): array
{
    return array_map(fn (VideoStatus $s) => $s->value, VideoStatus::cases());
}

/** @return list<array<string, mixed>> decoded envelopes, oldest first */
function outbox(): array
{
    return DB::table('outbox_messages')->orderBy('id')->pluck('envelope')
        ->map(fn ($e) => json_decode($e, true))->all();
}

beforeEach(function () {
    $this->owner = Auth::createUser('ada@example.com');
    $this->lifecycle = app(VideoLifecycle::class);
});

it('covers every status in the spec', function () {
    expect(array_keys(SPEC))->toEqualCanonicalizing(statuses());
});

it('allows exactly the transitions in the spec, each with one event', function (string $from) {
    Log::spy();
    $preBlock = 'published';

    foreach (statuses() as $to) {
        DB::table('outbox_messages')->delete();
        $video = Video::factory()->create([
            'uploader_user_id' => $this->owner->id, 'status' => $from, 'state_version' => 7,
            'pre_block_status' => $from === 'blocked' ? $preBlock : null,
        ]);

        try {
            $version = $this->lifecycle->transition($video->id, VideoStatus::from($to), 'test_reason');
            $accepted = true;
        } catch (IllegalVideoTransition $e) {
            $accepted = false;
            expect($e->from->value)->toBe($from)->and($e->to->value)->toBe($to);
        }

        expect($accepted)->toBe(specAllows($from, $to, $preBlock), "{$from} → {$to}");

        $fresh = $video->fresh();
        $events = outbox();
        if ($accepted) {
            expect($fresh->status)->toBe($to)
                ->and($fresh->state_version)->toBe(8)->and($version)->toBe(8)
                ->and($events)->toHaveCount(1)
                ->and($events[0]['payload'])->toBe(['video_id' => $video->id, 'public_id' => $video->public_id, 'from' => $from, 'to' => $to, 'reason' => 'test_reason'])
                ->and($events[0]['aggregate_version'])->toBe(8)
                ->and(Contracts::violations('video-state-changed.v1', json_encode($events[0])))->toBeNull();
        } else {
            expect($fresh->status)->toBe($from)->and($fresh->state_version)->toBe(7)->and($events)->toBe([]);
            Log::shouldHaveReceived('warning')->with('video.transition_rejected', ['video_id' => $video->id, 'from' => $from, 'to' => $to, 'reason' => 'test_reason']);
        }
    }
})->with(fn () => statuses());

it('names the events other modules subscribe to', function (string $from, string $to, string $type) {
    $video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => $from]);

    $this->lifecycle->transition($video->id, VideoStatus::from($to), 'x');

    expect(outbox()[0]['event_type'])->toBe($type);
})->with([
    ['uploading', 'uploaded', 'VideoUploaded'],
    ['ready', 'published', 'VideoPublished'],
    ['published', 'unpublished', 'VideoUnpublished'],
    ['published', 'blocked', 'VideoBlocked'],
    ['draft', 'deleted', 'VideoDeleted'],
    ['processing', 'ready', 'VideoStateChanged'],
    ['draft', 'upload_pending', 'VideoStateChanged'],
]);

it('holds its invariants over random sequences of transitions and edits', function (int $seed) {
    mt_srand($seed);
    $video = Video::factory()->create(['uploader_user_id' => $this->owner->id]);
    $all = VideoStatus::cases();
    $accepted = 0;
    $edits = 0;

    for ($step = 0; $step < 150; $step++) {
        if (mt_rand(1, 5) === 1 && $video->fresh()->deleted_at === null) {   // a metadata edit: bumps the version, no event
            $fresh = $video->fresh();
            app(Videos::class)->update($fresh, $fresh->state_version, ['title' => "edit {$step}"]);
            $edits++;

            continue;
        }

        $before = $video->fresh();
        $to = $all[mt_rand(0, count($all) - 1)];
        $legal = specAllows($before->status, $to->value, $before->pre_block_status);
        try {
            $this->lifecycle->transition($video->id, $to, 'random_walk');
            expect($legal)->toBeTrue("seed {$seed} step {$step}: {$before->status} → {$to->value} was accepted");
            $accepted++;
        } catch (IllegalVideoTransition) {
            expect($legal)->toBeFalse("seed {$seed} step {$step}: {$before->status} → {$to->value} was rejected");
        }
    }

    $events = outbox();
    $final = $video->fresh();
    expect($events)->toHaveCount($accepted)
        ->and($final->state_version)->toBe(1 + $accepted + $edits);

    // The events form one unbroken path from draft to the current status, with rising versions.
    $status = 'draft';
    $lastVersion = 1;
    foreach ($events as $event) {
        expect($event['payload']['from'])->toBe($status)
            ->and($event['aggregate_version'])->toBeGreaterThan($lastVersion);
        $status = $event['payload']['to'];
        $lastVersion = $event['aggregate_version'];
    }
    expect($final->status)->toBe($status);
})->with([1, 2, 3, 42, 1337, 2026, 31337, 99991]);

it('re-checks and retries when the row changes between read and update', function () {
    $video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'uploading']);

    // Simulate a concurrent metadata edit landing right after the state machine reads the row.
    $sneaked = false;
    DB::listen(function ($query) use ($video, &$sneaked) {
        if (! $sneaked && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"videos"')) {
            $sneaked = true;
            DB::table('videos')->where('id', $video->id)->increment('state_version');
        }
    });

    expect($this->lifecycle->transition($video->id, VideoStatus::Uploaded, 'upload_completed'))->toBe(3);
    expect($sneaked)->toBeTrue()->and(outbox())->toHaveCount(1);
});

it('fails with 412, and no event, when the caller\'s version is stale', function () {
    $video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'state_version' => 4]);

    expect(fn () => $this->lifecycle->transition($video->id, VideoStatus::Deleted, 'owner_deleted', expectedVersion: 3))
        ->toThrow(fn (ApiProblem $p) => expect($p->status)->toBe(412));
    expect(outbox())->toBe([])->and($video->fresh()->status)->toBe('draft');

    // A stale version wins over an illegal target: the caller must re-read first.
    expect(fn () => $this->lifecycle->transition($video->id, VideoStatus::Published, 'x', expectedVersion: 3))
        ->toThrow(fn (ApiProblem $p) => expect($p->status)->toBe(412));
});

it('returns 404 for a video that does not exist', function () {
    expect(fn () => $this->lifecycle->transition('0199b1f0-1111-7aaa-8bbb-0c0c0c0c0c01', VideoStatus::Deleted, 'x'))
        ->toThrow(fn (ApiProblem $p) => expect($p->errorCode)->toBe('VIDEO_NOT_FOUND'));
});

describe('side effects', function () {
    it('keeps the first publication date across unpublish and republish', function () {
        $video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'ready']);

        $this->travelTo(now()->subDay());
        $this->lifecycle->transition($video->id, VideoStatus::Published, 'owner_published');
        $first = $video->fresh()->published_at;
        $this->travelBack();
        $this->lifecycle->transition($video->id, VideoStatus::Unpublished, 'owner_unpublished');
        $this->lifecycle->transition($video->id, VideoStatus::Published, 'owner_published');

        expect($video->fresh()->published_at->equalTo($first))->toBeTrue();
    });

    it('remembers where a block came from and returns there', function () {
        $video = Video::factory()->published()->create(['uploader_user_id' => $this->owner->id]);

        $this->lifecycle->transition($video->id, VideoStatus::Blocked, 'policy_violation');
        expect($video->fresh())->pre_block_status->toBe('published')->blocked_reason->toBe('policy_violation');
        expect(fn () => $this->lifecycle->transition($video->id, VideoStatus::Ready, 'restore'))->toThrow(IllegalVideoTransition::class);

        $this->lifecycle->transition($video->id, VideoStatus::Published, 'appeal_upheld');
        expect($video->fresh())->status->toBe('published')->pre_block_status->toBeNull()->blocked_reason->toBeNull();
    });

    it('sets deleted_at on delete, including from blocked', function (string $from) {
        $video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => $from, 'pre_block_status' => $from === 'blocked' ? 'ready' : null]);

        $this->lifecycle->transition($video->id, VideoStatus::Deleted, 'owner_deleted');

        expect($video->fresh())->deleted_at->not->toBeNull()->pre_block_status->toBeNull();
    })->with(['draft', 'published', 'blocked']);
});

it('deletes through the state machine from the API', function () {
    $video = Video::factory()->create(['uploader_user_id' => $this->owner->id]);
    $token = Auth::login($this)['access_token'];

    $this->deleteJson("/v1/videos/{$video->public_id}", [], ['Authorization' => "Bearer {$token}"])->assertNoContent();

    $events = outbox();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toMatchArray(['event_type' => 'VideoDeleted', 'aggregate_version' => 2])
        ->and($events[0]['payload'])->toMatchArray(['from' => 'draft', 'to' => 'deleted', 'reason' => 'owner_deleted']);
});

it('has a database check constraint listing exactly the enum\'s statuses', function () {
    $definition = DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'videos_status_check'");
    preg_match_all("/'([a-z_]+)'/", $definition, $m);

    expect($m[1])->toEqualCanonicalizing(statuses());
    expect(fn () => DB::table('videos')->where('id', Video::factory()->create(['uploader_user_id' => $this->owner->id])->id)->update(['status' => 'bogus']))
        ->toThrow(QueryException::class);
});
