# LFMS Neon migration sequence

## Scope and safety

This directory contains Neon-specific SQL migrations. The original Supabase migrations in `/supabase/migrations` remain unchanged and are the historical source of truth. These files target only the LFMS Neon database.

Do not run Supabase CLI migration commands against the source project. Do not reset either database. Migration execution is a separate, explicitly reviewed step. Before any future data transfer, take a source backup and re-check Neon for pre-existing data; never assume the target is empty.

## Source reconciliation

- Source project: LFMS Supabase project; source schema inspected live on 2026-11-11.
- Repository source: 21 SQL files under `supabase/migrations`.
- Live source: 35 public tables, all with RLS enabled; 82 public RLS policies; 18 application functions; 39 application triggers; 5 views; 59 public foreign keys (58 internal application FKs plus the Supabase Auth FK).
- The only cross-system FK is `profiles.id -> auth.users.id`. Neon keeps the exact Supabase Auth UUID as `profiles.id` but omits that FK because Auth remains in Supabase.
- Supabase Auth triggers are replaced by Laravel user provisioning and email synchronization; Auth internal tables are not copied.
- Supabase Storage bucket and object policies remain in Supabase and are handled by a separate Laravel-mediated Storage stage. No `storage.*` tables are created in Neon.
- Source row data is not included in schema migrations. IDs, relationships, and timestamps are copied in a separate controlled data-transfer stage.
- The final `require_borrowing_rpc()` correction from migration `20261109084548` is mandatory and must be preserved in the workflow-function migration.

## Sequence

1. `001_core_schema.sql` — final-state table definitions, constraints, indexes, and the `btree_gist` dependency. This is DDL only; it does not seed or copy source rows.
2. `002_identity_and_workflow_functions.sql` — transaction-local identity helper, authorization helper, audited workflow functions, stock and borrowing RPCs, notification functions, and triggers. Translate every `auth.uid()` reference to `private.current_user_id()`; remove Supabase Auth triggers.
3. `003_rls_and_runtime_grants.sql` — enable RLS on all 35 application tables, port all 82 source policies to the `lfms_app` group role, and grant only required table/function privileges. Runtime login role must be separate from schema owner and must not have BYPASSRLS.
4. `004_reporting_views.sql` — five security-invoker reporting views.
5. `005_migration_registry.sql` — record this Neon sequence and its source-migration mapping; no source Supabase migration history is modified.

## Roles and connection model

- Schema owner/migration role: Neon administrative/migration identity, never used by Laravel request traffic.
- `lfms_app`: NOLOGIN group role referenced by RLS policies and granted only required privileges.
- `lfms_runtime`: separate LOGIN role, member of `lfms_app`, with no superuser, create-role, create-db, or BYPASSRLS attributes. Its credential is provisioned separately and never committed.
- Laravel uses the Neon pooled endpoint for normal requests and transaction-local `set_config('app.current_user_id', ..., true)` after Supabase token verification.
- Every request that touches business data must set and clear identity within a transaction. Missing identity must fail closed.

## Source migration mapping

The 21 original migrations are consolidated by responsibility rather than replayed verbatim. Supabase-specific Auth and Storage operations are intentionally reimplemented at their respective service boundaries. Source history is never repaired or advanced by these Neon files.

## Verification gates

- Validate the schema migration on an isolated Neon branch before applying to the Neon primary branch.
- Compare all 35 table definitions, 58 internal FKs, indexes, exclusion constraint, and final-state columns against the live source catalog.
- Verify RLS policies and grants as `lfms_runtime`, not as the owner.
- Test transaction-pool reuse across two different users and a request with no identity context.
- No production cutover or source Supabase mutation is included in this migration sequence.
