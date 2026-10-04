<?php

namespace App\Modules\Uploads\Services;

use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Upload sessions (design doc §10, ADR-003): one S3 multipart upload per session, at most one live
 * session per video. Session status and video status always change in the same transaction.
 *
 * The object key is always built here from the video and session ids; nothing the client sends
 * ends up in it.
 */
final class UploadSessions
{
    private const MIB = 1024 * 1024;

    /** S3 allows 10,000 parts; 9,000 leaves headroom (ADR-003). */
    private const TARGET_MAX_PARTS = 9000;

    public function __construct(
        private readonly ObjectStore $store,
        private readonly VideoDirectory $videos,
        private readonly VideoLifecycle $lifecycle,
        private readonly UserDirectory $users,
    ) {}

    /** Part size = max(8 MiB, size / 9000) rounded up to a whole MiB. */
    public static function partSizeFor(int $sizeBytes): int
    {
        return max(8 * self::MIB, (int) ceil($sizeBytes / self::TARGET_MAX_PARTS / self::MIB) * self::MIB);
    }

    public function create(string $callerId, string $videoPublicId, int $sizeBytes, string $contentType, ?string $sha256): UploadSession
    {
        $video = $this->videos->findOwned($videoPublicId, $callerId);

        $user = $this->users->find($callerId);
        if ($user === null || ! $user->isActive()) {
            throw new ApiProblem(403, 'ACCOUNT_DISABLED', 'This account cannot upload');
        }
        if (! $user->isEmailVerified()) {
            throw new ApiProblem(403, 'EMAIL_NOT_VERIFIED', 'Verify your email address before uploading');
        }

        $live = UploadSession::query()->where('video_id', $video->id)->whereIn('status', ['initiated', 'in_progress', 'completing'])->first();
        if ($live !== null && $live->isActive() && $live->isExpired()) {
            $this->expire($live);
            $live = null;
        }
        if ($live !== null) {
            throw self::uploadInProgress($live->id);
        }

        $status = $this->videos->find($video->id)?->status;
        if (! in_array($status, [VideoStatus::Draft, VideoStatus::UploadFailed], true)) {
            throw self::notUploadable($status);
        }

        $this->enforceDailyQuota($callerId);

        $sessionId = (string) Str::uuid7();
        $bucket = (string) config('uploads.bucket');
        $key = "uploads/{$video->id}/{$sessionId}/source";
        $partSize = self::partSizeFor($sizeBytes);
        $uploadId = $this->store->createMultipartUpload($bucket, $key, $contentType);

        try {
            return DB::transaction(function () use ($sessionId, $video, $callerId, $bucket, $key, $uploadId, $sizeBytes, $contentType, $sha256, $partSize) {
                $session = UploadSession::query()->create([
                    'id' => $sessionId,
                    'video_id' => $video->id,
                    'user_id' => $callerId,
                    'bucket' => $bucket,
                    'object_key' => $key,
                    's3_upload_id' => $uploadId,
                    'declared_size_bytes' => $sizeBytes,
                    'content_type' => $contentType,
                    'declared_sha256' => $sha256,
                    'part_size_bytes' => $partSize,
                    'total_parts' => (int) ceil($sizeBytes / $partSize),
                    'status' => 'initiated',
                    'expires_at' => now()->addSeconds((int) config('uploads.session_ttl_seconds')),
                ]);
                $this->lifecycle->transition($video->id, VideoStatus::UploadPending, 'upload_session_created');

                return $session;
            });
        } catch (Throwable $e) {
            $this->abortQuietly($bucket, $key, $uploadId);

            throw match (true) {
                $e instanceof UniqueConstraintViolationException => self::uploadInProgress(null),   // a concurrent create won
                $e instanceof IllegalVideoTransition => self::notUploadable($e->from),
                default => $e,
            };
        }
    }

    /**
     * Presigned URLs for the given parts. The first URL request marks the upload as started
     * (session in_progress, video uploading).
     *
     * @param  list<int>  $partNumbers
     * @return list<array{part_number: int, size_bytes: int, url: string, expires_at: string}>
     */
    public function sign(string $callerId, string $sessionId, array $partNumbers): array
    {
        $session = $this->activeSession($callerId, $sessionId);

        foreach ($partNumbers as $i => $n) {
            if ($n > $session->total_parts) {
                throw new ApiProblem(422, 'VALIDATION_FAILED', 'The request is invalid', errors: [
                    ['field' => "part_numbers.{$i}", 'code' => 'MAX', 'message' => "This upload has {$session->total_parts} parts."],
                ]);
            }
        }

        $this->touch($session, started: true);

        return $this->urls($session, $partNumbers);
    }

    /**
     * Status, the parts S3 already has, and fresh URLs for the next missing parts (resume, §10.4).
     *
     * @return array{session: UploadSession, uploaded_parts: list<array{part_number: int, size_bytes: int, etag: string}>, next_parts: list<array{part_number: int, size_bytes: int, url: string, expires_at: string}>}
     */
    public function status(string $callerId, string $sessionId): array
    {
        $session = $this->ownedSession($callerId, $sessionId);
        if ($session->isActive() && $session->isExpired()) {
            $this->expire($session);
            $session->refresh();
        }
        if (! $session->isActive()) {
            return ['session' => $session, 'uploaded_parts' => [], 'next_parts' => []];
        }

        $uploaded = $this->store->listParts($session->bucket, $session->object_key, $session->s3_upload_id);
        $this->touch($session, started: $uploaded !== []);

        $have = array_flip(array_column($uploaded, 'part_number'));
        $missing = [];
        for ($n = 1; $n <= $session->total_parts && count($missing) < (int) config('uploads.url_batch_size'); $n++) {
            if (! isset($have[$n])) {
                $missing[] = $n;
            }
        }

        return ['session' => $session, 'uploaded_parts' => $uploaded, 'next_parts' => $this->urls($session, $missing)];
    }

    /** Cancel: the session becomes aborted and the video upload_failed, ready for a new session. */
    public function abort(string $callerId, string $sessionId): void
    {
        $session = $this->ownedSession($callerId, $sessionId);
        if (! $session->isActive()) {
            throw self::notActive($session);
        }

        $this->end($session, 'aborted', 'upload_aborted') || throw self::notActive($session->refresh());
    }

    /** Ends a live session whose time is up. Also used by the expiry sweeper (S3-05). */
    public function expire(UploadSession $session): bool
    {
        return $this->end($session, 'expired', 'upload_expired');
    }

    /**
     * @param  list<int>  $partNumbers
     * @return list<array{part_number: int, size_bytes: int, url: string, expires_at: string}>
     */
    public function urls(UploadSession $session, array $partNumbers): array
    {
        $ttl = (int) config('uploads.url_ttl_seconds');

        return array_map(function (int $n) use ($session, $ttl) {
            $signed = $this->store->presignUploadPart($session->bucket, $session->object_key, $session->s3_upload_id, $n, $ttl);

            return ['part_number' => $n, 'size_bytes' => $session->partSize($n), 'url' => $signed['url'], 'expires_at' => $signed['expires_at']->toIso8601ZuluString()];
        }, $partNumbers);
    }

    public function ownedSession(string $callerId, string $sessionId): UploadSession
    {
        $session = Str::isUuid($sessionId) ? UploadSession::query()->find($sessionId) : null;

        // Someone else's session looks like no session at all.
        if ($session === null || $session->user_id !== $callerId) {
            throw new ApiProblem(404, 'UPLOAD_NOT_FOUND', 'Upload not found');
        }

        return $session;
    }

    private function activeSession(string $callerId, string $sessionId): UploadSession
    {
        $session = $this->ownedSession($callerId, $sessionId);
        if ($session->isActive() && $session->isExpired()) {
            $this->expire($session);
            throw new ApiProblem(410, 'UPLOAD_EXPIRED', 'This upload has expired', 'Start a new upload for the video.');
        }
        if (! $session->isActive()) {
            throw self::notActive($session);
        }

        return $session;
    }

    /** Slides the expiry forward; on the first sign of progress, marks the session and the video as uploading. */
    private function touch(UploadSession $session, bool $started): void
    {
        DB::transaction(function () use ($session, $started) {
            $expiresAt = now()->addSeconds((int) config('uploads.session_ttl_seconds'));
            $start = $started && $session->status === 'initiated';

            $updated = UploadSession::query()->whereKey($session->id)->whereIn('status', UploadSession::ACTIVE)
                ->update(['expires_at' => $expiresAt, 'updated_at' => now()] + ($start ? ['status' => 'in_progress'] : []));
            if ($updated === 0) {
                throw self::notActive($session->refresh());
            }

            if ($start) {
                try {
                    $this->lifecycle->transition($session->video_id, VideoStatus::Uploading, 'upload_started');
                } catch (IllegalVideoTransition $e) {
                    throw self::notUploadable($e->from);   // e.g. the video was deleted meanwhile
                }
            }
        });
        $session->refresh();
    }

    /** Moves a live session to a final status and the video to upload_failed, then aborts in S3. */
    private function end(UploadSession $session, string $status, string $reason): bool
    {
        $ended = DB::transaction(function () use ($session, $status, $reason) {
            $updated = UploadSession::query()->whereKey($session->id)->whereIn('status', UploadSession::ACTIVE)
                ->update(['status' => $status, 'failure_reason' => $reason, 'updated_at' => now()]);
            if ($updated === 0) {
                return false;
            }

            try {
                $this->lifecycle->transition($session->video_id, VideoStatus::UploadFailed, $reason);
            } catch (IllegalVideoTransition) {
                // The video moved on without this session (e.g. deleted); the session still ends.
            }

            return true;
        });

        if ($ended) {
            // After commit, so a slow or failing S3 call never holds the transaction. The bucket's
            // lifecycle rule aborts anything left behind after 7 days.
            $this->abortQuietly($session->bucket, $session->object_key, $session->s3_upload_id);
        }

        return $ended;
    }

    private function enforceDailyQuota(string $callerId): void
    {
        $quota = (int) config('uploads.daily_session_quota');
        $window = UploadSession::query()->where('user_id', $callerId)->where('created_at', '>', now()->subDay());

        if ((clone $window)->count() >= $quota) {
            $oldest = (clone $window)->min('created_at');
            $retryAfter = max(1, (int) ceil(now()->diffInSeconds(now()->parse((string) $oldest)->addDay())));

            throw new ApiProblem(429, 'UPLOAD_QUOTA_EXCEEDED', 'Daily upload limit reached',
                "You can start {$quota} uploads per 24 hours.", headers: ['Retry-After' => (string) $retryAfter]);
        }
    }

    private function abortQuietly(string $bucket, string $key, string $uploadId): void
    {
        try {
            $this->store->abortMultipartUpload($bucket, $key, $uploadId);
        } catch (Throwable $e) {
            Log::warning('upload.abort_failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private static function uploadInProgress(?string $sessionId): ApiProblem
    {
        return new ApiProblem(409, 'UPLOAD_IN_PROGRESS', 'This video already has an upload in progress',
            $sessionId ? "Resume upload {$sessionId} with GET /v1/uploads/{$sessionId}, or cancel it." : null);
    }

    private static function notUploadable(?VideoStatus $status): ApiProblem
    {
        return new ApiProblem(409, 'VIDEO_NOT_UPLOADABLE', 'This video cannot receive an upload',
            $status ? "The video is {$status->value}." : null);
    }

    private static function notActive(UploadSession $session): ApiProblem
    {
        return new ApiProblem(409, 'UPLOAD_NOT_ACTIVE', 'This upload is no longer active', "The upload is {$session->status}.");
    }
}
