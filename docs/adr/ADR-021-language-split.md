# ADR-021: Laravel for the core, Go for media processing

- **Status:** Accepted
- **Date:** 2026-10-04

## Context
The team is 3 Laravel engineers and 1 Go engineer. Most of the system is CRUD, auth, permissions and admin, which Laravel covers very well. Media processing is CPU-heavy and long-running and wraps FFmpeg, which Go handles well.

## Decision
- **Laravel (current major version, PHP 8.4+)** builds the whole control plane: every module in ADR-001, including playback authorization, the MVP analytics ingestion endpoint and the view-count aggregator.
- **Production runtime:** Laravel **Octane** (FrankenPHP or RoadRunner).
- **Go (current stable)** is used **only** for `media-worker` at the MVP.
- **Analytics ingestion moves to Go later (growth),** when event volume justifies it and Go capacity exists.
- **Business logic for one domain is never split across languages.**
- **The integration contract between the languages is messages only:** SQS/SNS JSON envelopes (ADR-005) with JSON Schemas in `contracts/`. There is no shared database access and no shared code.
- **Observability:** both sides use OpenTelemetry and pass `trace_id` along in message attributes.

## Monorepo layout
```
/api            Laravel modular monolith
/media-worker   Go service
/contracts      JSON Schemas for cross-language messages + example payloads
/infra          IaC (Terraform/CDK)
/docs           SYSTEM_DESIGN.md, ADRs, runbooks, sprint plans
/docker         local development (compose: postgres, redis, minio/localstack, elasticmq/localstack)
```

## Alternatives considered
- **Laravel only:** feasible, but FFmpeg orchestration in PHP workers is more fragile, and the Go engineer would be underused.
- **Go only:** much slower MVP for the 3 Laravel engineers.
- **Mixed ownership per feature:** duplicated models and coordination overhead.

## Consequences
- ➕ Each engineer works in their strongest language, and the riskiest component gets a dedicated owner.
- ➖ Two toolchains in CI, plus a bus factor of 1 on Go. Mitigation: Go PRs are reviewed by a Laravel engineer, and the worker is documented thoroughly.

## Revisit when
The team grows (more Go engineers allow moving ingestion, notification fan-out or feed services to Go), or Go capacity is lost (fall back to MediaConvert per ADR-006).
