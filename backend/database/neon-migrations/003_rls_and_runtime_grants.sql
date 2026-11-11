-- LFMS Neon RLS and runtime grants.
-- All 82 application policies are translated from the live final Supabase policy set.
-- Supabase roles are replaced by the non-login lfms_app group role.

alter table public.profiles enable row level security;
alter table public.roles enable row level security;
alter table public.user_roles enable row level security;
alter table public.permissions enable row level security;
alter table public.role_permissions enable row level security;
alter table public.buildings enable row level security;
alter table public.facility_types enable row level security;
alter table public.facilities enable row level security;
alter table public.facility_features enable row level security;
alter table public.facility_feature_assignments enable row level security;
alter table public.reservations enable row level security;
alter table public.reservation_participants enable row level security;
alter table public.reservation_approvals enable row level security;
alter table public.reservation_attachments enable row level security;
alter table public.item_categories enable row level security;
alter table public.inventory_items enable row level security;
alter table public.inventory_locations enable row level security;
alter table public.inventory_stock enable row level security;
alter table public.equipment_assets enable row level security;
alter table public.inventory_movements enable row level security;
alter table public.borrow_requests enable row level security;
alter table public.borrow_request_items enable row level security;
alter table public.borrow_transactions enable row level security;
alter table public.borrow_transaction_items enable row level security;
alter table public.return_transactions enable row level security;
alter table public.return_transaction_items enable row level security;
alter table public.maintenance_requests enable row level security;
alter table public.maintenance_records enable row level security;
alter table public.maintenance_schedules enable row level security;
alter table public.incident_reports enable row level security;
alter table public.incident_attachments enable row level security;
alter table public.notifications enable row level security;
alter table public.announcements enable row level security;
alter table public.activity_logs enable row level security;
alter table public.audit_logs enable row level security;
-- Keep the authorization helper outside every API-exposed schema.

revoke all on schema private from public, lfms_app;




revoke all on function private.has_permission(text) from public, lfms_app;
grant execute on function private.has_permission(text) to lfms_app;

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
revoke all on all tables in schema public from lfms_app;
revoke all on all sequences in schema public from lfms_app;

-- Authenticated users may query tables, but row policies below decide which rows.
grant usage on schema public to lfms_app;
grant select on all tables in schema public to lfms_app;

-- Role and permission administration is restricted by RLS to accounts.manage.
grant insert, update, delete on public.roles, public.user_roles,
  public.permissions, public.role_permissions to lfms_app;

-- Facility administration. Staff receive read-only access through SELECT policies.
grant insert, update, delete on public.buildings, public.facility_types,
  public.facilities, public.facility_features,
  public.facility_feature_assignments to lfms_app;

-- Reservation requests: users can submit and edit their own pending requests.
-- Status transitions and approvals remain server-side workflow operations.
grant insert on public.reservations to lfms_app;
grant update (facility_id, start_at, end_at, purpose, subject_course, participant_count)
  on public.reservations to lfms_app;
grant insert, delete on public.reservation_participants to lfms_app;
grant insert, delete on public.reservation_attachments to lfms_app;
grant insert on public.reservation_approvals to lfms_app;

-- Inventory master data is admin-managed. Stock ledger is intentionally append-only
-- through the trusted backend so every quantity change can be paired with a movement.
grant insert, update, delete on public.item_categories, public.inventory_items,
  public.inventory_locations, public.equipment_assets to lfms_app;
grant insert on public.inventory_movements to lfms_app;

-- Borrowing requests can be submitted by staff; releases are recorded by administrators.
grant insert on public.borrow_requests to lfms_app;
grant update (expected_return_at, remarks) on public.borrow_requests to lfms_app;
grant insert, update, delete on public.borrow_request_items to lfms_app;
grant insert on public.borrow_transactions, public.borrow_transaction_items to lfms_app;
grant insert on public.return_transactions, public.return_transaction_items to lfms_app;

-- Staff can report issues and receive equipment returns. Work assignment and schedules
-- are administrator-managed.
grant insert on public.maintenance_requests to lfms_app;
grant insert, update, delete on public.maintenance_records,
  public.maintenance_schedules to lfms_app;
grant insert on public.incident_reports, public.incident_attachments to lfms_app;

-- Notifications are immutable except for the recipient's read marker.
grant update (read_at) on public.notifications to lfms_app;
grant insert on public.notifications to lfms_app;
grant insert, update, delete on public.announcements to lfms_app;

-- No direct client writes to activity_logs or audit_logs.
-- Audit rows are written by the existing trigger function.

-- Profiles
create policy profiles_select_self_or_accounts
on public.profiles for select to lfms_app
using (
  (id = (select private.current_user_id()) and (select private.has_permission('profile.view_own')))
  or (select private.has_permission('accounts.view'))
  or (select private.has_permission('accounts.manage'))
);

create policy profiles_update_self_or_admin
on public.profiles for update to lfms_app
using (
  (id = (select private.current_user_id()) and (select private.has_permission('profile.update_own')))
  or (select private.has_permission('accounts.manage'))
)
with check (
  (id = (select private.current_user_id()) and (select private.has_permission('profile.update_own')))
  or (select private.has_permission('accounts.manage'))
);

-- Roles and permissions are readable to active application users; only account
-- administrators can modify role assignments or permission definitions.
create policy roles_select_active_users
on public.roles for select to lfms_app
using ((select private.has_permission('profile.view_own')));

create policy roles_admin_manage
on public.roles for all to lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy permissions_select_active_users
on public.permissions for select to lfms_app
using ((select private.has_permission('profile.view_own')));

create policy permissions_admin_manage
on public.permissions for all to lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy user_roles_select_self_or_admin
on public.user_roles for select to lfms_app
using (
  user_id = (select private.current_user_id())
  or (select private.has_permission('accounts.view'))
  or (select private.has_permission('accounts.manage'))
);

create policy user_roles_admin_manage
on public.user_roles for all to lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy role_permissions_select_active_users
on public.role_permissions for select to lfms_app
using ((select private.has_permission('profile.view_own')));

create policy role_permissions_admin_manage
on public.role_permissions for all to lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

-- Facility management
create policy buildings_select_facility_view
on public.buildings for select to lfms_app
using ((select private.has_permission('facilities.view')));

create policy buildings_admin_manage
on public.buildings for all to lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_types_select_facility_view
on public.facility_types for select to lfms_app
using ((select private.has_permission('facilities.view')));

create policy facility_types_admin_manage
on public.facility_types for all to lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facilities_select_facility_view
on public.facilities for select to lfms_app
using ((select private.has_permission('facilities.view')));

create policy facilities_admin_manage
on public.facilities for all to lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_features_select_facility_view
on public.facility_features for select to lfms_app
using ((select private.has_permission('facilities.view')));

create policy facility_features_admin_manage
on public.facility_features for all to lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_feature_assignments_select_facility_view
on public.facility_feature_assignments for select to lfms_app
using ((select private.has_permission('facilities.view')));

create policy facility_feature_assignments_admin_manage
on public.facility_feature_assignments for all to lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

-- Reservations
create policy reservations_select_own_or_approved
on public.reservations for select to lfms_app
using (
  (select private.has_permission('reservations.manage'))
  or (
    (select private.has_permission('reservations.view'))
    and (requester_id = (select private.current_user_id()) or status = 'approved')
  )
);

create policy reservations_staff_create_own
on public.reservations for insert to lfms_app
with check (
  (select private.has_permission('reservations.create'))
  and requester_id = (select private.current_user_id())
  and status = 'pending'
);

create policy reservations_staff_update_own_pending
on public.reservations for update to lfms_app
using (
  (select private.has_permission('reservations.create'))
  and requester_id = (select private.current_user_id())
  and status = 'pending'
)
with check (
  (select private.has_permission('reservations.create'))
  and requester_id = (select private.current_user_id())
  and status = 'pending'
);

create policy reservations_admin_manage
on public.reservations for all to lfms_app
using ((select private.has_permission('reservations.manage')))
with check ((select private.has_permission('reservations.manage')));

create policy reservation_participants_select_related
on public.reservation_participants for select to lfms_app
using (
  (select private.has_permission('reservations.manage'))
  or exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and (
        r.requester_id = (select private.current_user_id())
        or r.status = 'approved'
      )
      and (select private.has_permission('reservations.view'))
  )
);

create policy reservation_participants_insert_own_pending
on public.reservation_participants for insert to lfms_app
with check (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select private.current_user_id())
      and r.status = 'pending'
  )
);

create policy reservation_participants_delete_own_pending
on public.reservation_participants for delete to lfms_app
using (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select private.current_user_id())
      and r.status = 'pending'
  )
);

create policy reservation_approvals_select_related
on public.reservation_approvals for select to lfms_app
using (
  (select private.has_permission('reservations.manage'))
  or exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select private.current_user_id())
      and (select private.has_permission('reservations.view'))
  )
);

create policy reservation_approvals_admin_insert
on public.reservation_approvals for insert to lfms_app
with check (
  (select private.has_permission('reservations.manage'))
  and reviewer_id = (select private.current_user_id())
);

create policy reservation_attachments_select_related
on public.reservation_attachments for select to lfms_app
using (
  (select private.has_permission('reservations.manage'))
  or exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select private.current_user_id())
      and (select private.has_permission('reservations.view'))
  )
);

create policy reservation_attachments_insert_own_pending
on public.reservation_attachments for insert to lfms_app
with check (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select private.current_user_id())
      and r.status = 'pending'
  )
);

create policy reservation_attachments_delete_own_pending
on public.reservation_attachments for delete to lfms_app
using (
  (select private.has_permission('reservations.create'))
  and exists (
    select 1 from public.reservations r
    where r.id = reservation_id
      and r.requester_id = (select private.current_user_id())
      and r.status = 'pending'
  )
);

-- Inventory
create policy item_categories_select_inventory_view
on public.item_categories for select to lfms_app
using ((select private.has_permission('inventory.view')));

create policy item_categories_admin_manage
on public.item_categories for all to lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_items_select_inventory_view
on public.inventory_items for select to lfms_app
using ((select private.has_permission('inventory.view')));

create policy inventory_items_admin_manage
on public.inventory_items for all to lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_locations_select_inventory_view
on public.inventory_locations for select to lfms_app
using ((select private.has_permission('inventory.view')));

create policy inventory_locations_admin_manage
on public.inventory_locations for all to lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_stock_select_inventory_view
on public.inventory_stock for select to lfms_app
using ((select private.has_permission('inventory.view')));

create policy equipment_assets_select_inventory_view
on public.equipment_assets for select to lfms_app
using ((select private.has_permission('inventory.view')));

create policy equipment_assets_admin_manage
on public.equipment_assets for all to lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_movements_select_inventory_view
on public.inventory_movements for select to lfms_app
using ((select private.has_permission('inventory.view')));

create policy inventory_movements_admin_insert
on public.inventory_movements for insert to lfms_app
with check (
  (select private.has_permission('inventory.manage'))
  and performed_by = (select private.current_user_id())
);

-- Borrowing and returns
create policy borrow_requests_select_own_or_admin
on public.borrow_requests for select to lfms_app
using (
  (select private.has_permission('borrowing.manage'))
  or (
    (select private.has_permission('borrowing.view'))
    and borrower_id = (select private.current_user_id())
  )
);

create policy borrow_requests_staff_create_own
on public.borrow_requests for insert to lfms_app
with check (
  (select private.has_permission('borrowing.request'))
  and borrower_id = (select private.current_user_id())
  and status = 'pending'
);

create policy borrow_requests_staff_update_own_pending
on public.borrow_requests for update to lfms_app
using (
  (select private.has_permission('borrowing.request'))
  and borrower_id = (select private.current_user_id())
  and status = 'pending'
)
with check (
  (select private.has_permission('borrowing.request'))
  and borrower_id = (select private.current_user_id())
  and status = 'pending'
);

create policy borrow_request_items_select_own_or_admin
on public.borrow_request_items for select to lfms_app
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select private.current_user_id())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy borrow_request_items_insert_own_pending
on public.borrow_request_items for insert to lfms_app
with check (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select private.current_user_id())
      and br.status = 'pending'
  )
);

create policy borrow_request_items_update_own_pending
on public.borrow_request_items for update to lfms_app
using (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select private.current_user_id())
      and br.status = 'pending'
  )
)
with check (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select private.current_user_id())
      and br.status = 'pending'
  )
);

create policy borrow_request_items_delete_own_pending
on public.borrow_request_items for delete to lfms_app
using (
  (select private.has_permission('borrowing.request'))
  and exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select private.current_user_id())
      and br.status = 'pending'
  )
);

create policy borrow_transactions_select_related
on public.borrow_transactions for select to lfms_app
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1 from public.borrow_requests br
    where br.id = borrow_request_id
      and br.borrower_id = (select private.current_user_id())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy borrow_transactions_admin_insert
on public.borrow_transactions for insert to lfms_app
with check ((select private.has_permission('borrowing.manage')));

create policy borrow_transaction_items_select_related
on public.borrow_transaction_items for select to lfms_app
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1
    from public.borrow_transactions bt
    join public.borrow_requests br on br.id = bt.borrow_request_id
    where bt.id = transaction_id
      and br.borrower_id = (select private.current_user_id())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy borrow_transaction_items_admin_insert
on public.borrow_transaction_items for insert to lfms_app
with check ((select private.has_permission('borrowing.manage')));

create policy return_transactions_select_related
on public.return_transactions for select to lfms_app
using (
  (select private.has_permission('borrowing.manage'))
  or received_by = (select private.current_user_id())
  or exists (
    select 1
    from public.borrow_transactions bt
    join public.borrow_requests br on br.id = bt.borrow_request_id
    where bt.id = transaction_id
      and br.borrower_id = (select private.current_user_id())
      and (select private.has_permission('borrowing.view'))
  )
);

create policy return_transactions_staff_receive
on public.return_transactions for insert to lfms_app
with check (
  (select private.has_permission('borrowing.return'))
  and received_by = (select private.current_user_id())
);

create policy return_transaction_items_select_related
on public.return_transaction_items for select to lfms_app
using (
  (select private.has_permission('borrowing.manage'))
  or exists (
    select 1
    from public.return_transactions rt
    where rt.id = return_id
      and (
        rt.received_by = (select private.current_user_id())
        or exists (
          select 1
          from public.borrow_transactions bt
          join public.borrow_requests br on br.id = bt.borrow_request_id
          where bt.id = rt.transaction_id
            and br.borrower_id = (select private.current_user_id())
            and (select private.has_permission('borrowing.view'))
        )
      )
  )
);

create policy return_transaction_items_staff_receive
on public.return_transaction_items for insert to lfms_app
with check (
  (select private.has_permission('borrowing.return'))
  and exists (
    select 1 from public.return_transactions rt
    where rt.id = return_id
      and rt.received_by = (select private.current_user_id())
  )
);

-- Maintenance
create policy maintenance_requests_select_own_or_admin
on public.maintenance_requests for select to lfms_app
using (
  (select private.has_permission('maintenance.manage'))
  or (
    (select private.has_permission('maintenance.view'))
    and reported_by = (select private.current_user_id())
  )
);

create policy maintenance_requests_staff_create
on public.maintenance_requests for insert to lfms_app
with check (
  (select private.has_permission('maintenance.create'))
  and reported_by = (select private.current_user_id())
);

create policy maintenance_requests_admin_manage
on public.maintenance_requests for all to lfms_app
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

create policy maintenance_records_select_related
on public.maintenance_records for select to lfms_app
using (
  (select private.has_permission('maintenance.manage'))
  or assigned_to = (select private.current_user_id())
  or exists (
    select 1 from public.maintenance_requests mr
    where mr.id = request_id
      and mr.reported_by = (select private.current_user_id())
  )
);

create policy maintenance_records_admin_manage
on public.maintenance_records for all to lfms_app
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

create policy maintenance_schedules_select
on public.maintenance_schedules for select to lfms_app
using ((select private.has_permission('maintenance.view')));

create policy maintenance_schedules_admin_manage
on public.maintenance_schedules for all to lfms_app
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

-- Incidents
create policy incident_reports_select_own_or_admin
on public.incident_reports for select to lfms_app
using (
  (select private.has_permission('incidents.manage'))
  or (
    (select private.has_permission('incidents.view'))
    and reported_by = (select private.current_user_id())
  )
);

create policy incident_reports_staff_create
on public.incident_reports for insert to lfms_app
with check (
  (select private.has_permission('incidents.create'))
  and reported_by = (select private.current_user_id())
);

create policy incident_reports_admin_manage
on public.incident_reports for all to lfms_app
using ((select private.has_permission('incidents.manage')))
with check ((select private.has_permission('incidents.manage')));

create policy incident_attachments_select_related
on public.incident_attachments for select to lfms_app
using (
  (select private.has_permission('incidents.manage'))
  or exists (
    select 1 from public.incident_reports ir
    where ir.id = incident_id
      and ir.reported_by = (select private.current_user_id())
      and (select private.has_permission('incidents.view'))
  )
);

create policy incident_attachments_staff_create
on public.incident_attachments for insert to lfms_app
with check (
  (select private.has_permission('incidents.create'))
  and uploaded_by = (select private.current_user_id())
  and exists (
    select 1 from public.incident_reports ir
    where ir.id = incident_id
      and ir.reported_by = (select private.current_user_id())
  )
);

-- Notifications and announcements
create policy notifications_select_own_or_admin
on public.notifications for select to lfms_app
using (
  (select private.has_permission('notifications.manage'))
  or (
    (select private.has_permission('notifications.view_own'))
    and user_id = (select private.current_user_id())
  )
);

create policy notifications_update_own_read_marker
on public.notifications for update to lfms_app
using (
  (select private.has_permission('notifications.view_own'))
  and user_id = (select private.current_user_id())
)
with check (
  (select private.has_permission('notifications.view_own'))
  and user_id = (select private.current_user_id())
);

create policy notifications_admin_insert
on public.notifications for insert to lfms_app
with check ((select private.has_permission('notifications.manage')));

create policy announcements_select_published
on public.announcements for select to lfms_app
using (
  (status = 'published' and published_at <= now())
  or (select private.has_permission('notifications.manage'))
);

create policy announcements_admin_manage
on public.announcements for all to lfms_app
using ((select private.has_permission('notifications.manage')))
with check (
  (select private.has_permission('notifications.manage'))
  and created_by = (select private.current_user_id())
);

-- Activity and immutable audit history
create policy activity_logs_select_own_or_audit_admin
on public.activity_logs for select to lfms_app
using (
  user_id = (select private.current_user_id())
  or (select private.has_permission('audit.view'))
);

create policy audit_logs_select_admin
on public.audit_logs for select to lfms_app
using ((select private.has_permission('audit.view')));

-- Views use security_invoker=true, so their base-table RLS policies remain effective.
-- Reporting views are created and granted in 004_reporting_views.sql.

-- Prevent future SQL-created public objects from silently receiving API privileges.
alter default privileges for role neondb_owner in schema public
  revoke all on tables from lfms_app;
alter default privileges for role neondb_owner in schema public
  revoke all on sequences from lfms_app;
alter default privileges for role neondb_owner in schema public
  revoke execute on functions from public, lfms_app;
alter default privileges for role neondb_owner in schema private
  revoke execute on functions from public, lfms_app;

-- Atomic stock-write policies added after the original role-policy migration.
create policy inventory_stock_select_inventory_manage
on public.inventory_stock
for select
to lfms_app
using ((select private.has_permission('inventory.manage')));

create policy inventory_stock_insert_inventory_manage
on public.inventory_stock
for insert
to lfms_app
with check ((select private.has_permission('inventory.manage')));

create policy inventory_stock_update_inventory_manage
on public.inventory_stock
for update
to lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

grant insert, update on public.inventory_stock to lfms_app;

-- Borrowing workflow policy additions from the later source migration.
create policy borrow_requests_admin_manage_update
  on public.borrow_requests
  for update to lfms_app
  using ((select private.has_permission('borrowing.manage')))
  with check ((select private.has_permission('borrowing.manage')));

create policy borrow_requests_cancel_own_pending
  on public.borrow_requests
  for update to lfms_app
  using (
    (select private.has_permission('borrowing.request'))
    and borrower_id = (select private.current_user_id())
    and status = 'pending'
  )
  with check (
    (select private.has_permission('borrowing.request'))
    and borrower_id = (select private.current_user_id())
    and status = 'cancelled'
  );

-- Column-level self-profile update grant retained from source migration 10.1.
grant update (full_name) on public.profiles to lfms_app;
