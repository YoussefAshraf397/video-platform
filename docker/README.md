# docker — local development stack

```bash
make up      # start everything and create AWS resources (~1 min first time, mostly image pulls)
make check   # verify every service and resource is reachable
make down    # stop (keeps Postgres data)
make reset   # stop and delete all local data
make help    # all targets
```

## Services

| Service | Image | From your machine | From other containers | Credentials |
|---|---|---|---|---|
| PostgreSQL 17 | `postgres:17-alpine` | `localhost:5433` | `postgres:5432` | db `videoplatform` (tests: `videoplatform_test`), user/pass `videoplatform` |
| Redis 7.4 | `redis:7.4-alpine` | `localhost:6379` | `redis:6379` | none |
| AWS emulator (S3, SQS, SNS) | `motoserver/moto:5.2.3` | `http://localhost:4566` | `http://aws:5000` | key `test` / secret `test`, region `us-east-1` |
| Mailpit (SMTP + web inbox) | `axllent/mailpit:v1.27` | SMTP `localhost:1025`, UI http://localhost:8025 | `mailpit:1025` | none |

All credentials are local-only and never valid anywhere else.

## AWS resources (created by `docker/aws/init.sh`)

| Kind | Name | Used by |
|---|---|---|
| S3 bucket | `uploads` (CORS exposes `ETag`, sends `ObjectCreated` to `s3-upload-events`) | Upload module (S3-03) |
| S3 bucket | `media`, `images` | Go worker outputs, thumbnails |
| SQS queue + DLQ | `media-process`, `media-results` | Laravel ↔ Go worker |
| SQS queue + DLQ | `s3-upload-events` | Upload reconciliation (S3-05) |
| SQS queue + DLQ | `media-dispatcher` (subscribed to `video-events`) | Starts processing (S3-06) |
| SNS topic | `video-events` | Outbox relay (S1-06) |

Every queue has a `<name>-dlq` with `maxReceiveCount` 5. When you add a queue or topic, add it to `init.sh`, `check.sh` and `infra/`.

## Things to know

- **The AWS emulator keeps state in memory.** If the `aws` container restarts on its own, buckets and queues disappear, and `make check` will show failures. Run `make aws-init` to recreate them (bucket contents are lost).
- **Port clashes:** override host ports in `docker/.env` (gitignored): `PG_PORT`, `REDIS_PORT`, `AWS_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`. Postgres defaults to 5433 so it doesn't clash with a locally installed Postgres.
- **Presigned upload URLs** must use the host address (`http://localhost:4566`) so the browser can reach them. Server-side calls from containers use `http://aws:5000`. The Upload module needs separate "internal" and "public" endpoint settings (S3-03).
- **Why Moto and not LocalStack:** current LocalStack images refuse to start without a LocalStack account auth token. Moto is open source (Apache-2.0) and needs no account.
