<?php

namespace App\Modules\Processing\Services;

use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Videos\Contracts\IllegalVideoTransition;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Modules\Videos\Contracts\VideoLifecycle;
use App\Modules\Videos\Contracts\VideoStatus;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin retry of a failed job (design doc §12.3: PROCESSING_FAILED → QUEUED_FOR_PROCESSING, the
 * source is still retained). Typically after a worker fix, or once whatever made a transient
 * failure exhaust its retries is gone.
 *
 * The retry is a new job at the next processing version, not a rerun of the old one: the worker
 * derives result event ids from the job, so a rerun's results would look like duplicates of the
 * failed run's and be dropped.
 */
final class JobRetries
{
    public function __construct(
        private readonly JobQueue $jobs,
        private readonly VideoLifecycle $lifecycle,
        private readonly VideoDirectory $videos,
    ) {}

    public function retry(string $jobId, string $adminId): ProcessingJob
    {
        return DB::transaction(function () use ($jobId, $adminId) {
            $job = ProcessingJob::query()->whereKey($jobId)->lockForUpdate()->first()
                ?? throw new ApiProblem(404, 'JOB_NOT_FOUND', 'Processing job not found');

            if ($job->status !== 'failed') {
                throw new ApiProblem(409, 'JOB_NOT_FAILED', 'Only a failed job can be retried', "The job is {$job->status}.");
            }
            $latest = (int) ProcessingJob::query()->where('video_id', $job->video_id)->max('processing_version');
            if ($job->processing_version < $latest) {
                throw new ApiProblem(409, 'NOT_LATEST_JOB', 'A newer job exists for this video', "Its processing version is {$latest}.");
            }

            try {
                $videoVersion = $this->lifecycle->transition($job->video_id, VideoStatus::QueuedForProcessing, 'admin_retry');
            } catch (IllegalVideoTransition $e) {
                // e.g. deleted or blocked since, or re-uploaded by the creator
                throw new ApiProblem(409, 'VIDEO_NOT_RETRYABLE', 'The video is not waiting for a retry', "The video is {$e->from->value}.");
            }

            $retry = $this->jobs->queue($job->video_id, $job->upload_session_id, $job->source_bucket, $job->source_key, $videoVersion, retryOf: $job->id);
            Log::notice('processing.retried_by_admin', [
                'admin_id' => $adminId, 'job_id' => $job->id, 'retry_job_id' => $retry->id,
                'error_code' => $job->error_code, 'video_status' => $this->videos->find($job->video_id)?->status->value,
            ]);

            return $retry;
        });
    }
}
