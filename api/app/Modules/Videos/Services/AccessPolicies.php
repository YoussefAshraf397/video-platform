<?php

namespace App\Modules\Videos\Services;

use App\Modules\Videos\Contracts\VideoAccess;
use App\Modules\Videos\Contracts\VideoAccessPolicy;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cached access policies (design doc §13.2, §21: `vpolicy:{id}`, cache-aside, 5 min).
 *
 * Every write that can change who may watch (status, visibility, moderation, processing results)
 * calls forget(): once right away and once after its transaction commits, so a reader that
 * loaded the row just before the commit can't leave a stale policy behind for long. Playback is
 * authorized on every request, so a cleared entry is all it takes for a change to apply.
 */
final class AccessPolicies implements VideoAccess
{
    private const TTL_SECONDS = 300;

    public function __construct(private readonly Cache $cache) {}

    public function policy(string $publicId): ?VideoAccessPolicy
    {
        // Cached as a plain array: the cache store doesn't unserialize objects (config/cache.php).
        $cached = $this->cache->get(self::key($publicId));
        if (is_array($cached)) {
            return self::hydrate($cached);
        }

        $policy = $this->load($publicId);
        if ($policy !== null) {
            $this->cache->put(self::key($publicId), [...get_object_vars($policy), 'status' => $policy->status->value],
                self::TTL_SECONDS + random_int(0, 60));   // jitter, so entries don't all expire at once
        }

        return $policy;
    }

    public function forget(string $publicId): void
    {
        $key = self::key($publicId);
        $this->cache->forget($key);
        DB::afterCommit(fn () => $this->cache->forget($key));
    }

    public function forgetById(string $videoId): void
    {
        $publicId = Video::query()->whereKey($videoId)->value('public_id');
        if (is_string($publicId)) {
            $this->forget($publicId);
        }
    }

    private function load(string $publicId): ?VideoAccessPolicy
    {
        $video = Video::query()->where('public_id', $publicId)->first();
        if ($video === null) {
            return null;
        }
        $thumbnail = DB::table('thumbnails')->where('video_id', $video->id)->where('is_primary', true)->value('files');

        return new VideoAccessPolicy(
            id: $video->id,
            publicId: $video->public_id,
            ownerId: $video->uploader_user_id,
            title: $video->title,
            status: VideoStatus::from($video->status),
            visibility: $video->visibility,
            moderationStatus: $video->moderation_status,
            ageRestricted: $video->age_restricted,
            durationMs: $video->duration_ms,
            width: $video->source_width,
            height: $video->source_height,
            primaryThumbnail: is_string($thumbnail) ? json_decode($thumbnail, true, flags: JSON_THROW_ON_ERROR) : [],
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): VideoAccessPolicy
    {
        return new VideoAccessPolicy(...[...$row, 'status' => VideoStatus::from($row['status'])]);
    }

    private static function key(string $publicId): string
    {
        return "vpolicy:{$publicId}";
    }
}
