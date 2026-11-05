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
| AWS emulator (S3, SQS, SNS) | `floci/floci:2.4.0` | `http://localhost:4566` (web console: `/_floci/ui`) | `http://aws:4566` | key `test` / secret `test`, region `us-east-1` |
| Mailpit (SMTP + web inbox) | `axllent/mailpit:v1.27` | SMTP `localhost:1025`, UI http://localhost:8025 | `mailpit:1025` | none |

All credentials are local-only and never valid anywhere else.

## AWS resources (created by `docker/aws/init.sh`)

| Kind | Name | Used by |
|---|---|---|
| S3 bucket | `uploads` (CORS exposes `ETag`, sends `ObjectCreated` to `s3-upload-events`) | Upload module (S3-03) |
| S3 bucket | `media` (CORS: `localhost:8000` and `localhost:3000` with credentials, for players sending the signed cookies) | Go worker outputs (HLS, thumbnails), played by the test player (S4-07) |
| S3 bucket | `images` | Images |
| SQS queue + DLQ | `media-process` (subscribed to `media-commands`), `media-results` | Laravel ↔ Go worker |
| SQS queue + DLQ | `s3-upload-events` (S3 `ObjectCreated` for `uploads/*/source`, delivery delayed 90 s) | Upload reconciliation (S3-05) |
| SQS queue + DLQ | `media-dispatcher` (subscribed to `video-events`, filtered to `VideoUploaded`) | Starts processing (S3-06) |
| SNS topic | `video-events` | Domain events from the outbox relay (S1-06) |
| SNS topic | `media-commands` | Commands to the media worker, through the outbox (S3-06) |

Every queue has a `<name>-dlq` with `maxReceiveCount` 5. When you add a queue or topic, add it to `init.sh`, `check.sh` and `infra/`.

## Things to know

- **The AWS emulator keeps state in memory.** If the `aws` container restarts on its own, buckets and queues disappear, and `make check` will show failures. Run `make aws-init` to recreate them (bucket contents are lost).
- **Port clashes:** override host ports in `docker/.env` (gitignored): `PG_PORT`, `REDIS_PORT`, `AWS_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`. Postgres defaults to 5433 so it doesn't clash with a locally installed Postgres.
- **Presigned upload URLs** must use the host address (`http://localhost:4566`) so the browser can reach them. Server-side calls from containers use `http://aws:4566`. The Upload module needs separate "internal" and "public" endpoint settings (S3-03).
- **Why Floci:** current LocalStack images refuse to start without a LocalStack account auth token. Floci is open source (MIT), needs no account, and is closer to AWS than Moto (which we used before) on what we use: e.g. it sends S3's `s3:TestEvent` when notifications are configured, like AWS. It runs with `FLOCI_DEFAULT_ACCOUNT_ID=123456789012`, the account id in `SNS_TOPIC_ARN_PREFIX` (its default is `000000000000`).
- **Where Floci differs from AWS** (checked with 2.4.0):
  - S3 event notifications ignore the target queue's `DelaySeconds`: `s3-upload-events` gets upload events at once instead of after 90 s, so reconciliation can run while the client's own `:complete` is still in flight. Harmless (completion is idempotent; the loser gets `UPLOAD_COMPLETING` and retries), but it's a race the delay prevents in AWS. Direct sends and SNS deliveries do honour the delay.
  - `DeleteTopic` on a topic that doesn't exist fails with `NotFound`; AWS succeeds.
- The Terraform emulator test (`make tf-emulator-test`) still uses its own throwaway Moto container.
