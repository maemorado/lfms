# LFMS database migration reconciliation

**Inspection date:** 2026-11-09  
**Supabase project:** development project `bwebicixgcnzyqoaccha`  
**Repository base:** `main` at `d0abc4b4dd102cd64d5fa9a64514a668be1ada7f`

## Executive result

- The remote Supabase migration ledger contains 20 applied migration versions.
- Before this branch, the repository contained 7 migration files and was missing 13 historical definitions.
- All 13 missing definitions were recoverable verbatim from `supabase_migrations.schema_migrations.statements`. Each stored one SQL statement. The remote `rollback` arrays for these 13 entries were empty.
- The 13 historical files are restored from the ledger. They are source restoration only: they are **not replayed** against the database and no migration-history rows are inserted, deleted, or repaired.
- Four previously tracked migration files differed from the SQL stored in the remote ledger. Those four files are restored from the authoritative ledger statements. The borrowing-trigger correction is kept in its own new forward migration.
- The new borrowing-trigger migration is **not applied** to Supabase in this change. The development database already contains the corrected function from the earlier authorized direct correction.

## Reconciliation of the 13 missing definitions

Every row below was present in remote migration history (applied), had a recoverable original SQL statement in the remote ledger, and had its principal live objects checked. “Effects verified” means catalog/object-level verification, not a full replay against a pristine database.

| Migration ID | Migration name | Applied remotely | Original definition recovered | Live effects verified | Action |
|---|---|---:|---:|---:|---|
| `20261107115951` | `phase_1_identity_and_access_management` | Yes | Yes | Yes — profiles, roles, permissions, role_permissions, user_roles, and permission helper are present | Restored exact SQL file |
| `20261107120028` | `phase_2_facility_management` | Yes | Yes | Yes — buildings, facility_types, facilities, facility_features, and facility_feature_assignments are present | Restored exact SQL file |
| `20261107120605` | `phase_3_reservations_and_scheduling` | Yes | Yes | Yes — reservations, approvals, participants, and attachments tables are present | Restored exact SQL file |
| `20261107120628` | `phase_4_inventory_management` | Yes | Yes | Yes — item_categories, inventory_items, inventory_locations, inventory_stock, inventory_movements, and equipment_assets are present | Restored exact SQL file |
| `20261107121153` | `phase_5_borrowing_and_returns` | Yes | Yes | Yes — borrowing and return tables plus create/review/release/return RPCs are present | Restored exact SQL file |
| `20261107121242` | `phase_6_maintenance_and_incidents` | Yes | Yes | Yes — maintenance request/record/schedule and incident report/attachment tables are present | Restored exact SQL file |
| `20261107121336` | `phase_7_notifications_and_auditing` | Yes | Yes | Yes — notifications, announcements, activity_logs, audit_logs, and audit/notification helper functions are present | Restored exact SQL file |
| `20261107121408` | `phase_8_dashboard_and_reporting_views` | Yes | Yes | Yes — dashboard_metrics, facility_reservation_summary, inventory_low_stock, monthly_reservation_trends, and overdue_borrow_requests are present | Restored exact SQL file |
| `20261107121508` | `phase_9_security_and_index_hardening` | Yes | Yes | Yes — all four declared indexes are present; audit and nonnegative-stock guard functions exist | Restored exact SQL file |
| `20261107133622` | `phase_10_role_based_rls_policies` | Yes | Yes | Yes — its 77 public policy definitions are represented in the live policy set | Restored exact SQL file |
| `20261107133644` | `phase_10_1_profile_column_grants` | Yes | Yes | Yes — authenticated has UPDATE on profiles.full_name, alongside existing column SELECT grants | Restored exact SQL file |
| `20261107133721` | `phase_10_2_admin_return_permission` | Yes | Yes | Yes — admin has borrowing.return; the live mapping also includes staff_sa | Restored exact SQL file |
| `20261108114836` | `add_atomic_inventory_stock_movements` | Yes | Yes | Yes — apply_inventory_stock_movement, require_inventory_stock_rpc, stock guards, and three inventory_stock policies are present | Restored exact SQL file |

## Previously tracked migration-file drift

The three files below matched their remote ledger SQL. Four other tracked files did not match byte-for-byte; those four files have been restored from the original SQL statement stored in the remote migration ledger.

| Migration ID | Result |
|---|---|
| `20261108123440` | Already matched remote ledger |
| `20261108124928` | Already matched remote ledger |
| `20261108130025` | Already matched remote ledger |
| `20261108142822` | Restored from remote ledger |
| `20261108150037` | Restored from remote ledger |
| `20261108150121` | Restored from remote ledger |
| `20261108151127` | Restored from remote ledger |

This restores the SQL actually recorded as applied. It is not the mechanism used to introduce the borrowing-trigger correction; that correction is isolated in a new forward migration.

## Why the public RLS policy count is 82 (not 77)

The 77-policy baseline comes from `20261107133622_phase_10_role_based_rls_policies`. Two later migrations added five public policies:

- `20261108114836_add_atomic_inventory_stock_movements`: three policies on `public.inventory_stock`:
  - `inventory_stock_select_inventory_manage`
  - `inventory_stock_insert_inventory_manage`
  - `inventory_stock_update_inventory_manage`
- `20261108123440_phase_8_borrowing_returns_atomic_workflows`: two policies on `public.borrow_requests`:
  - `borrow_requests_admin_manage_update`
  - `borrow_requests_cancel_own_pending`

Therefore: **77 + 3 + 2 = 82 public policies**. The four `lfms_evidence_*` policies apply to `storage.objects` and are separate from the public-schema count.

The live database reports 35/35 public tables with RLS enabled. The live public policy names are fully accounted for by the historical SQL statements. No RLS policies were changed by this branch.

## Borrowing trigger correction

New forward migration:

`supabase/migrations/20261109084548_fix_borrowing_rpc_trigger_column_access.sql`

The migration replaces only `public.require_borrowing_rpc()`. It preserves:

- authenticated actor validation via `auth.uid()`;
- transaction-bound `app.borrowing_rpc` marker validation;
- the approved-request check against inactive inventory;
- the release check against inactive inventory;
- table-specific access to `NEW.status` only for `borrow_requests` and `NEW.item_id` only for `borrow_transaction_items`;
- `SECURITY INVOKER`;
- empty `search_path`;
- DELETE returning `OLD`, and other operations returning `NEW`.

It does not create or alter triggers, grants, policies, tables, constraints, or data. The six existing trigger attachments remain in place.

## Security and test evidence

Development database checks performed:

- 35/35 public tables have RLS enabled.
- 82 public RLS policies are present and their names are accounted for by migration SQL.
- Four separate evidence-storage policies remain on `storage.objects`.
- `require_borrowing_rpc()` has `prosecdef = false` and an empty `search_path`.
- The function remains attached to the same six borrowing tables.
- Function EXECUTE grants are limited to `postgres` and `service_role`; no EXECUTE grant to `PUBLIC`, `anon`, or `authenticated` was introduced.
- The profile column grant for authenticated UPDATE on `full_name` is present.
- The four indexes from the security/index-hardening migration are present.

A transaction-scoped PL/pgSQL test block completed successfully. It verified that unauthenticated borrowing inserts, authenticated inserts without the borrowing RPC marker, release of an inactive item, direct inventory-stock updates without the inventory RPC marker, and negative stock updates are rejected. The temporary inactive-status fixture and attempted writes were rolled back by PL/pgSQL exception subtransactions. The inventory item status distribution remained unchanged after the test (10 active items).

These are database-level tests, not Laravel API or React end-to-end tests. No production or staging database was touched.

## Operational notes and remaining limitations

- No historical migration was replayed. The remote migration ledger was not changed.
- The new borrowing-trigger migration has not been applied to the remote database; it versions the correction already present in development.
- No local repository checkout was exposed through the GitHub connection, so the local working-tree status and any uncommitted workstation changes could not be inspected. The GitHub branch was created from the observed remote `main` commit.
- The Supabase CLI/local PostgreSQL migration test harness was not available through this repository connection. Source-to-ledger comparison and live database catalog/behavior checks were used instead.
- Review this PR before merging. Do not run `db push` until the local migration list has been verified against the restored source history.
