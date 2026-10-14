<?php

namespace App\Modules\Playback\Services;

use App\Modules\Videos\Contracts\VideoAccessPolicy;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Platform\Api\Errors\ApiProblem;

/**
 * Who may play a video (design doc §13.2): a pure function of the video's policy and the viewer.
 * Anything a viewer isn't allowed to know exists is a plain 404.
 */
final class AccessRules
{
    /** Statuses the owner can preview before (or after) publishing: there's something playable. */
    private const PREVIEWABLE = [VideoStatus::Ready, VideoStatus::Unpublished];

    /** @throws ApiProblem when the viewer may not play the video */
    public function authorize(?VideoAccessPolicy $video, ?string $viewerId): VideoAccessPolicy
    {
        if ($video === null) {
            throw self::notFound();
        }
        $isOwner = $viewerId !== null && $viewerId === $video->ownerId;

        if (in_array($video->status, [VideoStatus::Deleted, VideoStatus::Purged], true)) {
            throw self::notFound();
        }
        if (in_array($video->status, [VideoStatus::Blocked, VideoStatus::Rejected], true) || $video->moderationStatus === 'blocked') {
            // The owner is told why (and can appeal); everyone else sees nothing.
            throw $isOwner ? new ApiProblem(403, 'VIDEO_BLOCKED', 'This video was removed by moderation') : self::notFound();
        }

        if ($video->status === VideoStatus::Published) {
            if ($video->visibility === 'private' && ! $isOwner) {
                throw self::notFound();   // unlisted and public: anyone with the link
            }
        } elseif (! $isOwner) {
            throw self::notFound();
        } elseif (! in_array($video->status, self::PREVIEWABLE, true)) {
            throw new ApiProblem(409, 'VIDEO_NOT_PLAYABLE', 'This video cannot be played yet', "The video is {$video->status->value}.");
        }

        // Age-gated videos need a viewer with a verified age of 18+. Age verification doesn't
        // exist yet, so until it does only the owner can play them.
        if (($video->ageRestricted || $video->moderationStatus === 'age_restricted') && ! $isOwner) {
            throw new ApiProblem(403, 'AGE_RESTRICTED', 'This video is age-restricted',
                $viewerId === null ? 'Sign in to an account with a verified age.' : 'Your account has no verified age.');
        }

        return $video;
    }

    private static function notFound(): ApiProblem
    {
        return new ApiProblem(404, 'VIDEO_NOT_FOUND', 'Video not found');
    }
}
