# ADR-002: PostgreSQL as the transactional source of truth

- **Status:** Accepted
- **Date:** 2026-10-04

## Context
We need ACID transactions for identity, ownership, the video state machine and engagement data. We also need search for the MVP without running extra infrastructure, and a clear path to scale.

## Decision
- Use **Amazon RDS for PostgreSQL** (Multi-AZ) as the single source of truth for all transactional data. Use one **schema per module** (or a strict table-prefix convention) to prepare for later vertical splits.
- Use **UUIDv7** primary keys. User-facing entities (videos, channels, playlists) also get a separate opaque `public_id`.
- **Search for the MVP uses PostgreSQL full-text search** (`tsvector` + GIN, plus `pg_trgm` for typo tolerance and autocomplete). OpenSearch comes in at growth (ADR-007).
- **Raw analytics events do not go into the main OLTP tables.** At the MVP they go to S3 through Firehose, and aggregates land in PostgreSQL rollup tables.
- Use **Redis (ElastiCache)** for cache, counters, rate limits and Laravel queue/locks where needed. Redis is never the source of truth.

## Alternatives considered
- **MySQL:** works well with Laravel too, but its full-text search, partial indexes, JSONB and partitioning are weaker. PostgreSQL lets us skip a search engine at the MVP.
- **DynamoDB:** scales without limits but has rigid access patterns and poor fit with Eloquent. Too early.

## Consequences
- ➕ One mature database covers the whole MVP, including search.
- ➖ A single writer limits write throughput. Planned path: read replicas → partitioning → module DB splits → sharding (design doc §28).
- Laravel migrations must use the expand/contract pattern (backward-compatible deploys).

## Revisit when
Primary CPU stays above 60%, tables exceed about 100M rows, or search latency or load hurts the primary.
