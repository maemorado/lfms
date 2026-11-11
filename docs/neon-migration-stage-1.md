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
| Public views | 5 | 0 | Not yet ported |
| Public RLS-enabled tables | 35 | 0 | Security stage still required |
| Public RLS policies | 82 | 0 | Security stage still required |

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

## Stage 1 repository change

Added:

- `backend/database/migrations/2026_11_11_000001_adopt_neon_schema_foundation.php`

This is a verification-only Laravel baseline adoption migration. It checks the known 35-table / 243-column foundation, key constraint counts, absence of the external Auth FK, `btree_gist`, the non-login `lfms_app` group, and the corrected borrowing guard. It does not create, alter, truncate, or delete application objects or records. It deliberately does not assert that the tables are empty.

The migration's `down()` throws rather than removing the existing foundation. This is intentional: the pre-existing schema is not disposable.

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
- Neon foundation branch was queried read-only; no schema or data changes were made.
- A dedicated Git branch, `migration/neon-stage-1`, was created from the observed remote `main`.
- No migration command, reset command, data transfer, service creation, or deployment was run.
- Render repository access still requires the user to select only `maemorado/lfms` in the Render GitHub App installation settings.

## Next gate

Before applying any Neon DDL, complete the constraint-difference inventory and prepare the additive/security migrations. Then test them only on `lfms-neon-migration-foundation`, using a non-owner, non-BYPASSRLS role and transaction-local identity context. The production cutover remains out of scope and requires separate approval.
