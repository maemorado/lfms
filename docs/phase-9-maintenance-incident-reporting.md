# Phase 9 — Maintenance and Incident Reporting

## Implemented modules

The Phase 9 workspace is available at `/maintenance-incidents`.

- Staff/SA can submit and track their own maintenance requests and incident reports.
- Admin can review all records visible under the existing Supabase policies, update permitted workflow statuses, record maintenance activities, and record incident investigation/resolution notes.
- Both lists support search, status filtering, and pagination.
- Existing facilities and equipment assets are referenced by their existing UUIDs; no duplicate facility or asset records are created.
- Maintenance activity uses the existing `maintenance_records` table.
- Incident evidence metadata uses the existing `incident_attachments` table.
- Maintenance evidence is stored under the private bucket path `maintenance/{request_id}/...`; incident evidence is stored under `incidents/{incident_id}/...`.

## Existing workflow values

Maintenance request status values are preserved from the database:
`pending`, `approved`, `in_progress`, `on_hold`, `completed`, `cancelled`.

Incident status values are preserved from the database:
`open`, `under_review`, `resolved`, `closed`, `dismissed`.

Incident categories use the existing `damage`, `missing`, `safety`, `facility`, and `other` values.

## API endpoints

All endpoints are under `/api/v1` and require the existing Supabase-authenticated LFMS authorization context.

### Maintenance

- `GET /maintenance/targets`
- `GET /maintenance/requests`
- `POST /maintenance/requests`
- `GET /maintenance/requests/{requestId}`
- `PATCH /maintenance/requests/{requestId}`
- `POST /maintenance/requests/{requestId}/records`

### Incidents

- `GET /incidents`
- `POST /incidents`
- `GET /incidents/{incidentId}`
- `PATCH /incidents/{incidentId}`
- `POST /incidents/{incidentId}/attachments`

## Evidence storage

Bucket: `lfms-evidence` (private)

- Maximum file size: 10 MB.
- Allowed MIME types: JPEG, PNG, WEBP, PDF, and plain text.
- Upload and signed URL generation use the authenticated Supabase client.
- Storage object policies scope read/write access to the related record owner or the existing module-management permission.
- Admin-only object update and delete policies are scoped by module folder.

## Equipment availability integration

- Admin status changes to `in_progress` put related equipment into the existing `under_repair` status.
- Admin investigation of damage/missing equipment sets the corresponding existing equipment status to `damaged` or `missing`.
- The borrowing catalog excludes equipment whose inventory status is not `available`.
- The borrowing catalog also filters equipment against active maintenance and damage/missing incident records visible to the caller through existing RLS.

## Data-model limitations retained intentionally

No maintenance or incident business tables, columns, constraints, indexes, or RLS policies were changed for this phase. The only database addition is the approved private Storage bucket and its four scoped Storage object policies.

The existing schema does not provide:
- a structured incident-occurrence timestamp (the report creation timestamp is displayed),
- a dedicated incident investigation/history table,
- separate maintenance finding/action columns (existing maintenance record remarks are used),
- a maintenance attachment metadata table (maintenance evidence is listed from the private Storage folder).

The equipment-blocking lookup respects existing RLS. A staff user cannot see another staff member's incident or maintenance report, so cross-user reports are guaranteed to block borrowing after an Admin marks the equipment unavailable in the existing inventory table. A narrowly scoped database helper would be needed to expose only the IDs of all blocked assets to every borrower without exposing other users' incident details; that additional database function has not been added.

## Verification status

Automated tests were added in `backend/tests/Feature/MaintenanceIncidentApiTest.php`. They have not yet been executed in this environment. Frontend TypeScript/build/lint checks have also not been executed. Run these locally before considering the phase complete:

- `cd backend; php artisan test`
- `cd frontend; npm run build`
- `cd frontend; npm run lint`

This pull request remains a draft until these checks and authenticated end-to-end workflows are verified.
