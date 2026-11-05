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

        if ($job === null) {
            return null;
        }

        // With captions published, the newest captions revision's master (the base one plus the tracks).
        return new PlayableOutput($job->processing_version, $job->output_bucket, $job->output_prefix,
            $job->captions_master_key ?? (string) $job->master_playlist_key, $job->captions ?? []);
    }
}
