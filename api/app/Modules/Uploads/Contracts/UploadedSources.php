<?php

namespace App\Modules\Uploads\Contracts;

interface UploadedSources
{
    /** The video's most recently completed upload, or null if it has none. */
    public function latestCompletedFor(string $videoId): ?UploadedSource;
}
