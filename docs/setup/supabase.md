# Supabase configuration

The connected LFMS Supabase project is in `ap-southeast-1` and currently reports PostgreSQL `17.11.0.018`. The project reference, URL, and exact connection strings are available in the Supabase dashboard. Copy current values from Project Settings / Connect rather than guessing endpoints.

## Environment values

- Frontend: `VITE_SUPABASE_URL` and `VITE_SUPABASE_PUBLISHABLE_KEY` only.
- Backend: `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY`, and (only for Auth Admin operations) `SUPABASE_SECRET_KEY`.
- PostgreSQL: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_SSLMODE=require` in production.

Use the local `.env.example` files for development placeholders and `.env.production.example` files for production placeholders. Never commit actual `.env` files. A publishable key is intended for browser use; a secret/service-role key and database password are not.

## Database connectivity

The direct database hostname may resolve only over IPv6 depending on the project/network. On an IPv4-only host, copy the Session Pooler connection settings from Supabase > Connect. Do not guess the pooler host, username, or password. Use SSL in production.

## Storage

The live project has a private `lfms-evidence` bucket, maximum object size 10 MiB, with allowed MIME types JPEG, PNG, WebP, PDF, and plain text. Storage policies scope access to authorized LFMS users and related records. Do not make this bucket public or bypass RLS for convenience.

## Migration history warning

The live project reports 20 migration-history entries, while this repository contains 7 migration SQL files. The missing foundational and intermediate migration definitions must be recovered and reconciled before using this repository to recreate or migrate a new environment. Do not run remote reset/push or schema reconciliation commands against the current project until that review is complete.

## Production safety

- Do not use the production project for routine development or destructive tests. Use local PostgreSQL or a separate non-production Supabase project.
- Never run `migrate:fresh`, `db:wipe`, `supabase db reset --remote`, or unreviewed `supabase db push` against the shared project.
- Keep Supabase secret keys and database passwords in backend-only secret storage.
- Keep Storage S3 access keys in a separate backup secret store; they bypass Storage RLS.
- See [Backup and Recovery](../backup-recovery.md) and [Production Deployment](../production-deployment.md) for operational procedures.
