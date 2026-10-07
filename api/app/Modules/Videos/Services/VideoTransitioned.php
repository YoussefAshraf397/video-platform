<?php

namespace App\Modules\Videos\Services;

use App\Modules\Videos\Contracts\VideoStatus;

/**
 * In-process event, internal to the Videos module: dispatched by the state machine inside the
 * transaction of a transition, so a listener's own transition commits (or rolls back) with it.
 * Other modules use the outbox events (Events\VideoStateChanged) instead.
 */
final readonly class VideoTransitioned
{
    public function __construct(
        public string $videoId,
        public VideoStatus $from,
        public VideoStatus $to,
    ) {}
}
