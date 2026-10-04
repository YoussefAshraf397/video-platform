<?php

namespace App\Modules\Videos\Events;

use App\Modules\Videos\Contracts\VideoStatus;
use App\Platform\Messaging\OutboxEvent;

/**
 * Published (topic `video-events`) for every status transition, exactly once per transition.
 *
 * The event type names the transitions other modules react to, so SQS subscriptions can filter on
 * the `event_type` message attribute (e.g. the media dispatcher only wants VideoUploaded). Every
 * other transition is VideoStateChanged. All types share one payload; contract:
 * contracts/schemas/video-state-changed.v1.json.
 */
final class VideoStateChanged implements OutboxEvent
{
    private const NAMED = [
        'uploaded' => 'VideoUploaded',
        'published' => 'VideoPublished',
        'unpublished' => 'VideoUnpublished',
        'blocked' => 'VideoBlocked',
        'deleted' => 'VideoDeleted',
    ];

    public function __construct(
        public readonly string $videoId,
        public readonly string $publicId,
        public readonly VideoStatus $from,
        public readonly VideoStatus $to,
        public readonly string $reason,
        public readonly int $version,
    ) {}

    public function topic(): string
    {
        return 'video-events';
    }

    public function eventType(): string
    {
        return self::NAMED[$this->to->value] ?? 'VideoStateChanged';
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
        return $this->version;
    }

    public function payload(): array
    {
        return [
            'video_id' => $this->videoId,
            'public_id' => $this->publicId,
            'from' => $this->from->value,
            'to' => $this->to->value,
            'reason' => $this->reason,
        ];
    }
}
