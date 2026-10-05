<?php

namespace App\Modules\Uploads\Services;

use App\Modules\Uploads\Models\UploadSession;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cleans up after clients that went away (design doc §10.5), every 15 minutes:
 *
 * - live sessions past `expires_at` → `expired` (video → upload_failed, S3 upload aborted);
 * - sessions stuck in `completing` (the completing call crashed) → finished from what S3 holds,
 *   or handed back so they can expire normally.
 *
 * Each session is handled on its own, so one failure (e.g. S3 hiccup) doesn't stop the rest; it
 * is simply picked up again on the next run.
 */
final class UploadSweeper
{
    public function __construct(
        private readonly UploadSessions $sessions,
        private readonly UploadCompletion $completion,
    ) {}

    /** @return array{expired: int, completed: int, released: int, failed: int, errors: int} */
    public function sweep(int $limit = 500): array
    {
        $counts = ['expired' => 0, 'completed' => 0, 'released' => 0, 'failed' => 0, 'errors' => 0];

        $expired = UploadSession::query()->whereIn('status', UploadSession::ACTIVE)->where('expires_at', '<', now())
            ->orderBy('expires_at')->limit($limit)->get();
        foreach ($expired as $session) {
            try {
                $counts['expired'] += $this->sessions->expire($session) ? 1 : 0;
            } catch (Throwable $e) {
                $counts['errors']++;
                Log::error('upload.sweep_failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);
            }
        }

        $stuck = UploadSession::query()->where('status', 'completing')
            ->where('updated_at', '<', now()->subSeconds(UploadCompletion::STALE_CLAIM_SECONDS))
            ->orderBy('updated_at')->limit($limit)->get();
        foreach ($stuck as $session) {
            try {
                $this->completion->reconcile($session);
                $counts['completed']++;
            } catch (ApiProblem $p) {
                // PARTS_MISSING hands the session back (it expires later); UPLOAD_FAILED ended it.
                $counts[$p->errorCode === 'UPLOAD_FAILED' ? 'failed' : 'released']++;
                Log::info('upload.sweep_reconcile', ['session_id' => $session->id, 'outcome' => $p->errorCode]);
            } catch (Throwable $e) {
                $counts['errors']++;
                Log::error('upload.sweep_failed', ['session_id' => $session->id, 'error' => $e->getMessage()]);
            }
        }

        return $counts;
    }
}
