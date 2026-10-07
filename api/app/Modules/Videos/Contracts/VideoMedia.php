<?php

namespace App\Modules\Videos\Contracts;

/**
 * What processing found and made for a video, recorded by the Processing module.
 */
interface VideoMedia
{
    /**
     * Records the media facts and auto thumbnails of a finished processing version. Idempotent:
     * recording the same version again changes nothing, and an older version than the one already
     * recorded is ignored. The first candidate around the middle of the video becomes the primary
     * thumbnail unless the video already has one from this version.
     *
     * @param  list<array{key: string, width: int, height: int, time_offset_ms: int}>  $thumbnails
     */
    public function recordProcessed(string $videoId, int $processingVersion, int $durationMs, int $width, int $height, array $thumbnails): void;
}
