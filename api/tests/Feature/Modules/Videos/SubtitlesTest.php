<?php

/*
 * Subtitle tracks API (S4-06). Uploads go to the local S3 emulator through the presigned URL, as a
 * client's would.
 */

use App\Modules\Videos\Models\Subtitle;
use App\Modules\Videos\Models\Video;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Auth;

uses(RefreshDatabase::class);

const SRT = "1\n00:00:01,000 --> 00:00:02,000\nHello\n";

beforeEach(function () {
    $this->owner = Auth::createUser('ada@example.com');
    Auth::createUser('eve@example.com');
    $this->asOwner = ['Authorization' => 'Bearer '.Auth::login($this, 'ada@example.com')['access_token']];
    $this->asEve = ['Authorization' => 'Bearer '.Auth::login($this, 'eve@example.com')['access_token']];
    $this->video = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'ready']);

    $this->add = fn (array $body = [], ?Video $video = null, ?array $as = null) => $this->postJson(
        '/v1/videos/'.($video ?? $this->video)->public_id.'/subtitles',
        [...['language' => 'en', 'format' => 'srt', 'size_bytes' => strlen(SRT)], ...$body],
        withKey($as ?? $this->asOwner),
    );
    /** Uploads a file with a track's form upload, as a browser would (fields first, the file last). Returns S3's status. */
    $this->upload = function (array $created, string $body = SRT): int {
        $request = Http::asMultipart();
        foreach ($created['upload']['fields'] as $name => $value) {
            $request = $request->attach($name, $value);
        }

        return $request->attach('file', $body, 'track.srt')->post($created['upload']['url'])->status();
    };
    $this->complete = fn (string $id, ?array $as = null) => $this->postJson("/v1/videos/{$this->video->public_id}/subtitles/{$id}:complete", [], $as ?? $this->asOwner);
});

describe('adding a track', function () {
    it('returns a form upload that S3 lets accept only the declared file', function () {
        $created = ($this->add)(['language' => 'pt-br', 'kind' => 'captions'])->assertCreated()
            ->assertJson(['language' => 'pt-BR', 'label' => 'Português (Brasil)', 'kind' => 'captions', 'format' => 'srt',
                'size_bytes' => strlen(SRT), 'status' => 'awaiting_upload', 'error' => null])
            ->assertJsonPath('upload.method', 'POST')
            ->assertJsonPath('upload.fields.key', "captions/{$this->video->id}/".Subtitle::query()->sole()->id.'/source')
            ->assertJsonPath('upload.fields.Content-Type', 'application/x-subrip')
            ->json();

        $policy = json_decode(base64_decode($created['upload']['fields']['Policy']), true);
        expect($policy['conditions'])->toContain(['content-length-range', strlen(SRT), strlen(SRT)])
            ->and(strtotime($policy['expiration']) - time())->toBeLessThanOrEqual(900);

        // S3 itself refuses a bigger file (the 2 MiB limit can't be bypassed by uploading more)…
        expect(($this->upload)($created, SRT.str_repeat('x', 1000)))->toBe(400)
            // …and takes the declared one.
            ->and(($this->upload)($created))->toBe(204);
    });

    it('keeps a label the creator gave, and defaults to subtitles', function () {
        ($this->add)(['language' => 'EN-us', 'label' => 'English (US) — director\'s commentary'])->assertCreated()
            ->assertJson(['language' => 'en-US', 'label' => 'English (US) — director\'s commentary', 'kind' => 'subtitles']);
    });

    it('validates the request', function (array $body, string $field) {
        ($this->add)($body)->assertUnprocessable()->assertJsonPath('errors.0.field', $field);
    })->with([
        'not a language tag' => [['language' => 'english'], 'language'],
        'unknown format' => [['format' => 'ass'], 'format'],
        'too big' => [['size_bytes' => 2 * 1024 * 1024 + 1], 'size_bytes'],
        'empty' => [['size_bytes' => 0], 'size_bytes'],
        'unknown kind' => [['kind' => 'karaoke'], 'kind'],
        'unknown field' => [['default' => true], 'default'],
    ]);

    it('is for the owner only', function () {
        $this->postJson("/v1/videos/{$this->video->public_id}/subtitles", ['language' => 'en', 'format' => 'srt', 'size_bytes' => 10], withKey([]))->assertUnauthorized();
        ($this->add)([], null, $this->asEve)->assertNotFound();   // a ready, unpublished video: Eve can't see it
        $this->video->update(['status' => 'published', 'visibility' => 'public']);
        ($this->add)([], null, $this->asEve)->assertForbidden()->assertJsonPath('code', 'NOT_VIDEO_OWNER');
        $this->getJson("/v1/videos/{$this->video->public_id}/subtitles", $this->asEve)->assertForbidden();
    });

    it('allows one live track per language and kind', function () {
        ($this->add)()->assertCreated();

        ($this->add)()->assertConflict()->assertJsonPath('code', 'SUBTITLE_EXISTS');
        ($this->add)(['kind' => 'captions'])->assertCreated();
        ($this->add)(['language' => 'fr'])->assertCreated();
    });

    it('frees the language when an upload is abandoned', function () {
        ($this->add)()->assertCreated();
        Subtitle::query()->update(['upload_expires_at' => now()->subMinute()]);   // its upload URL expired

        ($this->add)()->assertCreated();
        expect(Subtitle::query()->pluck('status')->sort()->values()->all())->toBe(['awaiting_upload', 'deleted']);
    });

    it('caps the tracks per video', function () {
        foreach (range(1, 20) as $i) {
            ($this->add)(['language' => 'en', 'label' => "Track {$i}", 'kind' => $i % 2 ? 'subtitles' : 'captions'])
                ->assertStatus($i <= 2 ? 201 : 409);
        }
        DB::table('subtitles')->delete();
        foreach (['en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'sv', 'da', 'fi', 'pl', 'cs', 'el', 'tr', 'ar', 'he', 'hi', 'ja', 'ko', 'zh'] as $language) {
            ($this->add)(['language' => $language])->assertCreated();
        }

        ($this->add)(['language' => 'ru'])->assertConflict()->assertJsonPath('code', 'SUBTITLE_LIMIT');
    });

    it('refuses on a blocked or deleted video', function (string $status) {
        $this->video->update(['status' => $status]);

        ($this->add)()->assertConflict()->assertJsonPath('code', 'VIDEO_NOT_EDITABLE');
    })->with(['blocked', 'rejected']);
});

describe('completing', function () {
    it('checks the file is in S3, as declared', function () {
        $created = ($this->add)()->json();

        ($this->complete)($created['id'])->assertConflict()->assertJsonPath('code', 'SUBTITLE_NOT_UPLOADED');

        // A different file under the key (e.g. written by another route): still checked.
        app(S3Client::class)->putObject(['Bucket' => 'uploads', 'Key' => $created['upload']['fields']['key'], 'Body' => SRT.'extra']);
        ($this->complete)($created['id'])->assertUnprocessable()->assertJsonPath('code', 'SUBTITLE_SIZE_MISMATCH');

        expect(($this->upload)($created))->toBe(204);
        ($this->complete)($created['id'])->assertOk()->assertJson(['id' => $created['id'], 'status' => 'processing']);
        ($this->complete)($created['id'])->assertOk()->assertJson(['status' => 'processing']);   // a retry
    });

    it('is for the owner only, and only for the video\'s own tracks', function () {
        $created = ($this->add)()->json();
        ($this->upload)($created);
        $other = Video::factory()->create(['uploader_user_id' => $this->owner->id, 'status' => 'ready']);

        $this->postJson("/v1/videos/{$other->public_id}/subtitles/{$created['id']}:complete", [], $this->asOwner)->assertNotFound()
            ->assertJsonPath('code', 'SUBTITLE_NOT_FOUND');
        ($this->complete)($created['id'], $this->asEve)->assertNotFound();
        expect(Subtitle::query()->sole()->status)->toBe('awaiting_upload');
    });
});

describe('listing and deleting', function () {
    it('lists the video\'s tracks, newest first, without deleted ones', function () {
        $en = ($this->add)()->json();
        $this->travel(1)->seconds();
        $fr = ($this->add)(['language' => 'fr'])->json();
        Subtitle::query()->whereKey($en['id'])->update(['status' => 'failed', 'error_code' => 'CAPTIONS_INVALID', 'error_detail' => 'line 3: expected a cue timing line']);

        $this->getJson("/v1/videos/{$this->video->public_id}/subtitles", $this->asOwner)->assertOk()
            ->assertJsonPath('items.*.id', [$fr['id'], $en['id']])
            ->assertJsonPath('items.1.error', ['code' => 'CAPTIONS_INVALID', 'detail' => 'line 3: expected a cue timing line']);

        $this->deleteJson("/v1/videos/{$this->video->public_id}/subtitles/{$fr['id']}", [], $this->asOwner)->assertNoContent();
        $this->getJson("/v1/videos/{$this->video->public_id}/subtitles", $this->asOwner)->assertJsonPath('items.*.id', [$en['id']]);
        $this->deleteJson("/v1/videos/{$this->video->public_id}/subtitles/{$fr['id']}", [], $this->asOwner)->assertNotFound();
        ($this->add)(['language' => 'fr'])->assertCreated();   // the language is free again
    });
});
