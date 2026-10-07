<?php

namespace App\Modules\Videos\Services;

use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Events\VideoStateChanged;
use App\Modules\Videos\Models\Video;
use App\Platform\Api\Http\Preconditions;
use App\Platform\Messaging\OutboxPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Owns videos.status (design doc §12). A transition is a conditional update on the status and
 * version just read, plus one outbox event, in one transaction. If the row changed in between
 * (a metadata edit, another transition), it re-reads and re-checks, so a transition is never
 * applied to a state it wasn't checked against.
 */
final class VideoStateMachine implements VideoLifecycle
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly OutboxPublisher $outbox) {}

    public function transition(string $videoId, VideoStatus $to, string $reason, ?int $expectedVersion = null): int
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $version = DB::transaction(fn () => $this->attempt($videoId, $to, $reason, $expectedVersion));
            if ($version !== null) {
                return $version;
            }
            if ($expectedVersion !== null) {
                throw Preconditions::failed();
            }
        }

        throw new RuntimeException("Video {$videoId}: gave up on {$to->value} after ".self::MAX_ATTEMPTS.' concurrent changes');
    }

    /** @return int|null the new version, or null if the row changed since it was read */
    private function attempt(string $videoId, VideoStatus $to, string $reason, ?int $expectedVersion): ?int
    {
        $video = Video::query()->find($videoId) ?? throw Videos::notFound();
        if ($expectedVersion !== null && $video->state_version !== $expectedVersion) {
            return null;   // stale If-Match: 412 before anything else
        }
        $from = VideoStatus::from($video->status);
        $version = $video->state_version;

        if (! $this->allowed($video, $from, $to)) {
            Log::warning('video.transition_rejected', [
                'video_id' => $videoId, 'from' => $from->value, 'to' => $to->value, 'reason' => $reason,
            ]);
            throw new IllegalVideoTransition($videoId, $from, $to);
        }

        $updated = Video::query()
            ->whereKey($videoId)
            ->where('status', $from->value)
            ->where('state_version', $version)
            ->update([...$this->sideEffects($video, $from, $to, $reason), 'status' => $to->value, 'state_version' => $version + 1, 'updated_at' => now()]);

        if ($updated === 0) {
            return null;
        }

        $this->outbox->publish(new VideoStateChanged($videoId, $video->public_id, $from, $to, $reason, $version + 1));
        Event::dispatch(new VideoTransitioned($videoId, $from, $to));   // in-transaction reactions, e.g. publish_on_ready
        Log::info('video.transitioned', ['video_id' => $videoId, 'from' => $from->value, 'to' => $to->value, 'reason' => $reason]);

        return $version + 1;
    }

    private function allowed(Video $video, VideoStatus $from, VideoStatus $to): bool
    {
        if (! $from->canTransitionTo($to)) {
            return false;
        }

        // Lifting a block returns the video to exactly where it was (§12.5); deleting is always allowed.
        return $from !== VideoStatus::Blocked || $to === VideoStatus::Deleted || $to->value === $video->pre_block_status;
    }

    /** @return array<string, mixed> columns that change along with the status */
    private function sideEffects(Video $video, VideoStatus $from, VideoStatus $to, string $reason): array
    {
        return match (true) {
            $to === VideoStatus::Blocked => ['pre_block_status' => $from->value, 'blocked_reason' => $reason],
            $from === VideoStatus::Blocked => ['pre_block_status' => null, 'blocked_reason' => null]
                + ($to === VideoStatus::Deleted ? ['deleted_at' => now()] : []),
            $to === VideoStatus::Published => ['published_at' => $video->published_at ?? now()],   // first publication date is kept
            $to === VideoStatus::Deleted => ['deleted_at' => now()],
            default => [],
        };
    }
}
