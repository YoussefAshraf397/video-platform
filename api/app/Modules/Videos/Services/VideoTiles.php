<?php

namespace App\Modules\Videos\Services;

use App\Modules\Playback\Contracts\MediaUrls;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Videos\Models\Video;
use Illuminate\Support\Facades\DB;

/** Turns videos into the tiles the web app lists: title, creator, duration and signed thumbnails. */
final class VideoTiles
{
    public function __construct(private readonly UserDirectory $users, private readonly MediaUrls $media) {}

    /**
     * One query for the thumbnails and one lookup for the creators, however many videos there are.
     *
     * @param  list<Video>  $videos
     * @return list<array{id: string, title: string, duration_ms: ?int, published_at: ?string, creator: array{handle: string, display_name: string}, thumbnails: list<array{url: string, width: int, height: int}>}>
     */
    public function present(array $videos): array
    {
        $thumbnails = $this->thumbnailsFor(array_map(fn (Video $v) => $v->id, $videos));
        $creators = $this->users->findMany(array_values(array_unique(array_map(fn (Video $v) => $v->uploader_user_id, $videos))));

        $tiles = [];
        foreach ($videos as $video) {
            $creator = $creators[$video->uploader_user_id] ?? null;
            if ($creator === null || ! $creator->isActive()) {
                continue;   // suspended or otherwise inactive creators are hidden, as their profile is
            }
            $tiles[] = [
                'id' => $video->public_id,
                'title' => $video->title,
                'duration_ms' => $video->duration_ms,
                'published_at' => $video->published_at?->toIso8601ZuluString(),
                'creator' => ['handle' => $creator->handle, 'display_name' => $creator->displayName],
                'thumbnails' => $thumbnails[$video->id] ?? [],
            ];
        }

        return $tiles;
    }

    /**
     * The WebP files of each video's primary thumbnail, signed, narrowest first.
     *
     * @param  list<string>  $videoIds
     * @return array<string, list<array{url: string, width: int, height: int}>>
     */
    public function thumbnailsFor(array $videoIds): array
    {
        if ($videoIds === []) {
            return [];
        }

        $result = [];
        $rows = DB::table('thumbnails')->whereIn('video_id', $videoIds)->where('is_primary', true)->get(['video_id', 'files']);
        foreach ($rows as $row) {
            /** @var list<array{key: string, width: int, height: int, format: string}> $files */
            $files = json_decode((string) $row->files, true, flags: JSON_THROW_ON_ERROR);
            $webp = array_values(array_filter($files, fn (array $f) => $f['format'] === 'webp'));
            usort($webp, fn (array $a, array $b) => $a['width'] <=> $b['width']);
            $result[$row->video_id] = array_map(fn (array $f) => [
                'url' => $this->media->signedUrl($f['key']), 'width' => $f['width'], 'height' => $f['height'],
            ], $webp);
        }

        return $result;
    }
}
