<?php

namespace App\Modules\Videos\Services;

use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Models\Video;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Http\Preconditions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publishing and unpublishing (design doc §12.4). Only this module decides publication: the
 * owner through the API, or `publish_on_ready` when processing makes the video READY.
 */
final class Publication
{
    public function __construct(private readonly VideoLifecycle $lifecycle) {}

    /**
     * Publishes the video (optionally setting its visibility first, in the same transaction).
     * Publishing a published video is a no-op.
     *
     * @throws ApiProblem 409 NOT_PUBLISHABLE listing every unmet guard, 412 on a stale If-Match
     */
    public function publish(Video $video, ?string $visibility, ?int $expectedVersion, string $reason = 'owner_published'): void
    {
        if ($video->status === VideoStatus::Published->value && ($visibility === null || $visibility === $video->visibility)) {
            return;
        }
        if ($problems = $this->unmetGuards($video)) {
            throw new ApiProblem(409, 'NOT_PUBLISHABLE', 'The video cannot be published yet', errors: $problems);
        }

        DB::transaction(function () use ($video, $visibility, $expectedVersion, $reason) {
            if ($visibility !== null && $visibility !== $video->visibility) {
                $updated = Video::query()->whereKey($video->id)
                    ->when($expectedVersion !== null, fn ($q) => $q->where('state_version', $expectedVersion))
                    ->update(['visibility' => $visibility, 'state_version' => DB::raw('state_version + 1'), 'updated_at' => now()]);
                if ($updated === 0) {
                    throw Preconditions::failed();
                }
                $expectedVersion = $expectedVersion === null ? null : $expectedVersion + 1;
            }
            if ($video->status !== VideoStatus::Published->value) {
                $this->transition($video, VideoStatus::Published, $reason, $expectedVersion);
            }
        });
    }

    /** Takes a published video down (it stays UNPUBLISHED until republished). A no-op if it isn't published. */
    public function unpublish(Video $video, ?int $expectedVersion): void
    {
        if ($video->status === VideoStatus::Unpublished->value) {
            return;
        }
        if ($video->status !== VideoStatus::Published->value) {
            throw new ApiProblem(409, 'NOT_PUBLISHED', 'Only a published video can be unpublished', "The video is {$video->status}.");
        }
        $this->transition($video, VideoStatus::Unpublished, 'owner_unpublished', $expectedVersion);
    }

    /** `publish_on_ready`: runs inside the transition to READY; a video that can't be published just stays READY. */
    public function publishOnReady(VideoTransitioned $event): void
    {
        if ($event->to !== VideoStatus::Ready) {
            return;
        }
        $video = Video::query()->find($event->videoId);
        if ($video === null || ! $video->publish_on_ready) {
            return;
        }
        if ($problems = $this->unmetGuards($video)) {
            Log::info('video.publish_on_ready_skipped', ['video_id' => $video->id, 'unmet' => array_column($problems, 'code')]);

            return;
        }
        $this->transition($video, VideoStatus::Published, 'publish_on_ready', null);
    }

    /**
     * Every reason the video can't be published now, so the client can show them all at once.
     *
     * @return list<array{field: string, code: string, message: string}>
     */
    public function unmetGuards(Video $video): array
    {
        $problems = [];
        if (! in_array($video->status, [VideoStatus::Ready->value, VideoStatus::Unpublished->value, VideoStatus::Published->value], true)) {
            $problems[] = ['field' => 'status', 'code' => 'NOT_READY', 'message' => "The video is {$video->status}; it can be published once processing has finished."];
        }
        if (trim($video->title) === '') {
            $problems[] = ['field' => 'title', 'code' => 'TITLE_REQUIRED', 'message' => 'Give the video a title first.'];
        }
        if ($video->moderation_status === 'blocked') {
            $problems[] = ['field' => 'moderation_status', 'code' => 'BLOCKED', 'message' => 'The video was blocked by moderation.'];
        }

        return $problems;
    }

    private function transition(Video $video, VideoStatus $to, string $reason, ?int $expectedVersion): void
    {
        try {
            $this->lifecycle->transition($video->id, $to, $reason, $expectedVersion);
        } catch (IllegalVideoTransition $e) {
            // Changed state between the guard check and the update (e.g. deleted or blocked meanwhile).
            throw new ApiProblem(409, 'NOT_PUBLISHABLE', 'The video cannot be published right now', "The video is {$e->from->value}.");
        }
    }
}
