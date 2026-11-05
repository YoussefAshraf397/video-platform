<?php

namespace App\Modules\Videos\Events;

/**
 * In-process event: the set of tracks a video should publish changed (a track finished uploading
 * or was deleted). Dispatched inside the transaction that changed it, so the Processing module's
 * listener requests the new captions revision in the same transaction: the request goes out if
 * and only if the change commits.
 */
final readonly class SubtitlesChanged
{
    public function __construct(public string $videoId) {}
}
