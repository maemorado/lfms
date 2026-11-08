# LFMS Production Deployment Preparation

**Status:** Configuration and deployment instructions prepared. No hosting provider or production domain has been selected, and no deployment has been performed.

## 1. Deployment topology

Choose one of these patterns after selecting a hosting provider:

### Option A — separate frontend and API domains

- Frontend static host: `https://app.example.edu`
- Laravel API host: `https://api.example.edu`
- Set frontend `VITE_API_BASE_URL=https://api.example.edu/api`.
- Set Laravel `FRONTEND_URL=https://app.example.edu`.
- Enable HTTPS on both domains.

### Option B — same-origin reverse proxy

- Frontend host: `https://lfms.example.edu`
- Reverse-proxy `/api/*` to Laravel.
- Set `VITE_API_BASE_URL=/api`.
- Set Laravel `FRONTEND_URL=https://lfms.example.edu`.

Do not configure wildcard production CORS origins. Use the exact frontend origin(s).

## 2. Backend hosting requirements

The repository requires:

- PHP 8.3 or newer, compatible with Laravel 13;
- Composer 2;
- PHP extensions: PDO + `pdo_pgsql`, OpenSSL, Mbstring, cURL, Fileinfo, XML/DOM, Ctype, Tokenizer, and JSON;
- writable Laravel `storage/` and `bootstrap/cache/` directories;
- outbound HTTPS to Supabase Auth and PostgreSQL connectivity to the configured Supabase endpoint;
- PostgreSQL TLS with `sslmode=require`.

Use a PHP application host or container that supports Laravel's public document root. Point the web server document root at `backend/public`; never expose the repository root, `.env`, `vendor/`, or `storage/` directly.

The current database config uses PostgreSQL and supports the Supabase connection values. Copy the exact host, port, username, and connection mode from Supabase > Connect. The existing project is in `ap-southeast-1`; do not guess the pooler endpoint.

## 3. Backend environment

Start from `backend/.env.production.example` and set the actual values in the hosting provider's encrypted environment/secret settings.

Required values:
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY`: generate a unique key once with `php artisan key:generate --show` in a secure environment, then store it as a secret. Do not commit it or regenerate it on every deployment.
- `APP_URL`: public HTTPS API URL.
- `FRONTEND_URL`: exact public frontend origin, no path and no trailing slash.
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE=require`.
- `SUPABASE_URL` and `SUPABASE_PUBLISHABLE_KEY`.
- `SUPABASE_SECRET_KEY` only if server-side Auth Admin operations (for example invitations or account status changes) are required. It is backend-only and must never be sent to the browser.
- `CACHE_STORE=file` for one backend instance. Use a shared cache such as Redis before running multiple instances.
- `LOG_CHANNEL=stderr` and `LOG_LEVEL=warning` so container hosts can collect logs.

The API uses bearer tokens, not Laravel/Sanctum cookie sessions; `supports_credentials=false` is intentional. There is no need to configure Laravel session cookies for the current API authentication model. The app currently uses synchronous queue behavior; no queue worker is required by the current implementation.

## 4. Backend release commands

Run from `backend/` during a deployment build/release:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan about
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

The Phase 13 CI workflow runs production-mode cache and route checks. Do not run database migrations as part of deployment until the migration-history gap documented below has been reconciled and a reviewed migration plan approved.

Ensure the hosting process can write to:
- `backend/storage/framework/cache`
- `backend/storage/framework/sessions` (not used for current bearer auth, but part of Laravel runtime)
- `backend/storage/framework/views`
- `backend/storage/logs`
- `backend/bootstrap/cache`

## 5. Frontend build and hosting

Use Node.js 22 LTS and the committed lockfile:

```bash
cd frontend
npm ci
npm run lint
npm run build
```

Deploy the contents of `frontend/dist/` to a static host. Configure SPA fallback so direct navigation and refresh of React Router paths return `index.html`. For example, a static host's equivalent of:

```text
/*    /index.html   200
```

For production set:
- `VITE_APP_NAME=LFMS`
- `VITE_API_BASE_URL=https://api.example.edu/api` (or `/api` when a same-origin proxy exists)
- `VITE_SUPABASE_URL=https://YOUR_PROJECT_REF.supabase.co`
- `VITE_SUPABASE_PUBLISHABLE_KEY=sb_publishable_...`

Vite variables are embedded in public browser assets. Only use a publishable key here. Never set `VITE_SUPABASE_SECRET_KEY`, a database password, an S3 access key, or a service-role/secret key.

The Supabase browser client is not created when its URL/key are absent; it does not silently fall back to the currently connected project. The production API defaults to the same-origin `/api` path if no explicit URL is supplied, so configure the reverse proxy or set the absolute API URL before launch.

## 6. Supabase Auth URLs

After the frontend domain is chosen, update Supabase Dashboard > Authentication > URL Configuration:

- Site URL: `https://app.example.edu`
- Redirect allowlist: `https://app.example.edu/auth/accept-invite`
- Add the exact additional callback URLs required by any configured Auth provider.
- Keep localhost callback URLs in the development project/config only, not the production allowlist unless explicitly required.

The current frontend contains an invitation-acceptance route. Do not add a password-reset redirect to the allowlist unless a password-reset UI/flow is implemented and tested.

Auth settings and allowed redirect URLs could not be read through the connected project tools; these are manual dashboard checks.

## 7. CORS, HTTPS and TLS

- The Laravel CORS config allows only the configured `FRONTEND_URL`, standard API methods, and the headers required by bearer-token requests.
- CORS credentials are disabled because LFMS uses bearer tokens.
- Both frontend and API must be served over HTTPS.
- Ensure the hosting PHP/OpenSSL/cURL stack supports modern TLS 1.2 cipher suites and TLS 1.3. Supabase announced that an outdated subset of TLS 1.2 ciphers will be disabled on **10 November 2026**. See the [Supabase changelog](https://supabase.com/changelog?types=breaking-change).
- Do not disable TLS verification to work around certificate errors.

## 8. Health checks and monitoring

- Laravel `/up`: framework liveness route.
- Laravel `/api/health`: versioned API liveness route, rate limited.
- Neither endpoint proves that PostgreSQL, Supabase Auth, or Storage are healthy. Configure separate uptime checks or operational alerts for the database and Supabase service health in the provider dashboard.
- Collect Laravel stderr logs and hosting platform HTTP/access logs.
- Alert on repeated 5xx responses, Auth/DB connection failures, Storage upload failures, and disk exhaustion.
- Never log bearer tokens, passwords, API keys, database URLs, or uploaded file contents.

## 9. Supabase production readiness

The connected LFMS project was inspected on 9 November 2026:
- project status: `ACTIVE_HEALTHY`;
- region: `ap-southeast-1`;
- PostgreSQL: `17.11.0.018`;
- 35 public base tables inspected and RLS enabled on each;
- `storage.objects` RLS enabled;
- `lfms-evidence` bucket private, 10 MiB max, allowlist of JPEG/PNG/WebP/PDF/plain text;
- leaked-password protection remains disabled according to the security advisor;
- Phase 12 still documents two medium-severity RLS findings (inventory movement ledger direct insert and announcement expiry visibility).

Do not call the database production-secure until the Phase 12 RLS findings are resolved or formally accepted with compensating controls. This phase makes no database or RLS changes.

## 10. Migration deployment blocker

The remote migration history has 20 entries, but only 7 SQL migration files are present in Git. Several foundational migrations are missing from the repository.

Before deploying a new environment or applying future schema changes:
1. Take and verify a backup.
2. Recover the exact SQL definitions for every missing migration from the original reviewed source/SQL history.
3. Compare the recovered migration sequence with the live schema and `supabase_migrations.schema_migrations`.
4. Reconcile the repository history without replaying migrations on the current live project.
5. Rebuild a disposable staging database from the recovered migrations.
6. Verify schema, RLS, grants, functions, triggers, constraints, and Storage policies.
7. Only then create a separately reviewed production migration plan.

Do not run `supabase db reset --remote`, `supabase db push`, `migrate:fresh`, or `db:wipe` against the current project as a shortcut.

## 11. Pre-launch acceptance checklist

- [ ] Hosting provider, deployment region, frontend domain, and API domain selected.
- [ ] Production secrets stored in host secret manager, not Git.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, unique `APP_KEY`.
- [ ] Backend PHP 8.3+, Composer dependencies, and `pdo_pgsql` verified.
- [ ] Backend config and route cache commands pass.
- [ ] Frontend lint/build passes with production environment values.
- [ ] SPA fallback and protected-route refresh verified on deployed host.
- [ ] Exact CORS origin and HTTPS verified.
- [ ] Supabase Auth Site URL and invite redirect allowlist verified.
- [ ] Database migration history reconciled and staging rebuild verified.
- [ ] Phase 12 security findings remediated/accepted and leaked-password protection decision recorded.
- [ ] Database and Storage backup/restore test completed.
- [ ] Real Admin and Staff/SA acceptance flows completed.
- [ ] Monitoring and incident response owner assigned.

## Current manual requirements

No hosting provider, production domain, Supabase Auth URL configuration, plan tier, backup schedule, or verified restore point was available through the connected repository/project tools. Those items remain unverified and must be completed in the Supabase dashboard and selected hosting provider before launch.
