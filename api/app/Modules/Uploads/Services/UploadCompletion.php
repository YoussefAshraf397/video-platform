<?php

namespace App\Modules\Uploads\Services;

use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Platform\Api\Errors\ApiProblem;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /v1/uploads/{id}:complete` (design doc §10.2, §10.8). Safe to call any number of times,
 * concurrently or after a crash; exactly one call completes the upload and emits VideoUploaded.
 *
 * 1. Claim: one conditional update moves the session to `completing`. A concurrent call finds it
 *    already claimed (409) or done (200, same result). A claim left by a crashed call can be taken
 *    over after STALE_CLAIM_SECONDS.
 * 2. Verify against S3: every part present, the client's ETags match S3's, every part the planned
 *    size. A missing part hands the session back so the client can upload it; a wrong ETag or size
 *    fails the session (the object can't be the file that was declared).
 * 3. Complete in S3, then check the final object's size against the declared size.
 * 4. One transaction: session `completed`, video `uploaded` (+ VideoUploaded via the outbox).
 */
final class UploadCompletion
{
    private const STALE_CLAIM_SECONDS = 120;

    public function __construct(
        private readonly UploadSessions $sessions,
        private readonly ObjectStore $store,
        private readonly VideoDirectory $videos,
        private readonly VideoLifecycle $lifecycle,
    ) {}

    /** @param  list<array{part_number: int, etag: string}>  $clientParts  the client's record of what it uploaded */
    public function complete(string $callerId, string $sessionId, array $clientParts): UploadSession
    {
        $session = $this->sessions->ownedSession($callerId, $sessionId);
        if ($session->status === 'completed') {
            return $session;   // a retry after success
        }
        if ($session->isActive() && $session->isExpired()) {
            $this->sessions->expire($session);
            throw new ApiProblem(410, 'UPLOAD_EXPIRED', 'This upload has expired', 'Start a new upload for the video.');
        }
        $clientEtags = $this->clientEtags($session, $clientParts);

        $previousStatus = $session->status;
        if (! $this->claim($session)) {
            $session->refresh();

            return match ($session->status) {
                'completed' => $session,
                'completing' => throw new ApiProblem(409, 'UPLOAD_COMPLETING', 'This upload is already being completed', 'Retry in a few seconds.'),
                default => throw UploadSessions::notActive($session),
            };
        }

        try {
            $this->assemble($session, $clientEtags, $previousStatus);
        } catch (Throwable $e) {
            if (! $e instanceof ApiProblem || $e->status >= 500) {
                $this->release($session, $previousStatus);   // unexpected (e.g. S3 unavailable): let the client retry
            }
            throw $e;
        }

        return $this->finish($session);
    }

    /**
     * The client must list each part 1..total_parts exactly once.
     *
     * @param  list<array{part_number: int, etag: string}>  $clientParts
     * @return array<int, string> normalized ETag by part number
     */
    private function clientEtags(UploadSession $session, array $clientParts): array
    {
        $etags = [];
        foreach ($clientParts as $part) {
            $etags[$part['part_number']] = self::normalizeEtag($part['etag']);
        }
        ksort($etags);

        if (count($etags) !== count($clientParts) || array_keys($etags) !== range(1, $session->total_parts)) {
            throw new ApiProblem(422, 'INVALID_PART_LIST', 'The part list is incomplete',
                "List every part from 1 to {$session->total_parts} exactly once, with the ETag S3 returned for it.");
        }

        return $etags;
    }

    private function claim(UploadSession $session): bool
    {
        return UploadSession::query()->whereKey($session->id)
            ->where(fn ($q) => $q->whereIn('status', UploadSession::ACTIVE)
                ->orWhere(fn ($q) => $q->where('status', 'completing')->where('updated_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS))))
            ->update(['status' => 'completing', 'updated_at' => now()]) === 1;
    }

    private function release(UploadSession $session, string $status): void
    {
        UploadSession::query()->whereKey($session->id)->where('status', 'completing')
            ->update(['status' => $status === 'completing' ? 'in_progress' : $status, 'updated_at' => now()]);
    }

    /** @param  array<int, string>  $clientEtags */
    private function assemble(UploadSession $session, array $clientEtags, string $previousStatus): void
    {
        try {
            $s3Parts = $this->store->listParts($session->bucket, $session->object_key, $session->s3_upload_id);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $e;
            }
            $s3Parts = null;   // an earlier attempt completed it in S3 and then crashed; verify the object below
        }

        if ($s3Parts !== null) {
            $this->verifyParts($session, $s3Parts, $clientEtags, $previousStatus);
            $this->store->completeMultipartUpload($session->bucket, $session->object_key, $session->s3_upload_id,
                array_values(array_filter($s3Parts, fn (array $p) => $p['part_number'] <= $session->total_parts)));
        }

        $object = $this->store->head($session->bucket, $session->object_key);
        if ($object === null) {
            $this->fail($session, 'upload_missing', 'S3 has neither the upload nor the object.');
        }
        if ($object['size_bytes'] !== $session->declared_size_bytes) {
            $this->store->delete($session->bucket, $session->object_key);
            $this->fail($session, 'upload_size_mismatch', "The file is {$object['size_bytes']} bytes, not the declared {$session->declared_size_bytes}.");
        }
    }

    /**
     * @param  list<array{part_number: int, size_bytes: int, etag: string}>  $s3Parts
     * @param  array<int, string>  $clientEtags
     */
    private function verifyParts(UploadSession $session, array $s3Parts, array $clientEtags, string $previousStatus): void
    {
        $byNumber = array_column($s3Parts, null, 'part_number');

        $missing = array_values(array_diff(array_keys($clientEtags), array_keys($byNumber)));
        if ($missing !== []) {
            $this->release($session, $previousStatus);
            throw new ApiProblem(409, 'PARTS_MISSING', 'Some parts have not been uploaded',
                'Missing parts: '.implode(', ', array_slice($missing, 0, 20)).(count($missing) > 20 ? ', …' : '').'. Upload them and complete again.');
        }

        foreach ($clientEtags as $n => $etag) {
            if (self::normalizeEtag($byNumber[$n]['etag']) !== $etag) {
                $this->fail($session, 'upload_etag_mismatch', "Part {$n} in S3 is not the part the client uploaded.");
            }
            if ($byNumber[$n]['size_bytes'] !== $session->partSize($n)) {
                $this->fail($session, 'upload_size_mismatch', "Part {$n} is {$byNumber[$n]['size_bytes']} bytes, expected {$session->partSize($n)}.");
            }
        }
    }

    /** Ends the session as failed (video → upload_failed, S3 upload aborted). The client starts a new upload. */
    private function fail(UploadSession $session, string $reason, string $detail): never
    {
        $this->sessions->end($session, 'failed', $reason, from: ['completing']);
        Log::warning('upload.completion_failed', ['session_id' => $session->id, 'reason' => $reason, 'detail' => $detail]);

        throw new ApiProblem(422, 'UPLOAD_FAILED', 'The upload does not match what was declared', "{$reason}: {$detail} Start a new upload.");
    }

    private function finish(UploadSession $session): UploadSession
    {
        DB::transaction(function () use ($session) {
            $updated = UploadSession::query()->whereKey($session->id)->where('status', 'completing')
                ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            if ($updated === 0) {
                return;   // a takeover finished first
            }

            try {
                if ($this->videos->find($session->video_id)?->status === VideoStatus::UploadPending) {
                    $this->lifecycle->transition($session->video_id, VideoStatus::Uploading, 'upload_started');   // completed without asking for more URLs
                }
                $this->lifecycle->transition($session->video_id, VideoStatus::Uploaded, 'upload_completed');
            } catch (IllegalVideoTransition $e) {
                // The video was deleted or blocked meanwhile: the upload is done, but the video doesn't move (§12.3).
                Log::warning('upload.completed_for_inactive_video', ['session_id' => $session->id, 'video_status' => $e->from->value]);
            }
        });

        return $session->refresh();
    }

    private static function normalizeEtag(string $etag): string
    {
        return strtolower(trim($etag, ' "'));
    }
}
