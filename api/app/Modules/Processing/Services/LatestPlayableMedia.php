<?php

namespace App\Modules\Processing\Services;

use App\Modules\Processing\Contracts\PlayableMedia;
use App\Modules\Processing\Contracts\PlayableOutput;
use App\Modules\Processing\Models\ProcessingJob;

final class LatestPlayableMedia implements PlayableMedia
{
    public function current(string $videoId): ?PlayableOutput
    {
        // master_playlist_key is set by the first VideoRenditionReady of a job.
        $job = ProcessingJob::query()->where('video_id', $videoId)->whereNotNull('master_playlist_key')
            ->orderByDesc('processing_version')->first();

        return $job === null ? null : new PlayableOutput($job->processing_version, $job->output_bucket, $job->output_prefix, (string) $job->master_playlist_key);
    }
}
