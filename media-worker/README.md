# media-worker — Go transcoding service

The Go module is bootstrapped by sprint 1 ticket **S1-10**: config, logging, OpenTelemetry, and an SQS consumer with a visibility heartbeat. Ticket **S1-11** adds the probe/validation logic and **S1-12** the golden test corpus.

The worker consumes `media-process` jobs, writes outputs to S3 under `media/{video_id}/v{n}/`, and reports to `media-results`. It never accesses PostgreSQL ([ADR-006](../docs/adr/ADR-006-transcoding-go-ffmpeg.md)).
