<?php

namespace App\Modules\Processing\Services;

use App\Modules\Processing\Events\MediaCaptionsRequested;
use App\Modules\Processing\Models\ProcessingJob;
use App\Modules\Videos\Contracts\VideoSubtitles;
use App\Modules\Videos\Events\SubtitlesChanged;
use App\Platform\Messaging\OutboxPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a video's playable version's captions in step with its subtitle tracks (S4-06).
 *
 * Every request carries the *whole* current set of tracks under the next captions revision of the
 * job, and the worker writes a new master playlist for it (master.c{n}.m3u8), so requests and
 * results can arrive in any order: the consumer only ever moves to a newer revision.
 *
 * Requests are made when the set changes (SubtitlesChanged) and when a job finishes (captions
 * uploaded before or during processing, and re-attaching them to a reprocessed version). Both
 * paths lock the job row, so a change racing a job's completion is published by one of them.
 */
final class CaptionPublisher
{
    public function __construct(
        private readonly OutboxPublisher $outbox,
        private readonly VideoSubtitles $subtitles,
    ) {}

    /** Listener for the Videos module's in-process event, inside the transaction that changed the set. */
    public function onSubtitlesChanged(SubtitlesChanged $event): void
    {
        DB::transaction(function () use ($event) {
            $job = ProcessingJob::query()->where('video_id', $event->videoId)
                ->orderByDesc('processing_version')->lockForUpdate()->first();
            if ($job !== null) {
                $this->publish($job);
            }
        });
    }

    /**
     * Requests the next revision for a job that is locked in the caller's transaction. Nothing to do
     * until the job's output is finished (its base master is final), or if it never had and doesn't
     * need captions.
     */
    public function publish(ProcessingJob $job): void
    {
        if (! in_array($job->status, ProcessingJob::FINISHED_OUTPUT, true) || $job->master_playlist_key === null) {
            return;   // the job's completion will publish them
        }
        $tracks = $this->subtitles->publishable($job->video_id);
        if ($tracks === [] && $job->captions_revision === 0) {
            return;
        }

        $revision = $job->captions_revision + 1;
        $job->update(['captions_revision' => $revision]);
        $this->outbox->publish(new MediaCaptionsRequested(
            $job->id, $job->video_id, $job->processing_version, $job->output_bucket, $job->output_prefix,
            $job->master_playlist_key, $revision, $tracks,
        ));
        Log::info('captions.requested', ['video_id' => $job->video_id, 'job_id' => $job->id, 'captions_revision' => $revision, 'tracks' => count($tracks)]);
    }
}
