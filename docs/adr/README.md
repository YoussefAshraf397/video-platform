# Architecture Decision Records

ADR numbers match the ADR list in [SYSTEM_DESIGN.md §41](../SYSTEM_DESIGN.md#41-architecture-decisions-adrs). That list numbers ADRs by topic. Only the ADRs that are needed to start building are written up below; the rest are written when their trigger is reached.

| ADR | Title | Status |
|---|---|---|
| [ADR-001](ADR-001-modular-monolith.md) | Modular monolith (Laravel) + separate media workers | Accepted |
| [ADR-002](ADR-002-postgresql.md) | PostgreSQL as the transactional source of truth | Accepted |
| [ADR-003](ADR-003-direct-to-s3-uploads.md) | Direct-to-S3 presigned multipart uploads | Accepted |
| [ADR-004](ADR-004-hls-cmaf.md) | HLS with CMAF segments as the streaming format | Accepted |
| [ADR-005](ADR-005-messaging-sqs-sns.md) | SQS + SNS for queues and events (Kafka later) | Accepted |
| [ADR-006](ADR-006-transcoding-go-ffmpeg.md) | Go + FFmpeg media workers, MediaConvert as fallback | Accepted |
| [ADR-017](ADR-017-aws-ecs.md) | AWS with ECS (Fargate + EC2 Spot) | Accepted |
| [ADR-021](ADR-021-language-split.md) | Laravel for the core, Go for media processing | Accepted |

## Shared assumptions behind these ADRs

- Team: 4 engineers (3 Laravel, 1 Go). No dedicated DevOps or QA.
- Cloud: AWS, single region, multi-AZ.
- MVP clients: **web first**. Mobile apps come after the beta.
- MVP scope: the reduced scope from the planning discussion. Playlists, Google login, recommendations beyond trending/subscriptions, rich creator analytics and formal appeals are deferred until after the beta.

If any of these assumptions change, review the ADRs that depend on them.

## Template

New ADRs use this structure: **Status · Date · Deciders · Context · Decision · Alternatives considered · Consequences · Revisit when**.
