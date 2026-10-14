<?php

namespace App\Modules\Videos\Contracts;

interface VideoAccess
{
    /**
     * The video's access policy, from a cache that every change to the video clears at once.
     * Null if there is no such video.
     */
    public function policy(string $publicId): ?VideoAccessPolicy;
}
