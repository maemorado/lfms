# LFMS Technical Architecture

## System overview

LFMS is a browser-based single-page application backed by a Laravel REST API and a managed Supabase project.

```text
Browser
  React + TypeScript + Vite
  React Router / Axios
  Supabase JS Auth client
       |                         |
       | HTTPS + bearer JWT      | HTTPS Auth + private Storage
       v                         v
Laravel 13 REST API          Supabase Auth / Storage
  - token verification          - issues and refreshes user sessions
  - profile / role / permission - private lfms-evidence bucket
  - validation / policies
  - business workflows
       |
       | PostgreSQL TLS connection
       v
Supabase PostgreSQL 17
  - LFMS relational data
  - RLS for PostgREST / Storage callers
  - constraints, functions, triggers, audit history
```

## Technology

| Layer | Technology |
|---|---|
| Frontend | React 19, TypeScript, Vite 8, Tailwind CSS 4, shadcn/ui patterns, Lucide React |
| Routing | React Router 7 |
| HTTP | Axios |
| Browser authentication / Storage | `@supabase/supabase-js` |
| Backend | PHP 8.3+, Laravel 13 |
| Database | Supabase-managed PostgreSQL 17 |
| Identity | Supabase Auth |
| File storage | Supabase Storage |
| CI | GitHub Actions |

## Authentication and authorization

1. The browser signs in with Supabase Auth and retains the Supabase session using the Supabase JS client.
2. Axios obtains the current access token and sends it as `Authorization: Bearer <token>` to Laravel.
3. `AuthenticateSupabaseUser` validates the token by calling the Supabase Auth `/auth/v1/user` endpoint with the configured publishable key and bearer token.
4. Laravel records the verified subject ID in request attributes. It does not trust client-supplied user IDs or role names.
5. `LoadLfmsAuthorizationContext` resolves the profile, active roles, and permissions using the caller token.
6. Route middleware and Laravel policies enforce permission and ownership rules.

LFMS uses bearer-token API authentication, not Laravel/Sanctum cookie authentication. CORS therefore does not allow credentialed cookies.

## Database trust boundary

The Laravel application uses a PostgreSQL connection for business operations. These privileged database operations bypass Supabase RLS. RLS still protects direct browser/PostgREST operations and Storage objects, but it does not automatically protect a privileged Laravel connection.

For every privileged route, Laravel must:
- validate the Supabase bearer token;
- resolve the authenticated LFMS profile and permissions;
- authorize the operation;
- derive actor and owner IDs from the verified subject;
- apply explicit ownership checks;
- use transactions and database constraints for integrity.

Facility/reservation write transactions set transaction-local audit actor and verified JWT subject context for existing audit and notification triggers. This context is not a replacement for application authorization.

## Main API modules

- Authentication and profile: `/api/v1/auth`, `/api/v1/profile`
- User and account management: `/api/v1/users`
- Facilities and reservations: `/api/v1/facilities`, `/api/v1/reservations`
- Inventory: `/api/v1/inventory`
- Borrowing and returns: `/api/v1/borrowing`
- Maintenance and incidents: `/api/v1/maintenance`, `/api/v1/incidents`
- Notifications and announcements: `/api/v1/notifications`, `/api/v1/announcements`
- Dashboard, reports, activity, and audit: `/api/v1/dashboard`, `/api/v1/reports`, `/api/v1/activity`, `/api/v1/audit`

The public `/up` and `/api/health` routes are liveness checks, not proof that the database, Auth, or Storage dependencies are available.

## Storage

The application uses the private `lfms-evidence` bucket for maintenance and incident evidence. The live bucket configuration inspected for Phase 13:
- private bucket;
- 10 MiB maximum object size;
- MIME allowlist: JPEG, PNG, WebP, PDF, and plain text;
- Storage RLS policies restrict create/read/update/delete operations by LFMS permission and related record ownership.

Browser uploads use the signed-in user's Supabase session. Never use a Supabase secret/service-role key in the browser. Storage S3 access keys bypass RLS and are for a tightly controlled backup process only.

## Environment boundaries

- Development should use local PostgreSQL or a separate non-production Supabase project.
- Production must use its own exact Supabase URL, publishable key, server-side secret key (only if Auth Admin operations require it), and database connection string.
- The React build embeds all `VITE_*` variables into browser assets. They must be public-only.
- Laravel secrets remain in the backend hosting secret store and are never copied into frontend build variables.

## Repository and migration state

The live project is in `ap-southeast-1` and reports PostgreSQL `17.11.0.018`. The connected project is currently `ACTIVE_HEALTHY`.

The live migration history contains 20 entries, while `supabase/migrations/` contains 7 SQL files. Thirteen live migration entries are not represented by source-controlled SQL files. This must be reconciled before the repository can reliably recreate the database in a new environment. Do not push/reset/reconcile the live database until the missing migration definitions have been recovered and reviewed.

## Operational assumptions and limitations

- No production hosting provider or domain has been selected.
- The frontend is a Vite SPA. The static host must rewrite unknown application routes to `index.html`.
- Laravel is stateless; there is no Laravel session cookie flow.
- The application currently uses a file cache by default and synchronous queue behavior. File cache is appropriate for a single backend instance only; multiple instances require a shared cache.
- No production queue worker or scheduled task is currently documented as required.
- The PWA manifest currently has no icon entries; installability should not be advertised until production icons are supplied and tested.
