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

Requirements: Docker with Compose v2, and `make`.

```bash
make up      # PostgreSQL, Redis, S3/SQS/SNS emulator, Mailpit
make check   # verify everything is reachable
```

Ports, credentials and AWS resources are listed in [docker/README.md](docker/README.md). To set up the Laravel app see [api/README.md](api/README.md), then run `make test`. The Go worker arrives with S1-10.

## CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) runs on every PR and on pushes to `main`. All jobs run in parallel (target < 10 min):

| Job | Checks |
|---|---|
| PHP lint, static analysis, audit | Pint, PHPStan (Larastan, level 8), `composer audit`, `openapi.json` up to date |
| PHP tests | `make up` + `make test-api`: unit, feature, architecture and contract tests on the real local stack |
| Go (per module) | golangci-lint ([`.golangci.yml`](.golangci.yml)), `go test -race`, govulncheck |
| Secret and dependency scan | gitleaks (full git history), Trivy (lockfiles + Dockerfile misconfiguration) |
| API image | Docker build, Trivy image scan, container smoke test (liveness, non-root) |
| **CI passed** | Succeeds only if every job above succeeded. **This is the one required check.** |

Scanners fail on HIGH/CRITICAL issues that have a fix available. Dependabot ([`.github/dependabot.yml`](.github/dependabot.yml)) opens weekly update PRs for Composer, Go modules, the base image and the Actions themselves.

**Branch protection for `main`** (GitHub → Settings → Branches), set up once the repo is on GitHub:
- Require a pull request with 1 approval and Code Owner review.
- Require the status check **`CI passed`**, with branches up to date before merging.
- Block force pushes and deletions.
