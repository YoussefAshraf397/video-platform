# Video Platform — Backend System Design & Technical Architecture Plan

| | |
|---|---|
| **Document type** | Master technical planning document (architecture, design, roadmap). No implementation. |
| **Audience** | Backend, DevOps/SRE, QA, Product Engineering, Engineering Management |
| **Status** | Draft v1.0 — for architecture review |
| **Date** | 2026-10-04 |

### How to read this document

Every significant decision is tagged with one of three maturity labels:

| Tag | Meaning |
|---|---|
| **[MVP]** | REQUIRED FOR MVP. Build it before first public launch. |
| **[GROWTH]** | RECOMMENDED FOR GROWTH. Introduce when a stated trigger is hit (not on a calendar). |
| **[SCALE]** | ONLY NEEDED AT LARGE SCALE. Do not build until evidence demands it. |

Where several technical options are valid, the document compares them and states a **Recommendation**. Diagrams are ASCII so they can live in a repository and be diffed. Schemas, events and API shapes are conceptual — they describe intent, not code.

---

## Table of Contents

1. Executive Summary
2. Goals
3. Requirements
4. Assumptions
5. Capacity Estimates
6. High-Level Architecture
7. Architecture Principles
8. Service Boundaries
9. Data Model & Database Strategy
10. Upload Architecture
11. Media Processing
12. Video Lifecycle
13. Playback
14. CDN
15. Engagement (Reactions, Comments, Subscriptions, Playlists, History, Notifications)
16. Search
17. Feed
18. Recommendations
19. Analytics (View Counting, Watch Analytics, Trending)
20. Event Architecture
21. Caching
22. API Design
23. Authentication & Authorization
24. Security, Rate Limiting, Privacy & Compliance
25. Moderation & Admin Platform
26. Observability
27. Reliability (Error Handling, Retries, Idempotency, Consistency)
28. Scalability (incl. Database Scaling)
29. Storage
30. Cloud Architecture
31. Deployment Strategy & CI/CD
32. Disaster Recovery & Multi-Region
33. Cost Optimization
34. Testing Strategy
35. MVP Architecture
36. Growth Architecture
37. Large-Scale Architecture
38. Technical Roadmap
39. Engineering Epics
40. Risks
41. Architecture Decisions (ADRs)
42. Final Architecture Diagram

---

## 1. Executive Summary

We are building the backend for a video-on-demand platform where creators upload videos to channels and viewers discover, watch, and engage with them. The platform must launch with a small team and modest traffic but have a clear, low-regret path to millions of users.

**The core architectural bet:** separate the system into four planes with very different scaling profiles, and keep the expensive one (bytes of video) off the application servers entirely.

| Plane | What it handles | Scaling driver | Key technology (AWS reference) |
|---|---|---|---|
| **Control plane** | Users, channels, metadata, permissions, comments, likes, subscriptions, playlists | Request rate (RPS) | Modular monolith on containers, PostgreSQL, Redis |
| **Media processing plane** | Upload completion, validation, transcoding, packaging, thumbnails | Upload minutes per day | S3, SQS, FFmpeg workers (or MediaConvert) |
| **Media delivery plane** | Manifests, segments, adaptive streaming, signed access | Egress bandwidth (Gbps) | CloudFront + S3 origin, signed cookies |
| **Data / analytics plane** | Views, watch events, engagement events, trending, recommendation inputs | Event volume (events/sec) | Event ingestion → queue → aggregator → PostgreSQL/Redis (MVP), Kafka + ClickHouse (scale) |

**Headline recommendations**

1. **Modular monolith first** — one deployable backend with strict internal module boundaries, plus a separately deployed **media worker** fleet. Extract services only when a module has a distinct scaling, reliability, or team-ownership reason.
2. **PostgreSQL is the source of truth** for all transactional data. Redis for cache, counters, rate limits. Search and analytics stores are *derived* and rebuildable.
3. **Direct-to-object-storage uploads** using presigned multipart URLs. Video bytes never transit the API.
4. **HLS (CMAF fMP4 segments) as the primary streaming format**, H.264/AAC baseline ladder for universal compatibility; DASH is optional and becomes nearly free once segments are CMAF.
5. **CDN-first delivery** with signed cookies/URLs and origin access control. CDN bandwidth will be the single largest cost line — design for high cache-hit ratio from day one.
6. **Queue-based asynchronous work (SQS-class) at MVP; Kafka only at large scale** when event fan-out and replay justify the operational cost.
7. **Views and watch-time are eventually consistent** and aggregated from events — never `UPDATE videos SET views = views + 1` per playback.
8. **Search starts in PostgreSQL full-text**, moves to OpenSearch at the growth stage. **Analytics starts in PostgreSQL rollups**, moves to ClickHouse when event volume demands it.
9. **Recommendations evolve in three stages**: rules → behavior/co-occurrence → ML candidate generation + ranking.
10. **Single region, multi-AZ for MVP.** Multi-region only when business continuity or global latency requirements justify it; the CDN already gives global playback performance.

---

## 2. Goals

### 2.1 Business goals
- Let creators publish videos with minimal friction and reliable processing.
- Give viewers fast, smooth playback on any device and network.
- Drive discovery (search, feed, trending, recommendations) to grow watch-time.
- Keep the platform safe (moderation, abuse prevention) and legally compliant (GDPR, takedowns).
- Control cost per watched hour as traffic grows.

### 2.2 Engineering goals
- A backend a small team (4–8 engineers) can build and operate for the MVP.
- Architecture that evolves by **adding** components, not rewriting the core.
- Clear ownership of data — every piece of data has exactly one source of truth.
- Observable from day one: every request, job and event traceable.
- Failure isolation: a transcoding backlog, search outage, or analytics outage must never stop playback or the core API.

### 2.3 Non-goals (for this plan)
- Live streaming (low-latency HLS, ingest servers) — called out as a future extension only.
- Monetization (ads, payments) — design leaves room (entitlements, idempotent payment hooks) but does not build it.
- Short-form vertical feed product — the processing pipeline supports vertical video, but the feed design targets long-form first.
- Client application design (player UI, apps) — referenced only where the backend depends on client behavior.

---

## 3. Requirements

### 3.1 Functional requirements

**Identity & accounts**
- FR-1 Register with email/password; verify email.
- FR-2 Log in/out; social login (Google, Apple) **[MVP: Google; GROWTH: others]**.
- FR-3 Password reset; session listing and revocation ("log out other devices").
- FR-4 User profile: display name, handle, avatar, bio, locale, privacy settings.
- FR-5 Account deletion and data export (GDPR).

**Channels**
- FR-6 A user can create one channel **[MVP]**; multiple channels per user and channel managers/roles **[GROWTH]**.
- FR-7 Channel profile: name, handle (unique), description, avatar, banner, links.
- FR-8 Channel page lists videos and playlists, sortable by newest/popular.

**Videos & uploads**
- FR-9 Create video draft with metadata (title, description, tags, category, language, visibility, age restriction).
- FR-10 Upload large files (up to 20 GB **[MVP: 10 GB]**) using multipart, resumable uploads directly to object storage.
- FR-11 Cancel an upload; resume after network failure or app restart.
- FR-12 Asynchronous processing: validation, transcoding to multiple resolutions, thumbnails, HLS packaging.
- FR-13 Creator sees processing status and can choose an auto-generated thumbnail or upload a custom one.
- FR-14 Visibility: public, unlisted, private; scheduled publishing **[GROWTH]**.
- FR-15 Edit metadata; replace thumbnail; delete video.
- FR-16 Captions/subtitles upload (WebVTT/SRT) **[MVP: upload; SCALE: auto-generated ASR]**.

**Playback**
- FR-17 Adaptive bitrate playback (HLS) on web, iOS, Android, TV.
- FR-18 Access control by visibility, ownership, age restriction, region restriction **[GROWTH]**, subscriber/paid entitlement **[future]**.
- FR-19 Resume playback ("continue watching") across devices.

**Engagement**
- FR-20 Like/dislike a video (dislike count private to creator — see §15).
- FR-21 Comments with one level of replies; like comments; pin/heart by creator; delete own comments.
- FR-22 Subscribe/unsubscribe to channels; subscriber counts.
- FR-23 Playlists: create, reorder, public/unlisted/private; "Watch later" system playlist.
- FR-24 Watch history: list, remove items, clear, pause history.

**Discovery**
- FR-25 Search videos and channels with relevance, filters (upload date, duration, category), autocomplete.
- FR-26 Tags and categories.
- FR-27 Home feed: subscriptions, continue watching, trending, recommendations.
- FR-28 Subscriptions feed (chronological uploads from subscribed channels).
- FR-29 Trending list (global and per-category; per-region **[GROWTH]**).
- FR-30 Related videos ("up next") on watch page.

**Notifications**
- FR-31 In-app notifications for new uploads from subscribed channels, replies, comment likes, mentions.
- FR-32 Email notifications (digest + transactional) **[MVP: transactional only]**; mobile push **[GROWTH]**.
- FR-33 Per-user notification preferences; per-channel "bell" settings.

**Analytics**
- FR-34 Public view count on each video.
- FR-35 Creator analytics: views, watch time, average view duration, retention curve **[GROWTH]**, traffic sources, geography, devices, subscribers gained/lost.
- FR-36 Platform analytics for internal teams.

**Trust & safety / admin**
- FR-37 Users report videos, comments, channels, users.
- FR-38 Moderation queue, actions (remove, age-restrict, limit visibility, strike, suspend), appeals.
- FR-39 Automated moderation hooks (hash matching, classifiers) **[GROWTH]**.
- FR-40 Admin console: search users/videos, suspend, block, inspect processing failures, audit history.

### 3.2 Non-functional requirements

| Category | Requirement (MVP → Large scale) |
|---|---|
| **Availability** | Core API (read paths, playback authorization): 99.9% MVP → 99.95% growth → 99.99% large scale. Video delivery (CDN): inherits CDN SLA, target 99.95%+. Upload acceptance: 99.9%. Processing is async — measured by completion latency, not availability. |
| **Durability** | Original uploads and processed renditions: object-storage durability (11 nines). Transactional data: no acknowledged write lost (RPO ≤ 5 min MVP → ≤ 1 min / near-zero at scale). Analytics events: at-least-once, tolerate ≤0.1% loss in raw telemetry. |
| **Latency** | See §3.3. |
| **Scalability** | Horizontal scaling of stateless tiers; workers scale with queue depth; data stores scale via replicas → partitioning → sharding. |
| **Throughput** | MVP: ~200 peak API RPS; Large: ~200k+ peak API RPS (see §4). |
| **Consistency** | Strong for identity, ownership, permissions, visibility, moderation blocks, billing. Eventual (seconds–minutes) for counts, search, feeds, recommendations, analytics. Read-your-writes for a user's own actions (their own like, comment, subscription). |
| **Security** | TLS everywhere, least-privilege IAM, encryption at rest, signed upload and playback URLs, WAF, rate limits, OWASP ASVS L2 target. |
| **Recovery** | RTO 4 h / RPO 15 min MVP → RTO 15–60 min / RPO ≤1 min large scale (see §32). |
| **Observability** | 100% of requests carry a trace/correlation ID; RED metrics on all endpoints; USE metrics on infra; structured logs; alerting on SLO burn rate. |
| **Maintainability** | Module boundaries enforced by tooling; contract tests between modules/services; schema-versioned events; ADRs for significant decisions. |
| **Cost efficiency** | Track cost per 1,000 watched hours and cost per uploaded hour as first-class metrics. |

### 3.3 Latency-sensitive (synchronous) vs asynchronous operations

**Latency-sensitive — must be synchronous and fast (p95 targets at the API edge, excluding client network):**

| Operation | p95 target | Notes |
|---|---|---|
| Video metadata (watch page) | < 100 ms | Cache-backed |
| Playback authorization (get manifest URL + cookies) | < 150 ms | On the critical path to first frame |
| Time to first frame (client-observed) | < 2 s p75, < 4 s p95 | Dominated by CDN + player; backend contributes authorization only |
| Home feed | < 300 ms | Precomputed/cached candidates |
| Search query | < 300 ms | |
| Autocomplete | < 100 ms | |
| Like / subscribe / comment write | < 200 ms | Write to DB, counters async |
| Login / token refresh | < 300 ms | Password hashing dominates login — intentionally slow (~100–250 ms) |
| Upload session creation / part URL signing | < 200 ms | |

**Asynchronous — can take seconds to hours:**

| Operation | Expected latency |
|---|---|
| Transcoding & packaging | Minutes; target first playable rendition within ~1× video duration for ≤1080p |
| Thumbnail & sprite generation | Minutes |
| Search indexing | < 30 s after change (MVP: < 5 s with PG FTS since it's the same DB) |
| View count update (public) | < 1–5 min |
| Creator analytics | < 15 min MVP → < 2 min large scale for "realtime" panel; daily for full reports |
| Trending recalculation | 5–15 min |
| Recommendation model refresh | Hourly–daily (offline); online features in seconds |
| Notification fan-out | Seconds to a few minutes (large channels) |
| Email delivery | Minutes |
| Account deletion propagation | ≤ 30 days (GDPR), target < 24 h for primary data |

---

## 4. Assumptions

Numbers are order-of-magnitude anchors used to justify design decisions — not forecasts.

| Dimension | MVP | Growth | Large Scale |
|---|---|---|---|
| Monthly active users (MAU) | 50k | 2M | 100M |
| Daily active users (DAU) | 10k | 500k | 25M |
| Active creators (upload ≥1/month) | 500 | 20k | 1M |
| Uploads per day | 200 | 10k | 500k |
| Average source video size | 500 MB | 500 MB | 400 MB |
| Maximum source video size | 10 GB | 20 GB | 50 GB (≈12 h) |
| Average video duration | 10 min | 10 min | 8 min |
| Total stored videos | 50k (1st year) | 5M | 500M |
| Avg watch time per DAU per day | 30 min | 40 min | 45 min |
| Peak concurrent viewers | 2k | 100k | 5M |
| Avg delivered bitrate | 2.5 Mbps | 3 Mbps | 3 Mbps |
| Peak API RPS (all endpoints) | 200 | 10k | 300k |
| Peak playback starts per second | 20 | 1k | 50k |
| Raw analytics events per second (peak) | 500 | 30k | 2M |
| Comments per day | 5k | 500k | 50M |
| Read : write ratio (API) | ~50:1 | ~100:1 | ~100:1 |
| Regions served | 1–2 continents | Global via CDN | Global, multi-region backend |

**Behavioural assumptions**
- Traffic is heavily skewed: ~20% of videos receive ~80% of views; a few videos per day go viral (10–100× normal traffic within hours).
- Most uploads come from mobile and home broadband; ~10% of uploads fail at least once and need resume.
- 70%+ of playback happens on mobile networks or Wi-Fi with fluctuating bandwidth → ABR is mandatory.
- Peak traffic ≈ 2–3× daily average, concentrated in evening hours per time zone.

---

## 5. Capacity Estimates

Formulas are the deliverable; numbers illustrate them. Re-run them with real metrics quarterly.

### 5.1 Storage

```
Ingest per day (originals)     = uploads/day × avg_source_size
Processed per day (renditions) = uploads/day × avg_duration_s × Σ(ladder bitrates) / 8
Thumbnails/sprites             ≈ 1–3 MB per video (negligible)
Storage growth per year        = 365 × (originals_retained + processed)
```

Example ladder sum (H.264, up to 1080p): 0.4 + 0.8 + 1.6 + 3.0 + 5.5 Mbps ≈ 11.3 Mbps ⇒ ≈ 85 MB per minute of video (all renditions), but most uploads are ≤1080p and many short ⇒ use ~1.2× source size as a rule of thumb for processed output.

| Stage | Originals/day | Processed/day | Yearly growth (originals kept hot 30 d, then IA/archive) |
|---|---|---|---|
| MVP | 200 × 0.5 GB = 100 GB | ~120 GB | ~80 TB/year total |
| Growth | 5 TB | ~6 TB | ~4 PB/year |
| Large | 200 TB | ~240 TB | ~160 PB/year (tiering mandatory) |

### 5.2 Upload bandwidth (into object storage)

```
Avg ingest bandwidth = originals_per_day × 8 / 86,400
Peak ingest          ≈ 3 × average
```
MVP: 100 GB/day ≈ 9 Mbps avg. Large: 200 TB/day ≈ 18.5 Gbps avg, ~55 Gbps peak — absorbed by object storage directly (and multi-region upload endpoints at scale), never by API servers.

### 5.3 CDN egress

```
Peak egress  = peak_concurrent_viewers × avg_delivered_bitrate
Monthly egress (bytes) = DAU × watch_min/day × 60 × avg_bitrate/8 × 30
```
| Stage | Peak egress | Monthly egress |
|---|---|---|
| MVP | 2k × 2.5 Mbps = 5 Gbps | 10k × 30 min × 60 × 2.5 Mbps/8 × 30 ≈ 170 TB |
| Growth | 100k × 3 Mbps = 300 Gbps | ≈ 13 PB |
| Large | 5M × 3 Mbps = 15 Tbps | ≈ 750 PB |

At large scale, egress cost dominates everything else combined → commit contracts, multi-CDN, efficient codecs (§33).

### 5.4 Transcoding

```
Transcode load (encoder-minutes/day) = uploads/day × avg_duration_min × renditions_cost_factor
Workers needed = load / (60 × 24 × worker_realtime_factor × target_utilization)
```
`renditions_cost_factor`: how many "realtime minutes" one minute of source costs across the whole ladder on one worker. For a CPU-based 5-rung H.264 ladder at "fast" preset on a 16-vCPU instance, ≈ 1.0–1.5 (i.e. roughly realtime for the whole ladder). 

| Stage | Source minutes/day | Encoder-minutes/day | 16-vCPU workers @ 60% util (steady) |
|---|---|---|---|
| MVP | 2,000 | ~3,000 | ~3–4 (spot/autoscaled to 0–10) |
| Growth | 100k | ~150k | ~175 avg, burst higher |
| Large | 4M | ~6M (+ AV1/HEVC for popular) | thousands; GPU/ASIC encoders or managed service |

Design for **burst**: uploads cluster in hours; target the "p95 time to first playable rendition" SLO, not average throughput.

### 5.5 Database

```
Write QPS ≈ API_RPS × write_fraction (≈1–2%) + async writes (aggregates, job state)
Read QPS on primary ≈ API_RPS × (1 − cache_hit_ratio) × reads_per_request × (1 − replica_fraction)
Row growth/day per table = events/day producing a row
```
| Table | Rows/day (Large) | Notes |
|---|---|---|
| comments | 50M | Largest user-generated OLTP table |
| reactions | 200M | High churn; append-mostly |
| watch_history | ~250M upserts | Per (user, video) latest position |
| subscriptions | 5M | Large total, slow growth |
| video_views (raw) | ~1B | **Must not be in OLTP at scale** → event store |

MVP fits comfortably on one PostgreSQL primary (e.g. 4–8 vCPU, 32 GB RAM) + one replica.

### 5.6 Redis

```
Memory ≈ Σ (hot keys × avg value size) × 1.5 overhead
```
MVP: < 2 GB (sessions, rate limits, metadata cache of top 50k videos, counters). Large: tens to hundreds of GB across clusters segmented by workload (cache vs counters vs rate limits vs feeds).

### 5.7 Queues and event streams

```
Messages/s = Σ(event producers' rate) ; retention size = rate × avg_msg_size × retention_seconds
```
Large scale: 2M events/s × 300 B ≈ 600 MB/s ingress; 7-day retention ≈ 360 TB pre-compression (~60–100 TB compressed) → Kafka with tiered storage.

### 5.8 Search

```
Index size ≈ docs × avg_doc_size × (1 + replicas) × ~1.3 overhead
Query QPS  ≈ search_requests/s + autocomplete_requests/s (autocomplete ≈ 5× search)
```
Large: 500M video docs × ~3 KB ≈ 1.5 TB primary, ~4.5 TB with 2 replicas — sharded cluster; autocomplete served from a separate lightweight index/cache.

---

## 6. High-Level Architecture

### 6.1 The four planes and how they communicate

```
                                 ┌──────────────────────────────────────────────┐
                                 │                   CLIENTS                    │
                                 │   Web · iOS · Android · Smart TV · Admin UI  │
                                 └───────┬──────────────────────┬───────────────┘
                     API calls (HTTPS)   │                      │  Video bytes (HTTPS)
                                         │                      │  upload: PUT parts to presigned URLs
                                         ▼                      │  playback: GET manifests/segments
                          ┌───────────────────────────┐         │
                          │ DNS · WAF · LB/API Gateway│         │
                          └─────────────┬─────────────┘         │
╔═══════════════════════════════════════▼══════════════╗        │
║  CONTROL PLANE  (synchronous, latency-sensitive)     ║        │
║  Backend API (modular monolith):                     ║        │
║  auth · users · channels · videos · uploads(sessions)║        │
║  playback-auth · comments · reactions · subs ·       ║        │
║  playlists · history · search API · feed · notif API ║        │
║  moderation · admin                                  ║        │
║        │                │                  │         ║        │
║   PostgreSQL         Redis        Search index       ║        │
╚════════╪════════════════╪══════════════════╪═════════╝        │
         │ outbox events  │                  ▲ indexer          │
         ▼                                   │                  │
   ┌──────────────── EVENT BUS / QUEUES ─────┴──────────┐       │
   │   (SQS + SNS at MVP/growth  →  Kafka at scale)     │       │
   └───┬─────────────────────┬─────────────────────┬────┘       │
       │                     │                     │            │
╔══════▼══════════════════╗  │   ╔═════════════════▼══════════╗ │
║ MEDIA PROCESSING PLANE  ║  │   ║ DATA / ANALYTICS PLANE     ║ │
║ upload-complete handler ║  │   ║ event ingestion endpoint   ║◄┼── player beacons
║ processing coordinator  ║  │   ║ aggregators (views, watch) ║ │   (async, batched)
║ worker pool: probe,     ║  │   ║ analytics store (PG→CH)    ║ │
║ transcode, package,     ║  │   ║ trending · rec features    ║ │
║ thumbnails, captions    ║  │   ║ creator/platform reports   ║ │
╚═══════╤═════════════════╝  │   ╚════════════════════════════╝ │
        │ writes renditions  │ notifications, search indexing   │
        ▼                    ▼                                  │
  ┌───────────────────────────────────┐                         │
  │ OBJECT STORAGE                    │◄── direct multipart ────┘ (upload)
  │  uploads/ (private)               │
  │  media/   (private, CDN origin)   │
  └───────────────┬───────────────────┘
                  │ origin fetch (OAC, cache miss only)
╔═════════════════▼══════════════════════════════════╗
║ MEDIA DELIVERY PLANE                               ║
║ CDN edge PoPs ─ origin shield ─ signed cookie/URL  ║──► clients (playback)
║ manifests (short TTL) · segments (long TTL)        ║
╚════════════════════════════════════════════════════╝
```

### 6.2 Plane-to-plane contracts

| From → To | Mechanism | Sync/Async | Contract |
|---|---|---|---|
| Client → Control | HTTPS REST/JSON | Sync | Versioned public API (§22) |
| Client → Object storage (upload) | Presigned multipart PUTs | Sync (client-driven) | URLs issued by control plane, scoped to one object key, short-lived |
| Object storage → Media processing | Storage event notification → queue **and** explicit client "complete" call | Async | `VideoUploaded` (control plane is authoritative; storage event is a safety net) |
| Control → Media processing | Queue message (`ProcessVideo` command) | Async | Idempotent by `video_id + source_version` |
| Media processing → Control | Internal callback API or event (`VideoProcessingCompleted/Failed`) | Async | Control plane owns the video state machine; workers report, never decide publication |
| Media processing → Object storage | Write renditions/manifests/thumbnails | — | Deterministic key layout (§29) |
| Client → Delivery | HTTPS GET via CDN | Sync | Signed cookies/URLs issued by playback API |
| Delivery → Object storage | Origin fetch with origin access control | Sync (on miss) | Bucket not publicly readable |
| Client → Analytics | Batched beacon POSTs to ingestion endpoint | Async (fire-and-forget) | Versioned event schema |
| Control → Analytics | Domain events (likes, comments, subs, publishes) | Async | Outbox → bus |
| Analytics → Control | Aggregates written back (view counts, trending lists, rec candidates) | Async | Control plane reads; never blocks on analytics |

**Rule:** the control plane never makes a synchronous call into the media processing plane or analytics plane on a user request path. A failure in either cannot take down browsing or playback.

---

## 7. Architecture Principles

| Principle | How it is applied |
|---|---|
| **Scalability** | Stateless API containers behind LB; work queues decouple producers from consumers; data stores evolve replica → partition → shard. |
| **Reliability** | Durable queues, retries with backoff, DLQs, idempotent consumers, transactional outbox. |
| **Availability** | Multi-AZ for every stateful component at MVP; graceful degradation when dependencies fail (§27). |
| **Fault tolerance** | Bulkheads: separate worker pools, separate connection pools, timeouts + circuit breakers on every remote call. |
| **Security** | Zero public buckets; signed URLs; least privilege; secrets in a manager; defense in depth at edge, API, data. |
| **Observability** | Traces across HTTP and async hops (trace context propagated in message attributes). |
| **Maintainability** | Modular monolith with enforced boundaries; each module owns its tables; cross-module access only via module APIs or events. |
| **Cost efficiency** | CDN-first, per-title ladder, lifecycle tiering, spot instances for encoding, autoscale to zero for workers. |
| **Horizontal scaling** | No in-process state that pins a user to a server; sessions in Redis/JWT. |
| **Asynchronous processing** | Anything not needed to answer the current request is deferred: counters, indexing, notifications, analytics. |
| **Loose coupling** | Modules communicate through domain events for side effects; synchronous calls only for queries required to answer a request. |
| **Eventual consistency where appropriate** | Counts, search, feeds, recommendations, analytics (§27.5). |
| **Strong consistency where required** | Auth, ownership, permissions, visibility, moderation blocks, upload finalization, billing. |
| **Cloud-native** | Managed services for undifferentiated heavy lifting (DB, queues, CDN, object store); containers for our code. |
| **CDN-first delivery** | API never streams video; manifests and segments always via CDN. |
| **Event-driven where useful** | Domain events for fan-out side effects; not for request/response flows. |
| **Avoid overengineering** | Every [GROWTH]/[SCALE] component has an explicit trigger (§36–37). |

**What starts simple vs. what comes later**

| Start simple **[MVP]** | Introduce later |
|---|---|
| One backend deployable (modular monolith) + one media worker deployable | Extract services: media processing coordinator, analytics ingestion, notifications, search indexer, feed, recommendations **[GROWTH/SCALE]** |
| PostgreSQL (primary + 1 replica) | Partitioned tables **[GROWTH]**, sharding/distributed SQL **[SCALE]** |
| PostgreSQL FTS | OpenSearch **[GROWTH]** |
| PostgreSQL rollup tables for analytics | ClickHouse + data lake **[GROWTH→SCALE]** |
| SQS (+ SNS fan-out) | Kafka/MSK **[SCALE]** |
| Single CDN | Origin shield **[GROWTH]**, multi-CDN **[SCALE]** |
| H.264 ladder | HEVC/AV1 for popular content **[SCALE]** |
| Rule-based recommendations | Co-occurrence **[GROWTH]**, ML two-tower + ranker **[SCALE]** |
| Fan-out-on-read feeds | Hybrid fan-out **[SCALE]** |
| Single region, multi-AZ | Active/passive DR **[GROWTH]**, active/active **[SCALE]** |

---

## 8. Service Boundaries

### 8.1 Monolith vs SOA vs microservices

| Option | Pros | Cons | Fit for MVP |
|---|---|---|---|
| **Modular monolith** | One deploy, one DB transaction scope, easy refactoring, low ops cost, fast for small team | Shared runtime (a memory leak affects all), coarse scaling, discipline needed to keep boundaries | **Best** |
| **Service-oriented (a few coarse services)** | Independent scaling of very different workloads | More infra, network calls, distributed failure modes | Good — for the *media worker* only |
| **Microservices (15–20 services)** | Team autonomy at scale, fine-grained scaling | Distributed transactions, high ops burden, slow for small team, premature boundaries are expensive to move | Poor at MVP |

**Recommendation [MVP]:** a **modular monolith** ("core API") **plus** a separately deployed **media worker** fleet (radically different resource profile: CPU/GPU-heavy, long-running, spot-friendly) and a lightweight **event ingestion** endpoint (can be a module in the monolith at MVP, deployed as a separate scaling group of the same artifact). Each module:
- owns its tables (no cross-module SQL joins in writes; read-model joins allowed only via views owned by the reading module or via module APIs),
- exposes an internal interface (in-process today, network tomorrow),
- publishes domain events through a shared transactional outbox.

This makes later extraction a deployment change, not a redesign.

### 8.2 Logical modules/services

Legend for "Deploy": **M** = inside monolith at MVP; **S** = separate deployable at MVP; **→X** = candidate for extraction at stage X.

#### Authentication Service — Deploy: M (→ GROWTH if SSO/partners)
- **Responsibility:** credentials, login, token issuance/refresh/revocation, OAuth, MFA, email verification, password reset.
- **Owned data:** `credentials`, `user_sessions` (refresh tokens), `oauth_identities`, `verification_tokens`, `mfa_factors`.
- **Operations:** register, login, refresh, logout, revoke session, reset password, verify email, social login callback.
- **Dependencies:** User (create profile), Notification (emails), Redis (rate limits, revocation list).
- **Produces:** `UserRegistered`, `UserLoggedIn` (security log), `PasswordChanged`, `SessionRevoked`.
- **Consumes:** `UserDeleted` (purge credentials), `UserSuspended` (revoke sessions).
- **Scaling:** CPU-bound on password hashing; stateless; burst during credential-stuffing attacks → aggressive rate limiting.

#### User Service — Deploy: M
- **Responsibility:** user profile, preferences, privacy settings, account lifecycle (active, suspended, deleted).
- **Owned data:** `users`, `user_preferences`.
- **Operations:** get/update profile, set preferences, request deletion/export.
- **Dependencies:** Auth.
- **Produces:** `UserCreated`, `UserUpdated`, `UserSuspended`, `UserDeletionRequested`, `UserDeleted`.
- **Consumes:** `ModerationActionTaken` (suspension).
- **Scaling:** read-heavy, highly cacheable.

#### Channel Service — Deploy: M
- **Responsibility:** channel CRUD, handles, branding, channel membership/roles **[GROWTH]**, channel-level counts (display).
- **Owned data:** `channels`, `channel_members`, `channel_stats` (denormalized counters).
- **Operations:** create/update channel, get channel page, list channel videos (via Video module).
- **Dependencies:** User, Video.
- **Produces:** `ChannelCreated`, `ChannelUpdated`, `ChannelDeleted`.
- **Consumes:** `ChannelSubscribed/Unsubscribed` (counter), `VideoPublished` (video count), `UserDeleted`.
- **Scaling:** read-heavy; hot channel pages cached.

#### Video Service (metadata) — Deploy: M
- **Responsibility:** video metadata, visibility, **video state machine owner**, tags/categories, thumbnails selection, subtitles metadata.
- **Owned data:** `videos`, `video_tags`, `tags`, `categories`, `thumbnails`, `subtitles`, `video_stats` (denormalized display counters).
- **Operations:** create draft, update metadata, publish/unpublish, delete, get video, list by channel.
- **Dependencies:** Channel, Upload, Media Processing (via events), Moderation.
- **Produces:** `VideoCreated`, `VideoMetadataUpdated`, `VideoPublished`, `VideoUnpublished`, `VideoVisibilityChanged`, `VideoDeleted`, `VideoBlocked`.
- **Consumes:** `VideoUploaded`, `VideoProcessingCompleted/Failed`, `ModerationActionTaken`, aggregated count updates.
- **Scaling:** extremely read-heavy (watch page); cache-aside with event-driven invalidation.

#### Upload Service — Deploy: M (→ GROWTH optional)
- **Responsibility:** upload sessions, multipart orchestration, presigned URL issuance, completion, abandonment cleanup.
- **Owned data:** `upload_sessions`, `upload_parts` (optional—object store is source of truth for parts).
- **Operations:** create session, sign part URLs, list uploaded parts (resume), complete, abort.
- **Dependencies:** Video (state), object storage API.
- **Produces:** `VideoUploadStarted`, `VideoUploaded`, `VideoUploadAborted`, `VideoUploadExpired`.
- **Consumes:** storage `ObjectCreated` notifications (reconciliation).
- **Scaling:** low RPS but latency-sensitive; never handles bytes.

#### Media Processing Service — Deploy: **S** (coordinator in monolith or separate; workers separate)
- **Responsibility:** processing workflow: probe, validate, malware/content scan hooks, transcode, package, thumbnails, captions, publish outputs.
- **Owned data:** `video_processing_jobs`, `processing_tasks`, `video_assets`, `video_variants` (written via coordinator).
- **Operations:** start job, run tasks, retry, cancel, reprocess (e.g. add new codec).
- **Dependencies:** object storage, queue, (optional) managed transcoder; reports to Video.
- **Produces:** `VideoProcessingStarted`, `VideoRenditionReady` (first playable), `VideoProcessingCompleted`, `VideoProcessingFailed`, `ThumbnailsGenerated`.
- **Consumes:** `VideoUploaded` / `ProcessVideo` command, `VideoDeleted` (cancel).
- **Scaling:** scales on queue depth / oldest message age; spot instances; per-task parallelism (chunked encoding at scale).

#### Playback Service — Deploy: M (→ GROWTH: separate scaling group, same artifact)
- **Responsibility:** authorize playback, select manifest, issue signed cookies/URLs, resume position, entitlement checks.
- **Owned data:** none persistent (reads Video, Moderation, Entitlements); signing keys (from secrets manager).
- **Operations:** `getPlaybackInfo(videoId)`.
- **Dependencies:** Video, Channel, User (age), Moderation (blocks), History (resume position), geo-IP.
- **Produces:** `PlaybackAuthorized` (analytics, optional).
- **Consumes:** —
- **Scaling:** high RPS (every play), must be cache-efficient (video access policy cached; per-user checks cheap).

#### Engagement Service (reactions) — Deploy: M
- **Responsibility:** likes/dislikes on videos and comments, saved/"watch later".
- **Owned data:** `reactions`, counters in Redis + `video_stats`.
- **Operations:** react, remove reaction, get my reaction(s), get counts.
- **Produces:** `VideoLiked`, `VideoDisliked`, `ReactionRemoved`, `CommentLiked`.
- **Consumes:** `VideoDeleted`, `UserDeleted`.
- **Scaling:** write bursts on viral videos → counter sharding / async aggregation.

#### Comment Service — Deploy: M (→ GROWTH)
- **Responsibility:** comments, replies, ranking, pinning, creator moderation (hold for review, blocked words), spam scoring hook.
- **Owned data:** `comments` (top-level and replies in one table with `parent_id`), `comment_stats`.
- **Operations:** post, edit, delete, list (top/newest), list replies, pin, heart.
- **Produces:** `VideoCommented`, `CommentReplied`, `CommentDeleted`, `UserMentioned`.
- **Consumes:** `ModerationActionTaken`, `VideoDeleted`, `UserDeleted`, `CommentLiked` (ranking).
- **Scaling:** large table; partition by `video_id` hash at scale.

#### Playlist Service — Deploy: M
- **Responsibility:** playlists, items, ordering, system playlists (Watch Later, Liked videos as a view).
- **Owned data:** `playlists`, `playlist_items`.
- **Produces:** `PlaylistCreated`, `PlaylistItemAdded`.
- **Consumes:** `VideoDeleted`/`VideoBlocked` (mark unavailable), `UserDeleted`.
- **Scaling:** modest.

#### Subscription Service — Deploy: M
- **Responsibility:** user→channel subscriptions, notification level (all/personalized/none), subscriber counts.
- **Owned data:** `subscriptions`, counters.
- **Produces:** `ChannelSubscribed`, `ChannelUnsubscribed`.
- **Consumes:** `ChannelDeleted`, `UserDeleted`.
- **Scaling:** huge rows for big channels; queries by subscriber (my subs) and by channel (fan-out) → two access paths (§15.4).

#### History Service (watch history / continue watching) — Deploy: M
- **Responsibility:** per-user, per-video last position and watched timestamp; history controls.
- **Owned data:** `watch_history`.
- **Consumes:** progress heartbeats (via analytics ingestion, sampled/coalesced), `UserDeleted`.
- **Scaling:** very write-heavy (heartbeats) → coalesce in Redis, flush periodically.

#### Search Service — Deploy: M (query) + indexer worker (→ GROWTH: OpenSearch + separate indexer)
- **Responsibility:** search & autocomplete for videos/channels; index maintenance.
- **Owned data:** search index (derived).
- **Consumes:** `VideoPublished`, `VideoMetadataUpdated`, `VideoUnpublished`, `VideoDeleted`, `VideoBlocked`, `ChannelUpdated`, periodic popularity updates.
- **Scaling:** read-heavy, independent cluster at growth.

#### Feed Service — Deploy: M (→ SCALE separate)
- **Responsibility:** home feed and subscriptions feed assembly from candidate sources; dedupe, filtering, blending.
- **Owned data:** feed caches (Redis), (at scale) inbox timelines.
- **Dependencies:** Subscription, Video, History, Trending, Recommendation.
- **Consumes:** `VideoPublished` (at scale for fan-out-on-write).
- **Scaling:** read-heavy; latency-sensitive.

#### Recommendation Service — Deploy: M rules (→ GROWTH batch jobs → SCALE separate online serving + offline pipelines)
- **Responsibility:** related videos, personalized candidates, ranking.
- **Owned data:** candidate tables, embeddings, feature store (at scale).
- **Consumes:** watch events, engagement events, metadata events.
- **Scaling:** offline compute heavy; online low-latency serving.

#### Notification Service — Deploy: M + async worker (→ GROWTH separate)
- **Responsibility:** in-app inbox, push, email; preferences; batching/digests; delivery tracking.
- **Owned data:** `notifications`, `notification_preferences`, `device_tokens`, `notification_deliveries`.
- **Consumes:** `VideoPublished`, `CommentReplied`, `UserMentioned`, `CommentLiked`, `VideoProcessingCompleted/Failed`, security events.
- **Produces:** `NotificationDelivered/Failed` (metrics).
- **Scaling:** fan-out spikes from big channels → rate-limited batches.

#### Analytics Service — Deploy: ingestion as separate scaling group (S); aggregation as workers
- **Responsibility:** ingest client telemetry, validate views, aggregate views/watch time, creator & platform analytics, trending inputs.
- **Owned data:** raw events (object storage/stream), aggregates (`video_daily_stats`, `video_view_counts`), analytics DB.
- **Consumes:** player events, domain events.
- **Produces:** `ViewCountsUpdated` (batched), `TrendingUpdated`.
- **Scaling:** highest event volume in the system; must be isolated from OLTP.

#### Moderation Service — Deploy: M (→ GROWTH: separate pipeline for automated classifiers)
- **Responsibility:** reports, review queues, decisions, strikes, appeals, automated scan orchestration.
- **Owned data:** `reports`, `moderation_cases`, `moderation_actions`, `strikes`, `appeals`, blocklists/hashes.
- **Produces:** `ModerationActionTaken`, `ContentBlocked`, `UserSuspended` (via User), `AppealResolved`.
- **Consumes:** `VideoUploaded`/`VideoProcessingCompleted` (scan), `VideoCommented` (spam), `ReportSubmitted`.
- **Scaling:** human queue throughput is the bottleneck, not compute.

#### Admin Service — Deploy: M (separate route prefix, separate auth policy; → GROWTH separate deployable for isolation)
- **Responsibility:** back-office APIs for support, T&S, ops; audit logging of every admin action.
- **Owned data:** `admin_audit_log`, admin roles.
- **Dependencies:** all modules through their APIs (never direct table writes).
- **Scaling:** tiny traffic; high security requirements.

### 8.3 What stays together vs. what separates

| Stays in the monolith through Growth | Separate at MVP | Extract at Growth (trigger) | Extract at Scale |
|---|---|---|---|
| Auth, User, Channel, Video, Upload sessions, Playback auth, Reactions, Comments, Playlists, Subscriptions, History, Search API, Feed (simple), Moderation, Admin | Media workers; event-ingestion scaling group | Media coordinator (when workflows get complex), Notification worker (fan-out load), Search indexer + OpenSearch, Analytics aggregation (event volume), Admin (security isolation) | Feed (fan-out infra), Recommendation (online serving + offline ML), Comments (table size/team), Playback (global edge-adjacent deployment), Identity (federation) |

**Extraction triggers (any one is sufficient):** independent scaling need that wastes >30% of monolith capacity; a module's deploy cadence blocks others; a separate team owns it; a failure in the module repeatedly impacts unrelated endpoints; a different runtime/language is clearly better (e.g., ML serving).


---

## 9. Data Model & Database Strategy

### 9.1 Conventions
- **Primary keys:** time-sortable 64-bit IDs (Snowflake-style) or UUIDv7 internally; **public IDs** are opaque short strings (e.g. 11-char base64url) mapped 1:1 to videos/channels so IDs in URLs are not enumerable. *Recommendation:* UUIDv7 as PK (native PG type, sortable, no coordination service), plus a `public_id` unique column on user-facing entities.
- **Timestamps:** `created_at`, `updated_at` in UTC on every table; soft-delete via `deleted_at` where retention/undo/legal hold matters.
- **Ownership:** every module owns its tables; foreign keys inside a module are enforced; across modules they are enforced at MVP (same DB) but designed so they can be dropped when a module is extracted.
- **Counters:** never the source of truth in the hot row — `*_stats` tables hold denormalized counts updated asynchronously.
- **Enums:** stored as constrained text or small ints with a documented mapping.

### 9.2 Entity relationship overview

```
users 1─1 credentials          users 1─* user_sessions        users 1─* oauth_identities
users 1─* channels (MVP: 1)    channels 1─* videos            videos 1─1 video_stats
videos 1─* video_assets (source + derived files)
videos 1─* video_variants (renditions)    videos 1─* video_processing_jobs 1─* processing_tasks
videos 1─* thumbnails          videos 1─* subtitles           videos *─* tags (video_tags)
videos *─1 categories          videos 1─* comments (parent_id self-ref → replies)
users  1─* reactions *─1 (video | comment)
users  *─* channels (subscriptions)
users  1─* playlists 1─* playlist_items *─1 videos
users  1─* watch_history *─1 videos
users  1─* notifications       reports *─1 (video|comment|channel|user)
moderation_cases 1─* moderation_actions ; reports *─1 moderation_cases
upload_sessions *─1 videos
```

### 9.3 Entities

> Field lists are conceptual. "IDX" = key indexes, "UQ" = uniqueness constraints.

#### users
- **Purpose:** identity and profile of every account.
- **Fields:** id, public_handle, email (citext), email_verified_at, display_name, avatar_asset_key, bio, locale, country, date_of_birth (or age_verified flag), role (viewer/creator/moderator/admin — see §23), status (active/suspended/deactivated/pending_deletion/deleted), created_at, updated_at, deleted_at.
- **Relationships:** 1–1 credentials; 1–* sessions, channels, playlists, history, reactions.
- **IDX:** (status), (created_at).
- **UQ:** lower(email) where not deleted; lower(public_handle).

#### credentials / oauth_identities (Auth-owned)
- credentials: user_id (PK), password_hash, hash_algo/params, password_changed_at, failed_attempts, locked_until.
- oauth_identities: id, user_id, provider, provider_subject, email_at_provider, created_at. **UQ:** (provider, provider_subject).

#### user_sessions
- **Purpose:** refresh-token sessions per device; enables "log out everywhere".
- **Fields:** id, user_id, refresh_token_hash, token_family_id (rotation detection), device_name, user_agent, ip_created, ip_last, created_at, last_used_at, expires_at, revoked_at, revoke_reason.
- **IDX:** (user_id, revoked_at), (expires_at) for cleanup.
- **UQ:** refresh_token_hash.

#### channels
- **Purpose:** publishing identity.
- **Fields:** id, public_id, owner_user_id, handle, name, description, avatar_key, banner_key, country, default_language, status (active/suspended/deleted), verified flag, created_at.
- **IDX:** (owner_user_id).
- **UQ:** lower(handle); public_id.
- **channel_stats:** channel_id PK, subscriber_count, video_count, total_views, updated_at.
- **channel_members [GROWTH]:** (channel_id, user_id, role: owner/manager/editor/viewer). UQ (channel_id, user_id).

#### videos
- **Purpose:** the central metadata record and state machine.
- **Fields:** id, public_id, channel_id, uploader_user_id, title, description, language, category_id, visibility (public/unlisted/private), **status** (see §12), moderation_status (none/pending_review/approved/age_restricted/limited/blocked), age_restricted, region_policy (allow/deny list ref) **[GROWTH]**, duration_ms, source_width, source_height, aspect_ratio, primary_thumbnail_id, published_at, scheduled_publish_at **[GROWTH]**, processing_version, source_asset_id, made_for_kids flag, license, comments_enabled, ratings_visible, created_at, updated_at, deleted_at, state_version (optimistic concurrency).
- **IDX:** (channel_id, status, published_at DESC) — channel page; (status, published_at DESC) — recency browse; (category_id, published_at DESC); partial index on (visibility='public' AND status='PUBLISHED').
- **UQ:** public_id.

#### video_stats
- video_id PK, view_count, like_count, dislike_count, comment_count, watch_time_seconds_total, last_aggregated_at. Updated by aggregators only.

#### upload_sessions
- **Purpose:** tracks resumable/multipart uploads.
- **Fields:** id, video_id, user_id, storage_bucket, storage_key, storage_upload_id (multipart), declared_size_bytes, declared_content_type, declared_checksum (whole-file SHA-256, optional), part_size_bytes, total_parts, status (initiated/in_progress/completing/completed/aborted/expired/failed), idempotency_key, expires_at, completed_at, created_at.
- **IDX:** (video_id), (status, expires_at) — cleanup scan, (user_id, created_at).
- **UQ:** (user_id, idempotency_key); storage_upload_id.

#### video_assets
- **Purpose:** every stored file belonging to a video (source, mezzanine, manifests, sprites, captions files).
- **Fields:** id, video_id, asset_type (source/mezzanine/hls_master/dash_mpd/sprite/audio_track/caption_file/thumbnail_file), storage_class, bucket, key_prefix, size_bytes, checksum_sha256, content_type, codec_info (json), created_at, deleted_at, retention_until.
- **IDX:** (video_id, asset_type).
- **UQ:** (bucket, key_prefix).

#### video_variants
- **Purpose:** each rendition in the ladder.
- **Fields:** id, video_id, processing_job_id, kind (video/audio), codec (h264/hevc/av1/aac/opus), profile/level, width, height, frame_rate, bitrate_avg, bitrate_peak, container (cmaf), playlist_key, segment_duration_ms, status (pending/ready/failed/retired), created_at.
- **IDX:** (video_id, status).
- **UQ:** (video_id, codec, height, frame_rate, processing_version).

#### video_processing_jobs / processing_tasks
- **jobs:** id, video_id, source_asset_id, processing_version, profile (ladder spec id), status (queued/running/succeeded/partially_succeeded/failed/cancelled), attempt, started_at, finished_at, error_code, error_detail, worker_info, idempotency_key.
  - IDX: (video_id, created_at DESC), (status, created_at). UQ: (video_id, processing_version, profile).
- **tasks:** id, job_id, task_type (probe/validate/scan/transcode_rendition/package/thumbnails/sprites/captions/finalize), params (json), status, attempt, max_attempts, lease_owner, lease_expires_at, started_at, finished_at, error.
  - IDX: (job_id), (status, lease_expires_at) for stuck-task detection. UQ: (job_id, task_type, params_hash).

#### thumbnails
- id, video_id, source (auto/custom), time_offset_ms, storage_key, width, height, moderation_status, is_primary, created_at. IDX (video_id). UQ partial: one is_primary per video.

#### subtitles
- id, video_id, language (BCP-47), label, kind (subtitles/captions/auto), source (upload/asr), storage_key (WebVTT), status, is_default, created_at. UQ (video_id, language, kind).

#### categories / tags / video_tags
- categories: id, slug (UQ), name (localized via translations table), parent_id, sort_order, is_active.
- tags: id, normalized_name (UQ, lowercase/unicode-normalized), display_name, usage_count.
- video_tags: (video_id, tag_id) PK; IDX (tag_id, video_id). Limit tags per video (e.g., ≤ 30).

#### comments (top-level and replies in one table)
- **Purpose:** discussion; `comment_replies` is modeled as `parent_id` to avoid two tables with the same shape.
- **Fields:** id, video_id, author_user_id, parent_id (null = top-level), root_id, body (text, length-limited), body_format, status (visible/held_for_review/removed_by_creator/removed_by_moderator/deleted_by_author/spam), is_pinned, creator_hearted, like_count, reply_count, rank_score, edited_at, created_at, deleted_at.
- **IDX:** (video_id, status, rank_score DESC, id) — "top"; (video_id, status, created_at DESC, id) — "newest"; (parent_id, created_at, id) — replies; (author_user_id, created_at DESC).
- **Depth rule:** one level of replies (`parent_id` must reference a top-level comment); replies to replies attach to root with an `@mention`. Simplifies pagination and ranking.
- **Partition (GROWTH/SCALE):** hash on video_id.

#### reactions
- **Purpose:** likes/dislikes on videos and comments.
- **Fields:** user_id, target_type (video/comment), target_id, reaction (like/dislike), created_at, updated_at.
- **PK/UQ:** (user_id, target_type, target_id) — one reaction per user per target; changing reaction = update.
- **IDX:** (target_type, target_id, created_at) — for audits/recounts; (user_id, target_type, created_at DESC) — "liked videos".

#### subscriptions
- **Fields:** subscriber_user_id, channel_id, notification_level (all/personalized/none), created_at.
- **PK/UQ:** (subscriber_user_id, channel_id).
- **IDX:** (channel_id, created_at, subscriber_user_id) — fan-out and subscriber lists.

#### playlists / playlist_items
- playlists: id, public_id, owner_user_id, channel_id (nullable), title, description, visibility, type (user/watch_later/system), item_count, created_at, updated_at. UQ: public_id; partial UQ (owner_user_id) where type='watch_later'.
- playlist_items: id, playlist_id, video_id, position (fractional/lexorank key for cheap reorders), added_at, added_by. IDX (playlist_id, position). UQ (playlist_id, video_id) (no duplicates; product decision).

#### watch_history
- **Purpose:** continue watching + history page.
- **Fields:** user_id, video_id, last_position_ms, duration_ms, progress_ratio, completed, watch_count, first_watched_at, last_watched_at, device_type.
- **PK/UQ:** (user_id, video_id).
- **IDX:** (user_id, last_watched_at DESC) — history; partial (user_id, last_watched_at DESC) where not completed and progress between 5%–95% — continue watching.
- **Partition (GROWTH):** hash on user_id.

#### video_views (raw events)
- **Purpose:** raw view/session events for validation and analytics.
- **Where:** **not** in OLTP beyond MVP. MVP: a time-partitioned PG table (daily partitions, 30–90 day retention) or append to object storage. Growth+: object storage (Parquet) + ClickHouse.
- **Fields:** event_id (UUID, UQ for dedupe), session_id, video_id, user_id (nullable), anon_id, ip_hash, country, device, player_version, started_at, watched_ms, max_position_ms, valid (bool), invalid_reason.

#### video_daily_stats (aggregate)
- (video_id, date, country?, traffic_source?, device?) → views, watch_time_s, avg_view_duration, likes, comments, subs_gained. At MVP keep dimensions small (video_id, date) + a separate per-dimension table; at growth move to ClickHouse.

#### notifications
- id, recipient_user_id, type, actor_user_id, target_type, target_id, group_key (for collapsing "5 people liked your comment"), payload (json, rendered fields), read_at, seen_at, created_at, expires_at.
- IDX: (recipient_user_id, created_at DESC); (recipient_user_id, read_at) partial for unread count. UQ: (recipient_user_id, dedupe_key) to prevent duplicates from event redelivery.
- Retention: 90 days. Partition by created_at (monthly) at growth.
- **notification_preferences:** user_id, channel (in_app/push/email), type, enabled.
- **device_tokens:** id, user_id, platform, token, last_seen_at, invalidated_at. UQ token.

#### reports
- id, reporter_user_id, target_type, target_id, reason_code, details, status (open/triaged/actioned/dismissed), case_id, created_at.
- IDX: (target_type, target_id), (status, created_at), (reporter_user_id, created_at). UQ: (reporter_user_id, target_type, target_id) for open reports (prevent spam).

#### moderation_cases / moderation_actions / strikes / appeals
- cases: id, target_type, target_id, source (report/auto/manual), priority, severity, state (open/in_review/escalated/resolved), assigned_to, report_count, auto_scores (json), sla_due_at, created_at.
  - IDX (state, priority DESC, created_at); UQ open case per (target_type, target_id).
- actions: id, case_id, actor_id (moderator or system), action (approve/remove/age_restrict/limit/block/restore/warn/strike/suspend/terminate), reason_code, policy_ref, notes, created_at, reversed_by_action_id. **Append-only.**
- strikes: id, channel_id/user_id, action_id, expires_at.
- appeals: id, action_id, appellant_user_id, statement, state (submitted/in_review/upheld/overturned), reviewer_id, decided_at. UQ (action_id) one appeal per action.

#### admin_audit_log
- id, actor_id, actor_role, action, target_type, target_id, request_id, ip, before (json), after (json), reason, created_at. **Append-only**, write-once storage replica at growth.

#### outbox (infrastructure)
- id, aggregate_type, aggregate_id, event_type, event_version, payload, trace_context, created_at, published_at. IDX (published_at NULLS FIRST, id).

#### processed_messages (infrastructure)
- consumer_name, message_id, processed_at. PK (consumer_name, message_id). TTL cleanup after retention window. Used for idempotent consumers.

### 9.4 Database technology strategy

| Technology | Strengths | Weaknesses | Role here |
|---|---|---|---|
| **PostgreSQL** | ACID, rich indexing (partial, GIN, BRIN), FTS, JSONB, partitioning, mature managed offerings, logical replication/CDC | Single-writer; vertical write scaling until sharded | **Source of truth for all transactional data [MVP→SCALE]** |
| MySQL | Mature, great replication, Vitess for sharding | Weaker FTS/partial indexes/JSON; team preference matters | Valid alternative; choose PG for feature breadth |
| **Redis** | Sub-ms latency, counters, sorted sets, rate limiting, TTLs | Memory-bound; persistence is not a ledger | **Cache, sessions/revocation, rate limits, hot counters, feed/trending lists [MVP]** |
| **OpenSearch/Elasticsearch** | Relevance ranking, fuzzy, analyzers per language, aggregations | Ops cost, eventual consistency, not a source of truth | **Search [GROWTH]** |
| **ClickHouse** | Columnar, very fast aggregations over billions of rows, cheap storage | Not for point updates/transactions | **Analytics store [GROWTH→SCALE]** |
| DynamoDB / Cassandra / ScyllaDB | Predictable latency at massive write scale, partitioned by design | Limited query flexibility, modelling discipline, cost model | **[SCALE] candidates** for watch_history, notifications, feed inboxes, reactions if PG sharding is undesirable |
| Data warehouse (BigQuery, Snowflake, Redshift) + data lake (S3 + Parquet/Iceberg) | Ad-hoc analysis, ML training data, BI | Latency minutes+, cost per query | **Platform BI and ML training [GROWTH→SCALE]** |

### 9.5 Which data lives where

| Data class | Store | Source of truth? | Rebuildable from |
|---|---|---|---|
| Transactional (users, channels, videos, comments, reactions, subscriptions, playlists, history, moderation, sessions) | PostgreSQL | **Yes** | Backups/PITR |
| Media files | Object storage | **Yes** (for bytes) | Originals → renditions can be re-derived |
| Cache (metadata, pages, policies) | Redis | No | PostgreSQL |
| Hot counters (views/likes in-flight) | Redis | No (transient) | Events + PG aggregates |
| Rate limits, revocation lists | Redis | Effectively yes for its TTL; loss is tolerable (fail-open/closed policy per endpoint) | — |
| Search index | PG FTS → OpenSearch | No | PostgreSQL (full reindex) |
| Raw events | MVP: PG partitions / S3; Scale: Kafka → S3 lake | **Yes** for analytics | — (immutable log) |
| Aggregated analytics | PG rollups → ClickHouse | No (derived) | Raw events |
| Recommendation features/models | Feature store / S3 / Redis | No | Raw events + metadata |

**Single-source-of-truth rule:** user-visible authorization decisions (can X see video Y?) read only from PostgreSQL-derived data (or caches invalidated from it), never from search or analytics stores.

---

## 10. Upload Architecture

### 10.1 Principles
- Video bytes go **client → object storage** directly via presigned URLs. The API issues credentials and tracks state; it never proxies bytes.
- Uploads are **multipart** above a threshold (e.g., > 64 MB) and always **resumable**.
- The control plane is authoritative for "upload complete"; storage notifications are a reconciliation safety net.

### 10.2 Upload flow

```
Client                     Core API (Upload module)            Object Storage            Queue / Media plane
  │ 1. POST /videos (metadata draft) ─►│ create video (DRAFT)            │                          │
  │◄────────────── video_id ──────────│                                  │                          │
  │ 2. POST /uploads {video_id, size, │                                  │                          │
  │    content_type, sha256?, Idem-Key}│ validate quotas/limits          │                          │
  │                                    │ CreateMultipartUpload ─────────►│                          │
  │                                    │◄──────── upload_id ─────────────│                          │
  │◄── session_id, part_size, parts, ──│ store upload_session            │                          │
  │    first N presigned part URLs     │ video → UPLOAD_PENDING          │                          │
  │ 3. PUT part 1..N (parallel 3–6) ──────────────────────────────────►  │                          │
  │◄──────────────────── ETag per part ─────────────────────────────────│                          │
  │ 4. POST /uploads/{id}/parts:sign (more URLs as needed, batch)  ──►   │                          │
  │ 5. POST /uploads/{id}/complete {parts:[(n,etag)...]} ─►│             │                          │
  │                                    │ CompleteMultipartUpload ───────►│                          │
  │                                    │ HEAD object (size, checksum)    │                          │
  │                                    │ session → completed             │                          │
  │                                    │ video → UPLOADED (same txn)     │                          │
  │                                    │ outbox: VideoUploaded ─────────────────────────────────────►│
  │◄──────── 202 {status: UPLOADED} ───│                                  │   ObjectCreated event ──►│ (reconciliation)
```

Video moves to `UPLOADING` on the first part-URL request or the first status poll that sees parts.

### 10.3 Normal vs large uploads

| Case | Approach |
|---|---|
| Small (≤ 64 MB — thumbnails, captions, short clips) | Single presigned PUT (or POST policy) with content-length range and content-type conditions. |
| Large (> 64 MB) | Multipart: part size chosen by server = max(8 MB, ceil(size / 9,000)) rounded to MB, staying under the provider's 10,000-part limit; 16–64 MB typical. |
| Very large (multi-GB on poor networks) | Same multipart; smaller parts on mobile (8–16 MB) to reduce re-send cost; client concurrency 3–6. |
| Alternative protocol | **tus** resumable protocol via a tus server writing to storage — consider only if clients need a standard protocol across many SDKs; it reintroduces bytes through our servers. **Recommendation: native multipart.** |

### 10.4 Resumable upload & retry
- **Resume:** client calls `GET /uploads/{id}` → server lists parts already stored (ListParts against storage — storage is truth for parts) and returns missing part numbers + fresh URLs.
- **Retry policy (client):** per-part retries with exponential backoff + jitter (e.g., 1s, 2s, 4s… cap 30s, max 8 attempts); refresh presigned URL on 403/expiry; whole-session resume on app restart (session id persisted locally).
- **Presigned URL TTL:** 15–60 min per part URL; issue in batches (e.g., 20 at a time) so leaked URLs have little value.
- **Session TTL:** 24 h of inactivity (configurable up to 7 days for creators); `expires_at` slides on activity.

### 10.5 Cancellation, expiration, abandoned uploads
- **Cancel:** `DELETE /uploads/{id}` → AbortMultipartUpload; session → aborted; video returns to `DRAFT` (or deleted if the user discards).
- **Expiration sweeper** (scheduled job, every 15 min): sessions past `expires_at` → abort multipart → `expired`; video → `UPLOAD_FAILED`/back to DRAFT; notify creator if they had progressed > 50%.
- **Storage lifecycle rule (belt-and-braces):** abort incomplete multipart uploads after 7 days on the uploads bucket — catches anything the sweeper misses.
- **Drafts never completed:** delete draft video records after 30 days.

### 10.6 Validation
| Layer | Check | When |
|---|---|---|
| Session creation | Declared size ≤ per-tier limit (MVP 10 GB; verified creators 50 GB at scale); declared content-type in allowlist (`video/mp4`, `video/quicktime`, `video/x-matroska`, `video/webm`, `video/x-msvideo`, …); daily upload quota per user/channel; account in good standing; email verified | Sync |
| Presign conditions | Content-Length range per part; object key fixed by server (`uploads/{video_id}/{session_id}/source`) — client never chooses keys | Sync |
| Completion | Object exists; size matches declared (± 0); multipart ETags match; optional whole-file SHA-256 verified via storage checksum features or by worker | Sync (fast) / async (hash) |
| Processing | **Magic-byte sniffing + full container probe** (never trust extension/MIME header); duration ≤ max; streams present; codec decodable; malware scan; dimension sanity | Async (§11) |

### 10.7 Checksums & integrity
- Per-part integrity: storage-native checksum on each part (e.g., `Content-MD5` or CRC32C/SHA-256 checksum headers) so corrupted parts are rejected on PUT.
- Whole-file: optional client-declared SHA-256; verified by the probe worker (it reads the whole file anyway). Mismatch → `UPLOAD_CORRUPTED` → creator re-uploads.

### 10.8 Duplicate upload handling & idempotency
- **Request idempotency:** `Idempotency-Key` header on session creation and completion. Server stores (user_id, key) → response for 24 h. Retried "create" returns the same session; retried "complete" on a completed session returns 200 with current state.
- **Completion race:** completion uses a conditional state transition (`UPDATE … WHERE status IN ('initiated','in_progress')` with state_version) so two concurrent completes produce one `VideoUploaded`.
- **Content duplicates:** worker computes perceptual + exact hash; exact duplicate of the *same creator's* existing video → warn creator (don't block, they may re-upload intentionally); duplicates of *blocked* content → hash-match blocking (§25); duplicates of other creators' content → feeds copyright/Content-ID system **[SCALE]**.

### 10.9 Upload session status model

```
initiated ──► in_progress ──► completing ──► completed
    │              │               │
    ├──────────────┴──► aborted (user cancel)
    ├──────────────┴──► expired (sweeper)
                       completing ──► failed (size/etag mismatch; retryable by client re-complete or re-upload)
```

### 10.10 Conceptual upload API

| Endpoint | Purpose |
|---|---|
| `POST /v1/videos` | Create draft with metadata (title can be defaulted to filename) |
| `POST /v1/videos/{videoId}/uploads` | Create upload session (size, type, checksum, Idempotency-Key) → session_id, part_size, part URLs |
| `POST /v1/uploads/{sessionId}/parts:sign` | Request presigned URLs for given part numbers |
| `GET /v1/uploads/{sessionId}` | Status + parts already received (resume) |
| `POST /v1/uploads/{sessionId}:complete` | Finalize with part list (idempotent) |
| `DELETE /v1/uploads/{sessionId}` | Abort |
| `GET /v1/videos/{videoId}/processing` | Processing status, progress, errors |

---

## 11. Media Processing

### 11.1 Pipeline overview

```
VideoUploaded (outbox) ─┐
Storage ObjectCreated ──┴─► [processing-requests queue] ─► Processing Coordinator
                                                             │ creates job + tasks (idempotent on video_id+version)
                                                             ▼
                                      ┌──────────── task queues (by class) ─────────────┐
                                      │ probe-q      transcode-q (CPU/GPU)   light-q     │
                                      └──────┬──────────────┬──────────────────┬────────┘
                                             ▼              ▼                  ▼
                                       Worker pool: probe → validate → scan → transcode renditions (parallel)
                                       → package (HLS/DASH, CMAF) → thumbnails/sprites → captions → finalize
                                             │ results/heartbeats
                                             ▼
                                   Coordinator updates job/tasks, writes video_variants/assets
                                             │
                    first playable rendition ├─► VideoRenditionReady → Video: PROCESSING → READY(partial)
                    all required done        ├─► VideoProcessingCompleted → Video: READY → (auto) PUBLISHED
                    unrecoverable failure    └─► VideoProcessingFailed → Video: PROCESSING_FAILED
                                   failed messages after N attempts ─► DLQ (alert + admin console)
```

### 11.2 Orchestration options

| Option | Pros | Cons | Stage |
|---|---|---|---|
| **Coordinator in our code + task table + SQS queues** | Simple, transparent, no new tech, fits monolith | We own retries/timeouts/state | **[MVP]** |
| AWS Step Functions / Temporal / Cadence | Durable workflows, visual state, built-in retries, long-running | New tech to learn; Temporal requires cluster ops (or cloud offering) | **[GROWTH]** when workflows branch (moderation gates, multi-codec, per-segment encoding, re-processing campaigns) |
| Managed transcoding (AWS MediaConvert, GCP Transcoder API, Azure (third-party)) | No encoder ops, good quality, accelerated modes, packaging built-in | Per-minute cost is high at scale, less control, vendor lock | **[MVP] option** if team lacks video expertise; reassess at growth |
| Self-managed FFmpeg workers on containers (spot) | Lowest unit cost at scale, full control (per-title, custom ladders) | Need video expertise, ops for scaling/updates/security of codecs | **[MVP] recommended if ≥1 engineer has media expertise; [GROWTH] recommended** |

**Recommendation:** Coordinator + task queues at MVP. Encoding engine behind an internal "Encoder" interface so MVP can use **managed transcoding** (fastest to ship, predictable) **or** FFmpeg workers; plan to move bulk encoding to **FFmpeg on spot instances** at growth when transcoding spend exceeds roughly the cost of one engineer's time to run it (ADR-006). Keep managed service as overflow/fallback.

### 11.3 Steps in detail

1. **Trigger.** `VideoUploaded` event → coordinator. Storage `ObjectCreated` notifications go to a reconciliation consumer that only acts if no job exists after N minutes (covers lost client "complete" calls — it performs the completion server-side).
2. **Inspect (probe).** Read container/stream metadata: duration, streams, codecs, resolution, rotation, SAR/DAR, frame rate (CFR/VFR), HDR metadata, audio channels/sample rate, bitrate. Fast — reads headers and samples.
3. **Validate.** Reject if: not a video, no video stream, duration > limit or < 1 s, resolution > 8K or < 128 px, corrupt/undecodable, encrypted/DRM'd source, file sniffing mismatch. Classify as **non-retryable** errors → `PROCESSING_FAILED` with user-facing reason.
4. **Security & safety scan.** Malware scan of the source file (container exploits target decoders); hash matching against known-illegal content (e.g., industry CSAM hash lists via an approved provider) — **mandatory before any publication [MVP for hash-matching via provider; GROWTH for in-house classifiers]**. Video classifiers (nudity/violence) **[GROWTH]**.
5. **Normalize (optional mezzanine) [GROWTH].** For awkward sources (VFR, interlaced, odd codecs, rotation), produce a normalized high-quality intermediate to make downstream steps deterministic and enable chunked parallel encoding.
6. **Transcode renditions.** One task per rendition (parallel across workers) at MVP; at scale, **chunked encoding**: split mezzanine into GOP-aligned chunks (e.g., 10–30 s), encode chunks in parallel, concatenate — cuts wall-clock for long videos.
7. **Audio processing.** Decode, downmix to stereo AAC-LC 128 kbps (plus 64 kbps low variant **[GROWTH]**), loudness normalization (target ~-14 LUFS integrated, measured and stored as metadata; apply normalization at playback via gain metadata or bake in — decide in ADR), preserve 5.1 as separate track **[SCALE]**; multiple audio languages **[SCALE]**.
8. **Package.** CMAF fMP4 segments, **4–6 s** segment duration with keyframe-aligned GOPs across all renditions (fixed GOP = segment duration, closed GOP, scene-cut disabled at boundaries) so the player can switch at any segment boundary. Generate HLS media playlists + master playlist; DASH MPD **[optional]** from the same segments.
9. **Thumbnails.** Extract candidates at scene-change frames (e.g., 3 picks at ~25/50/75% avoiding black/blurred frames), multiple sizes (e.g., 1280×720, 640×360, 320×180, WebP/AVIF + JPEG fallback).
10. **Preview sprites (trickplay).** Every N seconds (e.g., 5–10 s) a small frame; tiled into sprite sheets + WebVTT index (or HLS I-frame playlists) for scrub previews **[MVP: optional; GROWTH: standard]**.
11. **Captions.** Validate and convert uploaded SRT/VTT → WebVTT; reference in master playlist as subtitle renditions. Auto-captions via ASR **[SCALE]** (also feeds search and moderation).
12. **Metadata extraction.** Duration, dimensions, aspect ratio, HDR flag, audio info → `videos`/`video_assets`.
13. **Finalize.** Write variants/assets in DB, upload manifests last (so a manifest never references missing segments), emit completion event. Clean temp files.

### 11.4 Progressive availability
- Produce a **fast first rendition** (e.g., 480p or 720p) with priority; mark video `READY` once ≥1 rendition + master manifest exist ("partial ladder"). The master playlist is regenerated as higher renditions finish. Creators can publish sooner; viewers get HD shortly after.
- Priority queues: first-rendition tasks for all videos have priority over high-resolution tasks; verified/high-subscriber creators get a priority lane **[GROWTH]**.

### 11.5 Determining the rendition ladder

**Rule:** never upscale. Generate rungs whose height ≤ source height (using the *display* resolution after rotation and SAR correction), always include at least one low rung for poor networks.

Reference H.264 ladder (16:9, 30 fps; ×1.5 bitrate for 50/60 fps):

| Rung | Resolution | Target avg bitrate (H.264) | Generate when source ≥ | Stage |
|---|---|---|---|---|
| 240p *(optional)* | 426×240 | 300 kbps | always | GROWTH (emerging markets) |
| 360p | 640×360 | 600–800 kbps | always | MVP |
| 480p | 854×480 | 1.0–1.4 Mbps | 480p | MVP |
| 720p | 1280×720 | 2.5–3.0 Mbps (4.5 @60) | 720p | MVP |
| 1080p | 1920×1080 | 4.5–6 Mbps (7.5 @60) | 1080p | MVP |
| 1440p | 2560×1440 | 9–12 Mbps (HEVC/AV1 preferred) | 1440p | GROWTH |
| 2160p (4K) | 3840×2160 | 16–20 Mbps H.264 / 10–14 HEVC / 8–12 AV1 | 2160p **and** (popular or premium creator) | GROWTH/SCALE |

- **Aspect ratio:** define rungs by the **short side** (so vertical 1080×1920 gets "1080p" = 1080 wide); preserve source aspect ratio; no letterboxing baked into renditions; even-number dimensions; store display aspect for the player.
- **Frame rate:** keep source frame rate up to 60; convert VFR → CFR; low rungs (≤ 480p) may halve 50/60 fps to 25/30.
- **HDR:** **[SCALE]** produce HDR10/HLG rungs in HEVC/AV1 plus SDR tone-mapped H.264 for compatibility; MVP tone-maps HDR sources to SDR.
- **Per-title / content-aware encoding [GROWTH]:** run a fast complexity analysis (or trial encodes) and adjust bitrates per video (cartoons/screen recordings need far less bitrate than sports) — typically 20–40% bandwidth savings.
- **Lazy high rungs [GROWTH]:** for 4K and secondary codecs, encode **on demand** once a video crosses a popularity threshold (e.g., > 1k views in 24 h), from the retained original/mezzanine.

### 11.6 Codec strategy

| Codec | Compatibility | Efficiency vs H.264 | Encode cost | Use |
|---|---|---|---|---|
| **H.264/AVC (High profile)** | Universal | 1× | 1× | **[MVP] full ladder for every video** |
| HEVC/H.265 | Apple devices, most TVs, partial browsers; licensing complexity | ~30–40% smaller | 2–5× | **[SCALE]** 1440p/4K/HDR, Apple ecosystem |
| **AV1** | Modern browsers, Android, newer TVs/hardware decoders | ~40–50% smaller | 5–20× (software); hardware encoders improving | **[SCALE]** for top ~5–10% most-watched videos (where egress savings > encode cost) |
| VP9 | Browsers, Android | ~30–40% | 3–5× | Skip (AV1 supersedes) unless legacy TV support needed |
| AAC-LC | Universal | — | low | **[MVP]** audio |
| Opus | Browsers/Android | better at low bitrates | low | **[SCALE]** optional |

The master manifest advertises codecs (`CODECS` attribute); players pick the best supported. Decision rule for extra codecs: *encode if expected egress savings over 90 days > encode + storage cost*.

### 11.7 HLS and DASH
- **HLS [MVP]** is mandatory (only native option on iOS/Safari/tvOS).
- **CMAF fMP4 segments [MVP]** (not MPEG-TS) so the *same segments* serve HLS and DASH and work for HEVC/AV1 later.
- **DASH [optional/GROWTH]:** generate an MPD referencing the same CMAF segments when we need DRM via Widevine on Android/Chrome-based TVs or specific device partners. Near-zero storage overhead with CMAF.
- **DRM [future]:** CMAF with CENC/CBCS encryption + multi-DRM key server (FairPlay, Widevine, PlayReady) only if paid/premium content requires it. Signed URLs are sufficient for UGC.

### 11.8 Failure handling, retries, DLQ
| Failure class | Example | Handling |
|---|---|---|
| Transient infra | Spot interruption, worker OOM, storage 5xx, timeout | Task lease expires → task re-queued; retry with exponential backoff; max 3–5 attempts |
| Resource | OOM on 4K source | Retry on larger instance class (escalation queue) |
| Deterministic media | Corrupt stream, unsupported codec | No retry → `PROCESSING_FAILED` with reason code; creator notified with actionable message |
| Partial | 4K rung fails, others succeed | Job `partially_succeeded`; publish with available rungs; alert; retry rung later |
| Poison message | Malformed message or crash loop | After max receives → DLQ; alert; admin console can inspect/replay |

- **Leases & heartbeats:** workers heartbeat every ~30 s (extend queue visibility timeout); coordinator's stuck-task detector requeues tasks whose `lease_expires_at` passed.
- **Idempotent outputs:** output keys are deterministic (`media/{video_id}/v{processing_version}/{rendition}/…`), so a retried task overwrites identical content; DB writes are upserts keyed by (video_id, rendition, version).
- **Reprocessing:** bump `processing_version`, run a new job, atomically switch `videos.current_processing_version` when complete, retire old outputs after a grace period (CDN caches keep serving old version until TTL — paths are versioned so no invalidation needed).

### 11.9 Worker design (conceptual)
- Stateless containers; local NVMe scratch for working files; pull source via ranged GETs or stream; push outputs via multipart.
- Separate pools: **light** (probe, thumbnails, packaging — small instances), **heavy** (transcode — compute-optimized, spot, optional GPU), **scan** (isolated sandbox, no network egress beyond storage).
- Decoders process untrusted input → run with seccomp/AppArmor, no credentials beyond scoped storage access for the specific prefix, read-only filesystem except scratch, CPU/memory/time limits, kept patched (§24).

---

## 12. Video Lifecycle

### 12.1 States

| State | Meaning | Visible to |
|---|---|---|
| `DRAFT` | Metadata exists, no upload started | Owner |
| `UPLOAD_PENDING` | Upload session created | Owner |
| `UPLOADING` | Parts being received | Owner |
| `UPLOADED` | Source complete & verified in storage | Owner |
| `VALIDATING` | Probe, validation, safety scan | Owner |
| `QUEUED_FOR_PROCESSING` | Valid, waiting for workers | Owner |
| `PROCESSING` | Transcoding/packaging in progress | Owner |
| `READY` | ≥1 rendition playable; not published | Owner (preview) |
| `PUBLISHED` | Live per visibility (public/unlisted/private) | Per visibility rules |
| `SCHEDULED` **[GROWTH]** | READY + publish time set | Owner |
| `UPLOAD_FAILED` | Session expired/aborted/corrupt | Owner |
| `PROCESSING_FAILED` | Unrecoverable processing error | Owner |
| `REJECTED` | Failed safety scan (e.g., hash match) | Owner (reason), T&S |
| `BLOCKED` | Removed by moderation (policy/legal) | Owner (reason, appeal) |
| `UNPUBLISHED` | Owner took it down (kept) | Owner |
| `DELETED` | Soft deleted; purge scheduled | Nobody (T&S/legal hold only) |
| `PURGED` | Bytes and personal data removed; tombstone remains | — |

**Orthogonal attributes** (not states): `visibility` (public/unlisted/private), `moderation_status` (none/pending_review/approved/age_restricted/limited), `region_policy`. Keeping visibility orthogonal avoids a combinatorial explosion of states.

### 12.2 State diagram

```
                ┌────────┐ create session  ┌────────────────┐ first part  ┌───────────┐ complete ok ┌──────────┐
   create ────► │ DRAFT  │ ──────────────► │ UPLOAD_PENDING │ ──────────► │ UPLOADING │ ──────────► │ UPLOADED │
                └───▲────┘                 └───────┬────────┘             └─────┬─────┘             └────┬─────┘
                    │ retry upload                 │ abort/expire               │ abort/expire/corrupt    │ job created
                    │                     ┌────────▼─────────┐◄──────────────────┘                         ▼
                    └──────────────────── │  UPLOAD_FAILED   │                                     ┌────────────┐
                                          └──────────────────┘                    scan hit ┌─────── │ VALIDATING │
                                                                                            │        └─────┬──────┘
                                                         ┌──────────┐◄──────────────────────┘    invalid │ valid
                                                         │ REJECTED │                                    │
                                                         └──────────┘   ┌───────────────────┐◄───────────┘
                                                                        │ QUEUED_FOR_PROC.  │
                                         ┌──────────────────────┐       └─────────┬─────────┘
                                         │  PROCESSING_FAILED   │◄─── unrecoverable│ worker picks up
                                         └──────┬───────────────┘     ┌───────────▼─────┐
                                   retry/new    │ (admin/creator)     │   PROCESSING    │ ── transient error ──┐
                                   upload ──────┴──► QUEUED / DRAFT   └───────────┬─────┘ ◄── retry (≤N) ───────┘
                                                                     first rendition│
                                                                         ┌────────▼──────┐  publish (owner or auto)  ┌───────────┐
                                                                         │     READY     │ ─────────────────────────►│ PUBLISHED │
                                                                         └──┬────────▲───┘ ◄──── unpublish ───────── └─────┬─────┘
                                                              schedule [G]  │        │                                      │
                                                                      ┌─────▼─────┐  │ time reached                          │
                                                                      │ SCHEDULED │──┘──────────────────────────────────────►│
                                                                      └───────────┘

   From any non-terminal state:  ── moderation block ──► BLOCKED ── appeal upheld/restore ──► previous state (READY/PUBLISHED)
                                 ── owner delete ─────► DELETED ── (grace 30d, no legal hold) ──► PURGED
   PUBLISHED ── owner unpublish ──► UNPUBLISHED ── republish ──► PUBLISHED
```

### 12.3 Transition rules

| From | To | Trigger | Guard |
|---|---|---|---|
| DRAFT | UPLOAD_PENDING | upload session created | owner; quota; account in good standing |
| UPLOAD_PENDING | UPLOADING | first part signed/uploaded | — |
| UPLOAD_PENDING/UPLOADING | UPLOAD_FAILED | abort/expire/integrity failure | — |
| UPLOAD_FAILED | UPLOAD_PENDING | new session | — |
| UPLOADING | UPLOADED | completion verified | conditional update (idempotent) |
| UPLOADED | VALIDATING | coordinator creates job | job unique per (video, version) |
| VALIDATING | QUEUED_FOR_PROCESSING | probe+validation+scan pass | — |
| VALIDATING | PROCESSING_FAILED | invalid media | non-retryable reason code |
| VALIDATING | REJECTED | safety hash/classifier hit (high confidence) | creates moderation case; legal reporting workflow |
| QUEUED | PROCESSING | first task leased | — |
| PROCESSING | PROCESSING (retry) | transient failure | attempts < max |
| PROCESSING | PROCESSING_FAILED | exhausted retries / deterministic failure | — |
| PROCESSING | READY | first playable rendition + master manifest | — |
| PROCESSING_FAILED | QUEUED_FOR_PROCESSING | admin/creator retry (e.g., after worker fix) | source still retained |
| READY | PUBLISHED | owner publishes or `publish_on_ready` flag | moderation_status not blocked/pending (if pre-publish review required for this account tier); required metadata present |
| READY | SCHEDULED | schedule set | time in future |
| SCHEDULED | PUBLISHED | scheduler fires | same guards as publish |
| PUBLISHED | UNPUBLISHED | owner | — |
| UNPUBLISHED | PUBLISHED | owner | guards as publish |
| any (except PURGED) | BLOCKED | moderation action | moderator/system with policy reason |
| BLOCKED | prior state | appeal overturned / restore | moderator |
| any | DELETED | owner/admin | — |
| DELETED | PURGED | purge job after grace | no legal hold |
| DELETED | prior state | owner undo within grace (optional) | — |

**Implementation rules (conceptual):**
- State lives on `videos.status` with `state_version`; every transition is a conditional update `WHERE status = :expected AND state_version = :v`. Invalid transitions are rejected and logged.
- Each transition writes an outbox event in the same transaction (`VideoStateChanged` + specific events like `VideoPublished`).
- Workers never set `PUBLISHED`; only the Video module decides publication.
- Processing completion after a video was `DELETED`/`BLOCKED` is recorded (variants stored) but does **not** move the state.

### 12.4 Publication rules
- Publishing requires: status READY (or later), title present, category set (default allowed), not BLOCKED/REJECTED, moderation_status not `pending_review` when the channel is in the "pre-review" tier (new accounts, prior strikes) **[GROWTH]**.
- `publish_on_ready` lets creators choose "publish when processing finishes".
- Visibility changes take effect immediately for authorization (strong consistency), and propagate asynchronously to search/feeds (seconds). Because playback cookies are short-lived (§13), access to a just-privatized video ends within the token TTL.

### 12.5 Moderation transitions
- `moderation_status` changes (age_restricted, limited) don't change `status`; they change who can see/how it's distributed (search/recommendations exclude `limited`).
- BLOCKED stores `blocked_reason`, `blocking_action_id`, and whether the block is global or regional (legal geo-block = `region_policy` not BLOCKED).
- Restores return to the stored `pre_block_status`.


---

## 13. Playback

### 13.1 Flow

```
Client                    Core API (Playback)                        CDN edge                Origin (object storage)
  │ GET /v1/videos/{id}/playback ─►│                                     │                           │
  │  (access token optional)       │ 1. load access policy (cache→PG):   │                           │
  │                                │    status, visibility, owner,       │                           │
  │                                │    moderation, age, region          │                           │
  │                                │ 2. evaluate rules for caller        │                           │
  │                                │ 3. pick manifest(s) & version       │                           │
  │                                │ 4. sign cookie/URL scoped to        │                           │
  │                                │    /media/{video_id}/v{n}/* , TTL   │                           │
  │◄── {manifest_url, cookies,     │ 5. resume position, captions list,  │                           │
  │     thumbnails, sprites,       │    analytics session id             │                           │
  │     playback_session_id}       │                                     │                           │
  │ GET master.m3u8 (cookie) ──────────────────────────────────────────►│ verify signature          │
  │                                                                      │ hit? serve : fetch ──────►│ (OAC-only access)
  │ GET rendition playlists / segments ────────────────────────────────►│ cache (long TTL)          │
  │ POST /v1/events (beacons: start, heartbeat, quality, buffering) ──► Analytics ingestion (async)
```

The backend authorizes once per playback session (plus refresh for long sessions); it never touches segments.

### 13.2 Authorization rules

| Video condition | Rule |
|---|---|
| status ≠ PUBLISHED | Only owner, channel managers, moderators/admins (preview). |
| visibility = public | Anyone (subject to age/region/moderation). |
| visibility = unlisted | Anyone with the link (knowledge of unguessable `public_id`). Not listed in search/feeds/channel page. |
| visibility = private | Owner, channel members, and explicitly shared users **[GROWTH]**. |
| BLOCKED / REJECTED / DELETED | Deny all (moderators may preview BLOCKED via admin tools). |
| age_restricted | Require authenticated user with verified age ≥ 18 (age gating per jurisdiction); deny embeds. |
| region_policy **[GROWTH]** | Allow/deny by viewer country (geo-IP at edge; CDN geo-restriction as second layer). |
| subscriber-only / paid **[future]** | Check entitlement (`entitlements` table: user, scope=channel/video/plan, valid_from/to) — cached per user. |
| embed restrictions **[GROWTH]** | Check `Referer`/origin allowlist on playback API for embedded players. |

Evaluation is a pure function of (video policy, viewer context). Video policy is cached (key `vpolicy:{video_id}:{state_version}`) with event-driven invalidation; viewer context comes from the access token.

### 13.3 Signed URLs vs signed cookies vs tokens

| Mechanism | How | Pros | Cons | Use |
|---|---|---|---|---|
| Signed URL per object | Signature in query string per file | Simple | HLS has hundreds of segment URLs — signing each requires manifest rewriting | Single files (downloads, thumbnails of private videos) |
| **Signed cookies** (path-scoped, wildcard policy) | Cookie with policy for `/media/{video_id}/v{n}/*`, expiry | One signature covers manifest + all segments; manifests stay static & cacheable | Cookies need same parent domain (e.g., `media.example.com` under `example.com`); some TV/native players need special handling | **[MVP] web & apps** |
| Tokenized path / query token appended by manifest rewriting at edge | Edge function adds token to segment URLs | Works without cookies | Edge compute cost; manifests become per-user (less cacheable) | **[GROWTH]** for cookie-less clients (some smart TVs, AirPlay/Chromecast receivers) |

**Recommendation:** signed cookies for primary clients, signed URLs for single objects; edge-tokenization only for cookie-less device classes.

**Public videos:** still signed (cheap, uniform, prevents hotlinking/bandwidth theft and enforces takedowns quickly). An option at MVP is to serve public renditions unsigned for maximal cache efficiency; we **recommend signing everything** because cache keys exclude the signature, so caching is unaffected.

### 13.4 Token expiration
- Playback cookie/URL TTL: **duration of video + 30 min, capped at 6 h**, minimum 1 h. Player calls `playback` again (refresh) on 403 or near expiry.
- Short enough that unpublishing/blocking takes effect within the TTL; for urgent legal takedowns, remove/rename origin objects + CDN invalidation (§14.6).
- Signing keys: asymmetric CDN key pairs, private key in secrets manager/KMS, **rotated** quarterly with overlap (two active key IDs).

### 13.5 Origin protection
- Media bucket **not public**; only the CDN can read (origin access control / bucket policy restricted to the CDN's identity).
- CDN rejects requests without valid signature for `/media/*` paths.
- Origin shield **[GROWTH]** reduces origin load and request cost.
- Rate limits/WAF at edge for anomalous segment request patterns (scrapers).

### 13.6 Adaptive bitrate (ABR) streaming
- The **master playlist** lists every rendition with bandwidth, resolution, codecs, frame rate. Media playlists list segments (4–6 s each).
- The **player** (client-side; we don't control it but set expectations): 
  - Starts with a conservative rendition (estimate from previous session bandwidth or ~480p/720p) to minimize startup time.
  - Measures throughput per segment download (EWMA) and buffer level.
  - **Hybrid algorithm** (throughput + buffer, e.g., BOLA-style or player defaults in hls.js/ExoPlayer/AVPlayer): switch up when sustained throughput > next rung's bitrate × safety factor (~1.2–1.5) **and** buffer is healthy (> 10–15 s); switch down quickly when buffer drains below a threshold (< 5–8 s).
  - Caps resolution at viewport size (no 1080p in a 360 px window) and respects user "data saver"/manual quality.
  - Because segments are keyframe-aligned across renditions, switches happen seamlessly at segment boundaries.
- **Backend's role:** produce a good ladder, aligned segments, accurate `BANDWIDTH`/`AVERAGE-BANDWIDTH` values, and collect QoE telemetry (startup time, rebuffer ratio, average bitrate, switches) to tune the ladder (§19).
- **Low-latency starts:** first segment short (e.g., 2 s) **[GROWTH]**; `EXT-X-INDEPENDENT-SEGMENTS`; preload hints not needed for VOD.

### 13.7 Playback response (conceptual)
`{ video: {id, title, duration, aspect}, streams: {hls: url, dash?: url}, auth: {type: cookie|query, expires_at}, captions: [...], thumbnails: {...}, storyboard: {...}, resume_position_ms, playback_session_id, analytics: {beacon_url, heartbeat_interval_s} }`

---

## 14. CDN

### 14.1 Topology

```
Viewer ─► CDN edge PoP (L1 cache) ─► [Origin shield / regional mid-tier (L2)] ─► Origin: object storage (media bucket)
                                         [GROWTH]                                   (+ optional origin for API GET caching)
```
Separate CDN distributions/hostnames:
- `media.example.com` — manifests, segments, sprites, captions (signed).
- `img.example.com` — thumbnails, avatars, banners (public, image resizing at edge optional **[GROWTH]**).
- `api.example.com` — API (pass-through + WAF; cache only explicit public GETs with short TTL **[GROWTH]**).

### 14.2 Cache keys
- **Media:** path only (`/media/{video_id}/v{version}/{rendition}/{segment}`); **exclude** query strings, cookies, signatures, auth headers → one cached copy shared by all viewers.
- Include `Accept-Encoding` only for text (manifests, VTT). Never vary on user-specific headers.
- **Images:** path + normalized size/format parameter (whitelist) if edge resizing is used.

### 14.3 TTLs

| Object | TTL | Why |
|---|---|---|
| Media segments (immutable, versioned path) | 1 year (`immutable`) | Content never changes at a given path |
| Rendition (media) playlists (VOD, versioned) | 1 day–1 year | Immutable once complete |
| **Master playlist** | 1–5 min | Changes as higher rungs finish (progressive ladder) and when a version switches |
| Thumbnails (versioned keys) | 1 year | New thumbnail = new key |
| Captions (versioned) | 1 day | Edits produce new version |
| Error responses (403/404) | 0–10 s | Avoid caching transient states (e.g., during processing) |
| API public GETs (trending lists, public channel pages) **[GROWTH]** | 10–60 s with `stale-while-revalidate` | Absorb spikes |

**Versioned paths** (`v{processing_version}`, content-hash thumbnail keys) are the core strategy — they make invalidation almost unnecessary.

### 14.4 Hot vs cold content
- **Hot** (new viral videos, top 1%): naturally cached at many PoPs; shield collapses concurrent misses (request coalescing) so origin sees ~1 request per object per shield.
- **Cold / long tail** (majority of videos, few views): low edge hit ratio; origin shield **[GROWTH]** is the main lever (one regional cache holds the long tail better than hundreds of PoPs). Storage tiering must not put still-watched renditions in archive classes (§29).
- **Target metrics:** CDN byte hit ratio ≥ 95% (segments), ≥ 90% overall; origin request rate tracked per video.

### 14.5 Cache warming
- Generally **not needed** for VOD — first viewer per PoP warms it.
- **[GROWTH]** Pre-warm the first segments of the low/mid renditions + master playlist at the shield for scheduled premieres or creators with huge audiences (notification fan-out will cause a synchronized spike).

### 14.6 Invalidation
- Avoid by design (versioned paths).
- Required for: takedowns/legal removals (delete origin objects first, then invalidate `/media/{video_id}/*`), master playlist corrections (short TTL handles it), thumbnail replacement (new key, so none).
- Invalidation requests are batched and rate-limited (providers charge/limit); admin tooling exposes "emergency purge".

### 14.7 Signed access at the edge
- CDN verifies signed cookie/URL policy (resource path wildcard, expiry, optional IP-range for high-value content **[future]**).
- Edge functions **[GROWTH]**: geo-restriction enforcement, token validation for cookie-less clients, request normalization.

### 14.8 Evolution to multi-CDN **[SCALE]**
- **Trigger:** egress > ~1–5 PB/month (negotiating leverage), or availability needs beyond one provider's SLA, or regional performance gaps.
- **Approach:** 
  1. Make all URLs CDN-agnostic: playback API returns hostnames chosen by a **CDN selection service** (per-country weights, real-time QoE/availability, cost).
  2. Common signing scheme or per-CDN signing in playback API (cookies/tokens per provider).
  3. Shared origin behind a **dedicated origin shield** we control or one CDN acting as shield for others.
  4. Client-side failover: players switch to an alternate CDN on repeated segment errors (content steering — HLS/DASH content steering standards).
  5. Collect per-CDN QoE from player beacons → steer traffic.
- **Further:** private caches/peering in ISPs (Open Connect-style appliances) only at extreme scale.

---

## 15. Engagement (Reactions, Comments, Subscriptions, Playlists, History)

### 15.1 Reactions (likes/dislikes)
- **Write path:** `PUT /videos/{id}/reaction {like|dislike|none}` → upsert `reactions` row (unique per user/target) in PG → outbox `VideoLiked`/`ReactionChanged` with delta (+1 like, −1 dislike) → Redis `INCRBY` on counters → periodic flush to `video_stats` (every 10–60 s) by aggregator.
- **Read path:** counts from Redis/`video_stats` cache; "my reaction" from PG (or batched lookup cache) → read-your-writes for own state.
- **Dislikes:** store and use (recommendation and quality signal, visible to creator in analytics); **public dislike count hidden** (reduces brigading/harassment). Product decision — flagged in ADR list.
- **Idempotency:** naturally idempotent (upsert final state); deltas derived from previous state inside the transaction, so retries don't double count.
- **Hot videos:** counter increments on one Redis key → use **sharded counters** (`likes:{video}:{0..N}`) for top videos, or batch in-process for 1 s before `INCRBY` **[SCALE]**.
- **Recount job:** nightly reconciliation recomputes counts from `reactions` for videos with drift (or a sample), correcting Redis/aggregates.

### 15.2 Comments
- **Model:** one table, top-level + one level of replies (§9.3). Body limits (e.g., 10k chars), mention parsing, link handling (nofollow, link-shortener expansion for spam checks).
- **Write path:** validate → rate limit → **spam/abuse pre-check** (sync, cheap: blocklists, creator-blocked words, account age, velocity, link heuristics) → insert with status `visible` or `held_for_review` → outbox `VideoCommented` → async: deep spam/toxicity classifier **[GROWTH]**, notifications, counters, ranking.
- **Pagination:** cursor-based. "Newest": cursor = (created_at, id). "Top": cursor = (rank_score, id) over a **snapshot** — rank scores update periodically (not on every like) so pages don't shuffle; snapshot version included in cursor.
- **Ranking ("Top comments"):** score = f(likes, replies, creator heart/pin, author reputation, recency decay, report/negative signals), e.g. Wilson-style lower bound on positive engagement × time decay; recomputed asynchronously in batches for active videos. Pinned first, then creator-hearted boost.
- **Replies:** loaded lazily per parent, chronological, cursor-paginated; `reply_count` denormalized.
- **Edits/deletes:** edit keeps history (for moderation); deleting a parent with replies shows "comment deleted" placeholder.
- **Moderation controls:** creator can remove, hold for review, block user from channel, set blocked words, disable comments; platform moderation via reports and classifiers (§25).

**High-volume videos (millions of comments):**
- Partition `comments` by hash(video_id) **[GROWTH]**; very hot videos generate most rows but reads are paginated (only first pages matter).
- **Precompute & cache first page(s)** of top comments per video in Redis (TTL 30–60 s, refreshed by ranking job); newest comments page cached with very short TTL.
- Count display approximate ("1.2M comments") from aggregate counter.
- Write throttles per video (slow mode **[GROWTH]**) during spikes; queue async work.
- Ranking computed over a candidate pool (e.g., top 5k by engagement + recent) rather than the full set.
- **[SCALE]** Move comments to a wide-column store (Cassandra/Scylla/DynamoDB) keyed by (video_id, bucket) if PG partitions become operationally painful.

### 15.3 Comment & content spam detection
- Layer 1 (sync, MVP): rate limits, duplicate-text detection (hash of normalized text per user/time window), link blocklist, new-account restrictions, creator blocked words.
- Layer 2 (async, GROWTH): ML spam/toxicity classifier; scoring → auto-hide above threshold, "held for review" in grey zone.
- Layer 3 (SCALE): graph-based detection (coordinated accounts, shared IP/device fingerprints), reputation scores.

### 15.4 Subscriptions
- **Storage:** `subscriptions` PK (subscriber_user_id, channel_id) for "my subscriptions" and existence checks; secondary index (channel_id, created_at) for fan-out and subscriber lists.
- **Pagination:** cursor by (created_at, id) both directions.
- **Subscriber count:** Redis counter + `channel_stats` flush (same pattern as likes); displayed **rounded** for large counts (3 significant figures) which also hides minor eventual-consistency drift.
- **Idempotency:** subscribe = upsert; unsubscribe = delete; events emitted only on actual state change.
- **Subscription feed:** see §17 (fan-out-on-read for MVP).
- **Notification integration:** `VideoPublished` → notification fan-out reads subscribers with `notification_level ∈ {all, personalized}` in batches (§15.7).
- **Large channels (millions of subscribers):**
  - Fan-out in **paginated batches** (e.g., 1k subscribers per message) through the queue, rate-limited to protect push providers and our DB.
  - Read from a replica / dedicated subscriber-list store.
  - `personalized` level: only notify subscribers who engaged recently (reduces volume and is better UX).
  - At SCALE: subscriptions sharded by subscriber_id; a second copy partitioned by channel_id (fan-out index) maintained asynchronously.
  - Feed uses fan-out-on-read for celebrity channels in the hybrid model (§17).

### 15.5 Playlists
- Positions as **fractional/lexicographic keys** so reorder = single-row update.
- Max items per playlist (e.g., 5,000); system playlists: Watch Later (real playlist), Liked Videos (virtual view over reactions).
- Unavailable videos (deleted/blocked/private) shown as placeholders, filtered for non-owners.

### 15.6 Watch history & continue watching
- Player sends progress heartbeats (every 10–30 s) to analytics ingestion; History module consumes a **coalesced** stream: Redis hash `progress:{user}` updated per heartbeat, flushed to PG every 30–60 s and on `pause`/`ended`/page-hide events (so cross-device resume is near-real-time).
- Continue watching = recent `watch_history` rows with 5% < progress < 95%, excluding completed; capped at 20.
- Pause history setting respected at ingestion (don't record). User can delete items/clear (propagate to recommendation features via `HistoryCleared` event).

### 15.7 Notification system

**Channels**

| Channel | Use | Stage |
|---|---|---|
| **In-app** (inbox + unread badge) | All notification types | MVP |
| **Email — transactional** (verification, password reset, security alerts, processing failed, moderation decisions) | Must-deliver | MVP |
| **Email — engagement/digest** (new uploads digest, weekly creator summary) | Batched, unsubscribable | GROWTH |
| **Mobile push** (APNs/FCM) | New uploads (bell), replies, mentions | GROWTH |
| Web push | Optional | SCALE |

**Pipeline**
```
Domain event (VideoPublished, CommentReplied, UserMentioned, CommentLiked, VideoProcessingCompleted, ...)
  ─► Notification Router (consumer): determine recipients & type
        • VideoPublished → subscriber fan-out in batches of ~1k (paged by channel_id cursor) as separate queue messages
        • reply/mention/like → single recipient
  ─► Per-recipient: preferences check (type × channel), mute/block checks, quiet hours, frequency caps,
     aggregation (group_key: "12 people liked your comment" — collapse within window), dedupe (dedupe_key)
  ─► Write in-app notification (PG, UQ on dedupe_key → idempotent)  ─► unread counter (Redis)
  ─► Enqueue delivery tasks per external channel: push-q, email-q (priority lanes: transactional ≫ engagement)
  ─► Delivery workers: provider APIs (APNs/FCM, SES/SendGrid) with per-provider rate limits
  ─► Delivery result: delivered/failed → notification_deliveries; invalid tokens → device_tokens.invalidated_at
```
- **Real-time in-app updates [GROWTH]:** WebSocket/SSE gateway or periodic polling of unread count (MVP polling every 60 s or on navigation is enough).
- **Retries:** transient provider errors (5xx, throttling) → exponential backoff with jitter, max ~5 attempts within TTL (engagement push TTL 1–6 h; stale pushes are dropped, not delivered late). Permanent errors (invalid token, hard bounce, unsubscribed) → no retry, mark invalid / suppress address. Exhausted → DLQ + metrics; in-app record still exists (in-app is the durable baseline).
- **Email hygiene:** bounce/complaint webhooks → suppression list; unsubscribe links (one-click) for non-transactional email; SPF/DKIM/DMARC.
- **Large channel uploads:** fan-out throttled (e.g., spread over minutes), `personalized` level reduces volume, push only to devices active in last 30 days; CDN pre-warm for the video's first segments (§14.5).
- **Idempotency:** dedupe_key = hash(event_id, recipient, type) → redelivered events never create duplicate notifications or duplicate pushes (delivery task keyed by notification_id + channel).

---

## 16. Search

### 16.1 Stages

| Stage | Engine | Capabilities |
|---|---|---|
| **[MVP]** | PostgreSQL FTS (`tsvector` on title(A), tags(B), channel name(B), description(C); GIN index) + `pg_trgm` for fuzzy/typo tolerance and prefix autocomplete | Full-text, basic ranking blended with popularity/recency, filters; strongly consistent with DB |
| **[GROWTH]** | OpenSearch/Elasticsearch cluster, separate indexer | Language analyzers, fuzzy, synonyms, completion suggesters, custom scoring, aggregations/facets, horizontal scaling |
| **[SCALE]** | Sharded OpenSearch clusters (videos, channels separate), learning-to-rank, query understanding, personalization, semantic/vector retrieval hybrid | |

**Trigger to move to OpenSearch:** search p95 > 300 ms on PG, search load measurably impacting the primary/replica, need for multi-language analyzers/synonyms/autocomplete quality, or > ~1–5M searchable videos.

### 16.2 Index documents (conceptual)
- **Video doc:** video_id, public_id, title (per-language analyzed + edge-ngram subfield), description (truncated), tags, category, channel_id, channel_name, channel_verified, language, duration_s, published_at, visibility (public only indexed), moderation flags (limited/age_restricted), region_policy, view_count (bucketed), like_ratio, watch_time_7d, freshness, quality score.
- **Channel doc:** channel_id, handle, name, description, subscriber_count (bucketed), verified, country, language, video_count.
- **Suggest index:** popular queries (from search logs, filtered for safety), video titles, channel names — with weights.

### 16.3 Ranking
`final_score = text_relevance (BM25 with field boosts: title ≫ tags > channel > description) × popularity_boost (log(views_7d), watch_time, like_ratio) × freshness_decay (gauss on published_at, scale per query intent) × quality (completion rate, reports) × language_match × (personalization [SCALE])`
- Filters: upload date, duration buckets, type (video/channel/playlist), category, language.
- Exact channel-handle matches surface the channel card first.
- Safe search filtering on age_restricted by default.

### 16.4 Fuzzy, autocomplete, language
- Fuzzy: edit distance 1–2 depending on term length; phonetic/synonym dictionaries **[GROWTH]**.
- Autocomplete: prefix on suggest index, < 50 ms; cached per prefix in Redis/CDN for top prefixes.
- Language: detect at upload (metadata + title), store per doc; analyzers per major language (stemming, stop words, CJK tokenization) **[GROWTH]**; query language detection to boost same-language results.

### 16.5 Indexing flow & synchronization

```
Video module (PG txn: change + outbox row)
   └─► outbox relay ─► bus (SQS/SNS → Kafka)
          └─► Search Indexer (idempotent consumer)
                 ├─ fetch current state from PG (or replica) by id  ← "notify, then read" pattern
                 ├─ build doc (or delete if not searchable)
                 └─ upsert into index with external version = videos.state_version / updated_at
Periodic: popularity fields refreshed in bulk (every 15–60 min) from analytics aggregates
Daily/weekly: reconciliation job compares PG (ids + versions) vs index; fixes drift
Full reindex: build new index from PG snapshot → dual-write → alias swap (zero downtime)
```

- **Event carries ID + version; indexer re-reads from the source of truth** → out-of-order events can't regress the index (external versioning rejects stale writes).
- Deletions/blocks/privatizations are high priority (separate priority queue) — a takedown must disappear from search within seconds.
- Alternative: CDC (Debezium from PG WAL) **[SCALE]** if many tables feed the index; outbox is simpler and sufficient earlier.
- **Search results are re-filtered at serve time** for authorization-critical fields (visibility/blocked) using cached video policy, so index lag never leaks private content.

---

## 17. Feed

### 17.1 Sources
| Source | Content | Stage |
|---|---|---|
| Continue watching | Unfinished videos from history | MVP |
| Subscriptions | New uploads from subscribed channels | MVP |
| Trending / recently popular | Global/category/region trending | MVP (global) |
| Recommended | Personalized candidates (§18) | MVP rules → ML |
| Editorial / promoted | Curated shelves | GROWTH |

### 17.2 Fan-out models

| Model | How | Pros | Cons |
|---|---|---|---|
| **Fan-out-on-read (pull)** | At request time, query latest videos from each subscribed channel, merge | Simple, no write amplification, always fresh | Expensive for users with many subscriptions; latency grows with #subs |
| Fan-out-on-write (push) | On publish, insert video into every subscriber's inbox | Fast reads (one key per user) | Write amplification for big channels (millions of inbox writes per upload); wasted work for inactive users |
| **Hybrid** | Push for normal channels to *active* users' inboxes; pull for celebrity channels (> N subscribers) and merge at read | Bounded write cost, fast reads | More complex; two code paths |

**Recommendation:**
- **[MVP] Fan-out-on-read.** Query: for user's subscribed channel IDs (cap, e.g., 500 most-recently-engaged), fetch recent published videos via index `(channel_id, status, published_at DESC)` — single SQL with `channel_id = ANY(...)` + `published_at > now() − 30d` + limit, on a read replica; cache result per user for 1–5 min. Channel "latest videos" lists cached per channel (shared by all subscribers) so the merge is mostly Redis reads.
- **[SCALE] Hybrid:** inbox per active user in Redis/wide-column store (sorted set of video_ids, capped at ~500, TTL for inactive users); fan-out-on-write for channels below a threshold (e.g., < 100k subscribers) to users active in last 30 days; celebrity channels pulled at read time; inactive users' feeds rebuilt on next login.

### 17.3 Home feed assembly
```
Request → Feed module
  1. Gather candidates in parallel (each with timeout ~50–80 ms, optional on failure):
     continue-watching (≤ 10) · subscriptions (≤ 100) · recommendations (≤ 300) · trending (≤ 50)
  2. Filter: already watched (completed), blocked/limited/private, user-blocked channels, age/region policy
  3. Rank/blend: MVP = rule-based interleaving with quotas (e.g., shelves); SCALE = unified ranker score
  4. Diversity: max N per channel/topic per page; dedupe across sources
  5. Paginate with an opaque cursor that encodes the frozen candidate list (cached 30 min) → stable infinite scroll
  6. Log impressions (analytics) for training and for "don't show again" logic
```
- **Degradation:** if recommendations are down → trending + subscriptions; if everything fails → cached global trending (served even from CDN).

---

## 18. Recommendations

### 18.1 Stages

**Stage 1 — Rule-based [MVP]**
- *Related videos (watch page):* same channel (recent/popular), same category + overlapping tags, ranked by popularity × freshness; precomputed nightly + on publish for new videos.
- *Home:* trending in user's top categories (from last N watches), popular from subscribed channels, global trending fallback.
- Cheap SQL/Redis computations; no ML infra.

**Stage 2 — Behavior-based [GROWTH]**
- *Item-item co-watch:* "people who watched A (meaningfully, > 30% or > 2 min) also watched B" — computed in batch (daily, then hourly) from watch sessions: co-occurrence counts normalized (e.g., cosine/Jaccard with popularity damping). Stored as top-K neighbors per video in PG/Redis.
- *User profile vectors:* category/tag/channel affinities with time decay from watch time, likes, subscriptions; negative signals from dislikes, "not interested", quick skips (< 10 s).
- *Content similarity:* TF-IDF/embeddings on titles, tags, descriptions, transcripts → cold-start for new videos.
- Batch jobs on analytics store; online serving reads precomputed lists + light re-ranking.

**Stage 3 — ML-driven personalization [SCALE]**
```
Offline (batch/streaming)                                  Online (per request, < 100–150 ms)
──────────────────────────                                 ─────────────────────────────────────
Event lake (watch, impressions, clicks, likes, skips) ─┐   1. Candidate generation (multiple sources):
Content features (embeddings: visual, audio, text) ────┼─►    • ANN retrieval on user embedding (two-tower)
Training pipelines: two-tower retrieval, ranker (GBDT/ │      • co-watch neighbors of recent watches
  DNN multi-objective: watch time, satisfaction,       │      • subscriptions, trending, fresh content pool
  likes, not-interested)                               │   2. Feature fetch (online feature store: user, video, context)
Feature store (offline/online parity) ─────────────────┘   3. Ranking model scores ~500–1000 candidates
Model registry, evaluation, A/B experimentation            4. Re-ranking: diversity, freshness, policy filters, business rules
Vector index build (ANN: HNSW/IVF)                         5. Log impressions & features for training
```
- **Objective:** optimize long-term satisfaction (watch time with completion & survey/like signals), not clicks (clickbait resistance).
- **Experimentation platform** (A/B, holdouts) is a prerequisite for Stage 3.

### 18.2 Signals
| Signal | Strength | Notes |
|---|---|---|
| Watch duration / completion | Strong positive | Normalize by video length |
| Likes, saves, shares, subscribes after watch | Strong positive | |
| Dislikes, "not interested", "don't recommend channel" | Strong negative | Hard filters for the latter |
| Skips/quick abandons (< 10 s) | Negative | Also impression without click (weak negative) |
| Search history | Interest | Respect privacy controls |
| Subscriptions | Strong prior | |
| Category/tag affinity | Medium | |
| Similar users (collaborative) | Medium | Stage 2–3 |
| Video similarity (content) | Medium | Cold start |
| Context (device, time of day, country, language) | Modifier | |

### 18.3 Cold start
- **New user:** onboarding topic picker (optional) → geography/language-based trending → popular in chosen categories → quick adaptation (session-based recommendations from first few watches; bandit exploration).
- **New video:** content-based similarity (metadata/transcript embeddings) to seed related lists; **exploration budget** — show to a small, relevant audience slice (subscribers + users with matching affinities), measure early engagement (CTR, retention), then expand (bandit / "fresh pool" with guaranteed impressions); creators' historical performance as a prior.
- **New creator:** same as new video plus category priors; guard against spam with quality checks before exploration.

### 18.4 Offline vs online
| | Offline | Online |
|---|---|---|
| What | Training, co-occurrence, embeddings, candidate precompute, trending | Candidate retrieval, feature lookup, ranking, filtering |
| Latency | Minutes–hours | < 150 ms |
| Infra | Batch (Spark/SQL on lake/ClickHouse), schedulers | Rec service, Redis/feature store, ANN index |
| Freshness | Hourly/daily | Real-time session signals (last few watches) |

---

## 19. Analytics (View Counting, Watch Analytics, Trending)

### 19.1 Event collection

**Player events (client → ingestion):**

| Event | Key fields |
|---|---|
| `playback_session_started` | playback_session_id, video_id, user_id/anon_id, device, os, app_version, player, country (server-derived), referrer/traffic_source, autoplay flag, startup_time_ms |
| `play` / `pause` / `resume` | position_ms, ts |
| `heartbeat` (every 10 s while playing, batched every 30 s) | position_ms, watched_ms_since_last, current_rendition, bitrate, buffer_ms, dropped_frames |
| `seek` | from_ms, to_ms |
| `quality_change` | from, to, reason (abr/manual) |
| `rebuffer_start/end` | duration_ms |
| `error` | code, fatal, cdn_host, segment_url_hash |
| `completed` | total_watched_ms |
| `session_ended` | total_watched_ms, max_position_ms, reason (ended/closed/navigated) |

- **Schema-versioned** envelope: event_id (client UUID for dedupe), event_type, schema_version, client_ts, server_ts (assigned at ingestion), session ids, trace info.
- **Transport:** batched POST to `events.example.com` (separate host/scale group; `sendBeacon` on page hide); payload size limits; returns 202 immediately.
- **Ingestion service:** authenticates optional user token, enriches (geo from IP then **drops raw IP** or keeps salted hash with short retention, UA parsing), validates schema, rate-limits per session/IP, writes to stream.

**Server-side domain events** (likes, comments, subscriptions, publishes) come from the outbox — they're authoritative; client events are telemetry.

### 19.2 Processing pipeline

```
[MVP]
Clients ─► Ingestion endpoint ─► SQS queue ─► Aggregator workers ─► Redis counters (live)
                                   │                          └─► PG: video_daily_stats, video_view_counts (flush every 1–5 min)
                                   └─► Firehose-style batcher ─► S3 raw (JSON/Parquet, partitioned by date/hour)  ← replayable

[GROWTH]
Clients ─► Ingestion ─► Kinesis/Kafka stream ─┬─► Stream aggregator (views, live counters) ─► Redis + PG/ClickHouse
                                              ├─► ClickHouse (raw + materialized rollups)   ─► creator analytics API
                                              └─► S3 data lake (Parquet/Iceberg)             ─► warehouse/BI, ML training

[SCALE]
Kafka (multi-AZ, tiered storage) ─► Flink/Spark streaming (sessionization, fraud scoring, exactly-once aggregates)
   ─► ClickHouse clusters (sharded) · lake (Iceberg) · feature store · trending service
```

### 19.3 View counting

**Definition of a valid view (initial policy; tuned with data):**
- Playback session with actual frames rendered and **watched ≥ 30 s**, or **≥ 50% of duration for videos < 60 s** (min 3 s).
- Watched time = sum of played intervals (not seek position) — seeking to the end doesn't count.
- **One view per viewer per video per window**: dedupe key = (video_id, viewer_key, time_bucket) where viewer_key = user_id if authenticated else anon device id (+ IP/24 + UA fallback), window = e.g. 30 min (rewatches after the window can count, capped per day, e.g., ≤ 5 views/viewer/video/day).
- **Autoplay** views counted but tagged (traffic source) — muted autoplay previews in feeds don't count.
- Excluded: known bots/crawlers (UA lists, datacenter IP ranges), sessions failing integrity checks (impossible playback rates, no heartbeats, missing session authorization), owner's own views optionally excluded from public count (product decision; included in analytics separately).
- Embedded views count with referrer tagging.

**Flow:**
```
player heartbeats ─► ingestion ─► stream/queue
   ─► View Validator (stateful per playback_session_id, short-lived state in Redis with TTL):
        accumulate watched_ms; when threshold crossed → check dedupe key (Redis SET NX with TTL = window)
        → emit ViewCounted{video_id, viewer_type, country, source, ts} (exactly one per qualifying session)
   ─► Counter Aggregator: in-memory micro-batch (1–5 s) per video → Redis INCRBY views:{video_id}
   ─► Flusher (every 1–5 min): Redis deltas → PG video_stats.view_count (single UPDATE per video per interval)
                                          → video_daily_stats
   ─► Fraud pass (async, minutes–hours) [GROWTH]: anomaly detection (view spikes from few IPs/ASNs,
        zero-engagement sessions, abnormal geo) → subtract invalid views (corrections ledger)
```
- **Why not `UPDATE … +1` per view:** row lock contention on hot videos, WAL amplification, replication lag, and it couples playback load to the OLTP primary.
- **Eventual consistency:** public counts lag 1–5 min (MVP) and may be **adjusted downward** after fraud review. Display rules: raw counts updated with lag; large numbers rounded ("1.2M"); creator analytics shows "realtime (estimated)" and "final" figures; optionally freeze public counter at a threshold pending verification for suspicious spikes **[GROWTH]**.
- **Authenticated vs anonymous:** authenticated → strong dedupe by user_id; anonymous → device id cookie/app instance id + IP/UA heuristics; anonymous traffic receives stricter bot filtering and per-IP caps.
- **Durability:** counts are reproducible from the raw log; Redis loss → recompute deltas from stream offsets/raw S3 since last flush.

### 19.4 Watch-time analytics
- **Sessionization:** group events by playback_session_id; compute watched intervals, total watch time, max position, rebuffer count/time, avg bitrate, startup time, completion.
- **Retention curve [GROWTH]:** per video, % of sessions still watching at each 1% (or 5 s) of duration — aggregated from intervals.
- **Dimensions:** date/hour, country, device type, OS, traffic source (search, home, suggested, external, subscription feed, notification, playlist, direct), subscriber vs non-subscriber, age group/gender only if collected with consent (avoid at MVP).

### 19.5 Data flows by consumer
| Consumer | Data | Freshness | Store |
|---|---|---|---|
| **Creator analytics** | Views, watch time, AVD, retention, traffic sources, geo, devices, subs gained/lost, likes/comments | "Realtime" panel: last 48 h hourly, ~2–15 min lag; reports: daily | MVP: PG rollup tables; GROWTH: ClickHouse materialized views; API with per-creator scoping |
| **Platform analytics** | DAU/MAU, uploads, watch hours, QoE (startup, rebuffer), funnel metrics | Hourly/daily | Warehouse/lake + BI |
| **Recommendation models** | Sessions, impressions, clicks, watch time, negative feedback | Batch daily/hourly; streaming features | Lake + feature store |
| **Trending** | Validated views, watch time, engagement per time bucket | 5–15 min | Stream aggregates → Redis/PG |
| **Fraud/abuse** | Raw events with network/device signals | Near-real-time + batch | Stream + ClickHouse |

### 19.6 Trending

**Score (conceptual) per video, per scope (global / category / region):**
```
velocity_score = Σ over recent buckets  w_b × (valid_views_b + α·watch_hours_b + β·likes_b + γ·comments_b + δ·shares_b)
growth          = (views_last_6h + k) / (baseline_views_prev_24h_rate + k)        # acceleration, smoothed
quality         = completion_rate^a × (likes/(likes+dislikes) smoothed)^b
freshness       = exp(−age_hours / τ)    # τ ≈ 24–48 h
trending_score  = velocity_score × growth^c × quality × freshness × integrity_penalty
```
- **Time windows:** hourly buckets; "Trending now" uses last 1–6 h with 24 h context; "Today" uses 24 h; "This week" uses 7 d with lower freshness weight. Separate lists per window.
- **Normalization:** compare against channel's typical performance and category baseline so a niche category can trend within itself; cap per channel (≤ 2 per list) for diversity.
- **Computation:** MVP — job every 10–15 min over PG `video_hourly_stats` (only videos with views in last 48 h ⇒ small set) → writes sorted lists to Redis + snapshot table. GROWTH — stream aggregation + ClickHouse query. Lists cached at CDN (public, 1–5 min).
- **Manipulation resistance:**
  - Only **validated** views/engagement count (bot filtering, dedupe).
  - Weight engagement from **established accounts** (age, history) higher; discount new/low-reputation accounts and sudden coordinated activity (same ASN/IP ranges/device fingerprints).
  - Watch time and completion weigh more than raw views (harder to fake).
  - Diversity of audience as a factor (unique viewers / distinct countries / distinct referrers).
  - Eligibility filters: not limited/age-restricted, not reported above threshold, channel in good standing, minimum video age (e.g., > 1 h) to collect integrity signals.
  - Human review/override for top positions **[GROWTH]**; anomaly alerts on sudden entries.


---

## 20. Event Architecture

### 20.1 Principles
- **Transactional outbox [MVP]:** a module writes its state change and the event row in the same DB transaction; a relay publishes outbox rows to the bus and marks them published. Eliminates "DB committed but event lost" and "event sent but DB rolled back".
- **At-least-once delivery everywhere;** every consumer is **idempotent** (dedupe by event_id in `processed_messages`, or naturally idempotent upserts with version checks).
- **Events are facts in past tense** (`VideoPublished`); **commands** are imperative and point-to-point (`ProcessVideo`, `SendEmail`).
- **Envelope:** `event_id` (UUID), `event_type`, `schema_version`, `occurred_at`, `producer`, `aggregate_type`, `aggregate_id`, `aggregate_version` (for ordering/staleness), `trace_context`, `payload` (minimal: IDs + changed fields; consumers re-read if they need full state).
- **Schema registry / versioning:** additive changes only within a major version; breaking change → new `schema_version` and dual-publish during migration. Schemas documented in a shared catalog repo with contract tests **[MVP: JSON Schema in repo; SCALE: schema registry with Avro/Protobuf]**.
- **Ordering:** only per-aggregate ordering is ever relied upon (SQS FIFO group = aggregate_id, or Kafka partition key = aggregate_id); consumers use `aggregate_version` to drop stale events.

### 20.2 Domain event catalog

| Event | Producer | Consumers | Delivery | Idempotency requirement |
|---|---|---|---|---|
| `UserRegistered`/`USER_CREATED` | Auth/User | Notification (welcome/verify email), Analytics, Recs (cold start) | At-least-once | Email dedupe by user_id+template |
| `UserUpdated` | User | Search (channel docs if display name used), caches | At-least-once | Version check |
| `UserSuspended` | User/Moderation | Auth (revoke sessions), Video (hide), Search, Feed | At-least-once, **high priority** | Idempotent state set |
| `UserDeletionRequested` / `UserDeleted` | User | **All modules** owning user data, analytics, search, recs, object storage cleanup | At-least-once, **must complete** (tracked saga) | Each consumer idempotent; acknowledges completion to deletion tracker |
| `ChannelCreated`/`ChannelUpdated`/`ChannelDeleted` | Channel | Search, Feed caches, Notification | At-least-once | Version check |
| `VideoCreated` | Video | Analytics | At-least-once | — |
| `VideoUploadStarted` | Upload | Analytics (funnel) | Best effort ok | — |
| `VideoUploaded` | Upload | Media coordinator, Moderation (scan) | At-least-once, **must not be lost** | Job unique (video_id, processing_version) |
| `VideoProcessingStarted` | Media | Video (state), Analytics | At-least-once | Conditional state transition |
| `VideoRenditionReady` | Media | Video (→ READY), Notification (creator) | At-least-once | Conditional transition |
| `VideoProcessingCompleted` | Media | Video (state, auto-publish), Notification (creator), Moderation (post-processing classifiers), Recs (content features) | At-least-once | Conditional transition; notification dedupe |
| `VideoProcessingFailed` | Media | Video (state), Notification (creator), Admin alerting | At-least-once | Conditional transition |
| `VideoPublished` | Video | Search indexer, Feed (cache invalidation / fan-out), Notification (subscriber fan-out), Recs, Analytics, Channel (counts) | At-least-once | Fan-out batches idempotent by (event_id, batch_no); index upsert by version |
| `VideoMetadataUpdated` | Video | Search, caches, Recs | At-least-once | Version check |
| `VideoVisibilityChanged` / `VideoUnpublished` | Video | Search (**priority**), Feed, caches, Playlists | At-least-once | Version check |
| `VideoBlocked` / `ContentBlocked` | Moderation→Video | Search (**priority**), Feed, CDN purge job, Notification (creator), Analytics | At-least-once, **priority lane** | Idempotent |
| `VideoDeleted` | Video | Search, Feed, Playlists, Comments (hide), Media (stop jobs, schedule purge), Analytics | At-least-once | Idempotent |
| `VideoViewed` / `ViewCounted` | Analytics validator | Counter aggregator, Trending, Recs, History | At-least-once; dedupe in validator | event_id dedupe; counters tolerate replay only with dedupe |
| `VideoLiked` / `ReactionChanged` | Engagement | Counters, Recs, Notification (creator milestones), Analytics | At-least-once | Delta computed from state change inside txn; consumers dedupe by event_id |
| `VideoCommented` / `CommentReplied` | Comment | Notification (creator / parent author), Moderation (spam), Counters, Analytics | At-least-once | Notification dedupe_key |
| `UserMentioned` | Comment | Notification | At-least-once | dedupe_key |
| `CommentLiked` | Engagement | Comment ranking, Notification (aggregated) | At-least-once | — |
| `ChannelSubscribed` / `ChannelUnsubscribed` | Subscription | Counters, Feed cache, Recs, Notification (creator, aggregated) | At-least-once | Emitted only on actual change; consumer dedupe |
| `ReportSubmitted` | Moderation | Moderation case router, Trust scoring | At-least-once | Case upsert per target |
| `ModerationActionTaken` | Moderation | Video/Comment/User (apply), Notification (affected user), Search, Audit | At-least-once, **priority** | Idempotent by action_id |
| `PlaybackSessionStarted` + telemetry | Client via ingestion | Analytics, View validator, History | At-least-once (telemetry tolerant to tiny loss) | event_id dedupe |

### 20.3 Queue technology comparison

| | **SQS (+SNS for fan-out)** | RabbitMQ | **Kafka (MSK/Confluent)** | Kinesis |
|---|---|---|---|---|
| Model | Managed queues; SNS topics fan out to per-consumer queues | Broker with exchanges/queues, rich routing | Distributed log, partitions, consumer groups, replay | Managed log (shards) |
| Ops burden | **None** | Medium (clustering, upgrades) unless managed | High (even managed: partitions, sizing, upgrades) | Low |
| Ordering | FIFO queues per group (throughput limits) | Per queue | Per partition | Per shard |
| Replay | No (DLQ redrive only) | No | **Yes** (retention, offsets) | Yes (≤ 365 d) |
| Throughput | Very high (standard), scales automatically | High | **Very high**, best for event streams | High, per-shard limits |
| Delay/visibility timeout, DLQ | **Built-in** | Built-in (DLX) | Manual (retry topics) | Manual |
| Best for | Work queues, commands, moderate event fan-out | Complex routing, RPC-ish workloads, on-prem | Event streaming, analytics, many consumers, replay, CDC | Telemetry streams on AWS without Kafka ops |

**Recommendation by stage:**
- **[MVP]** SQS for work queues (processing tasks, notifications, indexing, emails) + SNS topic per domain event type (or EventBridge) fanning out to per-consumer SQS queues. Telemetry via SQS or a managed batcher (Firehose) to S3. Zero ops.
- **[GROWTH]** Add **Kinesis Data Streams or managed Kafka for analytics telemetry** when event rate > ~10k/s or multiple consumers need replay (stream aggregation + ClickHouse + lake). Keep SQS for work queues — they're the right tool for jobs with visibility timeouts and DLQs.
- **[SCALE]** **Kafka as the central event backbone** for domain events and telemetry (replay for new consumers, CDC streams, stream processing, multi-region replication via MirrorMaker/cluster linking). SQS (or Kafka-based task queues) still used for job dispatch.
- RabbitMQ: not recommended (no advantage over SQS on AWS; adds ops without Kafka's replay).

### 20.4 Outbox relay
- **MVP:** polling relay (every ~200–500 ms, `SELECT … WHERE published_at IS NULL ORDER BY id LIMIT n FOR UPDATE SKIP LOCKED`), publishes, marks published; outbox rows pruned after 7 days.
- **SCALE:** CDC (Debezium reading WAL) → Kafka, eliminating polling load.

---

## 21. Caching

### 21.1 Layers
1. **Client cache** (HTTP caching headers, app-level).
2. **CDN** (media always; public API GETs with short TTL **[GROWTH]**).
3. **Redis** (shared application cache).
4. **In-process LRU** (tiny, very hot, low-cardinality data: categories, feature flags, config; TTL 10–60 s) **[MVP]**.
5. **Database** (buffer cache; read replicas).

### 21.2 What is cached

| Data | Key pattern (conceptual) | Strategy | TTL | Invalidation |
|---|---|---|---|---|
| Video metadata (public view) | `video:{id}:v{state_version}` or `video:{id}` | Cache-aside | 5–15 min + jitter | Delete on `VideoMetadataUpdated`/visibility/state events |
| Video access policy | `vpolicy:{id}` | Cache-aside | 5 min | Delete on state/visibility/moderation change (**must** invalidate synchronously in same request path as change, plus event) |
| Channel page header | `channel:{id}` | Cache-aside | 10 min | On update |
| Channel latest videos | `channel:{id}:latest` | Cache-aside | 2–5 min | On publish/unpublish |
| View/like/sub counts | `cnt:views:{id}` etc. | Write-behind counters (Redis authoritative for deltas until flush) | none (persistent until flushed) | Flush to PG; reconcile |
| Trending lists | `trending:{scope}:{window}` | Precomputed by job | Replaced each run (TTL 2× interval as safety) | Overwrite |
| Popular videos / home fallback | `popular:{region}` | Precomputed | 15 min | Overwrite |
| Recommendations (per user) | `recs:{user}` | Precomputed or computed on demand | 30–60 min | On significant activity [GROWTH] |
| Feeds (per user) | `feed:{user}:{cursor_snapshot}` | Computed on demand | 1–5 min (page snapshot 30 min) | Expire |
| Sessions / token revocation | `revoked:{jti}` / `sess:{id}` | Write-through | Token lifetime | Explicit |
| Rate limit counters | `rl:{scope}:{key}:{window}` | Atomic increments / token bucket | Window | Expire |
| Comment first pages (hot videos) | `comments:{video}:top:p1` | Precomputed for hot videos | 30–60 s | Expire / ranking job |
| Autocomplete | `ac:{lang}:{prefix}` | Cache-aside | 10–60 min | Expire |
| Idempotency keys | `idem:{user}:{key}` | Write-through | 24 h | Expire |

### 21.3 Cache-aside & consistency
- Read: cache → miss → DB (replica allowed for public data; **primary or cache-bypass for read-your-writes** after a user's own write, using a short "recent writer" flag or session-level primary stickiness for N seconds).
- Write: update DB → commit → **delete** cache key (not update; avoids races) → event for other derived caches. A delayed second delete (~1 s) **[GROWTH]** mitigates the replica-lag repopulation race.
- Never cache negative authorization decisions for long; policy cache TTL is short and invalidated on change.

### 21.4 TTL strategy
- Every key has a TTL (no immortal keys except counters awaiting flush); **TTL jitter (±10–20%)** to avoid synchronized expiry.
- Longer TTLs for immutable/versioned data; short for lists.
- `stale-while-revalidate` semantics in-app: serve stale value while one worker refreshes.

### 21.5 Hot keys
- Symptoms: one viral video's metadata/counters receiving 100k+ ops/s on one Redis shard.
- Mitigations: **in-process L1 cache** (1–5 s) for hot metadata; **key replication** (`video:{id}#r{0..k}` random read replica keys) for extreme cases; **sharded counters** + periodic merge; Redis read replicas for read-heavy keys; hot-key detection (Redis hotkeys/monitoring sampling) with automatic L1 promotion **[SCALE]**.

### 21.6 Stampede prevention
- **Request coalescing / single-flight:** per-process lock so only one request per key per instance recomputes.
- **Distributed lock with short TTL** (`SET NX PX`) for expensive recomputations (trending, feeds) — others serve stale.
- **Probabilistic early refresh** (refresh before expiry with probability increasing as TTL approaches 0).
- **Precompute** expensive aggregates (trending, popular) on a schedule instead of on demand.

### 21.7 Cache outage behavior
- Redis down → reads fall through to DB with **concurrency limits** (bulkhead) and in-process cache extended; counters buffered in-process briefly then dropped to event stream (recomputable); rate limiting **fails open for reads, fails closed (local fallback limiter) for auth/login and uploads** (§27).

### 21.8 Redis topology by stage
- **[MVP]** One managed Redis (primary + replica, multi-AZ), logical separation by key prefix.
- **[GROWTH]** Separate clusters by workload: cache (eviction allowed: `allkeys-lru`), counters/rate-limits/sessions (no eviction: `noeviction`, persistence on), feeds.
- **[SCALE]** Redis Cluster per workload with sharding; regional clusters per region.

---

## 22. API Design

### 22.1 Style: REST vs GraphQL

| | REST (JSON over HTTPS) | GraphQL |
|---|---|---|
| Caching | **HTTP/CDN caching natural** (GET + URL) | Harder (POST bodies; needs persisted queries) |
| Client flexibility | Fixed shapes; risk of over/under-fetching | Clients pick fields; one round trip for complex screens |
| Security/rate limiting | Per-endpoint, simple | Query cost analysis required |
| Tooling/ops | Ubiquitous, OpenAPI | Schema stitching/federation at scale adds complexity |
| Fit for uploads/playback | Natural | Awkward |

**Recommendation:** **REST + OpenAPI 3** as the public API **[MVP]**, with screen-oriented composite endpoints where needed (`GET /v1/watch/{videoId}` returns video, channel, my reaction, counts in one call). Consider a **GraphQL/BFF layer [GROWTH]** only if multiple client teams struggle with over-fetching — it would sit on top of the same module APIs. Internal service-to-service (when extracted): REST or gRPC **[SCALE]**.

### 22.2 Conventions
- **Base:** `https://api.example.com/v1/…`; resources plural nouns; IDs are opaque public IDs.
- **Versioning:** URI major version (`/v1`). Additive changes don't bump version; breaking changes → `/v2` for affected resources with ≥ 6–12 months deprecation (`Deprecation`/`Sunset` headers). Mobile clients pinned to versions → keep old versions alive longer.
- **Cursor pagination [default]:** `?limit=20&cursor=<opaque>` → `{ items: [...], next_cursor, has_more }`. Cursor = encoded (sort key, id, snapshot/version), signed or encrypted to prevent tampering. Offset pagination only for small admin lists.
- **Filtering/sorting:** whitelisted fields: `?sort=-published_at&category=music&duration=short`. Each sort must be backed by an index.
- **Idempotency:** `Idempotency-Key` header required on non-idempotent POSTs that create resources or have side effects (upload session, complete, comment post, report). Server stores key + request hash + response 24 h; same key + different body → 422.
- **Concurrency:** `ETag`/`If-Match` on metadata updates (videos, playlists) → 412 on conflict.
- **Errors:** RFC 9457 Problem Details:
  `{ "type": "https://errors.example.com/video-not-found", "title": "Video not found", "status": 404, "code": "VIDEO_NOT_FOUND", "detail": "...", "request_id": "...", "errors": [ {"field": "title", "code": "TOO_LONG", "message": "..."} ] }`
  Stable machine `code`s; never leak internals (stack traces, SQL). Distinguish 401 vs 403; return 404 for private resources the caller can't see (avoid existence leaks).
- **Validation:** schema validation at the edge of the API (types, lengths, enums, formats), then domain validation in modules; reject unknown fields on writes; Unicode normalization + control-char stripping for text; size limits on bodies.
- **Rate-limit headers:** `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`; 429 with `Retry-After`.
- **Field expansion:** `?fields=` sparse fieldsets for lists **[GROWTH]**.
- **Localization:** `Accept-Language` for localized category names/messages.
- **Timestamps:** ISO-8601 UTC.

### 22.3 Endpoint groups (conceptual)

| Group | Endpoints (conceptual) |
|---|---|
| **Auth** | `POST /auth/register`, `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `POST /auth/logout-all`, `POST /auth/password/forgot`, `POST /auth/password/reset`, `POST /auth/email/verify`, `POST /auth/email/resend`, `GET /auth/oauth/{provider}/start`, `GET /auth/oauth/{provider}/callback`, `GET /auth/sessions`, `DELETE /auth/sessions/{id}`, MFA endpoints **[GROWTH]** |
| **Users** | `GET /me`, `PATCH /me`, `GET /me/preferences`, `PATCH /me/preferences`, `POST /me/deletion-request`, `POST /me/data-export`, `GET /users/{handle}` (public profile) |
| **Channels** | `POST /channels`, `GET /channels/{idOrHandle}`, `PATCH /channels/{id}`, `GET /channels/{id}/videos?sort=`, `GET /channels/{id}/playlists`, members **[GROWTH]** |
| **Videos** | `POST /videos`, `GET /videos/{id}`, `PATCH /videos/{id}`, `DELETE /videos/{id}`, `POST /videos/{id}:publish`, `POST /videos/{id}:unpublish`, `GET /videos/{id}/processing`, `PUT /videos/{id}/thumbnail` (choose/upload), `POST /videos/{id}/subtitles`, `GET /watch/{id}` (composite) |
| **Uploads** | `POST /videos/{id}/uploads`, `POST /uploads/{id}/parts:sign`, `GET /uploads/{id}`, `POST /uploads/{id}:complete`, `DELETE /uploads/{id}` |
| **Playback** | `GET /videos/{id}/playback` (manifest URL + signed cookies/token), `POST /playback/{sessionId}:refresh` |
| **Events** (separate host) | `POST /events:batch` |
| **Comments** | `GET /videos/{id}/comments?sort=top|newest&cursor=`, `POST /videos/{id}/comments`, `GET /comments/{id}/replies`, `POST /comments/{id}/replies`, `PATCH /comments/{id}`, `DELETE /comments/{id}`, `POST /comments/{id}:pin`, `POST /comments/{id}:heart` |
| **Reactions** | `PUT /videos/{id}/reaction` `{type: like|dislike|none}`, `PUT /comments/{id}/reaction`, `GET /me/liked-videos` |
| **Subscriptions** | `PUT /channels/{id}/subscription` `{notification_level}`, `DELETE /channels/{id}/subscription`, `GET /me/subscriptions`, `GET /channels/{id}/subscribers` (owner only) |
| **Playlists** | `POST /playlists`, `GET /playlists/{id}`, `PATCH /playlists/{id}`, `DELETE /playlists/{id}`, `GET /playlists/{id}/items`, `POST /playlists/{id}/items`, `PATCH /playlists/{id}/items/{itemId}` (move), `DELETE /playlists/{id}/items/{itemId}` |
| **Search** | `GET /search?q=&type=video|channel|playlist&filters…&cursor=`, `GET /search/suggest?q=` |
| **Feeds** | `GET /feed/home?cursor=`, `GET /feed/subscriptions?cursor=`, `GET /trending?category=&region=&window=`, `GET /videos/{id}/related` |
| **History** | `GET /me/history?cursor=`, `DELETE /me/history/{videoId}`, `DELETE /me/history`, `PUT /me/history/settings` (pause), `GET /me/continue-watching` |
| **Notifications** | `GET /me/notifications?cursor=`, `GET /me/notifications/unread-count`, `POST /me/notifications:mark-read`, `GET/PATCH /me/notification-preferences`, `POST /me/devices` (push tokens) |
| **Reports** | `POST /reports` `{target_type, target_id, reason, details}` |
| **Creator analytics** | `GET /channels/{id}/analytics/overview?range=`, `GET /videos/{id}/analytics?metrics=&dimensions=&range=` |
| **Admin** (separate prefix `/admin/v1`, separate auth policy, IP allowlist/VPN/SSO) | users search/suspend/restore, videos search/block/restore/reprocess, moderation queue/cases/actions, reports, appeals, processing jobs & DLQ inspection/replay, feature flags, audit log query, platform analytics overview |

---

## 23. Authentication & Authorization

### 23.1 Build vs buy

| Option | Pros | Cons |
|---|---|---|
| Managed IdP (Cognito, Auth0, Firebase Auth, Keycloak-managed) | Faster MFA/social/compliance features | Cost per MAU at scale, customization limits, lock-in, UX constraints |
| **In-house auth module using vetted libraries** | Full control of UX, tokens, sessions; no per-MAU fee | Security responsibility; must follow standards carefully |

**Recommendation:** In-house auth module built on standard, well-reviewed libraries (OAuth/OIDC client, password hashing, JWT) **or** a managed IdP if the team has no security engineering capacity. Either way the token model below applies. Document in ADR-009.

### 23.2 Flows
- **Registration:** email + password (+ handle) → hash with **Argon2id** (memory-hard; tuned to ~100–250 ms) → create user (status `pending_verification` limited capabilities) → `UserRegistered` → verification email with single-use token (hashed in DB, 24 h expiry). Breached-password check (k-anonymity API) **[GROWTH]**; CAPTCHA/risk check on suspicious signups.
- **Email verification:** required before uploading, commenting (configurable), or monetization.
- **Login:** constant-time verification; generic error ("invalid email or password"); progressive delays/lockout per account + per IP; risk-based step-up (new device/country → email OTP or MFA) **[GROWTH]**.
- **Social login (OIDC):** Authorization Code + PKCE, state + nonce validation; link to existing account only after verifying ownership of the email (verified at provider + explicit confirm). Apple required on iOS if other social logins offered.
- **Password reset:** request always returns 200 (no account enumeration); single-use token, 30–60 min TTL, hashed; on reset → revoke all sessions, notify by email.
- **Logout:** revoke current refresh token family; access token expires naturally (short TTL) — optional denylist for immediate revocation of high-risk cases.
- **Logout everywhere / session management:** list `user_sessions`; revoke individually or all.
- **MFA [GROWTH]:** TOTP + WebAuthn/passkeys; **mandatory for moderators/admins from day one [MVP]** (via SSO for internal staff).

### 23.3 Tokens

| Token | Format | Lifetime | Storage (client) | Notes |
|---|---|---|---|---|
| **Access token** | Signed JWT (asymmetric, e.g., EdDSA/ES256), claims: sub, sid, roles, scopes, age_verified, iat, exp, jti, kid | **10–15 min** | Memory (web), secure storage (mobile) | Stateless verification; keys rotated via JWKS with overlap |
| **Refresh token** | Opaque random 256-bit; stored hashed server-side | 30 days sliding, 90 days absolute | Web: **HttpOnly, Secure, SameSite=Strict cookie** scoped to `/auth`; Mobile: Keychain/Keystore | **Rotation on every use**; reuse of an old token ⇒ revoke whole family (theft detection) |
| Playback cookie | CDN-signed policy | ≤ 6 h | Cookie on media domain | §13.4 |
| Upload URLs | Presigned | 15–60 min | — | §10 |

- **Revocation:** refresh tokens revoked in DB (immediate). Access tokens: short TTL; for suspensions/password changes, a Redis denylist keyed by `sid`/`user_id` with "tokens issued before T are invalid" (`min_iat` per user) checked on each request **[MVP]** — cheap single Redis lookup.
- **Web token handling:** Prefer a **BFF-style** cookie session for web (HttpOnly session cookie + CSRF protection) **or** access token in memory + refresh cookie; never store tokens in localStorage.

### 23.4 Roles & permissions (RBAC + ownership/ABAC)

| Role | Capabilities |
|---|---|
| **anonymous** | Watch public/unlisted, search, browse |
| **viewer** (registered) | + comment, react, subscribe, playlists, history, report |
| **creator** (viewer with a channel; verified email) | + upload, manage own channel/videos, see own analytics, moderate own comments |
| **channel manager/editor [GROWTH]** | Delegated per-channel permissions |
| **moderator** | Review queues, take moderation actions within policy scope, view restricted content; no account admin |
| **senior moderator / T&S lead** | Appeals, account suspensions, policy overrides |
| **support agent [GROWTH]** | Read-only user/video lookup, limited account actions (resend verification) |
| **admin** | Platform configuration, role assignment, everything else — with audit; break-glass for destructive actions |

**Authorization rules (examples):**
- *Edit video:* `actor == video.uploader` **or** actor has `editor+` role on `video.channel_id` **[GROWTH]** **or** admin. Ownership checked against PG (strongly consistent).
- *View video:* §13.2 policy function.
- *Delete comment:* comment author, channel owner/manager of the video, moderator.
- *See channel analytics:* channel owner/managers.
- *Moderation actions:* moderator role + scope; actions on **their own content** forbidden (conflict of interest).
- *Admin endpoints:* staff identity via corporate SSO + MFA, separate token audience (`aud=admin`), IP/VPN restriction, every call audited.

Authorization is implemented as a **central policy layer** in the monolith (policy functions per resource/action) — not ad-hoc checks in handlers — so it can be tested exhaustively and later moved to a policy engine (OPA/Cedar) **[SCALE]** if needed.

---

## 24. Security, Rate Limiting, Privacy & Compliance

### 24.1 Trust boundaries

```
 [Untrusted]  Internet clients, uploaded media files, player telemetry, OAuth provider responses, webhooks
     │ TLS 1.2+/1.3 · WAF · DDoS protection · rate limits · bot management
 [Edge]       CDN / WAF / LB — terminates TLS, filters
     │ authenticated + validated requests only
 [App tier]   Core API, ingestion — validates all input, authZ on every request, no direct DB exposure
     │ least-privilege IAM roles, private subnets, security groups
 [Data tier]  PostgreSQL, Redis, OpenSearch — private network only, encrypted, IAM/password auth from secrets manager
 [Processing sandbox]  Media workers — treat every file as hostile: isolated, no access to DB/secrets beyond job scope,
                        egress restricted to object storage + coordinator API
 [Admin]      Staff-only plane — SSO, MFA, separate network path, full audit
```

### 24.2 Controls

| Area | Control |
|---|---|
| **TLS** | TLS 1.2+ (prefer 1.3) at all public endpoints; HSTS (preload); TLS between internal services/DB where supported; certificates managed by provider with auto-renewal |
| **Password hashing** | Argon2id (or bcrypt cost ≥ 12 as fallback); per-user salt intrinsic; pepper in secrets manager **[optional]**; rehash on login when parameters change |
| **Token security** | Asymmetric JWT signing with key rotation; short TTL; `aud`/`iss` validation; alg allowlist; refresh rotation + reuse detection; hashed refresh tokens at rest |
| **Rate limiting** | §24.3 |
| **DDoS** | CDN absorbs volumetric; L3/L4 provider protection (Shield Standard **[MVP]**, Advanced **[SCALE]**); L7 WAF rate rules; autoscaling with caps; cached fallbacks |
| **WAF** | Managed rule sets (OWASP core, known bad inputs, IP reputation, anonymous proxies for sensitive endpoints), custom rules for login/registration paths, geo rules for sanctioned regions |
| **Presigned upload URLs** | Server-chosen keys, short TTL, content-length range, content-type condition, single bucket prefix per session, uploads bucket private & no public ACLs, block public access enforced at account level |
| **Signed playback** | §13.3–13.5; key rotation; path-scoped policies |
| **Object storage permissions** | Separate buckets: `uploads` (write via presign only; read by workers only), `media` (write by workers; read by CDN only), `images`, `exports` (user data exports, short-lived signed download), `logs`. Bucket policies deny non-TLS, deny public, require encryption |
| **MIME validation** | Never trust client MIME or extension; magic-byte sniffing + full probe; images re-encoded server-side (strips EXIF/GPS metadata and polyglot payloads) |
| **Malware / malicious file detection** | AV scan on uploads (sandboxed); decoders run in isolated containers with seccomp, no network, resource limits; keep FFmpeg/libraries patched (CVE monitoring); reject unusual containers/codecs by allowlist |
| **SQL injection** | Parameterized queries/ORM only; no string-concatenated SQL; least-privilege DB users per module (read-only roles for replicas); static analysis rules |
| **XSS** | API returns JSON only; user content stored raw and **escaped/sanitized at render** in clients; strict CSP on web; rich text (descriptions) limited to safe subset (links auto-detected, rendered with `rel="nofollow ugc noopener"`) |
| **CSRF** | Relevant for cookie-authenticated endpoints (web refresh/session cookies): SameSite=Strict/Lax cookies + CSRF token (double-submit or synchronizer) + Origin header checks. Bearer-token APIs are not CSRF-prone |
| **CORS** | Allowlist of first-party origins; credentials only for those |
| **SSRF** | Any server-side URL fetch (link previews, avatar import from URL, webhooks) via egress proxy with allowlist/deny private IP ranges |
| **API abuse** | Per-user/IP quotas, anomaly detection on scraping patterns, pagination limits, expensive endpoints protected (search, feeds) |
| **Bot detection** | Edge bot management (signals: JA3/JA4 TLS fingerprints, behaviour), CAPTCHA/challenge (proof-of-work or privacy-friendly challenge) on risky signups/logins, device attestation on mobile (App Attest / Play Integrity) **[GROWTH]** |
| **Credential stuffing** | Per-IP and per-account login throttling, breached password detection, risk-based MFA, monitoring of login failure rates by ASN, notify users of new-device logins |
| **Secrets management** | Secrets manager for DB creds, signing keys, provider API keys; automatic rotation; workloads get secrets via IAM roles (no static keys in images/env files); secret scanning in CI |
| **Encryption at rest** | KMS-managed keys for DB, backups, object storage (SSE-KMS or SSE-S3 for high-volume media to control KMS request cost), Redis, search, queues |
| **Encryption in transit** | TLS everywhere incl. DB connections, Redis TLS, internal service mesh mTLS **[SCALE]** |
| **Audit logging** | Admin/moderation actions, auth events (login, MFA, password change, session revocation), permission changes, data exports/deletions → append-only audit store; cloud API audit (CloudTrail) enabled org-wide; retention ≥ 1 year |
| **Supply chain** | Dependency scanning, image scanning, signed images, minimal base images, pinned versions |
| **Vulnerability management** | Pen test before launch and annually; bug bounty **[GROWTH]** |

### 24.3 Rate limiting

**Dimensions**
- **IP-based:** protects unauthenticated endpoints (login, register, search, playback auth for anon). Be careful with CGNAT/mobile carriers — use generous limits and combine with device/fingerprint signals.
- **User-based:** primary for authenticated actions (comments, likes, uploads).
- **Endpoint-based:** each endpoint class has its own budget so abuse of one doesn't starve others.
- **Global/tenant-based:** protects shared dependencies (e.g., total email sends/min).

**Where:** edge WAF (coarse IP rules, volumetric) + application (fine-grained, token bucket or sliding window in Redis, with local in-memory fallback).

**Initial limits (tune with data):**

| Endpoint class | Per IP | Per user/account | Notes |
|---|---|---|---|
| Login | 10/min, 100/h | 5 failures/15 min → progressive delay; 20 failures/day → lock + email | Also per-ASN anomaly alerts |
| Registration | 5/h | — | CAPTCHA after 2; disposable email checks |
| Password reset request | 5/h | 3/h per email | Always 200 |
| Upload session creation | 30/h | 20/h, daily quota by trust tier (e.g., new: 10 videos/day, verified: 100) | Also total bytes/day per user |
| Part URL signing | 600/min | 600/min | High because large uploads have many parts |
| Comments | 30/min | 5/min, 200/day (new accounts lower) | Plus duplicate-content detection |
| Reactions (likes) | 120/min | 60/min | |
| Subscriptions | 60/min | 30/min, 500/day | Anti-follow-spam |
| Reports | 20/h | 10/h | |
| Search | 60/min | 120/min | Autocomplete separate: 300/min |
| Playback authorization | 300/min | 300/min | Per (user, video) 30/min to stop token farming |
| Events ingestion | 120 batches/min per session | — | Payload caps |
| Admin APIs | Corporate network only | 600/min per staff; destructive bulk actions require 2-person approval **[GROWTH]** | |

**Failure mode:** if the limiter store is unavailable → local in-memory limiter per instance (approximate) for auth & write endpoints; fail open only for low-risk reads.

### 24.4 Privacy & compliance

| Topic | Design |
|---|---|
| **GDPR/CCPA basis** | Data inventory per module (what personal data, purpose, retention); privacy policy; consent for non-essential cookies/analytics on web; DPA with vendors |
| **Data minimization** | Don't collect DOB/gender unless needed; IP addresses hashed/truncated in analytics after geo-lookup; raw IP retention ≤ 30 days (security logs) |
| **Retention** | Raw telemetry 13 months (aggregate forever, anonymized); security logs 1 year; notifications 90 days; deleted videos 30-day grace; moderation records per legal requirements (years) |
| **Data export (right of access/portability)** | Async job: collect from each module (profile, channel, videos metadata + originals links, comments, history, playlists, subscriptions, reactions) → archive in `exports` bucket → time-limited signed link emailed; rate-limited |
| **Account deletion (right to erasure)** | See below |
| **Content deletion** | Video DELETED → removed from all surfaces immediately (auth policy), search/feeds within seconds–minutes, CDN purge, bytes purged after 30-day grace (unless legal hold) |
| **Audit trails** | Who accessed/changed personal data (admin/support access logging); export/deletion request log |
| **Child safety** | Minimum age (e.g., 13; jurisdiction-specific); "made for kids" designation → disables comments, personalized ads/recs, notifications; COPPA-style handling; CSAM hash matching on all uploads/thumbnails with mandatory reporting workflow to the relevant authority (e.g., NCMEC in the US) via a legal-ops process; restricted access to such evidence |
| **Legal requests** | Takedown (DMCA-like) workflow; law enforcement requests through legal team; legal hold flag blocks purge |
| **Regional rules** | Region restrictions per video; data residency **[SCALE]** if required by market |

**User deletion propagation (asynchronous saga):**
```
1. User requests deletion → re-auth required → status = pending_deletion; sessions revoked; profile hidden immediately;
   grace period (e.g., 14–30 days) allowing cancellation.
2. Grace ends → Deletion Coordinator creates deletion_request(user_id) with a checklist of required consumers.
3. Emits UserDeleted(user_id, request_id). Each owning module consumer, idempotently:
   • Auth: delete credentials, sessions, OAuth links
   • User/Channel: anonymize profile (tombstone keeps id for referential integrity), channel removed
   • Video: all videos → DELETED → media purge jobs (originals, renditions, thumbnails), CDN purge
   • Comments: delete or anonymize ("[deleted user]") per policy; reactions removed (counts corrected via recount)
   • Subscriptions/Playlists/History/Notifications/devices: delete
   • Search: delete docs; Recs/feature store: delete user vectors & features
   • Analytics: replace user_id with irreversible pseudonym in raw lake (or delete); aggregates unaffected
   • Backups: not mutated; deletion re-applied if a backup is ever restored (deletion log replay) and backups expire per retention (≤ 35 days)
   • Moderation/legal: retain minimal records required by law (documented exception)
4. Each consumer acks (DeletionStepCompleted). Coordinator tracks; incomplete steps after SLA → alert.
5. Completion recorded in audit; confirmation email sent (to address captured before deletion).
```


---

## 25. Moderation & Admin Platform

### 25.1 Surfaces moderated

| Surface | Automated checks | Stage |
|---|---|---|
| Video content | Known-illegal hash matching (CSAM) **[MVP, via provider]**; perceptual-hash match against previously removed content **[GROWTH]**; visual classifiers (nudity, violence, gore) on sampled frames **[GROWTH]**; audio/ASR transcript toxicity **[SCALE]**; copyright fingerprinting (Content-ID-like) **[SCALE]** | |
| Titles / descriptions / tags | Blocklists, regex for spam/links, text toxicity classifier **[GROWTH]** | MVP basic |
| Thumbnails | Image classifier (nudity/shock), hash match | GROWTH (MVP: hash match) |
| Comments | §15.3 layers | MVP basic |
| Usernames / handles / channel names | Reserved words, impersonation of verified names (similarity), profanity list | MVP |
| Avatars / banners | Image classifier + hash match | GROWTH |
| User reports | All surfaces | MVP |

### 25.2 Workflow

```
Signals: user reports · automated scores · creator/community flags · legal requests · trusted flaggers [GROWTH]
   │
   ▼
Case Router: one open case per target; dedupe reports; priority = f(severity of reason, model confidence,
             reach (views/hour), reporter reputation, report count, legal deadline)
   │
   ├── High-confidence illegal (hash match) ──► automatic BLOCK/REJECT + legal escalation workflow (no human viewing required to block)
   ├── High-confidence policy violation ──────► auto-action (limit/age-restrict/hold) + queue for human confirmation
   └── Uncertain ─────────────────────────────► human review queue (by language/policy area/severity; SLA timers)
                                                   │
                                     Moderator decision (policy reason code required):
                                     approve · age-restrict · limit distribution · remove · warn · strike · suspend · terminate · escalate
                                                   │
                                     ModerationActionTaken → apply to target (Video/Comment/User modules) → notify affected user
                                     (reason, policy link, appeal link) → audit
                                                   │
                                     Appeal (once per action) → different reviewer (senior) → uphold / overturn (restore) → notify
```

### 25.3 Moderation states

- **Content moderation_status:** `none` → `pending_review` → `approved` | `age_restricted` | `limited` (no recommendations/search, still accessible by link) | `removed` (= BLOCKED status) | `restored` (after appeal).
- **Case states:** `open` → `in_review` → (`escalated`) → `resolved` → (`appealed` → `appeal_resolved`).
- **Account states:** `active` → `warned` → `restricted` (e.g., no uploads/comments for N days; temporary block) → `suspended` (temporary, all creation disabled, content may stay up) → `terminated` (permanent; content removed; re-registration prevention via signals).
- **Strike system:** strikes expire (e.g., 90 days); 3 active strikes → termination; severe violations → immediate termination.

### 25.4 Moderator tooling & wellbeing
- Content blurred/greyscale by default, audio muted, frame-strip view instead of full playback; exposure limits and rotation for graphic queues.
- Context panel: uploader history, prior actions, report reasons, model scores, similar removed content.
- Quality: double-review sampling, inter-rater agreement metrics, policy versioning on every action.

### 25.5 Admin platform capabilities

| Capability | Details |
|---|---|
| User search & profile | By email/handle/id/IP-hash/device (access-logged); sessions, strikes, reports, linked accounts |
| Account actions | Suspend/restore/terminate, force logout, reset MFA (with verification), verify channel, change role (admin only, 2-person rule for privileged roles **[GROWTH]**) |
| Video search & actions | By id/title/channel/status; block/restore/age-restrict/limit; reprocess; view processing history |
| Moderation queue | Queue views by policy/language/priority; case details; bulk actions with safeguards |
| Reports review | Report triage, reporter reputation, abuse-of-reporting detection |
| Appeals | Appeal queue with SLA |
| Processing failures | Failed jobs list, error codes, task logs, DLQ inspection, **replay/redrive**, retry on bigger worker |
| Analytics overview | DAU/MAU, uploads, processing latency, watch hours, top content, abuse metrics |
| Feature flags & config | Kill switches (e.g., disable uploads, disable comments globally, read-only mode) |
| Audit history | Searchable, immutable audit of all staff actions with before/after |
| Legal tools | Legal hold, takedown requests, data export/deletion request status |

Admin UI talks only to `/admin/v1` APIs; there's no direct DB access for routine operations. Production DB access is break-glass, time-bound, and audited.

---

## 26. Observability

### 26.1 Pillars
- **Structured logs** (JSON): timestamp, level, service/module, env, version, request_id, trace_id, span_id, user_id (hashed/pseudonymous where possible), route, status, latency, error code. No secrets/PII/tokens in logs (redaction middleware). Sampling for high-volume success logs at scale.
- **Metrics**: RED (Rate, Errors, Duration) per endpoint and consumer; USE (Utilization, Saturation, Errors) for infra; business metrics (uploads, publishes, plays, views counted).
- **Distributed tracing**: OpenTelemetry SDKs; context propagated over HTTP and **in message attributes** across queues (link spans for async hops); tail-based sampling (keep all errors/slow traces) **[GROWTH]**.
- **Client QoE telemetry**: from player beacons (startup time, rebuffer ratio, errors per CDN/ISP/country).
- **Tooling:** **[MVP]** cloud-native (CloudWatch/X-Ray) or a managed stack (Grafana Cloud/Datadog/Honeycomb). Use OpenTelemetry everywhere to avoid vendor lock-in.

### 26.2 Key metrics

| Area | Metrics |
|---|---|
| **API** | RPS by route; p50/p95/p99 latency; 4xx/5xx rate; saturation (CPU, memory, event-loop/thread pool, DB pool wait); rate-limit rejections |
| **Upload** | Sessions created/completed/aborted/expired; completion rate; part retry rate; avg upload duration by size; completion errors |
| **Media** | Processing queue depth & **age of oldest message**; job duration (p50/p95) by duration bucket; time-to-first-playable; time-to-full-ladder; transcoding failures by error code; retries; worker utilization; spot interruptions; cost per processed minute |
| **Playback** | Playback auth latency & errors; **video startup time** (client); **rebuffer ratio** (rebuffer time / watch time); playback failure rate; average bitrate; CDN hit ratio (requests & bytes); origin requests/egress; 4xx/5xx at edge by PoP |
| **Database** | Connections (used/max), pool wait, QPS, slow queries (> 100 ms), locks/deadlocks, CPU, memory, IOPS, storage growth, **replication lag**, autovacuum/bloat, transaction ID wraparound age |
| **Redis** | Memory, evictions, hit ratio, ops/s, latency, hot keys, replication |
| **Queue/stream** | Backlog (visible messages), **age of oldest message**, consumer lag (Kafka), throughput, **DLQ depth**, redelivery count |
| **Search** | Query latency, error rate, indexing lag (event time → indexed), cluster health, heap |
| **Notifications** | Fan-out latency, send success rate per provider, bounces, invalid tokens |
| **Analytics** | Ingestion rate, rejected events, end-to-end lag (event → aggregate), view counting lag |
| **Business** | DAU, uploads/day, publishes/day, watch hours, views counted, sign-ups, comment rate |
| **Security** | Login failures by ASN, WAF blocks, token reuse detections, privilege changes |

### 26.3 SLIs & SLOs (initial)

| SLI | SLO (MVP → Scale) | Window |
|---|---|---|
| API availability: % non-5xx of valid requests (excluding 4xx) | 99.9% → 99.95% | 30 days |
| API latency: % of read requests < 300 ms | 99% | 30 days |
| Playback authorization success (non-5xx) | 99.95% → 99.99% | 30 days |
| Playback authorization latency p95 | < 150 ms | 30 days |
| Video start success (client: started / attempted, excluding user aborts) | 99% → 99.5% | 7 days |
| Startup time p75 | < 2 s | 7 days |
| Rebuffer ratio | < 1% of watch time | 7 days |
| Upload completion success (server-side failures only) | 99.9% | 30 days |
| Processing: % of videos ≤ 30 min duration reaching first playable within 15 min | 95% → 99% | 7 days |
| Processing success (non-user-caused failures) | 99.5% | 30 days |
| Search availability / p95 latency | 99.9% / < 300 ms | 30 days |
| Search freshness: takedowns removed from index | 99% < 60 s | 7 days |
| View count freshness | 99% < 5 min | 7 days |
| Notification delivery (in-app created) within 2 min of event | 99% | 7 days |

Error budgets drive release decisions (freeze risky deploys when budget exhausted).

### 26.4 Dashboards
1. **Executive/health overview:** SLOs, error budgets, DAU, plays, uploads.
2. **API:** per-route RED, dependency latencies.
3. **Upload & processing funnel:** sessions → completed → processed → published; queue age; failures by code.
4. **Playback QoE:** startup, rebuffer, errors by country/ISP/CDN PoP/device; CDN hit ratio.
5. **Data stores:** PG, Redis, search.
6. **Async:** queues, DLQs, consumer lag, outbox lag.
7. **Cost:** egress, encode minutes, storage by class, per-unit costs.
8. **Trust & safety:** report volume, queue backlog, SLA breaches, auto-action rates.

### 26.5 Alerting
- **Page (on-call)** on: SLO **burn-rate** alerts (fast: 2% budget in 1 h; slow: 5% in 6 h), playback auth error spike, DB primary down/failover, replication lag > 30 s (affects read-your-writes), processing queue oldest message age > 30 min, DLQ growth on critical queues (`VideoUploaded`, deletion saga), outbox lag > 1 min, CDN 5xx spike, certificate expiry < 14 days.
- **Ticket (business hours)** on: slow queries trend, storage growth anomalies, cost anomalies, elevated non-critical DLQs, search indexing lag.
- Every alert links to a **runbook**.

---

## 27. Reliability (Error Handling, Retries, Idempotency, Consistency)

### 27.1 Failure strategy by component

| Failure | Impact | Detection | Strategy / graceful degradation |
|---|---|---|---|
| **Upload failures** (network, part errors) | Creator can't finish | Part retry rate, session failure metrics | Client part retries + resume; presign refresh; sessions persist 24 h+; clear user messaging. Storage outage → uploads disabled with banner (kill switch), browsing unaffected |
| **Transcoding failures** | Video stuck/failed | Job failure rate, queue age | Classify transient vs deterministic; retries with backoff; resource escalation; partial ladder publish; fallback to managed transcoder on systemic worker issue; creator notification with actionable reason |
| **Queue failures** (service degraded) | Async work delayed | Publish errors, backlog | Outbox buffers events in PG (producers unaffected); relay retries; consumers resume; work is delayed, not lost |
| **Worker crashes / spot interruptions** | Task interrupted | Lease expiry, heartbeats | Visibility timeout → redelivery; idempotent deterministic outputs; checkpoint per rendition/chunk; stuck-task sweeper |
| **Database outage** (primary) | Writes fail | Health checks | Managed multi-AZ automatic failover (~30–120 s); app retries with backoff on connection errors; **read-only mode**: reads from replicas/cache continue (watch pages, playback auth for public videos from cached policy), writes return 503 with retry-after; feature flags to disable writes gracefully |
| **Database replica lag/outage** | Stale or failed reads | Lag metric | Route reads to primary (with capacity guard) or serve from cache; read-your-writes paths always primary |
| **CDN issues** (regional PoP problems, provider outage) | Playback errors | Client QoE beacons, synthetic probes | Provider handles PoP failover; [SCALE] multi-CDN switch via steering; [GROWTH] secondary CDN configured but cold as manual failover |
| **Search outage** | Search unavailable | Health checks | Fallback to **PG FTS** (simplified results, rate-limited) or "search temporarily unavailable"; browsing, trending, channel pages unaffected; indexing events accumulate in queue and catch up |
| **Cache (Redis) outage** | Higher DB load/latency | Error rate | Fall through to DB behind concurrency limiters; in-process caches extended; non-essential features (personalized recs, live counts) degrade to defaults; counter increments buffered/replayed from events; rate limiting falls back to local limits |
| **Notification failures** (provider down) | Delayed push/email | Provider error rate | Retries with backoff within TTL; in-app inbox is the durable baseline; failover to secondary email provider **[GROWTH]**; drop stale engagement pushes |
| **Analytics pipeline down** | Counts/analytics stale | Lag metrics | Ingestion buffers in queue/stream (retention ≥ 24 h–7 d); counts freeze (not reset); creator analytics shows "data delayed" banner |
| **Recommendation service down** | Generic feed | Timeouts | Timeouts + fallback to trending/subscriptions/popular |
| **Third-party IdP down** (social login) | Social logins fail | Error rate | Email/password still works; show provider-specific message |

**General patterns:** timeouts on every remote call (budgeted from the endpoint SLO), retries only for idempotent operations, circuit breakers on dependencies, bulkheads (separate pools per dependency and per worker class), load shedding (reject low-priority traffic first under overload — e.g., analytics before playback auth), feature kill switches.

### 27.2 Operations requiring idempotency

| Operation | Idempotency mechanism |
|---|---|
| Upload session creation | `Idempotency-Key` (user, key) → same session |
| Upload finalization | Conditional state transition; repeated complete returns current state; one `VideoUploaded` via outbox in same txn |
| Processing job creation | Unique (video_id, processing_version, profile) |
| Processing task execution | Deterministic output paths; upsert of variants; finalize is conditional on job state |
| Processing completion callback/event | Conditional transition (`PROCESSING → READY`) + version check; duplicates no-op |
| Event handling (all consumers) | `processed_messages(consumer, event_id)` insert-if-absent in the same txn as the side effect, or natural idempotency (upsert with version) |
| Reactions / subscriptions | Upserts of final state; events only on change |
| Comment posting | `Idempotency-Key` from client (prevents double-post on retry) |
| Notifications | `dedupe_key` unique per recipient; delivery task keyed by (notification_id, channel) |
| Counters | Event-level dedupe before increment (view validator), or flush by idempotent "set to computed value" from aggregates |
| Search indexing | External version on documents |
| Account deletion steps | Each step idempotent and re-runnable; tracker records completion |
| Payments **[future]** | Provider idempotency keys; ledger entries unique per provider event id; webhook dedupe; never decide on client callbacks alone |

### 27.3 Retry policy
- **Exponential backoff with full jitter:** `delay = random(0, min(cap, base × 2^attempt))`; e.g., base 200 ms, cap 30 s for sync dependencies; base 10 s, cap 15 min for async jobs.
- **Retry budgets:** limit retries to ≤ 10–20% of requests per dependency to avoid retry storms; never retry on 4xx (except 408/429 respecting Retry-After).
- **Limits:** sync calls 2–3 attempts within the request deadline; queue consumers 5–8 attempts (`maxReceiveCount`) then DLQ; media tasks 3–5 attempts with escalation.
- **Poison messages:** malformed or deterministically failing messages detected by repeated failures with same error → straight to DLQ (don't burn retries); schema validation at consume time.
- **DLQ handling:** every queue has a DLQ; alarms on depth > 0 for critical flows; admin console shows messages with error context; **redrive** after fix (idempotency makes replay safe); DLQ retention 14 days; weekly DLQ review.

### 27.4 Consistency model per workflow

| Workflow | Consistency | Why / how |
|---|---|---|
| Authentication, sessions, password change | **Strong** | Security; PG primary; revocation via Redis denylist checked per request |
| Ownership, roles, permission changes | **Strong** | Authorization must not lag; read from primary or cache invalidated synchronously |
| Video visibility & moderation blocks (authorization effect) | **Strong** for playback auth; eventual for search/feeds (seconds) | Serve-time re-filtering protects against index lag; signed-cookie TTL bounds playback lag |
| Upload finalization / state machine | **Strong** (single row conditional updates) | Avoid double processing and illegal transitions |
| Handle/email uniqueness | **Strong** (unique constraints) | |
| Comments (author sees own comment) | Read-your-writes; others eventual (cache TTL) | Primary read for author's next request |
| Reactions/subscriptions own state | Read-your-writes | Counts eventual |
| View counts, like counts, subscriber counts | **Eventual** (≤ 1–5 min) | Aggregated; rounding hides drift |
| Search index | **Eventual** (seconds) | Outbox → indexer |
| Feeds, recommendations, trending | **Eventual** (minutes–hours) | Precomputed |
| Analytics | **Eventual** (minutes–day) | Corrections allowed (fraud) |
| Notifications | **Eventual** (seconds–minutes) | |
| Account deletion propagation | **Eventual with guaranteed completion** (tracked saga, SLA) | |
| Payments **[future]** | **Strong** + reconciliation | Ledger |

**Tradeoffs:** eventual consistency buys availability and throughput (no distributed transactions, no hot-row contention) at the cost of temporarily stale numbers and a need for idempotent, reconciling consumers. We confine strong consistency to single-database transactions in PG — **no distributed transactions (2PC)** anywhere; cross-module workflows use outbox + sagas with compensations.

---

## 28. Scalability (incl. Database Scaling)

### 28.1 Per-component strategy

| Component | Scaling approach |
|---|---|
| **API servers** | Stateless containers behind ALB; autoscale on CPU (~60%) and RPS per task; min 2–3 tasks across AZs; separate scaling groups for heavy endpoint classes from the same artifact (playback-auth, events ingestion, public reads) **[GROWTH]**; connection pooling (PgBouncer/RDS Proxy) to cap DB connections |
| **Databases** | §28.2 evolution |
| **Redis** | Vertical → read replicas → Redis Cluster sharding; workload-separated clusters; hot-key mitigations |
| **Queues** | SQS scales automatically; Kafka: partitions sized for peak consumer parallelism (partitions ≥ max consumers), add brokers, tiered storage |
| **Media workers** | Autoscale on **queue depth and oldest-message age** (target: backlog drain within SLO), scale to zero when idle; spot instances with diversified instance types + on-demand base for priority lane; separate pools per task class; chunked parallel encoding for long videos; GPU/VPU instances evaluated at scale |
| **Search** | Shards sized 10–50 GB each; replicas for read throughput; separate clusters for videos, channels, autocomplete at scale; dedicated master nodes; index lifecycle |
| **Analytics** | Stream partitions; ClickHouse sharded + replicated; rollups/materialized views; raw data in object storage (lake) queried by warehouse engines |
| **CDN** | Provider-scaled; our levers: cache hit ratio, origin shield, multi-CDN, commit contracts |
| **Notifications** | Fan-out workers scale on queue depth; per-provider concurrency limits |
| **Feed/Recs** | Precompute offline; online serving stateless with Redis/feature store; ANN indexes replicated |

### 28.2 Database scaling evolution

```
Stage A [MVP]      Single primary (multi-AZ standby) ─ all modules, separate schemas per module
                     + 1 read replica for analytics-ish queries/admin
                                │ triggers: primary CPU > 60% sustained, read QPS growth, replica for HA reads
Stage B [MVP→GROWTH] Primary + N read replicas; read routing (public reads, feeds, search fallback → replicas;
                     read-your-writes → primary); PgBouncer; caching absorbs most reads
                                │ triggers: tables > ~100M rows / 100s GB, vacuum/index maintenance pain,
                                │           retention deletes become expensive
Stage C [GROWTH]   Partitioned tables (native declarative partitioning):
                     • time-range: notifications, outbox, raw events (if still in PG), audit logs, video_views → drop old partitions
                     • hash: comments (video_id), watch_history (user_id), reactions (target_id or user_id)
                   Vertical split: move high-volume module DBs to their own instances (e.g., comments, history, analytics rollups)
                   — easy because modules already own separate schemas and avoid cross-module joins
                                │ triggers: single-module write throughput exceeds largest instance; storage > ~10–20 TB per instance
Stage D [SCALE]    Horizontal sharding of the largest domains:
                     • Option 1: application-level sharding by key (user_id / video_id) with a shard map service
                     • Option 2: Citus (distributed Postgres) — keeps SQL, shards by distribution column
                     • Option 3: distributed SQL (Aurora Limitless, CockroachDB, YugabyteDB, Spanner) for global consistency
                     • Option 4: move access-pattern-simple tables (watch_history, notifications, feed inboxes, reactions)
                       to DynamoDB/Cassandra/ScyllaDB
                   Core metadata (users, channels, videos) may stay unsharded much longer (it's small relative to engagement data).
```

### 28.3 Which tables grow first and partition keys

| Table | Grows | Partition/shard key | Rationale |
|---|---|---|---|
| Raw view/telemetry events | **First and fastest** (billions) | time (day) | Move out of OLTP early (→ S3/ClickHouse) |
| outbox / processed_messages | Fast churn | time | Drop partitions |
| watch_history | Very large (users × videos) | user_id (hash) | Access always by user |
| reactions | Very large | user_id (my reactions, uniqueness) — counts come from aggregates; or target_id if listing by target matters | Uniqueness check is (user, target) |
| comments | Large | video_id (hash) | Read by video; author queries via secondary index/table |
| notifications | Large, short-lived | recipient_user_id + time | Retention via partition drop |
| subscriptions | Large (billions at scale) | subscriber_user_id (primary copy) + channel_id (fan-out copy) | Two access patterns |
| playlist_items | Medium | playlist_id | |
| videos / video_variants | Moderate (hundreds of millions) | video_id if needed; channel_id for co-location of channel pages | Cache absorbs reads |
| users / channels | Moderate | user_id | Usually last to need sharding |
| audit/moderation actions | Moderate | time | Append-only |

**Cross-shard queries** are avoided by design: feeds/trending/search are served from derived stores, not by scatter-gather over OLTP shards.

---

## 29. Storage

### 29.1 Buckets & key layout (conceptual)

```
uploads-bucket (private)          uploads/{video_id}/{session_id}/source              ← original upload
media-bucket (private, CDN origin)
  media/{video_id}/v{processing_version}/master.m3u8
  media/{video_id}/v{n}/manifest.mpd                      [optional DASH]
  media/{video_id}/v{n}/{codec}_{height}p{fps}/init.mp4, seg_{00001}.m4s, playlist.m3u8
  media/{video_id}/v{n}/audio_{lang}_{bitrate}/...
  media/{video_id}/v{n}/subs/{lang}.vtt
  media/{video_id}/v{n}/storyboard/sprite_{k}.jpg + storyboard.vtt
images-bucket (public via CDN)    thumbs/{video_id}/{content_hash}_{w}x{h}.{webp|jpg}, avatars/{user_id}/{hash}.webp
originals-archive (private)       archive/{video_id}/source  (after lifecycle transition or retained in uploads bucket)
exports-bucket (private)          exports/{user_id}/{request_id}.zip  (TTL 7 days)
```
- Video ID prefixes spread load across storage partitions (no hot prefix by date).
- **Original moved** (or lifecycle-transitioned) from `uploads` to retention location after successful processing.

### 29.2 Storage classes & lifecycle

| Data | Initial class | Lifecycle | Notes |
|---|---|---|---|
| **Original uploads** | Standard (needed for processing & reprocessing) | → Infrequent Access after 30 days → Archive (Glacier Instant/Flexible) after 90–180 days | See retention decision below |
| **Processed renditions** | Standard | **Intelligent-Tiering** (auto moves cold objects to IA/archive-instant tiers without retrieval latency) **[MVP/GROWTH]** | Never use retrieval-delayed archive for anything playable |
| Renditions of videos with no views in 12+ months **[SCALE]** | — | Delete high rungs (keep ≤ 720p) and re-encode from original on demand if requested | Big savings for long tail |
| **Thumbnails / sprites / avatars** | Standard | Intelligent-Tiering | Small; high request counts → CDN |
| **Subtitles** | Standard | Same as renditions | Tiny |
| **Mezzanines** | Standard | Delete after 7–30 days | Rebuildable from original |
| **Deleted content** | Unchanged until purge | Purge after 30-day grace (unless legal hold → separate locked prefix with object lock) | Versioned buckets: noncurrent versions expire after 7–30 days |
| **Incomplete multipart uploads** | — | Abort after 7 days | |
| Raw analytics events | Standard | → IA after 30 days → archive after 13 months / delete per retention | Parquet + compression |
| Backups/logs | Per provider | Retention policies | Object lock for audit logs |

### 29.3 Should originals always be retained?

| Option | Pros | Cons |
|---|---|---|
| Retain forever (archived) | Re-encode for new codecs/ladders, quality upgrades, legal/evidence, disputes | Storage cost grows linearly (≈ 1/3 of total storage) |
| Delete after processing | Lowest cost | Can't re-encode (quality loss if re-encoding from top rendition); creators lose their master |
| **Tiered retention (recommended)** | Balance | Policy complexity |

**Recommendation:** **Retain originals by default**, moved to archive classes after 90–180 days (archive storage is ~10–20× cheaper than standard). Exceptions: very large originals of videos with near-zero views after 1–2 years may be replaced by a high-quality **mezzanine** (e.g., high-bitrate 1080p/4K H.264/HEVC) **[SCALE]** — a documented product decision. Deleted videos: originals purged with everything else.

### 29.4 Durability & integrity
- Object storage durability 11 nines; versioning on media bucket (protects against accidental overwrite/delete; noncurrent expiry keeps cost in check).
- Cross-region replication **[GROWTH]** for originals (DR) — renditions are rebuildable but replicate hot renditions at scale for multi-region origin.
- Periodic integrity audits: sample objects vs stored checksums.

---

## 30. Cloud Architecture

### 30.1 Reference AWS architecture

```
Route 53 (DNS, health checks, latency/failover routing [SCALE])
   │
   ├── api.example.com ─► CloudFront (API distribution: no-cache by default, WAF attached) ─► ALB ─► ECS Fargate: core-api
   │                         └── AWS WAF + Shield (Standard MVP / Advanced SCALE)              │
   ├── events.example.com ─► CloudFront ─► ALB ─► ECS: ingestion (same artifact, separate service)
   ├── media.example.com ──► CloudFront (signed cookies, Origin Shield [GROWTH], OAC) ─► S3 media bucket
   └── img.example.com ────► CloudFront (public, image resizing via Lambda@Edge/CloudFront Functions [GROWTH]) ─► S3 images

VPC (3 AZs): public subnets (ALB, NAT) · private app subnets (ECS tasks, workers) · private data subnets (RDS, ElastiCache, OpenSearch)
   ECS Fargate services: core-api, ingestion, outbox-relay, async-workers (notifications, indexer, aggregators, schedulers)
   ECS on EC2 (or EKS) capacity providers: media-workers (Spot, compute-optimized, NVMe) — or AWS Elemental MediaConvert
   RDS PostgreSQL Multi-AZ (+ read replica) → Aurora PostgreSQL [GROWTH] ; RDS Proxy
   ElastiCache Redis (cluster mode disabled MVP → enabled GROWTH), Multi-AZ
   SQS (work queues + DLQs), SNS or EventBridge (domain event fan-out)
   S3: uploads, media, images, archive, exports, logs ; S3 Event Notifications → SQS
   Kinesis Data Firehose → S3 (raw events) [MVP] ; Kinesis Data Streams / MSK [GROWTH/SCALE]
   OpenSearch Service [GROWTH] ; ClickHouse (ClickHouse Cloud or self-managed on EC2) [GROWTH] ; Athena/Glue on S3 lake [GROWTH]
   Step Functions [GROWTH, optional] for processing workflows
   Lambda: lightweight glue only (S3 event filters, scheduled sweepers, image thumbnailing small jobs) — not core API
   SES (email), SNS Mobile Push / direct APNs-FCM (push) [GROWTH]
   Secrets Manager (credentials, signing keys), KMS (CMKs), IAM roles per service, ACM certificates
   CloudWatch (logs/metrics/alarms), X-Ray or OpenTelemetry → managed backend, CloudTrail (org-wide), AWS Config, GuardDuty
   ECR (images), AWS Backup (cross-region copies [GROWTH])
```

### 30.2 Component choices & optionality

| Component | Status | Notes / alternatives |
|---|---|---|
| Route 53, CloudFront, WAF, Shield Std, S3, ALB, ECS Fargate, RDS PG, ElastiCache, SQS, SNS, Secrets Manager, KMS, CloudWatch, CloudTrail, SES, ECR | **[MVP] required** | |
| API Gateway | **Optional** | ALB is cheaper and simpler for a container API at high RPS; API Gateway useful for usage plans/API keys for third-party developer APIs [GROWTH+] |
| ECS vs EKS | **ECS [MVP]** | Less ops; EKS when the org needs Kubernetes ecosystem, multi-cloud portability, or large platform team [SCALE] |
| Lambda | Optional glue | Avoid for core API (connection management to PG, cold starts); fine for event glue |
| MediaConvert | **Option [MVP]**, fallback later | vs FFmpeg on ECS/EC2 Spot (ADR-006) |
| Aurora PostgreSQL | **[GROWTH]** | Faster failover, up to 15 replicas, storage autoscaling, Global Database for DR [SCALE] |
| OpenSearch | **[GROWTH]** | |
| MSK (Kafka) | **[SCALE]** | Kinesis as simpler stepping stone [GROWTH] |
| ClickHouse | **[GROWTH]** | Not native AWS; ClickHouse Cloud on AWS or Redshift as alternative (ADR-008) |
| Step Functions | Optional [GROWTH] | Or Temporal |
| Shield Advanced | [SCALE] | DDoS cost protection & response team |
| Global Accelerator | [SCALE] | Multi-region API ingress |

### 30.3 GCP and Azure equivalents

| Function | AWS | GCP | Azure |
|---|---|---|---|
| DNS | Route 53 | Cloud DNS | Azure DNS / Traffic Manager |
| CDN | CloudFront | Cloud CDN / Media CDN | Azure Front Door / Azure CDN |
| WAF / DDoS | WAF, Shield | Cloud Armor | Azure WAF, DDoS Protection |
| Load balancer | ALB/NLB | Cloud Load Balancing | Application Gateway / Front Door |
| Containers | ECS / EKS / Fargate | Cloud Run / GKE | Container Apps / AKS |
| Functions | Lambda | Cloud Functions / Run | Azure Functions |
| Object storage | S3 | Cloud Storage | Blob Storage |
| Managed transcoding | MediaConvert | Transcoder API | (Azure Media Services retired — use third-party or FFmpeg on AKS/Batch) |
| PostgreSQL | RDS / Aurora | Cloud SQL / AlloyDB | Azure Database for PostgreSQL Flexible Server |
| Redis | ElastiCache | Memorystore | Azure Cache for Redis / Managed Redis |
| Queues | SQS | Pub/Sub (or Cloud Tasks) | Service Bus / Storage Queues |
| Pub/Sub fan-out | SNS / EventBridge | Pub/Sub / Eventarc | Event Grid / Service Bus topics |
| Kafka/streaming | MSK / Kinesis | Managed Kafka / Pub/Sub / Dataflow | Event Hubs (Kafka API) |
| Search | OpenSearch Service | Elastic Cloud on GCP / Vertex AI Search | Elastic on Azure / Azure AI Search |
| Analytics DB / warehouse | ClickHouse, Redshift, Athena | BigQuery, ClickHouse | Synapse/Fabric, ADX (Kusto), ClickHouse |
| Workflows | Step Functions | Workflows | Durable Functions / Logic Apps |
| Secrets / keys | Secrets Manager / KMS | Secret Manager / Cloud KMS | Key Vault |
| Observability | CloudWatch / X-Ray | Cloud Monitoring/Logging/Trace | Azure Monitor / App Insights |
| Email | SES | (third-party: SendGrid etc.) | Azure Communication Services Email |

---

## 31. Deployment Strategy & CI/CD

### 31.1 Environments

| Env | Purpose | Data | Infra scale | Access |
|---|---|---|---|---|
| **Local** | Dev inner loop | Seeded synthetic data; local PG/Redis/S3-compatible emulator/queue emulator; tiny sample videos; encoder stub or real FFmpeg | Laptop | Developer |
| **Development** (shared) | Integration of merged main; feature flags | Synthetic | Minimal, auto-scaled to zero overnight | Engineering |
| **QA** | Test automation, manual QA, ephemeral **preview environments per PR [GROWTH]** | Synthetic, resettable | Small | Eng + QA |
| **Staging** | Production-like: same topology, same IaC, smaller size; release candidate validation, load tests, DR drills | Synthetic + anonymized/sampled prod-like datasets (never raw PII) | Prod-shaped (scaled down) | Eng, restricted |
| **Production** | Live | Real | Full | Least privilege, break-glass |

- **Account/project separation:** separate cloud accounts per environment (at least prod vs non-prod) under an organization with SCPs; no shared credentials.
- **Configuration separation:** 12-factor; config in parameter store per env; code identical across envs; feature flags service (LaunchDarkly/Unleash/OpenFeature-based) for runtime toggles.
- **Secrets:** per-env secrets in Secrets Manager; never in repo/images; developers have no prod secret access.
- **Database separation:** separate instances per env; prod snapshots never restored to lower envs without anonymization pipeline.
- **Object storage separation:** separate buckets per env (and per account); CDN distributions per env; separate signing keys.
- **Deployment promotion:** build **once**, promote the same immutable artifact (container image digest) dev → QA → staging → prod; infra changes promoted via IaC in the same order.

### 31.2 CI/CD (conceptual)

```
PR opened ─► CI:  lint/format · type check · unit tests · module-boundary checks · SAST · dependency & license scan
                  · secret scan · build image · image scan · contract tests (API/OpenAPI diff, event schema compat)
                  · integration tests (ephemeral PG/Redis/S3/queue containers) · migration dry-run (forward + backward compatibility check)
          ─► [GROWTH] ephemeral preview environment + E2E smoke
merge to main ─► build signed image (SBOM) ─► deploy dev ─► smoke ─► deploy QA ─► E2E suite + API tests
              ─► deploy staging ─► smoke + performance regression (periodic) + DAST
              ─► prod: progressive delivery — canary (5% → 25% → 100%) or blue/green per service,
                 automated analysis of SLO metrics (error rate, latency) at each step ─► auto-rollback on regression
              ─► post-deploy smoke tests (synthetic: login, upload small video end-to-end, playback, search)
```

- **Database migrations:** expand/contract pattern (add columns/tables first, backfill async, switch reads, then remove old) so every deploy is backward compatible with the previous app version → rollbacks never require schema rollback. Long migrations run as online operations (concurrent index builds, batched backfills).
- **Media workers:** versioned encoder configurations; new encoder versions canaried on a % of jobs with automated quality checks (VMAF/PSNR on test corpus) before full rollout.
- **Rollback:** redeploy previous image digest (one action); feature flags for instant disable; data migrations designed to be forward-fixable.
- **Release cadence:** continuous deployment to prod for the monolith (multiple times/day) once test maturity allows; change freeze only by error budget policy.
- **Approvals:** prod deploy gated by automated checks; manual approval for infra changes affecting data stores.

---

## 32. Disaster Recovery & Multi-Region

### 32.1 Backups & recovery

| Asset | Mechanism | MVP | Growth/Scale |
|---|---|---|---|
| PostgreSQL | Automated daily snapshots + **PITR** (WAL archiving, 5-min granularity) | 7–14 days retention | 35 days + monthly snapshots retained 1 year; **cross-region snapshot copies**; Aurora Global Database (RPO ~1 s) [SCALE] |
| Redis | Snapshots (for counters/rate-limit cluster only) | Daily | Hourly; data is rebuildable — treat as cache |
| Object storage | 11-nines durability; versioning; | Versioning + MFA delete on critical buckets | **Cross-region replication** for originals + images [GROWTH]; renditions for multi-region origin [SCALE] |
| Search | Rebuildable from PG; snapshots to S3 | — | Daily snapshots for faster recovery |
| Analytics | Raw events in S3 (source of truth) | — | Replicated lake; ClickHouse rebuildable from lake |
| Queues/streams | Outbox in PG means queue loss loses no domain events; Kafka replication | — | MirrorMaker/cluster linking [SCALE] |
| Infrastructure | IaC (planned) — environment reproducible | Required | Required, tested quarterly |
| Secrets/keys | Secrets manager replication | — | Multi-region keys |

**Recovery drills:** quarterly restore test of PG to a new instance (measure actual RTO); annual full DR exercise at growth.

### 32.2 RPO / RTO targets

| Scenario | MVP RPO | MVP RTO | Large-scale RPO | Large-scale RTO |
|---|---|---|---|---|
| Single instance/AZ failure | 0 (sync standby) | < 5 min (auto failover) | 0 | < 1 min |
| Data corruption / bad migration | ≤ 5 min (PITR) | 1–4 h | ≤ 1 min | < 1 h |
| Full region outage — playback of existing videos | n/a (CDN-cached content continues; uncached content unavailable) | Hours (restore in another region) | ~0 (replicated origin) | Minutes (multi-region origin + CDN failover) |
| Full region outage — control plane | ≤ 15 min (cross-region snapshot/PITR copies at growth; MVP: snapshot copies daily → **≤ 24 h** honestly) | 4–24 h (rebuild via IaC) | ≤ 1 min (async replication) | 15–60 min (active/passive) or ~0 (active/active) |
| Accidental object deletion | 0 (versioning) | < 1 h | 0 | < 1 h |

MVP accepts that a full-region disaster is a multi-hour event; this is a conscious cost decision documented in ADR-012.

### 32.3 Multi-region strategy

**When it becomes necessary (any of):**
- Business requires RTO < 1 h for full region failure (revenue/contractual SLAs).
- Large user populations far from the home region where API latency (not video — CDN handles that) hurts engagement (e.g., > 150 ms RTT added).
- Data residency regulations.
- Upload performance for distant creators (can be solved earlier with regional upload buckets / transfer acceleration).

**Progression:**
1. **[MVP]** Single region, multi-AZ. Global CDN for media (already gives worldwide playback performance). S3 Transfer Acceleration or multi-region upload buckets optional for distant creators.
2. **[GROWTH] Active/passive (warm standby):** secondary region with replicated DB (Aurora Global DB / cross-region replica), replicated S3 originals & images, IaC-ready compute at minimal size, CDN with origin failover groups. Failover: promote DB, scale compute, switch DNS. RTO 15–60 min, RPO seconds.
3. **[SCALE] Active/active (selective):**
   - **Read paths** (watch page, playback auth, search, feeds) served in multiple regions from local replicas/caches — the bulk of traffic.
   - **Writes** routed to a home region per user (user-homing/partitioning by user_id) or to a single global primary with regional read replicas; or distributed SQL for globally consistent writes.
   - Events replicated across regions (Kafka cluster linking); idempotent consumers make replay safe.
   - Media processing regional (process near the upload), outputs replicated to multi-region origin.
   - Global routing: latency-based DNS / anycast (Global Accelerator); health-checked failover.
   - Conflict-prone data (counters) aggregated regionally then merged (CRDT-like sums).
   - Playback and upload continue in surviving regions during an outage; some writes (for users homed in failed region) degraded until failover.

---

## 33. Cost Optimization

### 33.1 Largest cost drivers (typical order at scale)

| Rank | Cost | Driver | Share at large scale (indicative) |
|---|---|---|---|
| 1 | **CDN egress** | Watched hours × bitrate | 50–70% |
| 2 | **Storage** | Originals + renditions growth | 10–20% |
| 3 | **Transcoding** | Uploaded minutes × ladder × codecs | 5–15% |
| 4 | Compute (API, workers) | RPS | 5–10% |
| 5 | Databases | Size, IOPS, replicas | 3–8% |
| 6 | Analytics | Event volume, retention, queries | 3–8% |
| 7 | Search | Cluster size | 1–3% |
| 8 | Observability | Log/metric/trace volume | 2–5% (often surprisingly high) |

### 33.2 Strategies

| Area | Strategy | Stage |
|---|---|---|
| CDN | Maximize cache hit ratio (versioned immutable paths, path-only cache keys, shield) | MVP |
| CDN | Avoid serving bitrates no one can see: cap rendition by player size; sensible default start quality | MVP |
| CDN | Per-title encoding (20–40% fewer bits) | GROWTH |
| CDN | AV1/HEVC for top ~5–10% most-watched videos (30–50% fewer bits on a majority of egress) | SCALE |
| CDN | Committed-use pricing; multi-CDN price competition; ISP peering | GROWTH→SCALE |
| Transcoding | Generate only rungs ≤ source; skip 1440p/4K unless source and popularity justify (lazy encoding) | MVP / GROWTH |
| Transcoding | Spot instances (60–90% cheaper), scale to zero, right-sized instance types; managed service only where cheaper/necessary | GROWTH |
| Transcoding | Avoid re-encoding: per-version outputs, only encode new codecs for popular videos | GROWTH |
| Storage | Lifecycle originals to archive; Intelligent-Tiering for renditions; delete mezzanines; abort incomplete multipart; prune high rungs for dead long-tail [SCALE] | MVP→SCALE |
| Storage | Limits on upload size/duration by account trust tier | MVP |
| Compute | Autoscaling; Graviton/ARM instances; savings plans for baseline | MVP/GROWTH |
| Database | Caching to reduce instance size; move telemetry out of OLTP early; partition-drop retention instead of mass deletes | MVP/GROWTH |
| Analytics | Pre-aggregate; sample raw QoE events at scale; columnar compression; retention tiers; avoid over-dimensioned rollups | GROWTH |
| Observability | Log sampling, drop debug logs in prod, metric cardinality budgets, trace sampling | GROWTH |
| Abuse | Bot/spam filtering reduces wasted storage/egress (abusive uploads, scraping) | MVP |
| Unit economics | Track **cost per 1k watch hours**, **cost per uploaded hour**, **storage $/video/month**; review monthly; anomaly alerts | MVP |

---

## 34. Testing Strategy

| Level | Scope | Approach |
|---|---|---|
| **Unit** | Domain logic: state machine transitions, authorization policies, ladder selection, view validation rules, ranking formulas, pagination cursors | Fast, isolated; property-based tests for state machine (no illegal transitions) and ladder (never upscales) |
| **Integration** | Module + real PG/Redis/S3-compatible storage/queue in containers | Repositories, outbox relay, idempotent consumers, migrations |
| **API** | Endpoint behavior, validation, error format, auth, pagination, idempotency keys | Generated from OpenAPI; positive/negative cases; authorization matrix tests (role × resource × action) |
| **Contract** | Between client apps and API (OpenAPI compatibility diff), between modules/services (consumer-driven contracts), event schemas (compatibility checks on every change) | Breaking changes fail CI |
| **Event** | Producers emit correct events in same txn; consumers idempotent under duplicates, out-of-order, delayed delivery; DLQ routing on poison messages | Inject duplicates & reordering in tests |
| **Media pipeline** | **Golden corpus** of test videos: resolutions (144p→8K), aspect ratios (16:9, 9:16, 4:3, 21:9, square), rotation metadata, VFR, interlaced, HDR, 50/60 fps, no audio, multiple audio tracks, 5.1, corrupt files, truncated uploads, huge files, very short (<1 s) and very long, unusual containers, malicious samples (fuzzed headers) | Assert ladder chosen, playability (manifest validators, player smoke on each output), A/V sync, duration accuracy, quality (VMAF thresholds), thumbnails sane, failure codes correct |
| **Load** | Expected peak × 2: watch page, playback auth, feed, search, comments, events ingestion; upload session creation | k6/Gatling/Locust-class tools against staging; validate SLOs; capacity per instance |
| **Stress** | Beyond limits to find breaking points and confirm graceful degradation (load shedding, rate limits) | Ramp until failure |
| **Spike** | Viral video: 100× traffic to one video (hot keys, counters, comments); notification fan-out of a 10M-subscriber channel | Validate hot-key mitigations |
| **Soak** | 24–72 h sustained load | Leaks, connection exhaustion, queue drift |
| **Resilience** | Kill DB primary (failover), Redis outage, queue unavailability, search outage, slow dependencies (latency injection), worker spot interruptions, region-level CDN errors | Verify degradation modes in §27 |
| **Chaos [GROWTH/SCALE]** | Continuous fault injection in staging, then controlled in prod (game days) | After observability & runbooks mature |
| **Security** | SAST, DAST, dependency scans, authZ tests, presigned URL abuse tests (key tampering, size overflow), signed URL/cookie tampering, upload of malicious files, rate-limit verification, pen test pre-launch | |
| **Data/analytics** | View counting correctness (dedupe, thresholds, bots), aggregate reconciliation vs raw | Replay recorded event streams |
| **DR** | Restore tests, failover drills | Quarterly |
| **E2E smoke** | Register → upload → process → publish → search → play → like → comment → notification | Every deploy (synthetic, prod included with test accounts) |

**Highest-risk scenarios to test first:**
1. Private/unlisted/blocked video accessible to unauthorized users (authZ leak via playback, search, feeds, thumbnails, CDN caching).
2. Upload finalization race or lost completion → video stuck forever / double processing.
3. Processing pipeline stuck (worker crash loops, poison message) with no alert.
4. Viral video overload: hot keys, counters, comments, DB contention.
5. Duplicate events → double notifications/double counts.
6. Account deletion not fully propagating (compliance).
7. Credential stuffing and token theft/refresh reuse.
8. CDN misconfiguration caching per-user responses or ignoring signatures.
9. Malicious media files exploiting decoders.
10. Migration causing downtime or rollback incompatibility.


---

## 35. MVP Architecture

### 35.1 MVP scope — IN

| Area | Included |
|---|---|
| Identity | Email/password registration, email verification, login, refresh rotation, logout, password reset, Google login, session list/revoke; staff SSO + MFA for admin |
| Users & channels | Profile; one channel per user; handle; avatar/banner |
| Upload | Draft creation, presigned multipart resumable uploads (≤ 10 GB / ≤ 4 h), cancel, expiry sweeper, idempotent completion |
| Processing | Probe, validate, CSAM hash-match via provider, malware scan, H.264/AAC ladder 360p–1080p (≤ source), first-rendition priority, CMAF HLS, 3 auto thumbnails + custom thumbnail, uploaded captions (VTT/SRT), retries, DLQ |
| Lifecycle | Full state machine (minus SCHEDULED), visibility public/unlisted/private |
| Playback | Playback authorization, signed cookies, CloudFront + S3 OAC, resume position |
| Metadata | Title, description, tags, category, language, age restriction flag |
| Engagement | Likes/dislikes (dislike count private), comments + one-level replies (top/newest), comment likes, pin, creator delete; subscriptions; playlists + Watch Later; watch history + continue watching |
| Discovery | PG full-text search (videos + channels) with basic autocomplete; subscriptions feed (fan-out-on-read); home feed (continue watching + subscriptions + trending + rule-based recs); simple global & per-category trending; rule-based related videos |
| Notifications | In-app (new uploads, replies, mentions, comment likes aggregated, processing done/failed); transactional email |
| Analytics | Player event ingestion, validated view counting (Redis → PG), basic creator analytics (views, watch time, avg view duration per video per day, top videos, subscribers gained) |
| Trust & safety | Reports, moderation queue, actions (remove, age-restrict, limit, strike, suspend), appeals (simple), blocklists, rate limits, basic spam heuristics |
| Admin | User/video search, suspend/block/restore, processing failure inspection + retry, DLQ redrive, audit log, kill switches |
| Platform | Observability (logs/metrics/traces/alerts), CI/CD with canary/rollback, backups + PITR, IaC, environments |
| Compliance | Account deletion saga, data export, privacy policy hooks, cookie consent on web |

### 35.2 MVP scope — explicitly OUT

- Microservices beyond monolith + media workers + ingestion scaling group.
- Kafka/MSK; stream processing frameworks (Flink/Spark streaming).
- OpenSearch (PG FTS is enough), ClickHouse (PG rollups enough).
- ML recommendations, feature store, ANN indexes, experimentation platform (simple feature-flag A/B only).
- Fan-out-on-write feeds; real-time WebSocket notifications (poll instead); mobile push (GROWTH); email digests.
- HEVC/AV1, 1440p/4K, HDR, per-title encoding, DASH, DRM, chunked encoding.
- Multi-region active/active or even active/passive (single region, multi-AZ; cross-region backup copies only).
- Database partitioning/sharding (except time-partitioning of raw event/outbox tables if kept in PG).
- Multi-CDN, origin shield (enable shield if long-tail origin load demands it — cheap to turn on).
- Live streaming, monetization/payments, channel memberships, multiple channels per user, channel managers, scheduled publishing, region restrictions, auto-captions, copyright fingerprinting, advanced ML moderation classifiers.

### 35.3 MVP architecture diagram

```
 Clients ─► Route53 ─► CloudFront(+WAF) ─► ALB ─► ECS Fargate: core-api (modular monolith, ≥2 tasks, 2–3 AZ)
                                                 └─► ECS: ingestion (same artifact, events endpoint)
 core-api ─► RDS PostgreSQL (Multi-AZ, + 1 read replica) · ElastiCache Redis (Multi-AZ) · S3 (presign) · SQS/SNS
 outbox-relay + async workers (ECS): notifications, search index (PG FTS refresh), aggregators (views/likes/subs),
                                      trending job, recs batch job, sweepers, deletion saga
 Media: S3 uploads ─► (VideoUploaded via outbox + S3 events) ─► SQS ─► coordinator ─► SQS task queues
        ─► media workers (ECS on EC2 Spot w/ FFmpeg)  OR  MediaConvert  ─► S3 media ─► CloudFront (signed cookies) ─► viewers
 Telemetry: clients ─► ingestion ─► SQS (view validator/aggregator) + Firehose ─► S3 raw
 Email: SES  ·  Secrets: Secrets Manager/KMS  ·  Obs: CloudWatch + OpenTelemetry backend  ·  CloudTrail
```

**Team & effort indication:** 4–6 backend engineers, 1 DevOps/SRE, 1 QA, with media-processing expertise on at least one engineer (or choose managed transcoding). Roughly 5–7 months to public beta following the roadmap (§38), depending on client app timelines.

---

## 36. Growth Architecture

| Addition | Trigger to introduce | What changes |
|---|---|---|
| **OpenSearch** | Search p95 > 300 ms or noticeable DB load from search; > 1–5M videos; multi-language relevance needs; autocomplete quality complaints | Search indexer service consuming events; PG FTS remains as fallback |
| **Dedicated analytics store (ClickHouse) + data lake** | Rollup jobs > 15 min or impacting PG; events > ~5–10k/s; creators demand retention curves, traffic-source breakdowns; ad-hoc analysis needs | Kinesis/managed Kafka for telemetry; ClickHouse materialized views; Parquet lake in S3 + Athena/warehouse for BI |
| **Kinesis or Kafka for telemetry** | Telemetry > ~10k events/s or ≥ 3 consumers need the same stream with replay | Ingestion writes to stream; SQS stays for jobs |
| **Notification service (separate deploy)** | Fan-out load affecting API latency; push notifications launch; digest emails | Separate worker deployment, push providers, preferences UI |
| **Recommendation batch pipeline (co-watch, content similarity)** | Enough watch data (≥ ~100k daily sessions); rules plateau on CTR/watch time | Batch jobs on lake/ClickHouse → candidate tables in Redis/PG; A/B testing framework |
| **Workflow engine (Step Functions/Temporal)** | Processing workflows branch (moderation gates, lazy encoding, reprocessing campaigns), hand-rolled coordinator becomes complex | Coordinator replaced; same queues/workers |
| **Self-managed FFmpeg fleet (if MVP used managed)** | Transcoding spend > cost of 1 FTE to run it, or need per-title/custom ladders | Move bulk encoding to Spot workers; managed as overflow |
| **Per-title encoding, sprites, 240p rung, 1440p/4K lazy encoding** | Egress cost growth; QoE data showing low-bandwidth audiences | Pipeline features |
| **Origin shield** | Origin request rate/cost growth from long tail; hit ratio < 90% | CDN config |
| **Read-replica routing & PgBouncer/RDS Proxy; Aurora migration** | Primary CPU > 60%; connection counts high; failover time requirements | DB tier |
| **Table partitioning** (comments, watch_history, notifications, reactions) | Tables > ~100M rows or index maintenance pain | Partition migration plan (online) |
| **Module vertical split** (comments/history DB instances) | Single module dominates DB load | Separate DB instances per module |
| **Advanced moderation** (image/video/text classifiers, perceptual hash of removed content, trusted flaggers, pre-review tiers) | Report volume > moderator capacity; abuse incidents; regulatory requirements (e.g., EU DSA obligations at scale) | Moderation pipeline + vendor integrations |
| **Active/passive DR region** | Revenue/SLA requires RTO < 4 h for region loss | Cross-region replicas, IaC standby |
| **Mobile push, email digests, real-time in-app (SSE/WebSocket)** | Mobile apps launched; engagement goals | Notification channels |
| **Scheduled publishing, region restrictions, channel managers, multiple channels** | Creator demand | Product features on existing architecture |
| **Separate scaling groups** (playback-auth, public reads) from same artifact | Uneven load affecting other endpoints | Deployment topology only |
| **Preview environments, chaos experiments in staging, bug bounty** | Team > ~15 engineers | Process |

---

## 37. Large-Scale Architecture

| Capability | Design at scale | Prerequisite signals |
|---|---|---|
| **Kafka backbone** | All domain events + telemetry on Kafka (multi-AZ, tiered storage, schema registry); CDC from PG via Debezium replaces polling outbox relay; stream processing (Flink) for sessionization, view validation, fraud scoring, real-time features | Many consumers needing replay; > 100k events/s; cross-region replication needs |
| **ClickHouse clusters** | Sharded/replicated; separate clusters for creator analytics (serving, strict SLO) vs internal exploration | Billions of events/day |
| **Service decomposition** | Extract Feed, Recommendations, Comments, Notifications, Playback (edge-adjacent), Identity, Search, Media into independently deployed services owned by teams; API gateway/BFF layer; gRPC internally; service mesh (mTLS, retries, traffic shifting) | 50+ engineers, multiple teams, deploy contention |
| **Database sharding** | watch_history, reactions, notifications, feed inboxes → wide-column/KV (DynamoDB/Scylla) or sharded PG (Citus); comments sharded by video_id; subscriptions dual-indexed; core metadata on Aurora with many replicas / distributed SQL | Write throughput and storage beyond largest instances |
| **Multiple Redis clusters** | Per workload and per region; hot-key auto-detection with local caches; counters via stream aggregation rather than Redis increments | Hot keys > 100k ops/s; memory > hundreds of GB |
| **Dedicated feed infrastructure** | Hybrid fan-out: inboxes for active users, pull for celebrity channels; ranking service; impression logging; feed cache per user | Feed latency/cost with fan-out-on-read |
| **Dedicated recommendation infrastructure** | Two-tower retrieval + ANN, multi-objective ranker, feature store (online/offline), training pipelines, model registry, experimentation platform with guardrail metrics | Data scale and team with ML expertise |
| **Multi-region** | Active/active reads globally; user-homed writes or distributed SQL; regional processing; replicated origins; global traffic management | Global audience, strict availability |
| **Multi-CDN + origin shielding** | CDN selection/steering service using real-time QoE; dedicated shield tier; ISP embedded caches at extreme scale | Egress > PB/month, single-CDN outages unacceptable |
| **Advanced media** | AV1/HEVC for popular content, HDR, chunked parallel encoding, GPU/ASIC encoders, content-aware per-shot encoding, auto-captions/translation, audio language tracks, DRM if premium | Egress cost, premium content |
| **Trust & safety at scale** | ML classifiers across modalities, copyright fingerprinting, coordinated inauthentic behavior detection, transparency reporting, regional legal compliance workflows | Regulatory obligations, abuse scale |
| **Platform engineering** | Internal developer platform, golden paths, policy-as-code, SLO tooling, cost allocation per team | Many teams |

**Guardrail:** none of these are built speculatively. Each needs (a) a measured trigger, (b) an ADR, (c) a migration plan that runs old and new paths in parallel (dual-write/shadow-read) before cutover.

---

## 38. Technical Roadmap

> Durations are indicative for a team of ~6 backend engineers + 1 SRE + 1 QA; phases overlap where dependencies allow.

| Phase | Goal | Dependencies | Architecture decisions | Expected deliverables | Major risks |
|---|---|---|---|---|---|
| **0 — Architecture & requirements** (2–3 wks) | Align on scope, NFRs, key ADRs | Product requirements | ADR-001…012 (monolith, PG, uploads, HLS, queues, transcoding, auth, cloud, env strategy) | This document reviewed; ADRs accepted; module map; data model v1; API style guide; event catalog v1; risk register; capacity model spreadsheet | Analysis paralysis; unclear product scope (esp. moderation obligations) |
| **1 — Core backend foundation** (4–6 wks) | Running skeleton in all envs with auth & basic entities | Phase 0 | Module boundaries & enforcement; outbox; error format; pagination; idempotency middleware; observability baseline; IaC structure | Environments (dev/QA/staging/prod), CI/CD with canary/rollback, Auth (EPIC-01), Users (EPIC-02), Channels (EPIC-03), video metadata CRUD (EPIC-07 part), admin auth & audit skeleton, logging/metrics/tracing, outbox relay + SQS/SNS | Under-investing in foundations (CI, observability) to show features early |
| **2 — Upload pipeline** (3–4 wks) | Reliable direct-to-storage resumable uploads | Phase 1 (auth, videos) | Multipart parameters, presign TTLs, key layout, session state model | EPIC-04: sessions, part signing, resume, complete, abort, sweeper, lifecycle rules, upload metrics; client SDK guidance doc | Mobile network edge cases; CORS/storage config errors; client team coordination |
| **3 — Media processing** (5–7 wks; can start in parallel with Phase 2 using fixtures) | Turn uploads into playable HLS ladders reliably | Phase 2 (VideoUploaded), storage layout | ADR-006 transcoding engine; ladder spec; segment/GOP settings; coordinator/task model; worker isolation | EPIC-05: coordinator, probe/validate, safety scan integration, transcode, package, thumbnails, captions, retries/DLQ, state machine (§12), admin failure view, golden corpus tests | Media edge cases; encode cost/latency surprises; decoder security |
| **4 — Playback** (3–4 wks) | Secure, fast playback via CDN | Phase 3 outputs | Signed cookies vs URLs; TTLs; CDN cache policies; OAC; key rotation | EPIC-06: playback API, authorization policy engine, CDN distributions, signed access, resume position, QoE beacons (ingestion minimal), playback SLO dashboard | Cookie/domain issues on certain devices; CDN misconfiguration leaking private content |
| **5 — Engagement** (5–6 wks) | Social features | Phases 1, 4 | Counter architecture (Redis + flush); comment model & ranking; subscription access paths | EPIC-08 Comments, EPIC-09 Reactions, EPIC-10 Subscriptions, playlists, watch history/continue watching, in-app notifications + transactional email (EPIC-14 MVP part), reports & moderation queue basics (EPIC-15 MVP part) | Spam from day one; counter drift; notification duplicates |
| **6 — Search & discovery** (3–4 wks) | Find content | Phase 5 (popularity signals), outbox | PG FTS design; ranking formula; trending formula v1; feed assembly | EPIC-11 (PG FTS, autocomplete), trending v1, home & subscriptions feeds, related videos (rules) | Relevance quality; feed latency |
| **7 — Analytics** (4–5 wks; ingestion starts in Phase 4) | Trustworthy view counts & creator analytics | Phase 4 telemetry | View validity rules; aggregation pipeline; retention of raw events | EPIC-12: validator, aggregators, video_daily_stats, creator analytics API, platform dashboards, fraud heuristics v1 | Inflated/incorrect counts; PG load from analytics |
| **— MVP launch gate —** | | | | Security review & pen test, load test at 2× MVP peak, DR restore drill, runbooks, on-call rotation, moderation staffing, legal/privacy sign-off | |
| **8 — Recommendations** (post-launch, 6–8 wks) | Personalized discovery | Phase 7 data, experimentation via flags | Co-watch & content similarity; A/B framework | EPIC-13 Stage 2: batch candidates, blending, impression logging, A/B tests | Insufficient data; offline/online metric mismatch |
| **9 — Scaling (Growth stage)** (continuous) | Remove bottlenecks as triggers fire | Metrics from production | OpenSearch, ClickHouse, Kinesis/Kafka, partitioning, Aurora, origin shield, DR region, push notifications, advanced moderation, per-title encoding | Items from §36 each with ADR + migration plan | Migrating live systems without downtime; scope creep from premature scaling |
| **10 — Large scale** (as needed) | Global, multi-team platform | Growth-stage maturity | §37 decisions | Service extraction, sharding, multi-region, multi-CDN, ML recs | Organizational complexity; distributed system failure modes |

Cross-cutting from Phase 1 onward: EPIC-16 Observability, security (threat modelling per epic), testing, cost tracking.

---

## 39. Engineering Epics

> Each epic lists **objective, scope, dependencies, architecture concerns, system-level acceptance criteria**. Story-level breakdown is left to the implementing teams.

### EPIC-00 Platform Foundation (environments, CI/CD, IaC, module skeleton)
- **Objective:** a deployable, observable, secure skeleton.
- **Scope:** cloud accounts & networking, IaC, container platform, CI/CD with canary & rollback, module structure with boundary enforcement, config/secrets, outbox + relay, idempotency middleware, error format, pagination utilities, feature flags.
- **Dependencies:** Phase 0 ADRs.
- **Concerns:** reproducibility, least privilege, build-once-promote.
- **Acceptance:** a commit reaches prod through all envs automatically with tests; rollback in < 5 min; outbox events delivered at-least-once with no loss under relay crash; boundary violations fail CI.

### EPIC-01 Authentication
- **Objective:** secure identity and sessions.
- **Scope:** registration, email verification, login, Google OIDC, access/refresh tokens with rotation & reuse detection, logout/logout-all, password reset, session management, revocation (`min_iat`), staff SSO + MFA, auth rate limits, security event logging.
- **Dependencies:** EPIC-00, email provider.
- **Concerns:** credential stuffing, enumeration, token storage on web, key rotation.
- **Acceptance:** OWASP ASVS L2 auth controls verified; refresh token reuse revokes family; password reset revokes sessions; no account enumeration via any endpoint; login p95 < 300 ms; brute-force attempts throttled per IP & account.

### EPIC-02 User Management
- **Objective:** profiles, preferences, account lifecycle.
- **Scope:** profile CRUD, handles, avatars (image pipeline), preferences, suspension states, deletion saga, data export.
- **Dependencies:** EPIC-01.
- **Concerns:** privacy, propagation of deletion.
- **Acceptance:** deletion completes across all modules within SLA with tracked steps; export contains all user data categories; suspended users can't create content and sessions are revoked within 1 min.

### EPIC-03 Channel Management
- **Objective:** creator publishing identity.
- **Scope:** channel CRUD, unique handles, branding images, channel page (videos, playlists), channel stats.
- **Dependencies:** EPIC-02.
- **Concerns:** impersonation, handle squatting, caching.
- **Acceptance:** handle uniqueness enforced case-insensitively; channel page p95 < 150 ms with cache; counts eventually consistent < 5 min.

### EPIC-04 Upload System
- **Objective:** reliable, secure, resumable uploads direct to storage.
- **Scope:** sessions, multipart presigning, resume, complete, abort, expiry sweeper, lifecycle rules, quotas/limits, idempotency, reconciliation from storage events, metrics.
- **Dependencies:** EPIC-00, EPIC-07 (draft videos).
- **Concerns:** no bytes through API; URL scoping; races on completion.
- **Acceptance:** a 10 GB upload survives network drops and app restarts and completes; concurrent completes yield exactly one `VideoUploaded`; abandoned uploads cleaned within 24 h; presigned URLs cannot write outside their key; upload success rate ≥ 99.9% excluding client abandonment.

### EPIC-05 Media Processing
- **Objective:** convert sources to HLS ladders, thumbnails, captions reliably and safely.
- **Scope:** coordinator, task queues, worker pools, probe/validate, safety scans, ladder selection, transcode, package (CMAF HLS), thumbnails, captions conversion, retries/escalation, DLQ, reprocessing with versions, admin visibility, encoder canarying.
- **Dependencies:** EPIC-04; ADR-006.
- **Concerns:** untrusted input sandboxing, cost, burst capacity, idempotent outputs.
- **Acceptance:** golden corpus passes (all valid samples playable, invalid ones fail with correct codes); never upscales; 95% of ≤ 30-min videos first-playable in ≤ 15 min at 2× MVP upload load; worker kill mid-task leads to successful completion via retry; no duplicate variants; DLQ alerts fire.

### EPIC-06 Playback
- **Objective:** authorized, fast, CDN-delivered streaming.
- **Scope:** playback API, policy evaluation, signed cookies/URLs, key rotation, CDN distributions & cache policies, origin protection, resume position, QoE beacon contract.
- **Dependencies:** EPIC-05, EPIC-07.
- **Concerns:** no private content leakage, cache-key correctness.
- **Acceptance:** authorization matrix tests pass for every visibility × role × moderation state; direct origin access denied; segments cached with path-only keys (hit ratio ≥ 90% in load test); playback auth p95 < 150 ms; unpublish revokes access within token TTL.

### EPIC-07 Video Metadata & Lifecycle
- **Objective:** authoritative video records and state machine.
- **Scope:** drafts, metadata, tags/categories, visibility, state machine, publish/unpublish, delete/purge, thumbnails selection, subtitles metadata, caching & invalidation, events.
- **Dependencies:** EPIC-03.
- **Concerns:** illegal transitions, cache invalidation, consistency.
- **Acceptance:** property tests show no illegal transitions; every transition emits an event atomically; metadata changes visible to owner immediately and to public within cache TTL; deleted videos inaccessible everywhere immediately (auth) and from search within 60 s.

### EPIC-08 Comments
- **Objective:** scalable, moderated discussion.
- **Scope:** comments/replies, top/newest ranking, cursor pagination, pin/heart, edit/delete, creator controls (blocked words, hold for review), spam heuristics, counters, notifications hooks.
- **Dependencies:** EPIC-07, EPIC-15 basics.
- **Concerns:** hot videos, ranking stability, spam.
- **Acceptance:** first page p95 < 150 ms on a video with 1M comments (load test); pagination has no duplicates/skips; idempotent posting; spam heuristics block duplicate-text floods.

### EPIC-09 Reactions
- **Objective:** likes/dislikes with scalable counters.
- **Scope:** video & comment reactions, Redis counters, flush, reconciliation, liked-videos list.
- **Dependencies:** EPIC-07.
- **Concerns:** hot-key counters, drift.
- **Acceptance:** 10k reactions/s on one video sustained without DB contention; counts converge to exact value within 5 min after load stops; user's own reaction state always consistent.

### EPIC-10 Subscriptions
- **Objective:** follow channels; feed & notification inputs.
- **Scope:** subscribe/unsubscribe, notification level, counts, lists, subscriptions feed (fan-out-on-read), integration with notifications fan-out.
- **Dependencies:** EPIC-03.
- **Concerns:** large channel fan-out.
- **Acceptance:** subscriptions feed p95 < 300 ms for users with 500 subscriptions; new-upload notification fan-out to 1M subscribers completes < 15 min without API impact.

### EPIC-11 Search
- **Objective:** relevant, fast discovery.
- **Scope:** MVP PG FTS + trigram autocomplete, indexing via events, serve-time authorization filtering, ranking v1; GROWTH OpenSearch migration with reindex/alias, reconciliation.
- **Dependencies:** EPIC-07, EPIC-12 (popularity).
- **Concerns:** index consistency, privacy leaks via search.
- **Acceptance:** p95 < 300 ms; private/blocked videos never returned (tested); takedowns removed < 60 s; full reindex possible with zero downtime (growth).

### EPIC-12 Analytics
- **Objective:** trustworthy counts and insights.
- **Scope:** event schema, ingestion, view validator, aggregators, raw storage, creator analytics API, platform dashboards, trending input, fraud heuristics; GROWTH ClickHouse/lake.
- **Dependencies:** EPIC-06.
- **Concerns:** correctness, abuse, isolation from OLTP.
- **Acceptance:** replayed synthetic sessions produce exactly expected view counts (dedupe, thresholds, bots excluded); counts lag < 5 min; analytics outage doesn't affect playback/API; raw events replayable to rebuild aggregates.

### EPIC-13 Recommendations & Feeds
- **Objective:** personalized discovery that increases satisfied watch time.
- **Scope:** Stage 1 rules (related, home blending), trending, home feed assembly with fallbacks; Stage 2 co-watch/content similarity batch; impression logging; A/B framework; Stage 3 at scale.
- **Dependencies:** EPIC-12, EPIC-10, EPIC-11.
- **Concerns:** latency, cold start, filter correctness, feedback loops.
- **Acceptance:** home feed p95 < 300 ms; recs failure degrades to trending without errors; experiments measurable with guardrail metrics; no blocked/limited content recommended.

### EPIC-14 Notifications
- **Objective:** timely, non-duplicated, preference-respecting notifications.
- **Scope:** in-app inbox, unread counts, aggregation, preferences, transactional email, push (growth), fan-out, retries, suppression lists, delivery tracking.
- **Dependencies:** EPIC-08, EPIC-10.
- **Concerns:** duplicates, fan-out spikes, provider failures.
- **Acceptance:** duplicate event deliveries never cause duplicate notifications; preferences honored 100%; provider outage results in delayed delivery within TTL and no loss of in-app records.

### EPIC-15 Moderation & Trust/Safety (+ Admin)
- **Objective:** keep the platform safe and compliant.
- **Scope:** reports, case routing, queues, actions, strikes, suspensions, appeals, hash-matching integration, blocklists, admin console (search, actions, processing failures, DLQ, audit), legal holds, kill switches; GROWTH classifiers.
- **Dependencies:** EPIC-07, EPIC-08, EPIC-02.
- **Concerns:** reviewer safety, auditability, speed of takedown.
- **Acceptance:** hash-matched content never becomes publicly playable; takedown effective on playback/search/feeds within 60 s and CDN purge within 15 min; every admin/moderator action audited immutably; appeals routed to a different reviewer.

### EPIC-16 Observability & Reliability
- **Objective:** see and control system health.
- **Scope:** logging standards, metrics, tracing across async hops, dashboards (§26.4), SLOs & burn-rate alerts, runbooks, on-call, synthetic probes, DR drills, load/chaos testing harness, cost dashboards.
- **Dependencies:** EPIC-00.
- **Concerns:** cardinality/cost, alert fatigue.
- **Acceptance:** every request and job traceable end-to-end by ID; all SLOs measured with dashboards and alerts; every page-level alert has a runbook; quarterly restore drill meets RTO.

### EPIC-17 Security & Compliance (cross-cutting)
- **Objective:** defense in depth and privacy compliance.
- **Scope:** threat models per epic, WAF/rate limits, secrets, encryption, scanning in CI, pen test, privacy (export/deletion/retention), audit logging, cookie consent, child-safety workflows.
- **Dependencies:** all.
- **Acceptance:** pen test with no high/critical findings open at launch; data inventory and retention enforced automatically; deletion/export SLAs met.

---

## 40. Risks

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | **High CDN cost** outpaces revenue | High | High | Cache-hit optimization, versioned paths, viewport-capped bitrates, per-title encoding, AV1/HEVC for popular content, commit discounts, multi-CDN at scale, cost-per-watch-hour tracking & alerts |
| R2 | **Transcoding bottleneck** (burst uploads, long videos) | Medium | High | Queue-depth autoscaling, spot + on-demand base, first-rendition priority, chunked encoding, managed transcoder overflow, upload quotas by trust tier |
| R3 | **Queue backlog** (processing, notifications, indexing) | Medium | Medium–High | Age-of-oldest-message alerts, autoscaling consumers, priority queues, load shedding of non-critical work, DLQs |
| R4 | **Hot videos** (viral spikes) overloading metadata/comment/counter paths | High | High | CDN for media, L1 caches, hot-key replication, sharded counters, precomputed comment pages, slow mode, spike load tests |
| R5 | **Hot Redis keys / Redis saturation** | Medium | Medium | Local caches, key replication, workload-separated clusters, monitoring hot keys |
| R6 | **Database growth** (comments, history, reactions, events) | High (over time) | High | Telemetry out of OLTP early, partitioning plan, module DB splits, sharding roadmap, retention policies |
| R7 | **Spam** (comments, uploads, fake accounts) | High | Medium | Rate limits, reputation tiers, heuristics → classifiers, CAPTCHA/risk checks, creator tools |
| R8 | **Bot traffic / view fraud** | High | Medium–High | View validation rules, bot detection at edge, fraud pipeline with retroactive corrections, trending integrity penalties |
| R9 | **Video abuse** (illegal content, malware in files) | Medium | **Critical** (legal/brand) | Mandatory hash-matching pre-publication, malware scanning, sandboxed decoders, rapid takedown tooling, legal reporting workflow, staffing |
| R10 | **Content moderation complexity** (volume, languages, policy disputes) | High | High | Prioritized queues, automation for clear cases, appeals, policy versioning, vendor partners, transparency |
| R11 | **Inconsistent event processing** (lost events, partial side effects) | Medium | High | Transactional outbox, idempotent consumers, reconciliation jobs, DLQ monitoring, contract tests |
| R12 | **Duplicate events** → double counts/notifications | High | Medium | Dedupe tables, dedupe keys, natural idempotency, tests with injected duplicates |
| R13 | **Search index inconsistency** (stale or leaked private content) | Medium | High (privacy) | Re-read on event, external versioning, priority lane for takedowns, serve-time authorization filter, reconciliation, zero-downtime reindex |
| R14 | **Private content leakage** via CDN/thumbnail/search/caches | Low–Medium | **Critical** | Signed access everywhere, path-only cache keys verified, authorization test matrix, security review of CDN config |
| R15 | **Account takeover / credential stuffing** | High | High | Rate limits, breached password checks, MFA, refresh rotation, anomaly alerts |
| R16 | **Media edge cases** break pipeline (odd codecs, VFR, rotation, HDR) | High | Medium | Golden corpus, normalization step, clear failure codes, fallback to managed transcoder |
| R17 | **Vendor lock-in** (managed transcoding, CDN, DB) | Medium | Medium | Encoder abstraction, standard formats (CMAF/HLS), PG compatibility, CDN-agnostic URLs |
| R18 | **Premature complexity** (microservices/Kafka too early) slows delivery | Medium | High | Trigger-based adoption, ADRs, modular monolith |
| R19 | **Regulatory/compliance gaps** (GDPR, DSA, child safety, takedowns) | Medium | High | Privacy-by-design, deletion saga, legal workflows, counsel review before launch |
| R20 | **Single-region outage** at MVP | Low | High | Multi-AZ; cross-region backups; documented RTO; DR region at growth |
| R21 | **Observability cost explosion** | Medium | Medium | Sampling, retention tiers, cardinality limits |
| R22 | **Key personnel / media expertise gap** | Medium | High | Managed transcoding option, documentation, pairing, external expertise |

---

## 41. Architecture Decisions (ADRs)

| ADR | Decision | Alternatives | Tradeoffs | Recommended |
|---|---|---|---|---|
| **ADR-001** Service architecture | Modular monolith + separate media workers + ingestion scaling group | Microservices; SOA | Monolith: fast, simple, one DB txn scope, risk of boundary erosion (mitigated by tooling). Microservices: autonomy & independent scaling but heavy ops and distributed failure modes | **Modular monolith**, extract by trigger |
| **ADR-002** Transactional database | PostgreSQL (RDS → Aurora PG) | MySQL; DynamoDB; distributed SQL | PG: rich features, FTS for MVP, partitioning; single writer limits addressed later. DynamoDB: scale but rigid queries early. Distributed SQL: global but costly/complex | **PostgreSQL** |
| **ADR-003** Upload path | Direct-to-object-storage presigned multipart | Proxy through API; tus server | Direct: no API bandwidth, scales infinitely, more client logic. Proxy: simple client, but expensive and fragile. tus: standard protocol but bytes through our servers | **Direct presigned multipart** |
| **ADR-004** Streaming format | HLS with CMAF fMP4 segments; DASH optional from same segments | HLS-TS only; DASH only; both with separate segments | CMAF HLS: universal (iOS required), future codecs, DASH for free. TS: legacy only | **CMAF HLS primary** |
| **ADR-005** Messaging | SQS/SNS at MVP; Kinesis/Kafka for telemetry at growth; Kafka backbone at scale | Kafka from day one; RabbitMQ | SQS: zero ops, DLQ/visibility built-in, no replay. Kafka: replay & streaming but ops-heavy. RabbitMQ: no compelling advantage | **SQS → Kafka by trigger** |
| **ADR-006** Transcoding engine | FFmpeg workers on Spot behind encoder abstraction; managed (MediaConvert) as MVP option/overflow | Managed only; FFmpeg only | Managed: fast to ship, higher unit cost, less control. FFmpeg: cheapest at scale, needs expertise & sandboxing | **Managed or FFmpeg at MVP depending on team expertise; FFmpeg fleet at growth** |
| **ADR-007** Search engine introduction | PG FTS at MVP; OpenSearch at growth | OpenSearch from day one; Algolia/managed SaaS search; Typesense/Meilisearch | Early OpenSearch: better relevance but ops and sync complexity. SaaS: fast, costly at scale, data residency. PG FTS: zero extra infra, limited relevance | **PG FTS → OpenSearch by trigger** |
| **ADR-008** Analytics store | PG rollups at MVP; ClickHouse + S3 lake at growth | Redshift/BigQuery/Snowflake; Druid/Pinot; Elasticsearch | ClickHouse: fastest/cheapest for event aggregation, serving creator analytics with low latency; warehouses better for ad-hoc BI (use both: lake + warehouse for BI). Druid/Pinot: comparable, more ops | **ClickHouse (serving) + lake/warehouse (BI)** |
| **ADR-009** Identity | In-house auth module on vetted libraries (or managed IdP if no security capacity) | Cognito/Auth0/Firebase; Keycloak | Managed: features fast, per-MAU cost, UX limits. In-house: control & cost, security responsibility | **In-house with standards**, revisit if enterprise SSO/compliance needs grow |
| **ADR-010** Playback access control | Signed cookies (+ signed URLs for single objects; edge tokenization for cookie-less devices) | Unsigned public media; per-segment signed URLs; DRM | Cookies: cacheable static manifests. Per-segment URLs: manifest rewriting. DRM: strong protection, cost/complexity — only for premium | **Signed cookies** |
| **ADR-011** API style | REST + OpenAPI, composite screen endpoints | GraphQL; gRPC-web | REST: caching, simplicity; GraphQL: flexible clients but caching/security complexity | **REST**, optional BFF/GraphQL later |
| **ADR-012** Region strategy | Single region multi-AZ at MVP; active/passive at growth; selective active/active at scale | Multi-region from day one | Cost & complexity vs RTO for region loss | **Single region MVP** with documented RTO |
| **ADR-013** ID strategy | UUIDv7 PKs + opaque public IDs | Bigint sequences; Snowflake service | Sequences leak counts & hinder sharding; Snowflake needs coordination; UUIDv7 sortable & coordination-free | **UUIDv7 + public_id** |
| **ADR-014** Counter strategy | Event-driven aggregation (Redis + periodic flush), eventual consistency | Direct DB increments; CRDT counters | Direct increments: contention; aggregation: lag but scalable | **Aggregation** |
| **ADR-015** Comment threading | Single table, one reply level | Unlimited nesting; separate replies table | Unlimited nesting: complex pagination/ranking; one level: simpler, matches mainstream UX | **One level** |
| **ADR-016** Feed fan-out | Fan-out-on-read MVP; hybrid at scale | Fan-out-on-write | Push: write amplification; pull: read cost | **Pull → Hybrid** |
| **ADR-017** Container orchestration | ECS (Fargate for API, EC2 Spot capacity for media) | EKS; Lambda; VMs | ECS: low ops; EKS: ecosystem/portability, more ops; Lambda: unsuitable for long encodes and PG connection patterns | **ECS**, EKS at scale if needed |
| **ADR-018** Public dislike counts | Store dislikes; hide public count | Show counts; no dislikes | Hiding reduces brigading; keeps signal for recs & creators | **Hide publicly** (product sign-off) |
| **ADR-019** Original retention | Retain originals, tiered to archive | Delete after processing | Cost vs future re-encode ability | **Retain + tier** |
| **ADR-020** Workflow orchestration for processing | Hand-rolled coordinator at MVP; Step Functions/Temporal at growth | Workflow engine from day one | Simplicity vs durability features | **Coordinator → engine by trigger** |

Each ADR should be recorded in the repository (`/docs/adr/NNN-title.md`) with context, decision, status, consequences, and a revisit trigger.

---

## 42. Final Architecture Diagram

Legend: `══►` synchronous request path · `- ->` asynchronous (queue/event/stream) · `[G]` growth · `[S]` scale

```
┌───────────────────────────────────────────────────────────── CLIENTS ──────────────────────────────────────────────────────────────┐
│        Web · iOS · Android · Smart TV · Embedded player · Admin console (staff, SSO+MFA)                                            │
└───────┬───────────────────────────────┬───────────────────────────────────────┬──────────────────────────────┬────────────────────┘
        │ API (HTTPS)                   │ upload bytes (presigned PUT)          │ playback (manifests/segs)    │ telemetry beacons
        ▼                               │                                       ▼                              ▼
┌──────────────────┐                    │                     ┌────────────────────────────────┐   ┌─────────────────────────┐
│ DNS (Route 53)   │                    │                     │ CDN EDGE (CloudFront)          │   │ events.example.com      │
└────────┬─────────┘                    │                     │ verify signed cookie/URL       │   │ (CDN/WAF → ALB)         │
         ▼                              │                     │ path-only cache keys           │   └───────────┬─────────────┘
┌──────────────────────────────┐        │                     │ segments 1y · master 1–5 min   │               ║
│ CDN (api) + WAF + Shield     │        │                     │        │ miss                  │               ▼
│ rate limits · bot mgmt       │        │                     │  Origin Shield [G] ─ Multi-CDN [S]│  ┌──────────────────────────┐
└────────────┬─────────────────┘        │                     └────────┼───────────────────────┘   │ INGESTION (scale group)  │
             ║                          │                              │ OAC (read-only)           │ validate · enrich · 202  │
             ▼                          │                              │                           └───────────┬──────────────┘
┌─────────────────────────────┐         │                              │                                       │
│ ALB (API Gateway optional)  │         │                              │                                       - ->
└────────────┬────────────────┘         │                              │                                       ▼
             ║                          │                              │                       ┌────────────────────────────────┐
             ▼                          │                              │                       │ TELEMETRY STREAM               │
┌──────────────────────────────────────────────────────────┐          │                       │ SQS+Firehose → Kinesis [G]     │
│ CORE API — MODULAR MONOLITH (ECS, autoscaled, multi-AZ)  │          │                       │ → Kafka [S]                    │
│ Auth · Users · Channels · Videos(state machine) ·        │          │                       └──────┬────────────────┬────────┘
│ Uploads(sessions/presign) · Playback(authorize+sign) ·   │          │                              - ->              - ->
│ Comments · Reactions · Subscriptions · Playlists ·       │          │                              ▼                 ▼
│ History · Search API · Feed · Notifications API ·        │          │            ┌──────────────────────────┐ ┌───────────────────────┐
│ Moderation · Admin (/admin, audited)                     │          │            │ VIEW VALIDATOR &         │ │ RAW EVENT LAKE (S3)   │
│ policy layer · rate limiter · idempotency · outbox       │          │            │ AGGREGATORS              │ │ Parquet/Iceberg [G]   │
└───┬──────────────┬──────────────┬──────────────┬─────────┘          │            │ views · watch time ·     │ └──────────┬────────────┘
    ║              ║              ║              ║                    │            │ counters · history flush │            - ->
    ▼              ▼              ▼              ▼                    │            └────┬──────────────┬──────┘            ▼
┌──────────┐ ┌───────────┐ ┌─────────────┐ ┌─────────────────┐        │                 - ->           - ->     ┌──────────────────────┐
│PostgreSQL│ │  Redis    │ │ Search      │ │ Object storage  │◄───────┘(upload bytes)   ▼              ▼        │ ANALYTICS STORE      │
│ primary +│ │ cache ·   │ │ PG FTS (MVP)│ │ uploads/ (priv) │                ┌──────────────┐ ┌───────────┐  │ PG rollups (MVP) →   │
│ replicas │ │ counters ·│ │ OpenSearch  │ │ media/  (CDN    │                │ Redis        │ │ PostgreSQL│  │ ClickHouse [G]       │
│ (source  │ │ sessions ·│ │ [G]         │ │   origin)       │                │ live counters│ │ video_stats│  │ creator analytics ·  │
│ of truth)│ │ rate lim. │ └──────▲──────┘ │ images/ archive/│                └──────────────┘ └───────────┘  │ trending · BI        │
└────┬─────┘ └───────────┘        │        └───┬────────▲────┘                                                └──────────┬───────────┘
     │ outbox (same txn)          │            │        │ write renditions,                                                - ->
     - ->                         │            │ObjectCreated  manifests, thumbs                                           ▼
     ▼                            │            - ->     │                                            ┌──────────────────────────────────┐
┌────────────────────────────────────────────────────────────────┐                                  │ RECOMMENDATION SYSTEM            │
│ EVENT BUS:  SNS/EventBridge → per-consumer SQS (+DLQs)  →  Kafka [S]                              │ rules (MVP) → co-watch batch [G] │
│ VideoUploaded · VideoPublished · VideoProcessing* · Reaction* · Comment* · Subscribed · Deleted…  │ → two-tower + ANN + ranker [S]   │
└──┬─────────────────┬─────────────────┬──────────────────┬─────────────────┬───────────────────────┘ offline training ⇄ online serving│
   - ->              - ->              - ->               - ->              - ->                      └───────────────┬──────────────────┘
   ▼                 ▼                 ▼                  ▼                 ▼                                         ║ candidates
┌────────────────┐ ┌───────────────┐ ┌─────────────────┐ ┌───────────────┐ ┌──────────────────────┐                    ▼
│ MEDIA          │ │ SEARCH        │ │ NOTIFICATIONS   │ │ MODERATION    │ │ FEED / TRENDING JOBS │──► Redis lists ──► Core API (Feed)
│ COORDINATOR    │ │ INDEXER       │ │ router · fan-out│ │ scans · cases │ │ trending every 5–15m │
│ job/task state │ │ re-read PG,   │ │ in-app (PG) ·   │ │ hash-match ·  │ │ feed caches          │
└──────┬─────────┘ │ versioned     │ │ email (SES) ·   │ │ classifiers[G]│ └──────────────────────┘
       - ->        │ upserts ──────┼►│ push [G]        │ └───────────────┘
       ▼           └───────────────┘ │ retries · DLQ   │
┌──────────────────────────────────┐ └─────────────────┘
│ TASK QUEUES (priority lanes)     │
│ probe · transcode · package ·    │
│ thumbnails · captions · scan     │
└──────┬───────────────────────────┘
       - ->
       ▼
┌──────────────────────────────────────────────────────┐
│ MEDIA WORKERS (ECS on EC2 Spot, sandboxed)           │
│ FFmpeg: H.264 ladder 360p–1080p (MVP) · CMAF HLS ·   │
│ thumbnails · sprites · VTT  | MediaConvert (option)  │──► writes to media/ ──► served via CDN (above)
│ HEVC/AV1/4K for popular [S] · chunked encoding [S]   │──► reports (event/callback) ──► Core API Video state machine
└──────────────────────────────────────────────────────┘

Cross-cutting: Secrets Manager · KMS · IAM least privilege · CloudWatch/OpenTelemetry (logs, metrics, traces) · CloudTrail · Backups/PITR · IaC
```

**Synchronous paths:** client → CDN/WAF → ALB → core API → PostgreSQL / Redis / search; client → CDN → object storage (playback); client → object storage (upload parts).
**Asynchronous paths:** outbox → event bus → media coordinator → task queues → workers; event bus → indexer, notifications, moderation, feed/trending; telemetry → stream → validators/aggregators → Redis/PG/analytics store → recommendations.

---

*End of document. Next steps: review in architecture forum → accept ADR-001…020 (or amend) → produce epic-level estimates → begin Phase 1.*
