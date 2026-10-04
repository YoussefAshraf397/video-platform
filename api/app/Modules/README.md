# Modules

Each business area is a module under `app/Modules/<Name>` ([ADR-001](../../../docs/adr/ADR-001-modular-monolith.md)).

## Layout

```
app/Modules/<Name>/
├── Contracts/        PUBLIC  interfaces and DTOs other modules may depend on
├── Events/           PUBLIC  domain events other modules may listen to
├── Http/             internal  controllers, requests, resources
├── Models/           internal  Eloquent models (only this module touches its tables)
├── Providers/        internal  <Name>ServiceProvider: routes, bindings, listeners
└── routes.php        internal  loaded by the provider
```

Add other internal folders (`Services/`, `Jobs/`, `Policies/` …) as needed. Only `Contracts` and `Events` are public.

## Rules

1. **Other modules use you only through `Contracts` and `Events`.** `tests/Architecture/ModuleBoundariesTest.php` enforces this automatically for every folder under `app/Modules`. Code outside `app/Modules` follows the same rule.
2. **A module owns its tables.** Never query another module's tables, whether through Eloquent, `DB::table()` or a join. Ask through its Contract instead, or keep a local read model updated from its Events. The architecture test can't see raw SQL, so code review has to catch this.
3. **Register the provider** in `bootstrap/providers.php`.
4. **Business routes** go in the module's `routes.php`, wrapped in the `api` middleware group with a `/v1` prefix. The `Health` routes are deliberately registered without middleware.
