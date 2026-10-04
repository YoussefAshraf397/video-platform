<?php

namespace App\Platform\Messaging;

/**
 * A domain event to publish through the transactional outbox. Modules implement this in their
 * public `Events` namespace (e.g. App\Modules\Videos\Events\VideoUploaded).
 */
interface OutboxEvent
{
    /** SNS topic name, e.g. "video-events". */
    public function topic(): string;

    /** Past-tense event name, e.g. "VideoUploaded". */
    public function eventType(): string;

    /** Major version of the payload schema in contracts/. */
    public function schemaVersion(): int;

    public function aggregateType(): string;

    public function aggregateId(): string;

    /** Increases with every change to the aggregate, so consumers can drop stale events. */
    public function aggregateVersion(): int;

    /** @return array<string, mixed> IDs and changed fields only; consumers re-read full state. */
    public function payload(): array;
}
