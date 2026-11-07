# LFMS Core API Architecture (Phase 4)

## API versioning and compatibility

Laravel's API route loader adds the `/api` prefix. Version 1 routes are registered in `routes/api/v1.php` under `Route::prefix('v1')`, so the public path is `/api/v1`.

- New endpoints MUST be added under `/api/v1`.
- The existing `/api/health`, `/api/auth/me`, and `/api/auth/access` endpoints remain as compatibility aliases for Phases 1–3.
- The React Axios base URL remains `VITE_API_BASE_URL=http://localhost:8000/api`; the authentication context calls `/v1/auth/me` and `/v1/auth/access`.
- Do not create versionless module endpoints.

## Directory layout

```text
backend/
  app/
    Http/
      Controllers/Api/       # HTTP orchestration only
      Middleware/            # Supabase identity and RBAC enforcement
      Requests/Api/           # Reusable request/query validation
      Resources/              # Add API Resources when module representations exist
    Models/                   # Add Eloquent models only for actual mapped entities
    Policies/                 # Resource authorization when model-backed modules exist
    Providers/                # Gate registration and application bindings
    Services/                 # Business operations and integration boundaries
    Support/Api/              # Shared JSON response envelope
  routes/
    api.php                   # API loader and compatibility aliases
    api/v1.php                # Versioned API route registry
  tests/Feature/              # HTTP and integration contract tests
  docs/                      # API and backend standards
```

There are currently no business-module Eloquent models/controllers in the inspected backend. They are intentionally not generated as empty placeholders. Supabase remains the source of the existing schema and RLS rules.

## Standard response envelope

Successful response:

```json
{
  "success": true,
  "message": "Facilities retrieved successfully.",
  "data": [],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 20,
      "total": 0,
      "last_page": 1,
      "from": null,
      "to": null
    }
  }
}
```

Errors:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "validation_error",
  "errors": {
    "name": ["The name field is required."]
  }
}
```

- `data` is always present for success responses.
- `meta` is omitted unless metadata applies.
- `code` and `errors` are omitted when not applicable.
- HTTP status is authoritative; clients must not infer success from the message.
- Do not return raw SQL, SQLSTATE, stack traces, credentials, JWTs, or upstream exception bodies.

Use `App\\Support\\Api\\ApiResponse::success()`, `error()`, and `paginated()` rather than hand-built envelopes in new controllers.

## HTTP status conventions

| Status | Use |
|---|---|
| 200 | Successful read/update/action |
| 201 | Resource created |
| 204 | Successful response with no body, only when explicitly required |
| 400 | Malformed request where validation is not appropriate |
| 401 | Missing, invalid, or expired authentication |
| 403 | Authenticated but not permitted / role unassigned |
| 404 | Resource or route not found |
| 405 | HTTP method not supported |
| 409 | State or uniqueness conflict |
| 422 | Form Request validation failure |
| 429 | Rate limit exceeded |
| 500 | Unexpected/database operation failure |
| 503 | Supabase or required upstream service unavailable |

## Validation conventions

- Use a dedicated Form Request for each write operation (for example, `StoreReservationRequest`) and for nontrivial module query filters.
- Keep validation rules close to the endpoint contract; do not validate only in React.
- Use `authorize()` for resource-specific request authorization where appropriate, and retain route middleware / Policies for the actual security boundary.
- The reusable `IndexQueryRequest` validates `page`, `per_page`, `sort`, `direction`, and `search`. Defaults are page 1, 20 rows, descending order.
- `per_page` is limited to 100. Each resource must define an explicit allowlist of searchable, sortable, and filterable columns.
- Never pass a raw client-supplied sort/filter identifier directly into SQL or query-builder raw expressions.

## Exception handling

API exceptions are rendered in `bootstrap/app.php` only for `/api/*`; non-API Laravel behavior is preserved.

- Validation: 422 with field-level `errors`.
- Authentication: 401.
- Authorization: 403.
- Model/route not found: 404.
- Database query failure: sanitized 500.
- HTTP exceptions: stable message and HTTP status.
- Unexpected exceptions: sanitized 500 without exception details.

Log operational details server-side through Laravel logging/monitoring. Do not echo exception messages to API consumers.

## Controller, service, model, and resource responsibilities

- **Controller:** accept the validated request, call an application service, return `ApiResponse` or an API Resource. Do not place multi-step business rules in controllers.
- **Form Request:** validate and normalize input. Authorization still belongs to route middleware, Gates, or Policies.
- **Service:** implement a cohesive business operation, enforce domain/state invariants, coordinate persistence, and own transaction boundaries where multiple writes must be atomic.
- **Model:** represent a real database entity and define explicit relationships/casts. Do not create models for tables until their actual schema and access patterns have been inspected.
- **Policy/Gate:** enforce action-level and resource-level permissions. Check ownership against the verified Supabase identity, never a browser-provided owner ID.
- **API Resource:** shape stable public representations and prevent accidental disclosure of internal columns. Use `whenLoaded()` for optional relationships.

Avoid generic repository layers or service wrappers that merely rename one Eloquent call. Add abstractions when they isolate a real business rule, external integration, or repeated behavior.

## Transactions and Supabase persistence

For Laravel-managed database writes on the configured PostgreSQL connection, use `DB::transaction()` around operations that must succeed or fail together:

```php
$result = DB::transaction(function () use ($input) {
    // Persist the parent and dependent records.
    // Throw on failure; Laravel rolls back the complete transaction.
    return $createdRecord;
});
```

Use nested transactions/savepoints only when the call structure requires them. Keep external HTTP/Storage operations outside a database transaction where possible; use compensating cleanup or an outbox/workflow for cross-system consistency. A Laravel transaction does not wrap separate Supabase PostgREST HTTP requests in one PostgreSQL transaction.

This project currently accesses Supabase Auth/PostgREST using the caller's bearer token. Do not silently switch business writes to a privileged database role or service-role key to bypass RLS. Before a future module uses Laravel's direct PostgreSQL connection, its connection role, grants, RLS behavior, and transaction semantics must be explicitly verified. No database schema, policy, or production record was changed for Phase 4.

## Query conventions

- Paginate collection endpoints; default 20, maximum 100.
- Return pagination details under `meta.pagination`.
- Allowlist sort and filter columns per resource; default to a stable indexed sort with a unique tie-breaker.
- Normalize search text and use bound query parameters. Define which fields are searchable per endpoint.
- Eager-load only relationships needed by the response (`with()`, `loadMissing()`, `whenLoaded()`) to avoid N+1 queries.
- Select only columns required by the operation/Resource.
- Avoid unbounded `get()` for user-visible collections.
- Apply tenant/ownership constraints and database RLS before pagination, not after retrieving all rows.

## Route organization for future modules

Within the authenticated and authorization-context group in `routes/api/v1.php`, register module routes in these logical sections:

1. Authentication and identity
2. User/account management
3. Dashboard
4. Facilities and laboratories
5. Reservations
6. Inventory
7. Borrowing and returns
8. Maintenance
9. Incident reports
10. Notifications
11. Announcements
12. Reports and activity history

Each actual endpoint must declare its HTTP method, stable route name, Form Request (for input), permission middleware/Gate, controller action, and response contract. Resource-specific Policies must additionally enforce ownership and state transitions.

Example:

```php
Route::middleware('supabase.auth')->group(function (): void {
    Route::middleware('lfms.authorization')->group(function (): void {
        Route::get('/facilities', [FacilityController::class, 'index'])
            ->middleware('permission:facilities.view');

        Route::post('/facilities', [FacilityController::class, 'store'])
            ->middleware('permission:facilities.manage');
    });
});
```

This is an example for future implementation, not an endpoint currently registered. A Staff/SA request must derive the requester identity from the verified request attribute and must never trust `user_id` supplied by the client.

## Security checklist for every future endpoint

- [ ] Supabase access token is validated by `supabase.auth`.
- [ ] Role/permission context is loaded by `lfms.authorization`.
- [ ] Required permission is enforced server-side.
- [ ] Resource ownership and allowed state transitions are checked.
- [ ] Existing RLS remains enabled and is not bypassed.
- [ ] Input uses a Form Request and explicit allowlists.
- [ ] Output uses an API Resource or explicit safe response data.
- [ ] Multi-write operations use a transaction where supported.
- [ ] Errors do not disclose secrets, SQL, stack traces, or internal credentials.
- [ ] Feature tests cover Admin, Staff/SA, unauthorized access, validation, and ownership.

## Tests

Run from `backend/`:

```bash
composer install
php artisan test
```

The API architecture feature tests cover the versioned health contract, legacy health compatibility, unauthenticated versioned identity, list-query validation, pagination metadata, and transaction rollback. Existing authentication and RBAC feature tests remain in place.
