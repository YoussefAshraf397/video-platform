<?php

namespace App\Modules\Processing\Http\Controllers;

use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Videos\Contracts\VideoDirectory;
use App\Platform\Api\Http\CallerId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /v1/videos/{video}/processing: how the creator's latest processing job is doing. */
final class ProcessingStatusController
{
    public function __construct(private readonly VideoDirectory $videos) {}

    public function show(Request $request, string $video): JsonResponse
    {
        $owned = $this->videos->findOwned($video, CallerId::from($request));
        $job = ProcessingJob::query()->where('video_id', $owned->id)->orderByDesc('processing_version')->first();

        return new JsonResponse([
            'video_status' => $owned->status->value,
            'job' => $job === null ? null : [
                'processing_version' => $job->processing_version,
                'status' => $job->status,
                'renditions' => DB::table('video_variants')->where('processing_job_id', $job->id)->orderBy('height')
                    ->get(['width', 'height', 'frame_rate', 'bitrate_avg'])
                    ->map(fn ($v) => ['width' => (int) $v->width, 'height' => (int) $v->height, 'frame_rate' => (float) $v->frame_rate, 'bitrate' => (int) $v->bitrate_avg])
                    ->all(),
                // The worker's message is for logs and the admin console, not for creators (contract).
                'error' => $job->error_code === null ? null : [
                    'code' => $job->error_code, 'step' => $job->failed_step, 'retryable' => (bool) $job->failure_retryable,
                ],
                'created_at' => $job->created_at?->toIso8601ZuluString(),
                'started_at' => $job->started_at?->toIso8601ZuluString(),
                'finished_at' => $job->finished_at?->toIso8601ZuluString(),
            ],
        ]);
    }
}
