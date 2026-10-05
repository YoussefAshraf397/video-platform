<?php

namespace App\Modules\Processing\Events;

use App\Platform\Messaging\OutboxEvent;

/**
 * Command to the media worker: transcode one source (contract media-process-requested.v1).
 * Published through the outbox to the `media-commands` topic, which feeds the `media-process`
 * queue, so it is sent if and only if the job row commits.
 */
final class MediaProcessRequested implements OutboxEvent
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $videoId,
        public readonly int $processingVersion,
        public readonly string $sourceBucket,
        public readonly string $sourceKey,
        public readonly string $outputBucket,
        public readonly string $outputPrefix,
        public readonly string $profile,
        public readonly int $videoVersion,
    ) {}

    public function topic(): string
    {
        return 'media-commands';
    }

    public function eventType(): string
    {
        return 'MediaProcessRequested';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'video';
    }

    public function aggregateId(): string
    {
        return $this->videoId;
    }

    public function aggregateVersion(): int
    {
        return $this->videoVersion;
    }

    public function payload(): array
    {
        return [
            'video_id' => $this->videoId,
            'job_id' => $this->jobId,
            'processing_version' => $this->processingVersion,
            'source' => ['bucket' => $this->sourceBucket, 'key' => $this->sourceKey],
            'output' => ['bucket' => $this->outputBucket, 'prefix' => $this->outputPrefix],
            'profile' => $this->profile,
        ];
    }
}
