# Phase 3 — Role-based authorization

## Security model

1. `AuthenticateSupabaseUser` verifies the bearer token against Supabase Auth `/auth/v1/user`. The verified Supabase user ID is the only identity source.
2. `LoadLfmsAuthorizationContext` resolves the caller's active `profiles` row, `user_roles`, `roles`, `role_permissions`, and `permissions` through Supabase PostgREST, forwarding the caller's access token and the publishable key.
3. These queries remain subject to the caller's existing RLS policies. The Laravel PostgreSQL connection is deliberately not used for authorization because it may connect as a privileged database role and bypass RLS.
4. Unknown roles, missing profiles, inactive profiles, or empty role assignments are denied. An authenticated but unassigned account receives HTTP 403 (`role_not_assigned`). Upstream authorization failures return 503 and fail closed.
5. `RequirePermission`, `RequireRole`, and Laravel Gates named `permission` and `role` provide centralized checks. Business API routes must be placed behind `supabase.auth` and `lfms.authorization`, then use a specific `permission:<permission.name>` or Gate check.
6. The frontend reads `/api/auth/access` only to display role-aware UI. It does not send roles or permissions to the backend as authority.

## Canonical roles and existing permissions

The existing canonical role names are `admin` and `staff_sa`. The unused `admin01` role is not treated as a supported role.

| Area | Admin permissions | Staff/SA permissions |
|---|---|---|
| Dashboard | `dashboard.view` | `dashboard.view` |
| Accounts / profiles | `accounts.view`, `accounts.manage`, `profile.view_own`, `profile.update_own` | `profile.view_own`, `profile.update_own` |
| Facilities | `facilities.view`, `facilities.manage` | `facilities.view` |
| Reservations | `reservations.view`, `reservations.create`, `reservations.manage` | `reservations.view`, `reservations.create` |
| Inventory | `inventory.view`, `inventory.manage` | `inventory.view` |
| Borrowing | `borrowing.view`, `borrowing.request`, `borrowing.return`, `borrowing.manage` | `borrowing.view`, `borrowing.request`, `borrowing.return` |
| Maintenance | `maintenance.view`, `maintenance.create`, `maintenance.manage` | `maintenance.view`, `maintenance.create` |
| Incidents | `incidents.view`, `incidents.create`, `incidents.manage` | `incidents.view`, `incidents.create` |
| Notifications | `notifications.view_own`, `notifications.manage` | `notifications.view_own` |
| Audit / reports / settings | `audit.view`, `reports.view`, `settings.manage` | No admin audit/report/settings permissions |

The dashboard currently renders permission-filtered module entries, not full module routes. Published announcements and own activity history are governed by the existing RLS policies; the current permission catalog does not contain dedicated announcement-read or activity-own permission names.

## Ownership and sensitive actions

- Do not accept `user_id`, `requester_id`, `reported_by`, `uploaded_by`, `reviewer_id`, or equivalent ownership fields as authority from the browser.
- Derive ownership from the verified Supabase identity (`request()->attributes->get('supabase_user')['id']`) and retain database RLS checks.
- Staff reservation creation/update must remain own-request and pending-only. Approval requires `reservations.manage`; staff must not be able to approve their own requests.
- Staff incident/maintenance creation must use the authenticated caller as reporter. Staff cannot edit protected administrative fields.
- Return only records permitted by the existing RLS policies.

## Middleware examples

```php
Route::middleware(['supabase.auth', 'lfms.authorization'])->group(function () {
    Route::get('/facilities', [FacilityController::class, 'index'])
        ->middleware('permission:facilities.view');

    Route::post('/facilities', [FacilityController::class, 'store'])
        ->middleware('permission:facilities.manage');

    Route::post('/reservations/{reservation}/approve', [ReservationController::class, 'approve'])
        ->middleware('permission:reservations.manage');
});
```

Use `Gate::authorize('permission', 'reservations.manage')` inside service/controller actions where authorization depends on a loaded resource. Resource ownership checks must additionally compare the resource owner to the verified identity.

## Database change boundary

No Supabase rows, role assignments, migrations, grants, functions, or RLS policies are changed by Phase 3. Existing 77 RLS policies remain the final row-level enforcement layer. Users without an assignment remain denied by default.
