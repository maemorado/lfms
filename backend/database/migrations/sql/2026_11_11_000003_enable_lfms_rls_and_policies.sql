-- Keep the authorization helper outside every API-exposed schema.
-- The private schema and its identity helper are provisioned by the Neon foundation.
REVOKE ALL ON SCHEMA private FROM PUBLIC;
GRANT USAGE ON SCHEMA private TO lfms_app;

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
    where p.id = (select private.current_user_id())
      and p.status = 'active'
      and perm.name = required_permission
  );
$$;

REVOKE ALL ON FUNCTION private.has_permission(text) FROM PUBLIC, lfms_app;
GRANT EXECUTE ON FUNCTION private.has_permission(text) TO lfms_app;

-- Authorization rows are imported later with their original source UUIDs.
-- Do not seed generated role or permission IDs during schema installation.

-- Remove broad default grants before installing the explicit LFMS grants.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM PUBLIC, lfms_app;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM PUBLIC, lfms_app;

-- Authenticated users may query tables, but row policies below decide which rows.
GRANT USAGE ON SCHEMA public TO lfms_app;
GRANT SELECT ON public.activity_logs,
  public.announcements,
  public.audit_logs,
  public.borrow_request_items,
  public.borrow_requests,
  public.borrow_transaction_items,
  public.borrow_transactions,
  public.buildings,
  public.equipment_assets,
  public.facilities,
  public.facility_feature_assignments,
  public.facility_features,
  public.facility_types,
  public.incident_attachments,
  public.incident_reports,
  public.inventory_items,
  public.inventory_locations,
  public.inventory_movements,
  public.inventory_stock,
  public.item_categories,
  public.maintenance_records,
  public.maintenance_requests,
  public.maintenance_schedules,
  public.notifications,
  public.permissions,
  public.profiles,
  public.reservation_approvals,
  public.reservation_attachments,
  public.reservation_participants,
  public.reservations,
  public.return_transaction_items,
  public.return_transactions,
  public.role_permissions,
  public.roles,
  public.user_roles TO lfms_app;

-- RLS is enabled on every application table before policies are created.
ALTER TABLE public.activity_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.announcements ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.borrow_request_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.borrow_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.borrow_transaction_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.borrow_transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.buildings ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.equipment_assets ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.facilities ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.facility_feature_assignments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.facility_features ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.facility_types ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.incident_attachments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.incident_reports ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.inventory_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.inventory_locations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.inventory_movements ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.inventory_stock ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.item_categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.maintenance_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.maintenance_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.maintenance_schedules ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.permissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.reservation_approvals ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.reservation_attachments ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.reservation_participants ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.reservations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.return_transaction_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.return_transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.role_permissions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.roles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.user_roles ENABLE ROW LEVEL SECURITY;

-- Role and permission administration is restricted by RLS to accounts.manage.
grant insert, update, delete on public.roles, public.user_roles,
  public.permissions, public.role_permissions TO lfms_app;

-- Facility administration. Staff receive read-only access through SELECT policies.
grant insert, update, delete on public.buildings, public.facility_types,
  public.facilities, public.facility_features,
  public.facility_feature_assignments TO lfms_app;

-- Reservation requests: users can submit and edit their own pending requests.
-- Status transitions and approvals remain server-side workflow operations.
grant insert on public.reservations TO lfms_app;
grant update (facility_id, start_at, end_at, purpose, subject_course, participant_count)
  on public.reservations TO lfms_app;
grant insert, delete on public.reservation_participants TO lfms_app;
grant insert, delete on public.reservation_attachments TO lfms_app;
grant insert on public.reservation_approvals TO lfms_app;

-- Inventory master data is admin-managed. Stock ledger is intentionally append-only
-- through the trusted backend so every quantity change can be paired with a movement.
grant insert, update, delete on public.item_categories, public.inventory_items,
  public.inventory_locations, public.equipment_assets TO lfms_app;
grant insert on public.inventory_movements TO lfms_app;

-- Borrowing requests can be submitted by staff; releases are recorded by administrators.
grant insert on public.borrow_requests TO lfms_app;
grant update (expected_return_at, remarks) on public.borrow_requests TO lfms_app;
grant insert, update, delete on public.borrow_request_items TO lfms_app;
grant insert on public.borrow_transactions, public.borrow_transaction_items TO lfms_app;
grant insert on public.return_transactions, public.return_transaction_items TO lfms_app;

-- Staff can report issues and receive equipment returns. Work assignment and schedules
-- are administrator-managed.
grant insert on public.maintenance_requests TO lfms_app;
grant insert, update, delete on public.maintenance_records,
  public.maintenance_schedules TO lfms_app;
grant insert on public.incident_reports, public.incident_attachments TO lfms_app;

-- Notifications are immutable except for the recipient's read marker.
grant update (read_at) on public.notifications TO lfms_app;
grant insert on public.notifications TO lfms_app;
grant insert, update, delete on public.announcements TO lfms_app;

-- No direct client writes to activity_logs or audit_logs.
-- Audit rows are written by the existing trigger function.

-- Profiles
create policy profiles_select_self_or_accounts
on public.profiles for select TO lfms_app
using (
  (id = (select private.current_user_id()) and (select private.has_permission('profile.view_own')))
  or (select private.has_permission('accounts.view'))
  or (select private.has_permission('accounts.manage'))
);

create policy profiles_update_self_or_admin
on public.profiles for update TO lfms_app
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
on public.roles for select TO lfms_app
using ((select private.has_permission('profile.view_own')));

create policy roles_admin_manage
on public.roles for all TO lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy permissions_select_active_users
on public.permissions for select TO lfms_app
using ((select private.has_permission('profile.view_own')));

create policy permissions_admin_manage
on public.permissions for all TO lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy user_roles_select_self_or_admin
on public.user_roles for select TO lfms_app
using (
  user_id = (select private.current_user_id())
  or (select private.has_permission('accounts.view'))
  or (select private.has_permission('accounts.manage'))
);

create policy user_roles_admin_manage
on public.user_roles for all TO lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

create policy role_permissions_select_active_users
on public.role_permissions for select TO lfms_app
using ((select private.has_permission('profile.view_own')));

create policy role_permissions_admin_manage
on public.role_permissions for all TO lfms_app
using ((select private.has_permission('accounts.manage')))
with check ((select private.has_permission('accounts.manage')));

-- Facility management
create policy buildings_select_facility_view
on public.buildings for select TO lfms_app
using ((select private.has_permission('facilities.view')));

create policy buildings_admin_manage
on public.buildings for all TO lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_types_select_facility_view
on public.facility_types for select TO lfms_app
using ((select private.has_permission('facilities.view')));

create policy facility_types_admin_manage
on public.facility_types for all TO lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facilities_select_facility_view
on public.facilities for select TO lfms_app
using ((select private.has_permission('facilities.view')));

create policy facilities_admin_manage
on public.facilities for all TO lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_features_select_facility_view
on public.facility_features for select TO lfms_app
using ((select private.has_permission('facilities.view')));

create policy facility_features_admin_manage
on public.facility_features for all TO lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

create policy facility_feature_assignments_select_facility_view
on public.facility_feature_assignments for select TO lfms_app
using ((select private.has_permission('facilities.view')));

create policy facility_feature_assignments_admin_manage
on public.facility_feature_assignments for all TO lfms_app
using ((select private.has_permission('facilities.manage')))
with check ((select private.has_permission('facilities.manage')));

-- Reservations
create policy reservations_select_own_or_approved
on public.reservations for select TO lfms_app
using (
  (select private.has_permission('reservations.manage'))
  or (
    (select private.has_permission('reservations.view'))
    and (requester_id = (select private.current_user_id()) or status = 'approved')
  )
);

create policy reservations_staff_create_own
on public.reservations for insert TO lfms_app
with check (
  (select private.has_permission('reservations.create'))
  and requester_id = (select private.current_user_id())
  and status = 'pending'
);

create policy reservations_staff_update_own_pending
on public.reservations for update TO lfms_app
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
on public.reservations for all TO lfms_app
using ((select private.has_permission('reservations.manage')))
with check ((select private.has_permission('reservations.manage')));

create policy reservation_participants_select_related
on public.reservation_participants for select TO lfms_app
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
on public.reservation_participants for insert TO lfms_app
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
on public.reservation_participants for delete TO lfms_app
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
on public.reservation_approvals for select TO lfms_app
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
on public.reservation_approvals for insert TO lfms_app
with check (
  (select private.has_permission('reservations.manage'))
  and reviewer_id = (select private.current_user_id())
);

create policy reservation_attachments_select_related
on public.reservation_attachments for select TO lfms_app
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
on public.reservation_attachments for insert TO lfms_app
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
on public.reservation_attachments for delete TO lfms_app
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
on public.item_categories for select TO lfms_app
using ((select private.has_permission('inventory.view')));

create policy item_categories_admin_manage
on public.item_categories for all TO lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_items_select_inventory_view
on public.inventory_items for select TO lfms_app
using ((select private.has_permission('inventory.view')));

create policy inventory_items_admin_manage
on public.inventory_items for all TO lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_locations_select_inventory_view
on public.inventory_locations for select TO lfms_app
using ((select private.has_permission('inventory.view')));

create policy inventory_locations_admin_manage
on public.inventory_locations for all TO lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_stock_select_inventory_view
on public.inventory_stock for select TO lfms_app
using ((select private.has_permission('inventory.view')));

create policy equipment_assets_select_inventory_view
on public.equipment_assets for select TO lfms_app
using ((select private.has_permission('inventory.view')));

create policy equipment_assets_admin_manage
on public.equipment_assets for all TO lfms_app
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

create policy inventory_movements_select_inventory_view
on public.inventory_movements for select TO lfms_app
using ((select private.has_permission('inventory.view')));

create policy inventory_movements_admin_insert
on public.inventory_movements for insert TO lfms_app
with check (
  (select private.has_permission('inventory.manage'))
  and performed_by = (select private.current_user_id())
);

-- Borrowing and returns
create policy borrow_requests_select_own_or_admin
on public.borrow_requests for select TO lfms_app
using (
  (select private.has_permission('borrowing.manage'))
  or (
    (select private.has_permission('borrowing.view'))
    and borrower_id = (select private.current_user_id())
  )
);

create policy borrow_requests_staff_create_own
on public.borrow_requests for insert TO lfms_app
with check (
  (select private.has_permission('borrowing.request'))
  and borrower_id = (select private.current_user_id())
  and status = 'pending'
);

create policy borrow_requests_staff_update_own_pending
on public.borrow_requests for update TO lfms_app
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
on public.borrow_request_items for select TO lfms_app
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
on public.borrow_request_items for insert TO lfms_app
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
on public.borrow_request_items for update TO lfms_app
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
on public.borrow_request_items for delete TO lfms_app
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
on public.borrow_transactions for select TO lfms_app
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
on public.borrow_transactions for insert TO lfms_app
with check ((select private.has_permission('borrowing.manage')));

create policy borrow_transaction_items_select_related
on public.borrow_transaction_items for select TO lfms_app
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
on public.borrow_transaction_items for insert TO lfms_app
with check ((select private.has_permission('borrowing.manage')));

create policy return_transactions_select_related
on public.return_transactions for select TO lfms_app
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
on public.return_transactions for insert TO lfms_app
with check (
  (select private.has_permission('borrowing.return'))
  and received_by = (select private.current_user_id())
);

create policy return_transaction_items_select_related
on public.return_transaction_items for select TO lfms_app
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
on public.return_transaction_items for insert TO lfms_app
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
on public.maintenance_requests for select TO lfms_app
using (
  (select private.has_permission('maintenance.manage'))
  or (
    (select private.has_permission('maintenance.view'))
    and reported_by = (select private.current_user_id())
  )
);

create policy maintenance_requests_staff_create
on public.maintenance_requests for insert TO lfms_app
with check (
  (select private.has_permission('maintenance.create'))
  and reported_by = (select private.current_user_id())
);

create policy maintenance_requests_admin_manage
on public.maintenance_requests for all TO lfms_app
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

create policy maintenance_records_select_related
on public.maintenance_records for select TO lfms_app
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
on public.maintenance_records for all TO lfms_app
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

create policy maintenance_schedules_select
on public.maintenance_schedules for select TO lfms_app
using ((select private.has_permission('maintenance.view')));

create policy maintenance_schedules_admin_manage
on public.maintenance_schedules for all TO lfms_app
using ((select private.has_permission('maintenance.manage')))
with check ((select private.has_permission('maintenance.manage')));

-- Incidents
create policy incident_reports_select_own_or_admin
on public.incident_reports for select TO lfms_app
using (
  (select private.has_permission('incidents.manage'))
  or (
    (select private.has_permission('incidents.view'))
    and reported_by = (select private.current_user_id())
  )
);

create policy incident_reports_staff_create
on public.incident_reports for insert TO lfms_app
with check (
  (select private.has_permission('incidents.create'))
  and reported_by = (select private.current_user_id())
);

create policy incident_reports_admin_manage
on public.incident_reports for all TO lfms_app
using ((select private.has_permission('incidents.manage')))
with check ((select private.has_permission('incidents.manage')));

create policy incident_attachments_select_related
on public.incident_attachments for select TO lfms_app
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
on public.incident_attachments for insert TO lfms_app
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
on public.notifications for select TO lfms_app
using (
  (select private.has_permission('notifications.manage'))
  or (
    (select private.has_permission('notifications.view_own'))
    and user_id = (select private.current_user_id())
  )
);

create policy notifications_update_own_read_marker
on public.notifications for update TO lfms_app
using (
  (select private.has_permission('notifications.view_own'))
  and user_id = (select private.current_user_id())
)
with check (
  (select private.has_permission('notifications.view_own'))
  and user_id = (select private.current_user_id())
);

create policy notifications_admin_insert
on public.notifications for insert TO lfms_app
with check ((select private.has_permission('notifications.manage')));

create policy announcements_select_published
on public.announcements for select TO lfms_app
using (
  (status = 'published' and published_at <= now())
  or (select private.has_permission('notifications.manage'))
);

create policy announcements_admin_manage
on public.announcements for all TO lfms_app
using ((select private.has_permission('notifications.manage')))
with check (
  (select private.has_permission('notifications.manage'))
  and created_by = (select private.current_user_id())
);

-- Activity and immutable audit history
create policy activity_logs_select_own_or_audit_admin
on public.activity_logs for select TO lfms_app
using (
  user_id = (select private.current_user_id())
  or (select private.has_permission('audit.view'))
);

create policy audit_logs_select_admin
on public.audit_logs for select TO lfms_app
using ((select private.has_permission('audit.view')));

-- Views use security_invoker=true, so their base-table RLS policies remain effective.
grant select on public.dashboard_metrics, public.inventory_low_stock,
  public.overdue_borrow_requests, public.facility_reservation_summary,
  public.monthly_reservation_trends TO lfms_app;


-- Policies introduced after the original 77-policy baseline.
CREATE POLICY inventory_stock_select_inventory_manage
ON public.inventory_stock FOR SELECT TO lfms_app
USING ((SELECT private.has_permission('inventory.manage')));

CREATE POLICY inventory_stock_insert_inventory_manage
ON public.inventory_stock FOR INSERT TO lfms_app
WITH CHECK ((SELECT private.has_permission('inventory.manage')));

CREATE POLICY inventory_stock_update_inventory_manage
ON public.inventory_stock FOR UPDATE TO lfms_app
USING ((SELECT private.has_permission('inventory.manage')))
WITH CHECK ((SELECT private.has_permission('inventory.manage')));

CREATE POLICY borrow_requests_admin_manage_update
ON public.borrow_requests FOR UPDATE TO lfms_app
USING ((SELECT private.has_permission('borrowing.manage')))
WITH CHECK ((SELECT private.has_permission('borrowing.manage')));

CREATE POLICY borrow_requests_cancel_own_pending
ON public.borrow_requests FOR UPDATE TO lfms_app
USING (
  (SELECT private.has_permission('borrowing.request'))
  AND borrower_id = (SELECT private.current_user_id())
  AND status = 'pending'
)
WITH CHECK (
  (SELECT private.has_permission('borrowing.request'))
  AND borrower_id = (SELECT private.current_user_id())
  AND status = 'cancelled'
);

GRANT INSERT, UPDATE ON public.inventory_stock TO lfms_app;
GRANT UPDATE (full_name) ON public.profiles TO lfms_app;

-- The app role can call only the approved business RPCs and identity helpers.
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM PUBLIC, lfms_app;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA private FROM PUBLIC, lfms_app;
GRANT EXECUTE ON FUNCTION private.current_user_id() TO lfms_app;
GRANT EXECUTE ON FUNCTION private.has_permission(text) TO lfms_app;
GRANT EXECUTE ON FUNCTION public.apply_inventory_stock_movement(uuid, uuid, text, numeric, text, text, uuid) TO lfms_app;
GRANT EXECUTE ON FUNCTION public.create_borrow_request(timestamptz, text, jsonb, uuid) TO lfms_app;
GRANT EXECUTE ON FUNCTION public.review_borrow_request(uuid, text, text) TO lfms_app;
GRANT EXECUTE ON FUNCTION public.cancel_borrow_request(uuid) TO lfms_app;
GRANT EXECUTE ON FUNCTION public.release_borrow_request(uuid, jsonb, text) TO lfms_app;
GRANT EXECUTE ON FUNCTION public.process_borrow_return(uuid, jsonb, text) TO lfms_app;

-- Prevent future SQL-created public objects from silently receiving API privileges.
ALTER DEFAULT PRIVILEGES FOR ROLE neondb_owner in schema public
  REVOKE ALL ON TABLES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE neondb_owner in schema public
  REVOKE ALL ON SEQUENCES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE neondb_owner in schema public
  REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE neondb_owner in schema private
  REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
