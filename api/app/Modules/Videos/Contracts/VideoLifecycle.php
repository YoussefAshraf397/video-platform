<?php

namespace App\Modules\Videos\Contracts;

use App\Platform\Api\Errors\ApiProblem;

/**
 * The only way to change a video's status. Each successful transition is one conditional update
 * plus exactly one outbox event, in the same transaction.
 */
interface VideoLifecycle
{
    /**
     * @param  string  $reason  short machine-readable cause, e.g. "upload_completed", "owner_deleted"
     * @param  int|null  $expectedVersion  the version the caller saw (If-Match); null retries on concurrent edits
     * @return int the new state_version
     *
     * @throws IllegalVideoTransition when the current status doesn't allow it
     * @throws ApiProblem 404 if the video doesn't exist, 412 if $expectedVersion is stale
     */
    public function transition(string $videoId, VideoStatus $to, string $reason, ?int $expectedVersion = null): int;
}
