# LFMS Neon migration — Stage 1 foundation inspection

**Working Git branch:** `migration/neon-stage-1`  
**Neon project:** `fragrant-meadow-83303326` (LFMS Educational Demo)  
**Neon staging branch:** `lfms-neon-migration-foundation` (`br-wispy-sun-aouu86yk`)  
**Neon default branch:** `main` (`br-silent-sea-aog2tvmv`)  
**Supabase source:** `bwebicixgcnzyqoaccha`

## Result

The Neon default branch remains empty of LFMS application tables. The pre-existing Neon foundation branch is not empty of schema, but its 35 application tables currently contain zero records. All 35 table counts were checked individually. The baseline migration does **not** assume that this remains true; it never checks or requires empty tables.

The foundation branch is the correct isolated staging target for the next additive work. No DDL or DML was executed during this inspection.

## Source-to-Neon structural reconciliation

| Object | Supabase source | Neon foundation branch | Result |
|---|---:|---:|---|
| Public base tables | 35 | 35 | Match |
| Base-table columns | 243 | 243 | Match |
| Column signature | `4048058526cb58089d1790bb63420d02` | Same | Exact match |
| Primary keys | 35 | 35 | Match |
| Unique constraints | 22 | 22 | Match |
| Check constraints | 74 | 74 | Count matches; one definition differs |
| Exclusion constraints | 1 | 1 | Match |
| Internal public foreign keys | 58 | 58 | Match |
| External FK to `auth.users` | 1 | 0 | Correctly removed for cross-database identity |
| Public application triggers | 39 | 39 | Count matches |
| Public views | 5 | 5 | Match; all five are security-invoker views |
| Public RLS-enabled tables | 35 | 35 | Match |
| Public RLS policies | 82 | 82 | Match; all 82 target `lfms_app` |

**Correction to the earlier assessment:** a fresh source catalog count reports 59 public foreign keys total, not 58. One is the cross-database `profiles.id -> auth.users.id` FK; the 58 remaining internal FKs are present on Neon. The Neon target correctly has no cross-database FK.

The `btree_gist` extension is installed on Neon in `public` (version 1.7). The source uses an `extensions` schema; the Neon schema placement differs, but the required reservation exclusion constraint is present.

## Verified security foundation

- Neon has a `private.current_user_id()` helper that reads `current_setting('app.current_user_id', true)`.
- `private.has_permission(text)` resolves authorization through Neon profiles, roles, role permissions, and permissions.
- The shared borrowing trigger uses the local identity helper and contains the table-specific checks for `borrow_requests.status` and `borrow_transaction_items.item_id`.
- The final borrowing-trigger correction is already present on the Neon foundation branch.
- The `lfms_app` role exists as NOLOGIN, with neither superuser nor BYPASSRLS.
- `neondb_owner` is privileged and BYPASSRLS. It is not suitable for normal Laravel requests.
- `lfms_app` currently has no table privileges. Runtime grants and a separate login principal must be added deliberately after RLS is installed and tested.

## Known schema difference requiring reconciliation

The source check constraint `return_transaction_items_condition_check` allows `missing` as a return condition. The Neon foundation branch's same-named constraint currently allows only `new`, `good`, `fair`, `poor`, and `damaged`.

This is a real source/target mismatch. It was not silently accepted or changed. The correction needs a reviewed forward migration that replaces this check definition without touching any rows. It must be applied only to the Neon staging branch and validated before any production consideration.

## Stage 1 repository changes

Added on `migration/neon-stage-1`:

- `backend/database/migrations/2026_11_11_000001_adopt_neon_schema_foundation.php`
- `backend/database/migrations/2026_11_11_000002_create_lfms_reporting_views.php`
- `backend/database/migrations/sql/2026_11_11_000002_create_lfms_reporting_views.sql`
- `backend/database/migrations/2026_11_11_000003_enable_lfms_rls_and_policies.php`
- `backend/database/migrations/sql/2026_11_11_000003_enable_lfms_rls_and_policies.sql`

The baseline adoption migration is verification-only. It checks the known 35-table / 243-column foundation, key constraint counts, absence of the external Auth FK, `btree_gist`, the non-login `lfms_app` group, and the corrected borrowing guard. It does not create, alter, truncate, or delete application objects or records. It deliberately does not assert that the tables are empty.

The views and RLS migrations are adoption-aware: they skip a complete pre-existing object set and stop on a partial state rather than dropping/replacing policies. Their `down()` methods throw rather than removing security objects. This is intentional: automatic rollback must not remove the existing foundation or weaken RLS.

## Stage 1 applied to Neon staging

The five reporting views and the Neon-adapted RLS migration were applied in transactions to `lfms-neon-migration-foundation` only. The RLS migration created all 82 policies, enabled RLS on all 35 application tables, granted table access to the restricted `lfms_app` group, and granted execution only to the approved identity helpers and business RPCs.

Post-application catalog checks confirmed:

- 35/35 public tables have RLS enabled.
- 82 public policies exist across 35 tables.
- All 82 policies target only `lfms_app`; none refer to Supabase `auth.uid()`.
- All five views have `security_invoker=true`.
- All 39 application triggers remain attached.
- `private.has_permission(text)` is SECURITY DEFINER with an empty `search_path`.
- `lfms_app` can use the private schema and execute `private.current_user_id()` and `private.has_permission(text)`.
- The borrowing and inventory workflow RPCs are the only public business functions explicitly granted to `lfms_app`.

The direct SQL application was performed on the Neon staging branch. The Laravel migration command was not run, and the Laravel `migrations` ledger was not modified. The PHP migration wrappers detect the complete object sets and will adopt them without attempting to recreate policies when the Laravel migration process is separately approved.

## Stage 1 security verification

A temporary fixture test ran inside a transaction and rolled back its fixture changes. It verified:

1. Without transaction-local identity, `lfms_app` cannot read any profile rows.
2. With an active user UUID set locally, the caller sees exactly their own profile and cannot see another user's profile.
3. Neon role/permission lookup succeeds through `private.has_permission`.
4. A non-auditor cannot read audit history.
5. A caller with `borrowing.request` still cannot insert directly into the borrowing ledger; the borrowing RPC trigger rejects the write without the transaction-bound marker.
6. A user without the profile-update grant cannot change the profile email column.
7. The temporary profiles, role, and role-permission rows were rolled back; a follow-up query found zero fixture rows.

A separate transaction-context check confirmed `set_config('app.current_user_id', ..., true)` is visible inside its transaction and `private.current_user_id()` returns NULL after transaction completion. The underlying setting is reset to an empty value by PostgreSQL; the helper correctly normalizes it to NULL.

For this isolated staging test only, the existing owner role received SET membership on the `lfms_app` group so it could execute `SET LOCAL ROLE lfms_app`. The owner remains privileged and is not a runtime identity. The `lfms_app` group itself remains NOLOGIN and non-BYPASSRLS. A separate runtime login and password have not been created or exposed.

## Mapping of all 21 Supabase migrations

The Supabase migration files remain the immutable source history. They must not be replayed unchanged on Neon.

| Source migration | Neon treatment |
|---|---|
| `20261107115951` identity/access | Keep profiles/roles/permissions tables; remove the FK to `auth.users`; remove Auth table triggers and move profile creation/email sync to Laravel provisioning/sync. |
| `20261107120028` facilities | Port table definitions, indexes, and updated-at triggers. |
| `20261107120605` reservations | Port tables and exclusion constraint; install `btree_gist` in Neon. |
| `20261107120628` inventory | Port tables, indexes, and stock guard. |
| `20261107121153` borrowing/returns | Port tables and constraints. |
| `20261107121242` maintenance/incidents | Port tables and triggers. |
| `20261107121336` notifications/audit | Port tables, audit functions, and immutable audit trigger. |
| `20261107121408` reporting views | Port all five views with invoker security. |
| `20261107121508` security/index hardening | Port indexes and explicit function grants/revokes using Neon roles. |
| `20261107133622` role-based RLS | Translate all 77 baseline public policies from `auth.uid()` to `private.current_user_id()`; use the Neon authorization helper. |
| `20261107133644` profile grants | Translate the column-level profile grant to the restricted runtime group. |
| `20261107133721` admin return permission | Preserve the permission mapping; ensure role/permission rows are seeded idempotently. |
| `20261108114836` atomic inventory movements | Port the stock RPC, trigger guard, and three inventory-stock policies. |
| `20261108123440` atomic borrowing workflows | Port borrowing RPCs, the two added policies, and transaction-bound RPC marker checks. |
| `20261108124928` hardened payloads | Preserve unique indexes and final release/return validation. |
| `20261108130025` active inventory state | Preserve active-item enforcement. |
| `20261108142822` private evidence storage | Do not create Supabase Storage objects/policies in Neon. Keep the bucket in Supabase; move authorization to Laravel. |
| `20261108150037` notification enhancements | Port notification/announcement columns, constraints, indexes, and event functions. |
| `20261108150121` notification deduplication | Preserve the final unique event-key index and final event handler. |
| `20261108151127` submitter confirmations | Preserve all four submitter-confirmation triggers. |
| `20261109084548` borrowing trigger correction | Preserve the corrected table-specific guard; already present on the inspected Neon foundation branch. |

The next implementation stages will add versioned Neon migrations for the remaining view, identity/authorization, RLS, grant, and constraint differences. No Supabase source migration will be applied, repaired, or rewritten.

## Safety and execution status

- Supabase source project was queried read-only; no source writes were made.
- Neon default branch `main` was queried read-only; no schema or data changes were made.
- Neon foundation branch received only the additive reporting-view and RLS DDL described above. No application records were inserted or changed; the temporary RLS test fixtures were rolled back.
- A dedicated Git branch, `migration/neon-stage-1`, was created from the observed remote `main`.
- No Laravel migration command, reset command, data transfer, service creation, or deployment was run.
- Render repository access still requires the user to select only `maemorado/lfms` in the Render GitHub App installation settings.

## Next gate

The remaining check-constraint mismatch is still unresolved: Neon currently excludes `missing` from `return_transaction_items_condition_check`, while Supabase includes it. Correcting that definition requires replacing one check constraint. No rows need to be changed, but the operation does remove and recreate a schema constraint, so it is being held for explicit review rather than hidden inside the RLS migration.

Next work can proceed on Laravel's Neon authorization resolver and transaction middleware, plus the remaining service refactors. Runtime credentials must be configured separately; the database owner/BYPASSRLS principal will not be used for normal requests. Render repository access still awaits the user-side GitHub App installation change.

The production cutover remains out of scope and requires separate approval.
