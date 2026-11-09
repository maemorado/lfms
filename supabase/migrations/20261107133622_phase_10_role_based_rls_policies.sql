
begin;

-- Keep the authorization helper outside every API-exposed schema.
create schema if not exists private authorization postgres;
revoke all on schema private from public, anon, authenticated;
grant usage on schema private to authenticated;

create or replace function private.has_permission(required_permission text)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
    from public.profiles p
    join public.user_roles ur on ur.user_id = p.id
    join public.role_permissions rp on rp.role_id = ur.role_id
    join public.permissions perm on perm.id = rp.permission_id
    where p.id = (select auth.uid())
      and p.status = 'active'
      and perm.name = required_permission
  );
$$;

revoke all on function private.has_permission(text) from public, anon, authenticated;
grant execute on function private.has_permission(text) to authenticated;

-- Authorization data remains database-owned, never user-editable JWT metadata.
insert into public.permissions (name, description)
values ('borrowing.return', 'Record the receipt and inspection of returned equipment.')
on conflict (name) do nothing;

insert into public.role_permissions (role_id, permission_id)
select r.id, p.id
from public.roles r
join public.permissions p on p.name = 'borrowing.return'
where r.name = 'staff_sa'
on conflict (role_id, permission_id) do nothing;

-- Remove the broad default grants that would otherwise make RLS an incomplete control.
revoke all on all tables in schema public from anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;

-- Authenticated users may query tables, but row policies below decide which rows.
grant usage on schema public to authenticated;
grant select on all tables in schema public to authenticated;

-- Role and permission administration is restricted by RLS to accounts.manage.
grant insert, update, delete on public.roles, public.user_roles,
  public.permissions, public.role_permissions to authenticated;

-- Facility administration. Staff receive read-only access through SELECT policies.
grant insert, update, delete on public.buildings, public.facility_types,
  public.facilities, public.facility_features,
  public.facility_feature_assignments to authenticated;

-- Reservation requests: users can submit and edit their own pending requests.
-- Status transitions and approvals remain server-side workflow operations.
grant insert on public.reservations to authenticated;
grant update (facility_id, start_at, end_at, purpose, subject_course, participant_count)
  on public.reservations to authenticated;
grant insert, delete on public.reservation_participants to authenticated;
grant insert, delete on public.reservation_attachments to authenticated;
grant insert on public.reservation_approvals to authenticated;

-- Inventory master data is admin-managed. Stock ledger is intentionally append-only
-- through the trusted backend so every quantity change can be paired with a movement.
grant insert, update, delete on public.item_categories, public.inventory_items,
  public.inventory_locations, public.equipment_assets to authenticated;
grant insert on public.inventory_movements to authenticated;

-- Borrowing requests can be submitted by staff; releases are recorded by administrators.
grant insert on public.borrow_requests to authenticated;
grant update (expected_return_at, remarks) on public.borrow_requests to authenticated;
grant insert, update, delete on public.borrow_request_items to authenticated;
grant insert on public.borrow_transactions, public.borrow_transaction_items to authenticated;
grant insert on public.return_transactions, public.return_transaction_items to authenticated;

-- Staff can report issues and receive equipment returns. Work assignment and schedules
-- are administrator-managed.
grant insert on public.maintenance_requests to authenticated;
grant insert, update, delete on public.maintenance_records,
  public.maintenance_schedules to authenticated;
grant insert on public.incident_reports, public.incident_attachments to authenticated;

-- Notifications are immutable except for the recipient's read marker.
grant update (read_at) on public.notifications to authenticated;
grant insert on public.notifications to authenticated;
grant insert, update, delete on public.announcements to authenticated;

-- No direct client writes to activity_logs or audit_logs.
-- Audit rows are written by the existing trigger function.

-- Profiles
create policy profiles_select_self_or_accounts
on public.profiles for select to authenticated
using (
  (id = (select auth.uid()) and (select private.has_permission('profile.view_own')))
  or (select private.has_permission('accounts.view'))
  or (select private.has_permission('accounts.manage'))
);

create policy profiles_update_self_or_admin
on public.profiles for update to authenticated
using (
  (id = (select auth.uid()) and (select private.has_permission('profile.update_own')))
  or (select private.has_permission('accounts.manage'))
)
with check (
  (id = (select auth.uid()) and (select private.has_permission('profile.update_own')))
  or (select private.has_permission('accounts.manage'))
);

-- Roles and permissions are readable to active application users; only account
-- administrators can modify role assignments or permission definitions.
create policy roles_select_active_users
on public.roles for select to authenticated
using ((select private.has_permission('profile.view_own')));

create policy roles_admin_manage
on public.roles for all to authenticated
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy permissions_select_active_users
on public.permissions for select to authenticated
using ((select private.has_permission('profile.view_own')));

create policy permissions_admin_manage
on public.permissions for all to authenticated
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy user_roles_select_self_or_admin
on public.user_roles for select to authenticated
using (
  user_id = (select auth.uid())
  or (select private.has_permission('accounts.view'))
  or (select private.has_permission('accounts.manage'))
);

create policy user_roles_admin_manage
on public.user_roles for all to authenticated
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy role_permissions_select_active_users
on public.role_permissions for select to authenticated
using ((select private.has_permission('profile.view_own')));

create policy role_permissions_admin_manage
on public.role_permissions for all to authenticated
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

-- Facility management
create policy buildings_select_facility_view
on public.buildings for select to authenticated
using ((select private.has_permission('facilities.view')));

create policy buildings_admin_manage
on public.buildings for all to authenticated
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_types_select_facility_view
on public.facility_types for select to authenticated
using ((select private.has_permission('facilities.view')));

create policy facility_types_admin_manage
on public.facility_types for all to authenticated
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facilities_select_facility_view
on public.facilities for select to authenticated
using ((select private.has_permission('facilities.view')));

create policy facilities_admin_manage
on public.facilities for all to authenticated
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_features_select_facility_view
on public.facility_features for select to authenticated
using ((select private.has_permission('facilities.view')));

create policy facility_features_admin_manage
on public.facility_features for all to authenticated
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_feature_assignments_select_facility_view
on public.facility_feature_assignments for select to authenticated
using ((select private.has_permission('facilities.view')));

create policy facility_feature_assignments_admin_manage
on public.facility_feature_assignments for all to authenticated
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

-- Reservations
create policy reservations_select_own_or_approved
on public.reservations for select to authenticated
using (
  (select private.has_permission('reservations.manage'))
  or (
    (select private.has_permission('reservations.view'))
    and (requester_id = (select auth.uid()) or status = 'approved')
  )
);

create policy reservations_staff_create_own
on public.reservations for insert to authenticated
with check (
  (select private.has_permission('reservations.create'))
  and requester_id = (select auth.uid())
  and status = 'pending'
);

create policy reservations_staff_update_own_pending
on public.reservations for update to authenticated
using (
  (select private.has_permission('reservations.create'))
  and requester_id = (select auth.uid())
  and status = 'pending'
)
with check (
  (select private.has_permission('reservations.create'))
  and requester_id = (select auth.uid())
  and status = 'pending'
);

create policy reservations_admin_manage
on public.reservations for all to authenticated
using ((select private.has_permission('reservations.manage')))
with check ((select private.has_permission('reservations.manage')));

create policy reservation_participants_select_related
on public.reservation_participants for select to authenticated
using (
  (select private.has_permission('reservations.manage'))
  or exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and (
        r.requester_id = (select auth.uid())
        or r.status = 'approved'
      )
      and (select private.has_permission('reservations.view'))
  )
);

create policy reservation_participants_insert_own_pending
on public.reservation_participants for insert to authenticated
with check (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select auth.uid())
      and r.status = 'pending'
  )
);

create policy reservation_participants_delete_own_pending
on public.reservation_participants for delete to authenticated
using (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select auth.uid())
      and r.status = 'pending'
  )
);

create policy reservation_approvals_select_related
on public.reservation_approvals for select to authenticated
using (
  (select private.has_permission('reservations.manage'))
  or exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select auth.uid())
      and (select private.has_permission('reservations.view'))
  )
);

create policy reservation_approvals_admin_insert
on public.reservation_approvals for insert to authenticated
with check (
  (select private.has_permission('reservations.manage'))
  and reviewer_id = (select auth.uid())
);

create policy reservation_attachments_select_related
on public.reservation_attachments for select to authenticated
using (
  (select private.has_permission('reservations.manage'))
  or exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select auth.uid())
      and (select private.has_permission('reservations.view'))
  )
);

create policy reservation_attachments_insert_own_pending
on public.reservation_attachments for insert to authenticated
with check (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select auth.uid())
      and r.status = 'pending'
  )
);

create policy reservation_attachments_delete_own_pending
on public.reservation_attachments for delete to authenticated
using (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select auth.uid())
      and r.status = 'pending'
  )
);

-- Inventory
create policy item_categories_select_inventory_view
on public.item_categories for select to authenticated
using ((select private.has_permission('inventory.view')));

create policy item_categories_admin_manage
on public.item_categories for all to authenticated
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_items_select_inventory_view
on public.inventory_items for select to authenticated
using ((select private.has_permission('inventory.view')));

create policy inventory_items_admin_manage
on public.inventory_items for all to authenticated
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_locations_select_inventory_view
on public.inventory_locations for select to authenticated
using ((select private.has_permission('inventory.view')));

create policy inventory_locations_admin_manage
on public.inventory_locations for all to authenticated
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_stock_select_inventory_view
on public.inventory_stock for select to authenticated
using ((select private.has_permission('inventory.view')));

create policy equipment_assets_select_inventory_view
on public.equipment_assets for select to authenticated
using ((select private.has_permission('inventory.view')));

create policy equipment_assets_admin_manage
on public.equipment_assets for all to authenticated
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_movements_select_inventory_view
on public.inventory_movements for select to authenticated
using ((select private.has_permission('inventory.view')));

create policy inventory_movements_admin_insert
on public.inventory_movements for insert to authenticated
with check (
  (select private.has_permission('inventory.manage'))
  and performed_by = (select auth.uid())
);

-- Borrowing and returns
create policy borrow_requests_select_own_or_admin
on public.borrow_requests for select to authenticated
using (
  (select private.has_permission('borrowing.manage'))
  or (
    (select private.has_permission('borrowing.view'))
    and borrower_id = (select auth.uid())
  )
);

create policy borrow_requests_staff_create_own
on public.borrow_requests for insert to authenticated
with check (
  (select private.has_permission('borrowing.request'))
  and borrower_id = (select auth.uid())
  and status = 'pending'
);

create policy borrow_requests_staff_update_own_pending
on public.borrow_requests for update to authenticated
using (
  (select private.has_permission('borrowing.request'))
  and borrower_id = (select auth.uid())
  and status = 'pending'
)
with check (
  (select private.has_permission('borrowing.request'))
  and borrower_id = (select auth.uid())
  and status = 'pending'
);

create policy borrow_request_items_select_own_or_admin
on public.borrow_request_items for select to authenticated
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select auth.uid())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy borrow_request_items_insert_own_pending
on public.borrow_request_items for insert to authenticated
with check (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select auth.uid())
      and br.status = 'pending'
  )
);

create policy borrow_request_items_update_own_pending
on public.borrow_request_items for update to authenticated
using (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select auth.uid())
      and br.status = 'pending'
  )
)
with check (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select auth.uid())
      and br.status = 'pending'
  )
);

create policy borrow_request_items_delete_own_pending
on public.borrow_request_items for delete to authenticated
using (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select auth.uid())
      and br.status = 'pending'
  )
);

create policy borrow_transactions_select_related
on public.borrow_transactions for select to authenticated
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select auth.uid())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy borrow_transactions_admin_insert
on public.borrow_transactions for insert to authenticated
with check ((select private.has_permission('borrowing.manage')));

create policy borrow_transaction_items_select_related
on public.borrow_transaction_items for select to authenticated
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1
    from public.borrow_transactions bt
    join public.borrow_requests br on br.id = bt.borrow_request_id
    where bt.id = transaction_id
      and br.borrower_id = (select auth.uid())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy borrow_transaction_items_admin_insert
on public.borrow_transaction_items for insert to authenticated
with check ((select private.has_permission('borrowing.manage')));

create policy return_transactions_select_related
on public.return_transactions for select to authenticated
using (
  (select private.has_permission('borrowing.manage'))
  or received_by = (select auth.uid())
  or exists (
    select 1
    from public.borrow_transactions bt
    join public.borrow_requests br on br.id = bt.borrow_request_id
    where bt.id = transaction_id
      and br.borrower_id = (select auth.uid())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy return_transactions_staff_receive
on public.return_transactions for insert to authenticated
with check (
  (select private.has_permission('borrowing.return'))
  and received_by = (select auth.uid())
);

create policy return_transaction_items_select_related
on public.return_transaction_items for select to authenticated
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1
    from public.return_transactions rt
    where rt.id = return_id
      and (
        rt.received_by = (select auth.uid())
        or exists (
          select 1
          from public.borrow_transactions bt
          join public.borrow_requests br on br.id = bt.borrow_request_id
          where bt.id = rt.transaction_id
            and br.borrower_id = (select auth.uid())
            and (select private.has_permission('borrowing.view'))
        )
      )
  )
);

create policy return_transaction_items_staff_receive
on public.return_transaction_items for insert to authenticated
with check (
  (select private.has_permission('borrowing.return'))
  and exists (
    select 1 from public.return_transactions rt
    where rt.id = return_id
      and rt.received_by = (select auth.uid())
  )
);

-- Maintenance
create policy maintenance_requests_select_own_or_admin
on public.maintenance_requests for select to authenticated
using (
  (select private.has_permission('maintenance.manage'))
  or (
    (select private.has_permission('maintenance.view'))
    and reported_by = (select auth.uid())
  )
);

create policy maintenance_requests_staff_create
on public.maintenance_requests for insert to authenticated
with check (
  (select private.has_permission('maintenance.create'))
  and reported_by = (select auth.uid())
);

create policy maintenance_requests_admin_manage
on public.maintenance_requests for all to authenticated
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

create policy maintenance_records_select_related
on public.maintenance_records for select to authenticated
using (
  (select private.has_permission('maintenance.manage'))
  or assigned_to = (select auth.uid())
  or exists (
    select 1 from public.maintenance_requests mr
    where mr.id = request_id
      and mr.reported_by = (select auth.uid())
  )
);

create policy maintenance_records_admin_manage
on public.maintenance_records for all to authenticated
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

create policy maintenance_schedules_select
on public.maintenance_schedules for select to authenticated
using ((select private.has_permission('maintenance.view')));

create policy maintenance_schedules_admin_manage
on public.maintenance_schedules for all to authenticated
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

-- Incidents
create policy incident_reports_select_own_or_admin
on public.incident_reports for select to authenticated
using (
  (select private.has_permission('incidents.manage'))
  or (
    (select private.has_permission('incidents.view'))
    and reported_by = (select auth.uid())
  )
);

create policy incident_reports_staff_create
on public.incident_reports for insert to authenticated
with check (
  (select private.has_permission('incidents.create'))
  and reported_by = (select auth.uid())
);

create policy incident_reports_admin_manage
on public.incident_reports for all to authenticated
using ((select private.has_permission('incidents.manage')))
with check ((select private.has_permission('incidents.manage')));

create policy incident_attachments_select_related
on public.incident_attachments for select to authenticated
using (
  (select private.has_permission('incidents.manage'))
  or exists (
    select 1 from public.incident_reports ir
    where ir.id = incident_id
      and ir.reported_by = (select auth.uid())
      and (select private.has_permission('incidents.view'))
  )
);

create policy incident_attachments_staff_create
on public.incident_attachments for insert to authenticated
with check (
  (select private.has_permission('incidents.create'))
  and uploaded_by = (select auth.uid())
  and exists (
    select 1 from public.incident_reports ir
    where ir.id = incident_id
      and ir.reported_by = (select auth.uid())
  )
);

-- Notifications and announcements
create policy notifications_select_own_or_admin
on public.notifications for select to authenticated
using (
  (select private.has_permission('notifications.manage'))
  or (
    (select private.has_permission('notifications.view_own'))
    and user_id = (select auth.uid())
  )
);

create policy notifications_update_own_read_marker
on public.notifications for update to authenticated
using (
  (select private.has_permission('notifications.view_own'))
  and user_id = (select auth.uid())
)
with check (
  (select private.has_permission('notifications.view_own'))
  and user_id = (select auth.uid())
);

create policy notifications_admin_insert
on public.notifications for insert to authenticated
with check ((select private.has_permission('notifications.manage')));

create policy announcements_select_published
on public.announcements for select to authenticated
using (
  (status = 'published' and published_at <= now())
  or (select private.has_permission('notifications.manage'))
);

create policy announcements_admin_manage
on public.announcements for all to authenticated
using ((select private.has_permission('notifications.manage')))
with check (
  (select private.has_permission('notifications.manage'))
  and created_by = (select auth.uid())
);

-- Activity and immutable audit history
create policy activity_logs_select_own_or_audit_admin
on public.activity_logs for select to authenticated
using (
  user_id = (select auth.uid())
  or (select private.has_permission('audit.view'))
);

create policy audit_logs_select_admin
on public.audit_logs for select to authenticated
using ((select private.has_permission('audit.view')));

-- Views use security_invoker=true, so their base-table RLS policies remain effective.
grant select on public.dashboard_metrics, public.inventory_low_stock,
  public.overdue_borrow_requests, public.facility_reservation_summary,
  public.monthly_reservation_trends to authenticated;

-- Prevent future SQL-created public objects from silently receiving API privileges.
alter default privileges for role postgres in schema public
  revoke all on tables from anon, authenticated;
alter default privileges for role postgres in schema public
  revoke all on sequences from anon, authenticated;
alter default privileges for role postgres in schema public
  revoke execute on functions from public, anon, authenticated;
alter default privileges for role postgres in schema private
  revoke execute on functions from public, anon, authenticated;

commit;
