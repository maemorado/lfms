# LFMS API Reference

This is a route-level reference derived from `backend/routes/api.php` and `backend/routes/api/v1.php`. Validation fields and response resource shapes are defined by the corresponding Laravel Form Requests, Resources, and controllers; consult those source files for the current field-level contract.

## Base URL and authentication

- Local base URL: `http://localhost:8000/api`
- Versioned prefix: `/v1`
- Protected requests require `Authorization: Bearer <Supabase access token>`.
- Send `Accept: application/json` and `Content-Type: application/json` for JSON requests.
- The public publishable key is used by the browser Supabase client and by Laravel to validate caller-scoped Auth requests.
- Laravel verifies the bearer token with Supabase Auth and resolves LFMS roles/permissions before protected business operations.
- All API routes are rate-limited to 120 requests per minute per client IP in the current backend configuration. Ensure the hosting reverse proxy is configured to preserve the real client IP safely.

## Response envelope

Successful response:

```json
{
  "success": true,
  "message": "Request completed successfully.",
  "data": {}
}
```

Paginated responses include `meta.pagination` with `current_page`, `per_page`, `total`, `last_page`, `from`, and `to`.

Error response:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "validation_error",
  "errors": {}
}
```

Common status codes: 401 unauthenticated/expired token, 403 permission or role denied, 404 resource not found, 409 business conflict, 422 validation failure, 429 rate limit, 500 unexpected server error, and 503 Auth/authorization dependency unavailable.

## Public liveness routes

| Method | Path | Purpose |
|---|---|---|
| GET | `/health` | Basic API liveness |
| GET | `/v1/health` | Versioned API liveness |
| GET | `/up` | Laravel framework liveness |

These routes do not prove PostgreSQL, Supabase Auth, or Storage availability.

## Protected endpoints

All routes below require a valid Supabase bearer token. The permission names below are the middleware requirements; additional Laravel policy and ownership checks may apply.

### Authentication, profile, users and dashboard

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/auth/me` | Authenticated |
| GET | `/v1/auth/access` | Authenticated |
| GET | `/v1/profile` | `profile.view_own` |
| PATCH | `/v1/profile` | `profile.update_own` |
| GET | `/v1/users` | `accounts.view` |
| GET | `/v1/users/roles` | `accounts.view` |
| GET | `/v1/users/{userId}` | `accounts.view` |
| POST | `/v1/users` | `accounts.manage` + `accounts.view` |
| PATCH | `/v1/users/{userId}` | `accounts.manage` + `accounts.view` |
| PATCH | `/v1/users/{userId}/status` | `accounts.manage` + `accounts.view` |
| GET | `/v1/dashboard` | `profile.view_own` |
| GET | `/v1/reports` | `reports.view` |
| GET | `/v1/activity` | `profile.view_own` |
| GET | `/v1/audit` | `audit.view` |

### Facilities and reservations

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/facilities/lookups` | `facilities.view` |
| GET | `/v1/facilities` | `facilities.view` |
| GET | `/v1/facilities/{facilityId}` | `facilities.view` |
| GET | `/v1/facilities/{facilityId}/availability` | `facilities.view` |
| POST | `/v1/facilities` | `facilities.manage` + `facilities.view` |
| PATCH | `/v1/facilities/{facilityId}` | `facilities.manage` + `facilities.view` |
| GET | `/v1/reservations` | `reservations.view` |
| GET | `/v1/reservations/{reservationId}` | `reservations.view` |
| POST | `/v1/reservations` | `reservations.create` + `reservations.view` |
| PATCH | `/v1/reservations/{reservationId}` | `reservations.create` + `reservations.view` |
| PATCH | `/v1/reservations/{reservationId}/cancel` | `reservations.create` + `reservations.view` |
| PATCH | `/v1/reservations/{reservationId}/decision` | `reservations.manage` + `reservations.view` |

### Inventory and borrowing

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/inventory` | `inventory.view` |
| GET | `/v1/inventory/categories` | `inventory.view` |
| GET | `/v1/inventory/locations` | `inventory.view` |
| GET | `/v1/inventory/{itemId}` | `inventory.view` |
| GET | `/v1/inventory/{itemId}/assets` | `inventory.view` |
| GET | `/v1/inventory/{itemId}/movements` | `inventory.view` |
| POST/PATCH/DELETE | `/v1/inventory/categories[/{categoryId}]` | `inventory.manage` + `inventory.view` |
| POST | `/v1/inventory` | `inventory.manage` + `inventory.view` |
| PATCH | `/v1/inventory/{itemId}` | `inventory.manage` + `inventory.view` |
| POST | `/v1/inventory/{itemId}/archive` | `inventory.manage` + `inventory.view` |
| POST | `/v1/inventory/{itemId}/movements` | `inventory.manage` + `inventory.view` |
| POST/PATCH | `/v1/inventory/{itemId}/assets[/{assetId}]` | `inventory.manage` + `inventory.view` |
| GET | `/v1/borrowing/inventory` | `borrowing.view` + `inventory.view` |
| GET | `/v1/borrowing/requests` | `borrowing.view` |
| GET | `/v1/borrowing/requests/{requestId}` | `borrowing.view` |
| POST | `/v1/borrowing/requests` | `borrowing.request` + `borrowing.view` |
| POST | `/v1/borrowing/requests/{requestId}/cancel` | `borrowing.request` + `borrowing.view` |
| POST | `/v1/borrowing/requests/{requestId}/review` | `borrowing.manage` + `borrowing.view` |
| POST | `/v1/borrowing/requests/{requestId}/release` | `borrowing.manage` + `borrowing.view` |
| GET | `/v1/borrowing/overdue` | `borrowing.manage` + `borrowing.view` |
| GET | `/v1/borrowing/records` | `borrowing.view` |
| GET | `/v1/borrowing/records/{transactionId}` | `borrowing.view` |
| GET | `/v1/borrowing/records/{transactionId}/returns` | `borrowing.view` |
| POST | `/v1/borrowing/records/{transactionId}/returns` | `borrowing.manage` + `borrowing.return` + `borrowing.view` |

### Maintenance, incidents, notifications and announcements

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/maintenance/targets` | `maintenance.view`, `facilities.view`, `inventory.view` |
| GET | `/v1/maintenance/requests` | `maintenance.view` |
| GET | `/v1/maintenance/requests/{requestId}` | `maintenance.view` |
| POST | `/v1/maintenance/requests` | `maintenance.create` + `maintenance.view` |
| PATCH | `/v1/maintenance/requests/{requestId}` | `maintenance.manage` + `maintenance.view` |
| POST | `/v1/maintenance/requests/{requestId}/records` | `maintenance.manage` + `maintenance.view` |
| GET | `/v1/incidents` | `incidents.view` |
| GET | `/v1/incidents/{incidentId}` | `incidents.view` |
| POST | `/v1/incidents` | `incidents.create` + `incidents.view` |
| POST | `/v1/incidents/{incidentId}/attachments` | `incidents.view` (record ownership and Storage checks also apply) |
| PATCH | `/v1/incidents/{incidentId}` | `incidents.manage` + `incidents.view` |
| GET | `/v1/notifications` | `notifications.view_own` |
| GET | `/v1/notifications/unread-count` | `notifications.view_own` |
| GET | `/v1/notifications/{notificationId}` | `notifications.view_own` |
| PATCH | `/v1/notifications/{notificationId}/read` | `notifications.view_own` |
| POST | `/v1/notifications/read-all` | `notifications.view_own` |
| POST | `/v1/notifications/dispatch-overdue` | `notifications.manage` |
| GET | `/v1/announcements` | `notifications.view_own` |
| GET | `/v1/announcements/{announcementId}` | `notifications.view_own` |
| GET | `/v1/announcements/{announcementId}/history` | `notifications.manage` + `audit.view` + `notifications.view_own` |
| POST | `/v1/announcements` | `notifications.manage` + `notifications.view_own` |
| PATCH | `/v1/announcements/{announcementId}` | `notifications.manage` + `notifications.view_own` |
| POST | `/v1/announcements/{announcementId}/publish` | `notifications.manage` + `notifications.view_own` |
| POST | `/v1/announcements/{announcementId}/unpublish` | `notifications.manage` + `notifications.view_own` |
| POST | `/v1/announcements/{announcementId}/archive` | `notifications.manage` + `notifications.view_own` |

## Storage

The frontend uploads evidence directly to the private `lfms-evidence` bucket using the signed-in user's Supabase session. Storage RLS and related-record ownership checks apply. See the user guide and backup guide for file restrictions and backup procedures.

## API documentation maintenance

This route list is derived from the current route files. When adding/changing endpoints:
1. update the Form Request and Resource contract;
2. update frontend service usage;
3. update authorization tests;
4. update this route reference;
5. run backend tests, route cache, frontend lint/build, and live role-based smoke tests.
