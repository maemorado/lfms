# LFMS Neon migration sequence

## Scope and safety

This directory contains the Neon-specific schema and security migration sequence. The original 21 SQL files under `/supabase/migrations` remain unchanged and are the historical source of truth for the Supabase project.

- These scripts target only the LFMS Neon project and its isolated migration branch.
- Never run Supabase CLI migration commands against the source project.
- Never reset either database or use destructive migration commands.
- Do not copy source data until a backup, row-count reconciliation, and migration sign-off are complete.
- Re-check Neon object and row state immediately before every schema or data operation. Do not assume the destination is empty.
- Production cutover and any source Supabase changes require separate approval.

## Live source and destination reconciliation

Inspected on 2026-11-11.

| Object | Supabase source | Neon migration branch |
|---|---:|---:|
| Public base tables | 35 | 35 |
| Public views | 5 | 5 |
| Public columns | 273 | 273 |
| Public indexes | 129 | 129 |
| RLS-enabled public tables | 35 | 35 |
| Public RLS policies | 82 | 82 |
| Public application triggers | 39 | 39 |
| Public foreign keys | 59 | 58 |
| Application functions | 18 | 17 |

The one-FK difference is intentional: Supabase has `profiles.id -> auth.users.id`; Neon keeps the exact Supabase Auth UUID as `profiles.id` but cannot enforce a cross-database FK. All 58 internal application FKs are present.

The policy definitions match semantically after translating Supabase `auth.uid()` to `private.current_user_id()`. All 5 Neon views have `security_invoker=true`, and `lfms_app` has SELECT on them. The final `require_borrowing_rpc()` implementation includes the table-specific field-access correction from source migration `20261109084548`.

### Outstanding schema differences

Two CHECK constraints still differ from the source's final schema:

1. Neon `equipment_assets.condition` currently permits `missing`; the source permits only `new`, `good`, `fair`, `poor`, and `damaged`.
2. Neon `return_transaction_items.condition` currently rejects `missing`; the source permits it.

The second difference can prevent recording missing equipment returns. Migration `007_source_final_constraint_alignment.sql` contains a preflight and forward correction. **It has not been executed.** It replaces two CHECK constraints and therefore requires a separate explicit approval before execution. The preflight aborts if existing Neon rows would violate the source-final definitions.

Four submitter-notification trigger names differ from Supabase, but their event timing and function attachments are equivalent. This is naming drift, not a workflow behavior difference.

### Destination data state

At the time of inspection, exact row counts were zero in all 35 Neon application tables. This is a timestamped observation, not a guarantee about future state. The Supabase source contains live records and remains unchanged. No source data has been copied by this sequence.

### Runtime-role state

The existing database has the non-login `lfms_app` group role, with no superuser or BYPASSRLS attributes. It does not yet have an `lfms_runtime` principal or a migration registry. Migration `006_runtime_role_membership.sql` creates `lfms_runtime` as a **NOLOGIN** least-privilege placeholder and grants membership in `lfms_app`. It does not provision a password or a Render secret. A LOGIN credential must be provisioned separately only when the Render service is ready.

## Versioned sequence

| Version | File | Purpose | Current status |
|---:|---|---|---|
| 001 | `001_core_schema.sql` | Final-state table definitions, columns, constraints, indexes, and `btree_gist`. No source rows are seeded or copied. | Schema objects exist; adoption awaits constraint reconciliation |
| 002 | `002_identity_and_workflow_functions.sql` | Transaction-local identity helper, permission helper, audit, stock and borrowing RPCs, notification functions, and application triggers. Supabase Auth triggers are replaced by Laravel provisioning/synchronization. | Function and trigger objects exist; final review ongoing |
| 003 | `003_rls_and_runtime_grants.sql` | RLS on all 35 tables, all 82 translated policies, and least-privilege grants to `lfms_app`. | Policies and grants exist; final review ongoing |
| 004 | `004_reporting_views.sql` | Five security-invoker reporting views and SELECT grants. | Views already exist; migration file now versions them |
| 005 | `005_migration_registry.sql` | Private registry for executed, adopted, and pending-reconciliation migration records. | Migration file prepared; registry not yet created |
| 006 | `006_runtime_role_membership.sql` | Non-login `lfms_runtime` principal inheriting only `lfms_app` privileges. | Migration file prepared; runtime role not yet created |
| 007 | `007_source_final_constraint_alignment.sql` | Align the two incorrect CHECK constraints with the source's final state. | Prepared only; **not approved for execution** |

Migrations 001-003 are represented by the existing Neon objects, but no ledger existed when they were created. Do not claim exact execution history until the live schema is reconciled and the registry records them as `adopted` or `pending_reconciliation`. Migration 004 is intentionally idempotent and reasserts the already-present view definitions. Migration 005's registry is private and inaccessible to `lfms_app`.

## Source migration mapping

The 21 original source migrations are consolidated by responsibility rather than replayed verbatim:

- Table, column, constraint, index, and final-state data-shape changes are represented in 001.
- Supabase-independent functions and triggers, including the final borrowing trigger correction, are represented in 002.
- The 82 public RLS policies and privilege model are translated in 003.
- The five reporting views are represented in 004.
- Migration provenance is tracked in 005.
- Runtime-role membership is represented in 006.
- The two discovered final-state CHECK differences are corrected only by the separately gated 007.

Supabase Auth objects are not copied. The `profiles.id` UUID is provisioned by Laravel using the exact Supabase Auth user ID. Supabase Auth email changes must be synchronized by Laravel or a verified Auth webhook rather than a trigger on `auth.users`.

The private `lfms-evidence` Storage bucket and its object policies remain in Supabase. No `storage.*` tables or direct browser Storage access are created in Neon. Laravel will mediate upload, download, and delete after authorization against Neon.

## Roles and connection model

- Schema/migration owner: administrative Neon identity used only for reviewed schema changes; never for Laravel request traffic.
- `lfms_app`: NOLOGIN group role targeted by RLS policies and granted only the required table/function privileges.
- `lfms_runtime`: separate least-privilege role, member of `lfms_app`, with no superuser, create-role, create-db, replication, or BYPASSRLS privileges. Its password is provisioned separately and never committed.
- Laravel uses Neon's pooled endpoint for normal requests and transaction-local identity context only after Supabase bearer-token validation.
- Each request that touches business data must establish identity inside a transaction; missing identity must fail closed. Session-level identity must never survive connection reuse.
- RLS tests must execute as the runtime-equivalent role, not as the schema owner.

## Current migration-branch status

- 004, 005, and 006 have been executed on the isolated Neon migration branch in one transaction. The registry records their Git blob IDs.
- 001 remains `pending_reconciliation` because of the two CHECK-constraint differences. 002 and 003 are recorded as adopted after semantic verification. 007 remains pending and was not executed.
- Post-execution verification still reports 35 tables, 5 views, 35 RLS-enabled tables, 82 policies, 39 triggers, and 58 internal foreign keys. All 35 application tables currently contain zero rows. This must be rechecked before any future operation.
- `lfms_runtime` exists as NOLOGIN/INHERIT, has membership in `lfms_app`, and has no elevated or BYPASSRLS attributes. It cannot yet accept network connections.
- The source Supabase project was read-only throughout this stage; no source rows, objects, migrations, Auth users, or Storage objects were modified.

## Verification gates

- Compare all 35 table definitions and 273 columns, 58 internal FKs, 129 indexes, constraints, and the approved-overlap exclusion constraint against the live source catalog.
- Confirm all 82 policy predicates match after the identity-helper translation.
- Verify all 39 trigger attachments and all non-Auth workflow function bodies, including the final borrowing correction.
- Verify the 5 views remain security-invoker and preserve underlying table RLS.
- Test staff/admin boundaries, missing identity, and two different users reusing a pooled connection.
- Test workflow atomicity for stock movement, borrowing release, partial return, missing/damaged returns, notifications, and immutable audit records.
- Do not copy source records or perform production cutover until a complete migration reconciliation report is approved.
