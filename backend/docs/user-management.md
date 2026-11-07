# Phase 5 — User and Account Management

## Existing schema

Uses existing public.profiles (id, full_name, email, employee_id, status), roles, user_roles, role_permissions, permissions, activity_logs, and audit_logs. No schema or RLS changes are made.

Only admin and staff_sa are exposed as assignable operational roles. The legacy admin01 role has no configured permissions and is not assignable.

## Required backend environment

Set these in backend/.env; never commit this file:

SUPABASE_URL=https://<project-ref>.supabase.co
SUPABASE_PUBLISHABLE_KEY=<publishable-key>
SUPABASE_SECRET_KEY=<server-only-secret-key>
FRONTEND_URL=http://localhost:5173

The secret key is required for Auth invitations and ban/unban operations. It must remain server-side and must never be prefixed with VITE_ or sent to React.

In Supabase Dashboard > Authentication > URL Configuration, allow-list http://localhost:5173/auth/accept-invite and the exact production redirect URL before production use.

## API endpoints

All routes require a valid Supabase bearer token and active LFMS authorization context.

| Method | Endpoint | Permission | Purpose |
| --- | --- | --- | --- |
| GET | /api/v1/users | accounts.view | Search, filter, sort and paginate accounts |
| GET | /api/v1/users/roles | accounts.view | List assignable roles |
| GET | /api/v1/users/{userId} | accounts.view | View an account |
| POST | /api/v1/users | accounts.view + accounts.manage | Invite user and create profile/role |
| PATCH | /api/v1/users/{userId} | accounts.view + accounts.manage | Update profile fields and role |
| PATCH | /api/v1/users/{userId}/status | accounts.view + accounts.manage | Activate/deactivate |
| GET | /api/v1/profile | profile.view_own | Read own profile |
| PATCH | /api/v1/profile | profile.update_own | Update own name / employee ID |

User listing accepts search, role, status, page, per_page (max 100), sort and direction.

Account creation sends a Supabase invitation email. The invited user completes password setup at /auth/accept-invite. Password changes use the signed-in Supabase client updateUser flow; no Laravel endpoint accepts passwords.

Email is read-only in this phase because changing it requires a dedicated Auth verification flow.

## Security and audit

Laravel resolves authorization from the verified bearer token and existing role/permission tables. Own-profile operations derive the target ID from the verified identity. Deactivation bans the Supabase Auth user and sets profiles.status to inactive; reactivation reverses both. Inactive profiles are denied by the existing authorization context and existing RLS permission function. JWTs can remain cryptographically valid until expiry, so the active-profile check remains mandatory.

Role and status transitions lock the canonical Admin role row to serialize last-active-Admin checks. Self-deactivation/reactivation and self-role changes are blocked.

Existing profile audit triggers are preserved. Role assignment writes audit_logs; account creation, updates, activation/deactivation, and own-profile changes write activity_logs. Passwords, tokens and secrets are never logged.

## Operational limitations

1. Laravel's direct PostgreSQL connection does not automatically inherit Supabase auth.uid(). Laravel must enforce authorization for these direct database operations.
2. Local DB connectivity still depends on the configured Supabase pooler, DB password, and PHP pdo_pgsql extension.
3. Invitation delivery depends on Supabase Auth email configuration and the allow-listed redirect URL.
4. The current UI treats admin and staff_sa as mutually exclusive operational roles.
5. Admin email changes, account deletion, bulk import and MFA administration are outside this phase.
6. Automated tests are committed but must be run locally or in CI before merge. This GitHub editing session cannot execute the local PHP or frontend toolchain.