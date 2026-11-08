# Phase 12 — System Integration, Security and Comprehensive Testing

**Status:** In progress. This document records inspected evidence and work on `feat/lfms-system-integration-security-testing`. It is not a production-readiness declaration.

## Baseline and Git review

- Repository: `maemorado/lfms`
- Baseline branch: `main`
- Baseline commit: `02c13b030a79379627c4438e7b6f0644d70e30eb` (Phase 11 merge)
- Phase 11 PR #18 is merged.
- No open Phase 12 pull request existed at the start of this phase.
- The Phase 6 PR #13 was merged into `feat/lfms-core-api-architecture`, not `main`. The current mainline therefore lacked the facility/reservation API controllers, routes, frontend pages, and tests. The old feature branch had diverged substantially, so it was not merged wholesale. The required Phase 6 files were selectively restored onto the current mainline in this phase.
- Local workstation working-tree state cannot be observed through the repository connector. Remote branch and PR state were inspected.

## Architecture and authorization

- Supabase Auth bearer tokens are verified by calling Supabase Auth's `/auth/v1/user` endpoint. The Laravel middleware then resolves the active profile, role assignments, and permissions through PostgREST with the caller JWT.
- Both `admin` and `staff_sa` role-permission mappings were inspected in the live project. The permissions used by the facility/reservation routes exist for the intended roles.
- Facility and reservation mutations use Laravel policies, permission middleware, explicit actor IDs derived from the verified request, row locks, transactions, and the existing PostgreSQL approved-reservation exclusion constraint.
- User-management and the restored facility/reservation services use Laravel's privileged PostgreSQL connection. Such direct database calls bypass Supabase RLS; RLS being enabled does not automatically protect these operations. Server-side middleware, policies, and ownership checks are therefore mandatory. The service transaction sets `app.current_user_id` for audit triggers and `request.jwt.claim.sub` from the verified Supabase subject for triggers that rely on `auth.uid()`.
- The existing schema, migrations, production records, and RLS policies were not modified by this phase's application changes.

## Supabase security inspection

- All 35 inspected public base tables have RLS enabled.
- `storage.objects` has RLS enabled.
- No table grants to the `anon` role were returned for the inspected public schema.
- The `lfms-evidence` Storage bucket is private, has a 10 MB object limit, and has an explicit MIME-type allowlist. Storage policies scope object access to authorized incident owners/managers.
- Public views inspected use `security_invoker=true`.
- Inspected `SECURITY DEFINER` functions use an empty search path; no anonymous EXECUTE grants were found.
- The Supabase security advisor reports leaked-password protection disabled. This project setting was not changed.

## Confirmed findings

| Severity | Finding | Status |
| --- | --- | --- |
| Medium | `inventory_movements_admin_insert` allows an authenticated user with `inventory.manage` to insert a movement ledger row directly when `performed_by = auth.uid()`. There is no movement-table trigger enforcing that the stock-movement RPC created the ledger entry. This can forge movement history without changing stock quantity. | Unresolved — changing the RLS policy or adding a database guard requires explicit approval. |
| Medium | `announcements_select_published` checks published status and `published_at`, but not `expires_at`. Expired announcements can remain readable through direct authenticated PostgREST access even though the Laravel announcements endpoint filters expiry. | Unresolved — RLS policy change requires explicit approval. |
| Low | Supabase leaked-password protection is disabled according to the security advisor. | Unresolved — project Auth setting not changed. |
| High-risk trust boundary | Privileged Laravel database operations bypass RLS. The application must enforce authentication, permission, and ownership checks on every route. | Reviewed in the inspected user-management and facility/reservation paths; broader live adversarial testing remains pending. |
| Functional integration | Phase 6 facility/reservation code was not present on mainline because PR #13 targeted a feature branch. | Restored selectively in this Phase 12 branch; CI and live workflow verification pending. |
| Data accuracy | Phase 11 date-only upper bounds excluded records later on the selected end date; the dashboard published-announcement metric counted expired announcements. | Application fixes and regression tests added; execution pending CI. |

## Integration changes in this branch

- Restored facility and reservation controllers, services, policies, resources, routes, frontend pages, API client, and feature tests from the previously merged Phase 6 branch without merging its stale branch history.
- Registered the Facility and Reservation policies and API exception mapping.
- Fixed partial reservation updates so an `end_at`-only update is validated against the existing `start_at` by the transactional service instead of a missing request field.
- Set the transaction-local JWT subject alongside the audit actor for privileged reservation/facility writes so database notification triggers can identify requester cancellations.
- Normalized date-only `from`/`to` filters for reports, activity, and audit queries to cover the full selected day.
- Excluded expired announcements from the dashboard published-announcement metric.
- Added regression tests for the date bounds and announcement expiry filter.
- Added a Phase 12 GitHub Actions workflow for Laravel feature tests, frontend lint/build, and isolated PostgreSQL reservation constraint tests.
- Hardened the destructive PostgreSQL integration test: it now refuses to run unless explicitly enabled and connected to the local `lfms_test` database with no database URL configured.

## Test status

No local PHP, Composer, npm, browser, or live authenticated end-to-end command has been executed from this repository connector. GitHub Actions results will be recorded after the Phase 12 pull request is opened. Mocked HTTP tests do not prove live JWT, RLS, Storage, or notification-trigger behavior.

Required live smoke tests still include:
- Admin and Staff/SA login, token refresh, logout, expired/invalid token rejection.
- Facility/reservation submit, approve/reject, cancellation, simultaneous approvals, and requester/Admin notification delivery.
- Inventory movement direct-write denial after an approved database policy fix, plus stock quantity reconciliation.
- Private Storage upload/download ownership checks.
- Borrowing release, partial/damaged/missing return, and stock reconciliation.
- Maintenance/incident notifications and equipment availability.
- Dashboard/report counts against live database fixtures.

## Approval-gated database findings

The two RLS findings above are intentionally not changed in this phase until explicitly approved:
1. Restrict direct INSERT into `inventory_movements` so only the trusted stock-movement path can create ledger entries.
2. Add expiry semantics to the published-announcement SELECT policy.

No production database writes were performed during this audit.
