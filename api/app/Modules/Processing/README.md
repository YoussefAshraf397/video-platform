# Processing module

Turns completed uploads into work for the Go media worker, and the worker's results into video state (design doc §11). Owns `video_processing_jobs`, `video_variants` and `video_assets`. Uses Videos through `VideoLifecycle`, `VideoDirectory` and `VideoMedia`, and Uploads through `UploadedSources`.

## Dispatcher (S3-06)

`Consumers\MediaDispatcher` (`php artisan messages:consume media-dispatcher`) reads `VideoUploaded` from the `media-dispatcher` queue. That queue is subscribed to `video-events` with a filter policy, so only `VideoUploaded` arrives, and the code ignores anything else anyway. In one transaction (opened by `IdempotentConsumer`), it:

1. moves the video `uploaded → validating` (reason `processing_job_created`);
2. creates a `video_processing_jobs` row: `queued`, profile `h264-sdr-v1`, `processing_version` = the video's previous highest + 1, output prefix `media/{video_id}/v{n}/`;
3. publishes `MediaProcessRequested` (contract `media-process-requested.v1`) through the outbox to the `media-commands` topic, which feeds the `media-process` queue the worker reads.

The command is sent if and only if the job row commits, and the worker never sees a job the database doesn't have.

**Duplicate `VideoUploaded` → one job:**

- The same event delivered again is skipped by the `(consumer, event_id)` marker.
- A different event for the same upload finds the video no longer `uploaded`, and the state machine rejects the transition, so nothing is created.
- The unique `(upload_session_id, profile)` index is the last line of defence.

Tested for both kinds of duplicate. Deliberately removing the state-machine guard makes the index reject the second job.

A video deleted or blocked before dispatch isn't processed, and the message is consumed rather than retried. A later upload of the same video gets the next `processing_version`, so its output never overwrites an earlier one.

**Proven end to end on the local stack:** complete an upload → `outbox:relay` → `video-events` → filtered `media-dispatcher` queue → `messages:consume media-dispatcher` → job v1, video `validating` → `outbox:relay` → `media-commands` → one message on `media-process` that validates against the contract the Go worker uses. All queues and DLQs empty afterwards.

## Results (S4-01)

`Consumers\MediaResultsConsumer` (`php artisan messages:consume media-results`) applies the worker's results:

| Result | Effect |
|---|---|
| `VideoRenditionReady` | Upserts the rendition into `video_variants` and stores the job's master playlist. The **first** one makes the video `ready`, stepping through the states the worker doesn't report: `validating → queued_for_processing → processing → ready`. Job → `running`. |
| `VideoProcessingCompleted` | Upserts every rendition. Job → `succeeded`. Video → `ready` if it wasn't yet. Through `VideoMedia`, records the duration and source size, plus the thumbnail candidates, with the frame nearest the middle as primary. |
| `VideoProcessingFailed` | Job → `failed` with the code, step and retryable flag. Video → `processing_failed` with the code as the reason (e.g. `not_a_video`). If a rendition was already playable, the job is `partially_succeeded` instead and the video stays `ready`. |

Every result also records the job's source and output prefix in `video_assets`, so the purge saga knows what to delete.

**Duplicates and ordering.** The worker's event ids are deterministic, so `IdempotentConsumer` skips redeliveries and the repeats of a rerun job. SQS doesn't preserve order, so:

- every write is an upsert;
- states only move forward;
- a failure never un-completes a job, but a completion after a failure of the same job wins;
- results whose processing version (`aggregate_version`) is older than the video's latest job are dropped;
- a job's results are applied one at a time (row lock on the job).

Results for a video that was deleted or blocked meanwhile are recorded, but don't move it (§12.3).

**`GET /v1/videos/{id}/processing`** (owner only):

```json
{"video_status": "ready", "job": {"processing_version": 1, "status": "partially_succeeded",
  "renditions": [{"width": 640, "height": 360, "frame_rate": 30, "bitrate": 800000}],
  "error": {"code": "ENCODER_FAILED", "step": "transcode", "retryable": true},
  "created_at": "…", "started_at": "…", "finished_at": "…"}}
```

The worker's error message isn't shown; the contract reserves it for logs and the admin console.

**Proof.**
- 17 tests built from contract-valid messages, covering every row above, every duplicate/ordering case, and the endpoint.
- Three deliberate breakages were caught: no stale-version check, a failure overriding a completion, and a failure ignoring playable renditions.
- A real run: upload through Laravel → dispatcher → Go worker → this consumer. The video went `… → validating → queued_for_processing → processing → ready`, with 3 renditions, the duration and size recorded, and 3 thumbnail candidates.

## Not yet built

- Retrying a failed job (`processing_failed → queued_for_processing`), stuck-job detection, and the `GET /v1/videos/{id}/processing` status endpoint.
- `publish_on_ready` (S4-02), and admin retry of failed jobs (S4-05).
