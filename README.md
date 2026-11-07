# LFMS — Laboratory and Facility Management System

LFMS is a monorepo for a Laravel REST API backend and a React + TypeScript frontend. Supabase provides the PostgreSQL project; Supabase Auth and Storage integration will be prepared in later phases.

## Repository layout

- `frontend/` — React, TypeScript, Vite, Tailwind CSS, shadcn/ui configuration, React Router, Axios and PWA tooling.
- `backend/` — Laravel 13 API foundation and PostgreSQL configuration.
- `docs/setup/` — environment and Supabase setup notes.

## Prerequisites

- PHP 8.3+ and required Laravel PHP extensions
- Composer 2
- Node.js LTS and npm
- Git

## Frontend setup

```powershell
cd frontend
Copy-Item .env.example .env
npm install
npm run dev
```

Vite serves the frontend at http://localhost:5173.

## Backend setup

```powershell
cd backend
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan serve
```

Laravel serves the API at http://localhost:8000. Configure the local PostgreSQL password in `backend/.env` before attempting database access.

## Supabase configuration

The connected project is **LFMS**, project ref `bwebicixgcnzyqoaccha`, region `ap-southeast-1`, URL `https://bwebicixgcnzyqoaccha.supabase.co`.

The direct PostgreSQL endpoint `db.bwebicixgcnzyqoaccha.supabase.co:5432` is IPv6-only. On IPv4-only networks use the shared pooler in session mode: `aws-0-ap-southeast-1.pooler.supabase.com:5432`, username `postgres.bwebicixgcnzyqoaccha`, database `postgres`, SSL mode `require`. The database password is not available through the integration and must be entered locally from the Supabase dashboard.

The frontend publishable key is browser-safe. Any secret key and database password must remain in backend-only environment variables. Never put secrets in Vite-prefixed variables or commit actual `.env` files.

## Existing database safety

The Supabase project already contains identity/access-management and facility-management tables, including profiles, roles, permissions, buildings, facilities, facility types, and facility-feature assignment tables. Role and permission seed records and existing migration history are present.

This repository initialization does **not** create, alter, drop, truncate, or migrate any remote database objects. Before writing future migrations, compare them with the live schema. Never run `migrate:fresh` or `db:wipe` against the shared Supabase project.

## API conventions

- Laravel owns business logic, validation, authorization, and database operations.
- Keep REST endpoints under `/api`.
- Use Form Requests for validation and API Resources for response shaping.
- Do not expose sensitive database columns directly to browser clients.
- Future authentication should validate Supabase-issued tokens; do not create a duplicate password/authentication system.

## Local verification

After installing dependencies:

```powershell
cd frontend
npm run build
npm run lint

cd ..\backend
php artisan about
php artisan route:list
php artisan test
```

No dependency installation, frontend build, PHP validation, or live database connection test has been run from this GitHub-only environment. Run the checks locally after setup.
