# api — Laravel modular monolith

The Laravel application is bootstrapped by sprint 1 ticket **S1-04**: modules under `app/Modules/*`, architecture tests for module boundaries, Octane, and health endpoints.

One artifact is deployed as four ECS services: `api`, `worker`, `scheduler` and `outbox-relay` ([ADR-001](../docs/adr/ADR-001-modular-monolith.md)).
