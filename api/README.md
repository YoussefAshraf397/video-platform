# api — Laravel modular monolith

Laravel 13 on PHP 8.4, served by **Octane (FrankenPHP)** in production. Modules live under [`app/Modules`](app/Modules/README.md) ([ADR-001](../docs/adr/ADR-001-modular-monolith.md)).

## Requirements

PHP 8.4 with `pdo_pgsql`, plus Composer and the local stack running (`make up` from the repo root).

## First run

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan octane:start          # http://localhost:8000 (first run downloads the FrankenPHP binary)
```

`php artisan serve` also works for quick checks. Use Octane for anything that touches request lifecycle or state, because workers are long-lived and static state persists between requests.

## Tests

```bash
make test        # from the repo root; needs the local stack (tests use the videoplatform_test database)
```

Test suites:
- `Unit`
- `Feature` (HTTP + database)
- `Architecture` (module boundaries)

## Health endpoints

| Endpoint | Meaning | Checks |
|---|---|---|
| `GET /health/live` | Process is up (container liveness) | none |
| `GET /health/ready` | Instance can serve traffic (load-balancer readiness): 200 or 503 with per-check status | PostgreSQL, Redis |

## Notes

- Redis uses the `predis` client because it needs no PHP extension. The production image may switch to `phpredis` for performance; this is decided in S2-01.
- Database connection attempts time out after `DB_CONNECT_TIMEOUT` seconds (default 3), so an outage can't hang Octane workers.
- One artifact is deployed as four ECS services: `api`, `worker`, `scheduler` and `outbox-relay`.
