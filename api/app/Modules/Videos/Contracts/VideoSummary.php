<?php

namespace App\Modules\Videos\Contracts;

/** What other modules may know about a video. */
final readonly class VideoSummary
{
    public function __construct(
        public string $id,
        public string $publicId,
        public string $ownerId,
        public VideoStatus $status,
    ) {}
}
