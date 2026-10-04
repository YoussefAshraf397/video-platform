<?php

namespace App\Modules\Videos\Contracts;

use App\Platform\Api\Errors\ApiProblem;

interface VideoDirectory
{
    /**
     * The video, if `$callerId` owns it.
     *
     * @throws ApiProblem 404 VIDEO_NOT_FOUND if the caller can't see it, 403 NOT_VIDEO_OWNER if they can but it isn't theirs
     */
    public function findOwned(string $publicId, string $callerId): VideoSummary;

    /** By internal id, regardless of owner or status; null if it doesn't exist. */
    public function find(string $videoId): ?VideoSummary;
}
