# Processing module

Turns completed uploads into work for the Go media worker (design doc §11). Owns `video_processing_jobs`. Uses Videos through `VideoLifecycle` and Uploads through `UploadedSources`.

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

## Not yet built

- Consuming the worker's results (`media-results`: rendition ready, completed, failed), which moves the video on to `ready` or `processing_failed` and updates the job (S4).
- Retrying a failed job (`processing_failed → queued_for_processing`), stuck-job detection, and the `GET /v1/videos/{id}/processing` status endpoint.
- The `validating → queued_for_processing → processing` steps are reported by the worker (S3-08 / S4). Until then a dispatched video stays `validating`.
