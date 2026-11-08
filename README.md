# LFMS — Laboratory and Facility Management System

LFMS is a monorepo containing a Laravel 13 REST API and a React + TypeScript + Vite single-page application. Supabase provides PostgreSQL, Auth, and private Storage.

## Repository layout

- `frontend/` — React 19, TypeScript, Vite, Tailwind CSS, shadcn/ui components, React Router, Axios, Supabase JS, and PWA tooling.
- `backend/` — Laravel 13 REST API, Supabase Auth token verification, role/permission authorization, and PostgreSQL business operations.
- `supabase/migrations/` — source-controlled SQL migrations (see the migration-history warning below).
- `docs/` — setup, architecture, deployment, backup/recovery, user guide, and phase reports.

## Prerequisites

- PHP 8.3+
- Composer 2
- PHP extensions: `pdo_pgsql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `xml`, `ctype`, `tokenizer`, and `json`
- Node.js 22 LTS and npm
- PostgreSQL for local backend development, or a dedicated non-production Supabase project
- Git

## Local development

Do not point routine development or destructive tests at the production Supabase project. Use a local PostgreSQL database or a separate non-production Supabase project.

### Frontend

```powershell
cd frontend
Copy-Item .env.example .env
npm ci
npm run dev
```

Vite defaults to `http://localhost:5173`. Fill in the non-production Supabase URL and publishable key in `frontend/.env`.

### Backend

```powershell
cd backend
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan serve
```

Laravel defaults to `http://localhost:8000`. Configure a local/non-production PostgreSQL connection and the matching Supabase Auth URL and publishable key in `backend/.env`.

Never commit real `.env` files, database passwords, Supabase secret keys, S3 access keys, or backup credentials.

## Supabase configuration

The currently connected Supabase project is named LFMS, region `ap-southeast-1`, PostgreSQL `17.11.0.018`. The project reference and actual endpoints are documented in the private project dashboard; copy the connection values from Supabase > Connect rather than guessing pooler hostnames or passwords.

- Frontend: Supabase URL + publishable key only.
- Backend: Supabase URL + publishable key for caller-scoped Auth/PostgREST requests; optional secret key only for Auth Admin operations.
- PostgreSQL: use the exact session-pooler or direct connection settings shown by Supabase, with SSL required in production.
- Storage: the private `lfms-evidence` bucket is accessed with the signed-in user's session and Storage RLS policies.

See [Supabase setup](docs/setup/supabase.md) and [production deployment](docs/production-deployment.md).

## API and authentication

- API routes are under `/api/v1`; `/api/health` and Laravel's `/up` are liveness endpoints.
- Supabase Auth issues the bearer token. Laravel validates the token against Supabase Auth, resolves the LFMS profile/roles/permissions, and enforces API authorization.
- Business operations use Laravel's PostgreSQL connection. Those privileged connections bypass Supabase RLS, so server-side authentication, permission, policy, and ownership checks are mandatory.
- The browser uses Supabase's publishable key only. Never expose a Supabase secret/service-role key through `VITE_*` variables.

## Important database migration warning

The live Supabase project reports **20 migration-history entries**, while this repository currently contains **7 SQL migration files**. The original database foundation and several early migrations are not fully represented in Git. This is a reproducibility gap.

Do not run `supabase db push`, `supabase db reset --remote`, `migrate:fresh`, or any schema reconciliation against the shared project until the missing migration history has been recovered and reviewed. See the [backup and recovery guide](docs/backup-recovery.md).

## Verification

```powershell
cd frontend
npm ci
npm run lint
npm run build

cd ..\backend
composer install
composer check-platform-reqs
php artisan about
php artisan route:list
php artisan config:cache
php artisan route:cache
php artisan test
```

GitHub Actions runs the Phase 12 automated suites and the Phase 13 production-configuration checks. CI does not replace live Admin/Staff acceptance testing, Storage ownership checks, backup restoration tests, or a production deployment smoke test.

## Documentation

- [Technical architecture](docs/technical-architecture.md)
- [Production deployment preparation](docs/production-deployment.md)
- [Backup and recovery](docs/backup-recovery.md)
- [Admin and Staff/SA user guide](docs/user-guide.md)
- [Phase 12 integration and security findings](docs/phase-12-system-integration-security-testing.md)

## Current deployment status

LFMS has **not** been deployed to a production hosting provider. The production frontend/API domains, hosting provider, Supabase Auth redirect allowlist, backup plan, and a verified restore point still need to be configured and tested. Phase 13 prepares configuration and documentation only; it does not deploy or modify live records.
