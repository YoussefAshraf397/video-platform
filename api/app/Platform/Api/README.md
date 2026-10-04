# API conventions

Shared building blocks every module uses (design doc §22). They are demonstrated end to end by the test-only [SampleController](../../../tests/Fixtures/SampleController.php).

| Convention | How to use it |
|---|---|
| **Errors (RFC 9457)** | Throw `new ApiProblem(status, 'STABLE_CODE', 'Title', 'optional detail')`. Validation, 401/403/404, 429 and unexpected errors are converted automatically by `ProblemRenderer`. Responses are `application/problem+json` with `type, title, status, detail?, code, request_id, errors?`. Internal exception messages never reach clients. |
| **Request IDs** | Automatic, on every request. The ID is in the `X-Request-Id` response header, in every log line (`Context`), in problem bodies, and in queued jobs. A well-formed incoming `X-Request-Id` is kept. |
| **Cursor pagination** | `CursorPage::response(CursorPage::paginate($query->orderBy(...)->orderBy('id'), $request), YourResource::class)` returns `{items, next_cursor, has_more}` from `?limit` (1-100, default 20) and `?cursor`. The ORDER BY must end with a unique column and be index-backed. |
| **Idempotency keys** | Add `->middleware('idempotent')` to POST routes that create things or have side effects. A client retry with the same `Idempotency-Key` gets the stored 2xx response for 24 h. A different request with the same key gets 422, and a request still in flight gets 409. |
| **Caller id** | `CallerId::from($request)` returns the signed-in user's id (`401` if there is none). Use it instead of `$request->user()`, so modules don't depend on the Auth module's user class. |
| **OpenAPI** | Generated from code by Scramble for routes under `/v1`. Export with `make openapi` (writes `api/openapi.json`, committed so PRs show API diffs). In local env the UI is at `/docs/api`. |

## Limits worth knowing

- Cursors are base64 position markers and are not signed. A tampered cursor can only move the client's own position in a list it can already read. Don't put filters or permissions in a cursor.
- Idempotency records live in the default cache store (Redis). If that store is flushed, the records are lost; at growth stage they move to a non-evicting Redis cluster (design doc §21.8).
- The in-flight lock lasts 60 s. Endpoints that can run longer must not use `idempotent` without raising it.
