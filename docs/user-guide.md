# LFMS User Guide

This guide describes the current LFMS screens and workflows. Menu visibility and API access depend on the permissions assigned to the signed-in account. The system currently uses the existing **Admin** and **Staff/SA** roles; do not assume that every user has every permission.

## 1. Sign in and account access

1. Open the LFMS application URL provided by your institution.
2. Enter the email address and password associated with your Supabase Auth account.
3. Select **Sign in**.
4. LFMS verifies the account with Supabase Auth and checks that an active LFMS profile and role assignment exist.
5. If sign-in succeeds but access is not assigned, the workspace remains locked. Contact an LFMS administrator; do not create a second account.

For a new invited account:
1. Open the invitation link sent by the administrator.
2. Confirm that the browser is on the institution's official LFMS domain.
3. Set and confirm the account password on the invitation acceptance page.
4. Continue to the dashboard.

Sign out from the account menu when using a shared computer. Do not share passwords, invitation links, or one-time recovery links.

## 2. Admin guide

### 2.1 User and account management

1. Open **Users**.
2. Search for the account or use the list filters.
3. Open a user to review the profile and assigned role.
4. Use the available account actions to invite/create an account, update permitted profile details, or change account status.
5. Assign only the existing approved role. Do not invent new roles or grant permissions without institutional approval.

Account invitations and status changes use Supabase Auth Admin operations. If the operation fails, verify that the backend's server-only Supabase secret key is configured in the hosting secret store; never paste it into a browser or chat.

### 2.2 Facility management

1. Open **Facilities**.
2. Review the available buildings, facility types, and feature lookups.
3. Create a facility with the required name, code, type, building, and other validated fields.
4. Edit facility details when the information changes.
5. Confirm the facility is active and has the correct availability information before accepting reservations.

Facility creation and updates require the relevant facility-management permission.

### 2.3 Reservation review

1. Open **Reservations** or use the dashboard's reservation summary.
2. Review the requester, facility, requested start/end time, and purpose.
3. Check the availability/conflict information.
4. Approve or reject the request with the appropriate decision.
5. Confirm that the status and notification have updated.

The database has a constraint that prevents overlapping approved reservations for the same facility. Do not bypass a conflict by manually editing records.

### 2.4 Inventory management

1. Open **Inventory**.
2. Manage item categories and locations as permitted.
3. Create inventory items with the correct item code, type, unit, and category.
4. Add or update equipment asset records for individually tracked equipment.
5. Record stock movements through the inventory workflow. Enter accurate movement type, quantity, location, and remarks.
6. Review the movement history after recording a change.

Never create a movement merely to correct a display count. If a stock discrepancy is found, reconcile the physical count and use the approved adjustment process. The Phase 12 security review identified an unresolved direct-insert weakness in the movement ledger; until that database policy is corrected, restrict direct database access and use only the authorized LFMS workflow.

### 2.5 Borrowing and returns

1. Open **Borrowing and returns**.
2. Review pending borrowing requests and the requested quantities/expected return date.
3. Approve or reject the request and add a reason when rejecting.
4. For an approved request, select the correct stock location or available equipment assets and release the items.
5. When items are returned, open the active borrowing record.
6. Record the quantity returned and condition for each line. The system supports partial returns and tracks remaining quantities.
7. Record damaged or missing conditions accurately and add remarks.
8. Verify the updated borrowing status and inventory availability.

Do not mark a return as complete until the physical item has been received and checked.

### 2.6 Maintenance management

1. Open **Maintenance & incident reporting** and select **Maintenance**.
2. Review the submitted request, related facility/equipment, and priority.
3. Update the request status as work progresses.
4. Add maintenance records with assignment, start/completion times, cost, and remarks where applicable.
5. Upload evidence only when it is relevant to the maintenance record.
6. Confirm the equipment/facility availability state reflects the maintenance status.

### 2.7 Incident investigation

1. Open **Maintenance & incident reporting** and select **Incidents**.
2. Review the incident type, description, related facility/equipment, and attachments.
3. Investigate the report and record findings in the resolution field.
4. Update the incident status to the correct next state.
5. For damage or missing equipment, verify the associated asset status is updated correctly.
6. Confirm the incident record and activity/audit history reflect the investigation.

### 2.8 Notifications and announcements

**Notifications**
- Open **Notifications** to review received messages.
- Open an item or use the available action to mark it read.
- Use the unread indicator to track outstanding messages.
- The overdue-notification dispatch action is permission-restricted; use it only when the institution's operating procedure calls for it.

**Announcements**
1. Open **Announcements**.
2. Create an announcement with a clear title, body, category, and optional expiry.
3. Review the content before publishing.
4. Publish when it is approved for Staff/SA visibility.
5. Unpublish or archive it when appropriate.
6. Use announcement history to review recorded changes where permitted.

Expired announcements should not be treated as current instructions. Direct PostgREST expiry enforcement is a known Phase 12 security finding pending an approved RLS policy fix.

### 2.9 Dashboard, reports, activity and audit

- **Dashboard:** review the available operational summaries and recent records.
- **Reports:** select a report type, date range, status/search filters, and page. CSV export currently exports the visible/current page; do not assume it is a complete all-pages export.
- **Activity:** review the activity entries available to your account.
- **Audit history:** use the admin-only audit view to inspect recorded actor, table, action, record, and timestamp fields.

Some aggregate charts, detailed before/after audit diffs, and complete print/export functionality remain documented Phase 11 limitations. Verify source records before using reports for formal institutional decisions.

## 3. Staff/SA guide

### 3.1 Sign in and profile

1. Open the LFMS URL and sign in with your invited account.
2. Open **Profile** to review or update the fields your account is allowed to change.
3. If the system reports that access has not been assigned, contact an administrator.

### 3.2 Browse facilities and submit reservations

1. Open **Facilities** and review available facilities.
2. Open a facility to review details and availability.
3. Open **Reservations** and submit the facility, date/time, and purpose.
4. Review the request status in your reservation list or dashboard.
5. If the request is still eligible for cancellation, use the cancel action.
6. Wait for the approval/rejection notification. Do not assume a submitted request is approved.

### 3.3 Browse inventory and request borrowing

1. Open **Inventory** to browse available items and equipment.
2. Open **Borrowing and returns**.
3. Select the available item(s) and quantity.
4. Enter the expected return date and purpose/remarks.
5. Submit the request.
6. Track its pending, approved, rejected, or released status.
7. Wait for the administrator to release the approved items before taking them.

### 3.4 Return borrowed items

1. Check the expected return date on the borrowing record.
2. Return the physical items to the designated administrator or inventory custodian.
3. The administrator records the received quantity and condition in LFMS.
4. Confirm that the borrowing record reflects the returned or partially returned status.

Staff/SA users do not use the Admin return-processing controls.

### 3.5 Submit maintenance requests

1. Open **Maintenance & incident reporting**.
2. Select **Maintenance**.
3. Choose the related facility or equipment asset.
4. Describe the issue and select its priority.
5. Attach relevant evidence if requested/available.
6. Submit and monitor the request status.

### 3.6 Submit incident reports

1. Open **Maintenance & incident reporting**.
2. Select **Incidents**.
3. Choose the related facility/equipment and incident type.
4. Describe what happened clearly and factually.
5. Attach relevant evidence.
6. Submit and monitor the investigation status.

Do not upload unrelated, confidential, or unnecessarily identifying documents.

### 3.7 Notifications, announcements and activity

- Open **Notifications** to review and mark messages as read.
- Open **Announcements** to read currently published institutional notices.
- Open **Activity** to review the activity history visible to your account.
- The dashboard displays the operational summaries permitted for Staff/SA.

## 4. File and evidence rules

The current private `lfms-evidence` bucket accepts JPEG, PNG, WebP, PDF, and plain text files up to 10 MiB. Upload only files related to the selected maintenance or incident record. Access is restricted by Storage policies; do not share signed file links outside the intended audience.

## 5. Troubleshooting

| Problem | What to do |
|---|---|
| Invalid email/password | Recheck the email and password. Contact the administrator if the account is not confirmed. |
| Sign-in works but workspace is locked | Ask an administrator to verify your active profile and role assignment. |
| API or authorization unavailable | Retry after a short wait. If persistent, contact the system administrator; do not share your access token. |
| Reservation conflict | Choose another time or facility. Do not ask staff to override the database conflict constraint. |
| Item unavailable | Check available quantity/assets and ask the inventory administrator to reconcile stock. |
| Upload rejected | Check file type and size. Use only the allowed evidence formats. |
| Invitation link invalid/expired | Ask an administrator to send a new invitation. |
| Unexpected report totals | Check the report filters and compare against source records; known aggregation limitations are documented in the Phase 11 report. |

## 6. Support and operational responsibility

Contact the institution's designated LFMS administrator for account assignment, permissions, data corrections, and operational incidents. Contact the technical maintainer for API failures, deployment issues, backup/recovery, and security incidents.

Never send passwords, access tokens, database connection strings, Supabase secret keys, S3 access keys, or unredacted backup files through support messages.
