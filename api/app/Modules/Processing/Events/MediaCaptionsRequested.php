<?php

namespace App\Modules\Processing\Events;

use App\Modules\Videos\Contracts\SubtitleTrack;
use App\Platform\Messaging\OutboxEvent;

/**
 * Command to the media worker: publish a processing version's complete set of subtitle tracks as
 * captions revision `captionsRevision` (contract media-captions-requested.v1, S4-06). Same route as
 * MediaProcessRequested: outbox → `media-commands` → `media-process` queue.
 */
final class MediaCaptionsRequested implements OutboxEvent
{
    /** @param  list<SubtitleTrack>  $tracks */
    public function __construct(
        public readonly string $jobId,
        public readonly string $videoId,
        public readonly int $processingVersion,
        public readonly string $outputBucket,
        public readonly string $outputPrefix,
        public readonly string $baseMasterKey,
        public readonly int $captionsRevision,
        public readonly array $tracks,
    ) {}

    public function topic(): string
    {
        return 'media-commands';
    }

    public function eventType(): string
    {
        return 'MediaCaptionsRequested';
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
        return $this->captionsRevision;
    }

    public function payload(): array
    {
        return [
            'video_id' => $this->videoId,
            'job_id' => $this->jobId,
            'processing_version' => $this->processingVersion,
            'output' => ['bucket' => $this->outputBucket, 'prefix' => $this->outputPrefix],
            'base_master_key' => $this->baseMasterKey,
            'captions_revision' => $this->captionsRevision,
            'tracks' => array_map(fn (SubtitleTrack $t) => [
                'subtitle_id' => $t->id, 'language' => $t->language, 'label' => $t->label, 'kind' => $t->kind,
                'format' => $t->format, 'source' => ['bucket' => $t->sourceBucket, 'key' => $t->sourceKey],
            ], $this->tracks),
        ];
    }
}
