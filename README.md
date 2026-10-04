# Video Platform

Backend for a video-on-demand platform: uploads, processing, HLS playback, engagement, and discovery.

## Repository layout

| Path | What | Owner |
|---|---|---|
| [`api/`](api/) | Laravel modular monolith: every business module (auth, users, channels, videos, uploads, playback, engagement, admin) | Laravel team |
| [`media-worker/`](media-worker/) | Go service: probe, transcode (FFmpeg), HLS packaging, thumbnails | Go engineer |
| [`contracts/`](contracts/) | JSON Schemas for messages exchanged between Laravel and Go | Both |
| [`infra/`](infra/) | Infrastructure as code (AWS) | Platform lead |
| [`docker/`](docker/) | Local development stack | Platform lead |
| [`docs/`](docs/) | System design, ADRs, sprint plans, runbooks | Everyone |

## Key documents

- [System design](docs/SYSTEM_DESIGN.md)
- [Architecture decision records](docs/adr/README.md)
- [Sprint plan, weeks 1–8](docs/SPRINT_PLAN_W1-8.md)

## Ground rules

- Laravel owns the database and the video state machine. The Go worker never touches PostgreSQL. It reports results through messages ([ADR-021](docs/adr/ADR-021-language-split.md)).
- Any message crossing the Laravel/Go boundary must have a schema in `contracts/` ([ADR-005](docs/adr/ADR-005-messaging-sqs-sns.md)).
- Modules inside `api/` don't read each other's tables ([ADR-001](docs/adr/ADR-001-modular-monolith.md)).

## Getting started

Not set up yet. The local stack (`make up`) lands with sprint 1 ticket S1-03. The Laravel app lands with S1-04, and the Go worker with S1-10.
