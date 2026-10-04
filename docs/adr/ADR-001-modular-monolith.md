# ADR-001: Modular monolith (Laravel) + separate media workers

- **Status:** Accepted
- **Date:** 2026-10-04
- **Deciders:** Engineering team

## Context
There are four engineers and no dedicated DevOps. The MVP needs about 15 functional areas: auth, users, channels, videos, uploads, playback, comments, reactions, subscriptions, history, search, feed, notifications, moderation and admin. Most of these are CRUD with permissions and share one database. Media processing is the exception: it is CPU-heavy, long-running, and handles untrusted input.

## Decision
- Build **one Laravel application** (`api/`) as a **modular monolith**. Each module lives under its own namespace (e.g. `app/Modules/Videos`) and **owns its tables**. Other modules may use it only through its public service classes or through domain events. They must not query its tables or use its Eloquent models directly.
- Deploy the same Laravel artifact as several ECS services:
  - `api`: HTTP
  - `worker`: queue consumers
  - `scheduler`: cron
  - `outbox-relay`: publishes domain events
- Deploy **media processing as a separate service** (Go, see ADR-006/ADR-021).
- Enforce module boundaries in CI with an architecture-test tool (e.g. Pest arch tests or Deptrac).

## Alternatives considered
- **Microservices:** too much operational and coordination cost for 4 people. They also force distributed transactions early.
- **Unstructured Laravel monolith:** fastest at first, but boundaries erode and extracting services later becomes a rewrite.

## Consequences
- ➕ One deploy, one database transaction scope, fast feature work, easy refactoring.
- ➕ A module can be extracted later by changing its deployment, not by redesigning it.
- ➖ A problem in one module (e.g. a memory leak) can affect all endpoints. Mitigation: separate ECS services for http, worker and scheduler.
- ➖ The boundaries depend on discipline plus CI checks.

## Revisit when
A module needs independent scaling that wastes more than 30% of monolith capacity, its deploy cadence blocks others, or a separate team takes ownership of it.
