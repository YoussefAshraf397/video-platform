<?php

namespace App\Modules\Videos\Contracts;

/** A video's subtitle tracks, for the Processing module (S4-06). */
interface VideoSubtitles
{
    /**
     * The tracks the video's playable version should have now: uploaded and not deleted or failed.
     *
     * @return list<SubtitleTrack>
     */
    public function publishable(string $videoId): array;

    /**
     * Tracks by id, whatever their status (a published revision may list a track deleted since).
     *
     * @param  list<string>  $ids
     * @return array<string, SubtitleTrack> by id
     */
    public function find(string $videoId, array $ids): array;

    /**
     * The outcome of converting each track. Deleted tracks stay deleted.
     *
     * @param  list<array{subtitle_id: string, status: string, error_code: ?string, error_detail: ?string}>  $outcomes  status: ready | failed
     */
    public function recordConverted(string $videoId, array $outcomes): void;
}
