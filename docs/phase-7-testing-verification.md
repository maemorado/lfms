# Phase 7 — Frontend Testing and Verification

**Status:** Verification plan prepared; CI and live browser checks are pending for this branch. This document is a test plan, not a test-result declaration.

## Scope and safety

This phase verifies the frontend work from Phases 2–6 without changing application behavior. Preserve existing API contracts, Supabase authentication, role/permission checks, database schema, RLS policies, and backend business logic. Do not use mock data as evidence of a working live integration. Do not merge or deploy as part of this phase.

## Existing automated checks

The repository already contains two GitHub Actions workflows:

| Workflow | Automated coverage |
| --- | --- |
| `.github/workflows/lfms-phase12-ci.yml` | Laravel feature tests, frontend ESLint + TypeScript/Vite production build, isolated PostgreSQL reservation constraint/concurrency tests |
| `.github/workflows/lfms-phase13-ci.yml` | Laravel feature tests, production PHP platform/dependency checks, Laravel config/route/event cache boot checks, frontend ESLint + production build and production dependency audit |

The workflows support both pull-request and manual `workflow_dispatch` triggers. The Phase 13 workflow is configured to run for pull requests targeting `main` when relevant backend, frontend, docs, README, or workflow paths change.

**Important:** A successful build or mocked backend test does not prove that the browser can authenticate against the live Supabase project, that real role assignments are correct, or that private Storage and RLS behavior work. CI must be checked on this phase's own PR/commit before recording a pass.

## Automated verification to record

- [ ] Phase 12 CI workflow: Laravel feature tests pass.
- [ ] Phase 12 CI workflow: frontend ESLint passes.
- [ ] Phase 12 CI workflow: frontend TypeScript check and Vite production build pass.
- [ ] Phase 12 CI workflow: isolated PostgreSQL reservation tests pass.
- [ ] Phase 13 production-readiness workflow: backend tests and production boot/cache checks pass.
- [ ] Phase 13 production-readiness workflow: frontend lint and production build pass.
- [ ] Phase 13 production dependency audit passes.
- [ ] Review warnings and audit output; do not automatically upgrade dependencies as part of UI verification.

## Live browser smoke-test matrix

Run these checks against a configured non-production/test environment with valid test accounts and representative records. Record the browser, viewport, account role, result, and any error for each test. Do not create, modify, or delete production records for testing.

### Authentication and protected navigation

- [ ] Signed-out user opening `/dashboard` is redirected to `/login`.
- [ ] Valid assigned user can sign in and reach the dashboard.
- [ ] Invalid credentials show an understandable error without exposing secrets.
- [ ] Session restoration after refresh keeps a valid signed-in user in the workspace.
- [ ] Sign-out ends the local session and returns the user to the login screen.
- [ ] An account with no assigned LFMS role sees the unassigned-access state and cannot enter protected pages.
- [ ] API identity/access failures show the retry state; retry does not create duplicate actions.
- [ ] Invite acceptance route remains accessible without an existing LFMS session.

### Role and permission visibility

Test with actual accounts whose assignments have been confirmed in the target environment. Do not assume a role name alone grants a permission.

- [ ] Admin navigation only shows destinations allowed by the API-provided permissions.
- [ ] Staff/SA navigation only shows destinations allowed by the API-provided permissions.
- [ ] Directly entering a route does not bypass backend authorization.
- [ ] Profile, notifications, announcements, and activity pages behave consistently with the account's actual permissions.
- [ ] Admin-only user management, audit, and report actions are not exposed to accounts lacking their required permissions.

### Page and API integration

For each page, verify initial loading, populated state, empty state, API error/retry, and responsive layout where applicable.

- [ ] Dashboard: metrics, reservations, notifications, and quick links use live API responses.
- [ ] Facilities: list, filters, details, and permitted create/update actions work.
- [ ] Reservations: list, filters, submission, and permitted review/cancellation actions work.
- [ ] Inventory: list, filters, and permitted stock actions work.
- [ ] Borrowing: request, review/release, and return flows work where permitted.
- [ ] Maintenance & Incidents: list, submission, status changes, and evidence display work where permitted.
- [ ] User Management: permitted account/profile/role operations work and errors are visible.
- [ ] Announcements: list, read, and permitted management actions work.
- [ ] Notifications: unread/read state and permitted management actions work.
- [ ] Reports: filters and report data load from the API.
- [ ] Activity and Audit History: filters, pagination, and permission restrictions work.
- [ ] Profile: current account information loads and permitted profile updates persist.

For write actions, verify the resulting state by reloading or re-fetching from the API. Avoid destructive tests unless using dedicated test records and an approved cleanup procedure.

### Theme, responsive layout, and accessibility

- [ ] Light theme renders correctly on login, invitation, and every protected page.
- [ ] Dark theme renders correctly on login, invitation, and every protected page.
- [ ] System theme follows the operating-system preference and responds when that preference changes.
- [ ] Explicit theme selection persists after refresh.
- [ ] Desktop sidebar navigation and active-route indication work.
- [ ] Mobile navigation opens, closes via close control/backdrop/Escape, and restores body scrolling.
- [ ] Mobile drawer navigation closes after selecting a destination.
- [ ] No unintended horizontal page overflow at narrow mobile widths.
- [ ] Header controls, menus, forms, tables, and dialogs remain usable at mobile and tablet widths.
- [ ] Keyboard focus is visible; interactive controls have accessible names and can be operated by keyboard.
- [ ] Reduced-motion preference is respected.

## Test evidence and known limitations

Record actual GitHub Actions run URLs and statuses in the PR after they finish. Record manual test evidence separately; do not mark a live check as passed based only on source inspection or CI.

The current frontend package scripts provide `lint` and `build`; no dedicated browser E2E test script is declared in `frontend/package.json`. Therefore, the listed live browser checks require a running frontend and backend connected to a configured test environment. This phase does not introduce a new test framework or alter production dependencies solely to simulate live integration.

The Phase 12 integration report also identifies live authenticated role-based workflows, private Storage ownership, and several end-to-end operational paths as outstanding at that report's date. Recheck current test-account role assignments and environment readiness before attempting those checks; historical role counts are not proof of current assignments.

## Exit criteria

Phase 7 is complete only when:

1. Both existing CI workflows have successful runs for this phase's proposed changes, with warnings and audit findings reviewed.
2. Live browser smoke tests have been executed using verified test accounts for each available role, or explicitly recorded as blocked with the missing prerequisite.
3. Any confirmed regression is fixed in a narrowly scoped change and re-tested.
4. No application, backend, database, authentication, or authorization behavior has been changed without separate explicit approval.
5. The PR remains unmerged until it has been reviewed and the user explicitly approves merging.
