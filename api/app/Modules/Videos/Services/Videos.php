<?php

namespace App\Modules\Videos\Services;

use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Contracts\VideoSummary;
use App\Modules\Videos\Models\Category;
use App\Modules\Videos\Models\Video;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Http\Preconditions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Video records: create, read with access rules, change metadata, delete.
 *
 * Every write bumps `state_version` with a conditional update on the version the caller last
 * saw (the ETag), so concurrent edits can't overwrite each other. Status changes go through
 * VideoLifecycle (VideoStateMachine), never through here.
 */
final class Videos implements VideoDirectory
{
    public function __construct(private readonly VideoLifecycle $lifecycle) {}

    public const MAX_TAGS = 30;

    public const MAX_TAG_LENGTH = 50;

    /** 8 random bytes as base64url: 11 characters, not guessable or enumerable. */
    public static function newPublicId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(8)), '+/', '-_'), '=');
    }

    /**
     * Tags match regardless of case, Unicode form and spacing: "Lo-Fi  Beats" and "lo-fi beats"
     * are the same tag. The creator's own spelling is kept as the label.
     */
    public static function normalizeTag(string $label): string
    {
        $label = Normalizer::normalize($label, Normalizer::FORM_KC);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $label)));
    }

    /**
     * The video if the caller may see it; null otherwise, including when it exists but they
     * can't see it, so private videos don't reveal that they exist.
     */
    public function findVisible(string $publicId, ?string $callerId): ?Video
    {
        $video = Video::query()->with('category')->where('public_id', $publicId)->whereNull('deleted_at')->first();

        if ($video === null || ($video->uploader_user_id !== $callerId && ! $video->isVisibleToPublic())) {
            return null;
        }

        return $video;
    }

    public function findOwned(string $publicId, string $callerId): VideoSummary
    {
        return self::summary($this->findOwnedModel($publicId, $callerId));
    }

    public function find(string $videoId): ?VideoSummary
    {
        $video = Video::query()->find($videoId);

        return $video ? self::summary($video) : null;
    }

    /** The video if the caller owns it. 404 if they can't see it, 403 if they can but it isn't theirs. */
    public function findOwnedModel(string $publicId, string $callerId): Video
    {
        $video = $this->findVisible($publicId, $callerId) ?? throw self::notFound();

        if ($video->uploader_user_id !== $callerId) {
            throw new ApiProblem(403, 'NOT_VIDEO_OWNER', 'Only the owner can change this video');
        }

        return $video;
    }

    /** @param  array<string, mixed>  $attributes  validated input; `category` is a slug, `tags` a list of labels */
    public function create(string $ownerId, array $attributes): Video
    {
        $columns = $this->columns($attributes);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($ownerId, $columns, $attributes) {
                    $video = Video::query()->create([...$columns, 'public_id' => self::newPublicId(), 'uploader_user_id' => $ownerId]);
                    if (isset($attributes['tags'])) {
                        $this->replaceTags($video->id, $attributes['tags']);
                    }

                    return $this->reload($video->id);
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 3) {   // a public_id collision is ~1 in 10^19; three in a row is a bug
                    throw $e;
                }
            }
        }
    }

    /** @param  array<string, mixed>  $changes  validated input, as for create() */
    public function update(Video $video, int $expectedVersion, array $changes): Video
    {
        return DB::transaction(function () use ($video, $expectedVersion, $changes) {
            $updated = Video::query()
                ->whereKey($video->id)
                ->where('state_version', $expectedVersion)
                ->whereNull('deleted_at')
                ->update([...$this->columns($changes), 'state_version' => DB::raw('state_version + 1'), 'updated_at' => now()]);

            if ($updated === 0) {
                throw Preconditions::failed();
            }
            if (array_key_exists('tags', $changes)) {
                $this->replaceTags($video->id, $changes['tags'] ?? []);
            }

            return $this->reload($video->id);
        });
    }

    /** Soft delete through the state machine; the purge job (EPIC-07) removes the bytes later. `$expectedVersion` is optional. */
    public function delete(Video $video, ?int $expectedVersion): void
    {
        try {
            $this->lifecycle->transition($video->id, VideoStatus::Deleted, 'owner_deleted', $expectedVersion);
        } catch (IllegalVideoTransition) {
            throw self::notFound();   // deleted by a concurrent request
        }
    }

    /**
     * Tag labels for many videos in one query, in the creator's order.
     *
     * @param  list<string>  $videoIds
     * @return array<string, list<string>> keyed by video id
     */
    public function tagsFor(array $videoIds): array
    {
        $tags = array_fill_keys($videoIds, []);
        foreach (DB::table('video_tags')->whereIn('video_id', $videoIds)->orderBy('position')->get(['video_id', 'label']) as $row) {
            $tags[(string) $row->video_id][] = (string) $row->label;
        }

        return $tags;
    }

    private static function summary(Video $video): VideoSummary
    {
        return new VideoSummary($video->id, $video->public_id, $video->uploader_user_id, VideoStatus::from($video->status));
    }

    public static function notFound(): ApiProblem
    {
        return new ApiProblem(404, 'VIDEO_NOT_FOUND', 'Video not found');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function columns(array $input): array
    {
        $columns = array_intersect_key($input, array_flip([
            'title', 'description', 'language', 'visibility', 'age_restricted', 'made_for_kids', 'comments_enabled',
        ]));

        if (array_key_exists('category', $input)) {
            $columns['category_id'] = $input['category'] === null
                ? null
                : Category::query()->where('slug', $input['category'])->value('id');
        }

        return $columns;
    }

    /** @param  list<string>  $labels */
    private function replaceTags(string $videoId, array $labels): void
    {
        $byName = [];
        foreach ($labels as $i => $label) {
            $name = self::normalizeTag($label);
            if ($name === '' || mb_strlen($name) > self::MAX_TAG_LENGTH) {   // NFKC can lengthen text (e.g. ligatures)
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'The request is invalid', errors: [
                    ['field' => "tags.{$i}", 'code' => 'INVALID_TAG', 'message' => 'The tag is empty or too long once normalized.'],
                ]);
            }
            $byName[$name] ??= trim($label);   // duplicates by normalized name: the first spelling wins
        }

        DB::table('video_tags')->where('video_id', $videoId)->delete();
        if ($byName === []) {
            return;
        }

        DB::table('tags')->insertOrIgnore(array_map(fn (string $name) => ['normalized_name' => $name], array_keys($byName)));
        $ids = DB::table('tags')->whereIn('normalized_name', array_keys($byName))->pluck('id', 'normalized_name');

        $position = 0;
        $rows = [];
        foreach ($byName as $name => $label) {
            $rows[] = ['video_id' => $videoId, 'tag_id' => $ids[$name], 'position' => $position++, 'label' => $label];
        }
        DB::table('video_tags')->insert($rows);
    }

    private function reload(string $videoId): Video
    {
        return Video::query()->with('category')->findOrFail($videoId);
    }
}
