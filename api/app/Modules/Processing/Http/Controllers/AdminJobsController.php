<?php

namespace App\Modules\Processing\Http\Controllers;

use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Processing\Services\JobRetries;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Platform\Api\Http\CallerId;
use App\Platform\Api\Pagination\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin API (/admin/v1, admins only): processing jobs that need a human. */
final class AdminJobsController
{
    public function __construct(private readonly VideoDirectory $videos) {}

    /** Jobs by status (default: failed), newest first, cursor-paginated. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate([
            'status' => ['sometimes', Rule::in(['queued', 'running', 'succeeded', 'partially_succeeded', 'failed', 'cancelled'])],
        ])['status'] ?? 'failed';

        $query = ProcessingJob::query()->where('status', $status)->orderByDesc('created_at')->orderByDesc('id');

        return CursorPage::response(CursorPage::paginate($query, $request), fn (array $jobs) => array_map(fn (ProcessingJob $j) => $this->present($j), $jobs));
    }

    /**
     * Retries a failed job as a new job (next processing version). The video goes back to
     * QUEUED_FOR_PROCESSING and the worker gets the source again.
     */
    public function retry(Request $request, string $job, JobRetries $retries): JsonResponse
    {
        $retry = $retries->retry($job, CallerId::from($request));

        return new JsonResponse($this->present($retry), 201);
    }

    /** @return array<string, mixed> */
    private function present(ProcessingJob $job): array
    {
        $video = $this->videos->find($job->video_id);

        return [
            'id' => $job->id,
            'video' => ['id' => $video?->publicId, 'status' => $video?->status->value],
            'processing_version' => $job->processing_version,
            'status' => $job->status,
            'retry_of' => $job->retry_of_job_id,
            // Admins see the worker's own message (creators don't, per the contract).
            'error' => $job->error_code === null ? null : [
                'code' => $job->error_code, 'step' => $job->failed_step, 'retryable' => (bool) $job->failure_retryable, 'message' => $job->error_detail,
            ],
            'source' => ['bucket' => $job->source_bucket, 'key' => $job->source_key],
            'created_at' => $job->created_at?->toIso8601ZuluString(),
            'finished_at' => $job->finished_at?->toIso8601ZuluString(),
        ];
    }
}
