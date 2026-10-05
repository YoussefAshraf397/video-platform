<?php

namespace App\Modules\Uploads\Contracts;

/** A completed upload: the original file in S3, as other modules (e.g. Processing) may know it. */
final readonly class UploadedSource
{
    public function __construct(
        public string $uploadSessionId,
        public string $videoId,
        public string $bucket,
        public string $key,
        public int $sizeBytes,
        public string $contentType,
    ) {}
}
