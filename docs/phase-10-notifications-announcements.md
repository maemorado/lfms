# Phase 10 — Notifications and Announcements

## Implemented

- Reuses the existing `public.notifications` and `public.announcements` tables.
- Adds notification event keys, related-record references, and JSON metadata; a unique recipient/event key prevents duplicate deliveries.
- Adds announcement categories and optional expiration timestamps.
- Uses private, SECURITY DEFINER PostgreSQL trigger functions with an empty search path. Function execution is revoked from PUBLIC, anon, and authenticated; no client-callable notification-write RPC is introduced.
- Notifications are created inside the same transaction as the source reservation, borrowing, return, maintenance, incident, or announcement mutation. A notification insert failure aborts the source transaction rather than leaving a successful business change without its corresponding notification.
- Existing RLS policies, role assignments, and permission definitions are preserved.
- Laravel routes use the caller's Supabase JWT and existing permission middleware. User IDs come from verified auth middleware context.
- Staff/SA see only their own notification feed and currently published, unexpired announcements. Admins see their own notifications and can manage announcements subject to existing announcement RLS.
- Announcement change history is read from the existing audit_logs records.
- Overdue borrowing notices are detected by the Admin notification-center request and inserted with an idempotent event key. No pg_cron extension or external notification service was enabled.

## Supported event notifications

- Reservation inserted (Admin recipients); reservation status changes (requester); requester cancellation (Admin recipients).
- Borrowing request inserted (Admin recipients); approval/rejection/cancellation status changes (borrower); items released (borrower); return transaction processed (borrower).
- Maintenance request inserted (Admin recipients); maintenance status changes and completion (reporter).
- Incident report inserted (Admin recipients); status changes and resolution/investigation updates (reporter).
- Announcement publication (all active Admin and Staff/SA profiles).
- Overdue borrowing (borrower; checked when an Admin opens the notification center).

## API

- `GET /api/v1/notifications`
- `GET /api/v1/notifications/unread-count`
- `GET /api/v1/notifications/{notificationId}`
- `PATCH /api/v1/notifications/{notificationId}/read`
- `POST /api/v1/notifications/read-all`
- `POST /api/v1/notifications/dispatch-overdue` (Admin only)
- `GET /api/v1/announcements`
- `GET /api/v1/announcements/{announcementId}`
- `POST /api/v1/announcements` (Admin)
- `PATCH /api/v1/announcements/{announcementId}` (Admin)
- `POST /api/v1/announcements/{announcementId}/publish` (Admin)
- `POST /api/v1/announcements/{announcementId}/unpublish` (Admin)
- `POST /api/v1/announcements/{announcementId}/archive` (Admin)
- `GET /api/v1/announcements/{announcementId}/history` (Admin with audit.view)

## Existing-schema limitations preserved

- The current announcement update RLS policy requires `created_by = auth.uid()` in its check. Therefore an Admin can edit, publish, unpublish, or archive only announcements created by that same Admin. This phase deliberately does not bypass or weaken that policy.
- The existing published-announcement RLS policy does not include `expires_at`. The Laravel API filters expired announcements for Staff/SA, but direct PostgREST access under the existing RLS policy may still return a published expired row. A future, separately approved restrictive RLS policy would be needed to enforce expiry at the database boundary.
- The reservation frontend route does not yet expose a reservation detail page. Reservation notifications link to the dashboard; borrowing, maintenance, incident, and announcement links route to their module.
- Maintenance statuses do not include `rejected`; this phase reports only the existing status values and does not invent a new state.
- There is no configured pg_cron extension or Laravel scheduler runner in the inspected project. Overdue notices are generated when an Admin opens the notification center, not by a background timer. A production scheduler would be needed for time-based automatic delivery without an Admin visit.
- Email, SMS, and push notifications are not included.

## Validation status

Feature tests have been added but have not been run in the local environment. Frontend build/lint and authenticated end-to-end tests still require running on the project workstation. No production business records were inserted or modified for testing.
