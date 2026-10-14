<?php

namespace App\Modules\Videos\Contracts;

/**
 * Everything playback needs to decide who may watch a video (design doc §13.2), and to describe it.
 */
final readonly class VideoAccessPolicy
{
    /**
     * @param  list<array{key: string, width: int, height: int, format: string}>  $primaryThumbnail
     */
    public function __construct(
        public string $id,
        public string $publicId,
        public string $ownerId,
        public string $title,
        public VideoStatus $status,
        public string $visibility,
        public string $moderationStatus,
        public bool $ageRestricted,
        public ?int $durationMs,
        public ?int $width,
        public ?int $height,
        public array $primaryThumbnail,
    ) {}
}
