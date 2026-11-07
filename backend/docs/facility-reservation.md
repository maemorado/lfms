# Phase 6 — Facility and Reservation Management

## Existing database model (no schema changes)

This implementation uses the existing `buildings`, `facility_types`, `facilities`, `facility_features`, `facility_feature_assignments`, `reservations`, `reservation_participants`, `reservation_approvals`, and `reservation_attachments` tables.

Facility statuses are `available`, `unavailable`, `maintenance`, and `inactive`. Reservation statuses are `pending`, `approved`, `rejected`, `cancelled`, and `completed`. Approval decisions are `approved` and `rejected`.

The existing PostgreSQL `reservations_no_approved_overlap` exclusion constraint prevents overlapping approved time ranges for one facility. It is not changed by this phase.

## API

All endpoints require a valid Supabase bearer token and active LFMS authorization context.

### Facilities

| Method | Endpoint | Permission | Description |
| --- | --- | --- | --- |
| GET | `/api/v1/facilities` | `facilities.view` | Paginated/searchable facility list |
| GET | `/api/v1/facilities/lookups` | `facilities.view` | Buildings, active facility types, features, statuses |
| GET | `/api/v1/facilities/{id}` | `facilities.view` | Facility details |
| GET | `/api/v1/facilities/{id}/availability` | `facilities.view` | Check status and approved-booking overlap for a time range |
| POST | `/api/v1/facilities` | `facilities.manage` | Create facility |
| PATCH | `/api/v1/facilities/{id}` | `facilities.manage` | Update facility and status |

Facility listing supports search, status, building, type, sort, and pagination. Facility deletion/archive is intentionally not exposed because the schema has no archive field and foreign keys restrict destructive deletion.

### Reservations

| Method | Endpoint | Permission | Description |
| --- | --- | --- | --- |
| GET | `/api/v1/reservations` | `reservations.view` | Paginated reservation list |
| GET | `/api/v1/reservations/{id}` | `reservations.view` | Reservation details and review history |
| POST | `/api/v1/reservations` | `reservations.create` | Submit pending reservation |
| PATCH | `/api/v1/reservations/{id}` | `reservations.create` | Edit an eligible pending reservation |
| PATCH | `/api/v1/reservations/{id}/cancel` | `reservations.create` | Cancel own eligible reservation; Admin may cancel any eligible reservation |
| PATCH | `/api/v1/reservations/{id}/decision` | `reservations.manage` | Admin approve/reject with remarks |

Staff/SA listing and detail reads are scoped to the authenticated requester. Admin may view all and filter by requester. Staff/SA cannot call the decision endpoint.

## Scheduling and concurrency

- API validates end after start, future start time, facility availability, and participant count against facility capacity.
- New requests are pending. Only approved reservations occupy the schedule.
- Facility rows are locked during request creation/update and approval to serialize relevant facility status and approval decisions.
- Approval checks for an overlapping approved reservation before updating status.
- PostgreSQL's existing exclusion constraint is the final concurrency-safe guard. A PostgreSQL exclusion violation (`23P01`) is returned as HTTP 409.
- Availability checks are advisory; a successful check does not guarantee a later approval because another Admin may approve a request after the check.
- A facility with future approved reservations cannot be moved to a non-available status; capacity cannot be reduced below the participant count of a future approved reservation.

## Audit and authorization

Facility/reservation mutations set the existing transaction-local `app.current_user_id` used by the database audit trigger and add records to `activity_logs`. Laravel permission middleware enforces role permissions. Reservation ownership is derived from the verified Supabase user ID, never a request-supplied requester ID.

The Laravel PostgreSQL connection is not automatically assigned Supabase `auth.uid()`; the database RLS policies remain unchanged, but Laravel must continue to enforce the verified identity and permission/ownership checks before its privileged database operations.

## Limitations and verification

- No new database migrations, columns, policies, or production records are created or modified.
- Facility types/buildings are selected from existing records; this phase does not add CRUD for those lookup tables or feature assignment management.
- Automated API authorization tests are included. Database-backed PostgreSQL concurrency tests and local PHP/frontend commands must be run in an environment connected to the configured test database before production use.
