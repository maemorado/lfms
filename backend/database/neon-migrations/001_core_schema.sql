-- LFMS Neon baseline schema
-- Generated from the repository's original Supabase migration history and reconciled to the live final schema.
-- Target: Neon PostgreSQL. This file is intentionally DDL-only; it does not copy source data.
-- Supabase Auth identity is represented by profiles.id (same UUID), without a cross-database FK.
-- RLS is enabled and policies/grants are installed in a later migration.

create extension if not exists btree_gist;

-- Source: supabase/migrations/20261107115951_phase_1_identity_and_access_management.sql
create table public.profiles (
  id uuid primary key,
  full_name text not null,
  email text not null,
  employee_id text,
  status text not null default 'active'
    check (status in ('active', 'inactive', 'suspended')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint profiles_email_key unique (email),
  constraint profiles_employee_id_key unique (employee_id),
  constraint profiles_full_name_not_blank check (btrim(full_name) <> '')
);

create table public.roles (
  id uuid primary key default gen_random_uuid(),
  name text not null unique,
  description text,
  created_at timestamptz not null default now(),
  constraint roles_name_format check (name ~ '^[a-z][a-z0-9_]*$')
);

create table public.user_roles (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.profiles(id) on delete cascade,
  role_id uuid not null references public.roles(id) on delete restrict,
  created_at timestamptz not null default now(),
  constraint user_roles_user_role_key unique (user_id, role_id)
);

create table public.permissions (
  id uuid primary key default gen_random_uuid(),
  name text not null unique,
  description text,
  constraint permissions_name_format check (name ~ '^[a-z][a-z0-9_.]*$')
);

create table public.role_permissions (
  id uuid primary key default gen_random_uuid(),
  role_id uuid not null references public.roles(id) on delete cascade,
  permission_id uuid not null references public.permissions(id) on delete cascade,
  created_at timestamptz not null default now(),
  constraint role_permissions_role_permission_key unique (role_id, permission_id)
);

create index user_roles_user_id_idx on public.user_roles(user_id);
create index user_roles_role_id_idx on public.user_roles(role_id);
create index role_permissions_role_id_idx on public.role_permissions(role_id);
create index role_permissions_permission_id_idx on public.role_permissions(permission_id);

-- Source: supabase/migrations/20261107120028_phase_2_facility_management.sql
create table public.buildings (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  location text,
  description text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint buildings_name_not_blank check (btrim(name) <> ''),
  constraint buildings_name_key unique (name)
);

create table public.facility_types (
  id uuid primary key default gen_random_uuid(),
  name text not null unique,
  description text,
  is_active boolean not null default true,
  created_at timestamptz not null default now(),
  constraint facility_types_name_not_blank check (btrim(name) <> '')
);

create table public.facilities (
  id uuid primary key default gen_random_uuid(),
  building_id uuid not null references public.buildings(id) on delete restrict,
  facility_type_id uuid not null references public.facility_types(id) on delete restrict,
  name text not null,
  code text not null unique,
  capacity integer not null default 1,
  description text,
  status text not null default 'available'
    check (status in ('available', 'unavailable', 'maintenance', 'inactive')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint facilities_name_not_blank check (btrim(name) <> ''),
  constraint facilities_code_not_blank check (btrim(code) <> ''),
  constraint facilities_capacity_positive check (capacity > 0)
);

create table public.facility_features (
  id uuid primary key default gen_random_uuid(),
  name text not null unique,
  description text,
  created_at timestamptz not null default now(),
  constraint facility_features_name_not_blank check (btrim(name) <> '')
);

create table public.facility_feature_assignments (
  id uuid primary key default gen_random_uuid(),
  facility_id uuid not null references public.facilities(id) on delete cascade,
  feature_id uuid not null references public.facility_features(id) on delete restrict,
  created_at timestamptz not null default now(),
  constraint facility_feature_assignments_unique unique (facility_id, feature_id)
);

create index facilities_building_id_idx on public.facilities(building_id);
create index facilities_facility_type_id_idx on public.facilities(facility_type_id);
create index facilities_status_idx on public.facilities(status);
create index facility_feature_assignments_feature_id_idx
  on public.facility_feature_assignments(feature_id);

-- Source: supabase/migrations/20261107120605_phase_3_reservations_and_scheduling.sql
create table public.reservations (
  id uuid primary key default gen_random_uuid(),
  facility_id uuid not null references public.facilities(id) on delete restrict,
  requester_id uuid not null references public.profiles(id) on delete restrict,
  start_at timestamptz not null,
  end_at timestamptz not null,
  purpose text not null,
  subject_course text,
  participant_count integer not null default 1,
  status text not null default 'pending'
    check (status in ('pending', 'approved', 'rejected', 'cancelled', 'completed')),
  cancellation_reason text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint reservations_valid_period check (end_at > start_at),
  constraint reservations_purpose_not_blank check (btrim(purpose) <> ''),
  constraint reservations_participant_count_positive check (participant_count > 0),
  constraint reservations_cancellation_reason_required check (
    status <> 'cancelled' or cancellation_reason is not null
  ),
  constraint reservations_no_approved_overlap
    exclude using gist (
      facility_id with =,
      tstzrange(start_at, end_at, '[)') with &&
    )
    where (status = 'approved')
);

create table public.reservation_participants (
  id uuid primary key default gen_random_uuid(),
  reservation_id uuid not null references public.reservations(id) on delete cascade,
  user_id uuid not null references public.profiles(id) on delete restrict,
  created_at timestamptz not null default now(),
  constraint reservation_participants_unique unique (reservation_id, user_id)
);

create table public.reservation_approvals (
  id uuid primary key default gen_random_uuid(),
  reservation_id uuid not null references public.reservations(id) on delete cascade,
  reviewer_id uuid not null references public.profiles(id) on delete restrict,
  decision text not null check (decision in ('approved', 'rejected')),
  remarks text,
  reviewed_at timestamptz not null default now()
);

create table public.reservation_attachments (
  id uuid primary key default gen_random_uuid(),
  reservation_id uuid not null references public.reservations(id) on delete cascade,
  file_path text not null,
  file_name text not null,
  uploaded_at timestamptz not null default now(),
  constraint reservation_attachments_path_not_blank check (btrim(file_path) <> ''),
  constraint reservation_attachments_name_not_blank check (btrim(file_name) <> '')
);

create index reservations_facility_start_idx on public.reservations(facility_id, start_at);
create index reservations_requester_created_idx on public.reservations(requester_id, created_at desc);
create index reservations_status_start_idx on public.reservations(status, start_at);
create index reservation_participants_user_id_idx on public.reservation_participants(user_id);
create index reservation_approvals_reservation_reviewed_idx on public.reservation_approvals(reservation_id, reviewed_at desc);
create index reservation_approvals_reviewer_idx on public.reservation_approvals(reviewer_id);
create index reservation_attachments_reservation_id_idx on public.reservation_attachments(reservation_id);

-- Source: supabase/migrations/20261107120628_phase_4_inventory_management.sql
create table public.item_categories (
  id uuid primary key default gen_random_uuid(),
  name text not null unique,
  description text,
  created_at timestamptz not null default now(),
  constraint item_categories_name_not_blank check (btrim(name) <> '')
);

create table public.inventory_items (
  id uuid primary key default gen_random_uuid(),
  category_id uuid references public.item_categories(id) on delete restrict,
  name text not null,
  item_code text not null unique,
  item_type text not null check (item_type in ('consumable', 'equipment')),
  unit text not null default 'unit',
  description text,
  minimum_stock numeric(12,3) not null default 0,
  status text not null default 'active' check (status in ('active', 'inactive', 'discontinued')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint inventory_items_name_not_blank check (btrim(name) <> ''),
  constraint inventory_items_code_not_blank check (btrim(item_code) <> ''),
  constraint inventory_items_unit_not_blank check (btrim(unit) <> ''),
  constraint inventory_items_minimum_stock_nonnegative check (minimum_stock >= 0)
);

create table public.inventory_locations (
  id uuid primary key default gen_random_uuid(),
  facility_id uuid references public.facilities(id) on delete restrict,
  name text not null,
  description text,
  created_at timestamptz not null default now(),
  constraint inventory_locations_name_not_blank check (btrim(name) <> ''),
  constraint inventory_locations_facility_name_unique unique (facility_id, name)
);

create table public.inventory_stock (
  id uuid primary key default gen_random_uuid(),
  item_id uuid not null references public.inventory_items(id) on delete restrict,
  location_id uuid not null references public.inventory_locations(id) on delete restrict,
  quantity numeric(12,3) not null default 0,
  updated_at timestamptz not null default now(),
  constraint inventory_stock_nonnegative check (quantity >= 0),
  constraint inventory_stock_item_location_unique unique (item_id, location_id)
);

create table public.equipment_assets (
  id uuid primary key default gen_random_uuid(),
  item_id uuid not null references public.inventory_items(id) on delete restrict,
  asset_tag text not null unique,
  serial_number text unique,
  condition text not null default 'good'
    check (condition in ('new', 'good', 'fair', 'poor', 'damaged', 'missing')),
  status text not null default 'available'
    check (status in ('available', 'borrowed', 'damaged', 'missing', 'under_repair', 'retired')),
  location_id uuid references public.inventory_locations(id) on delete restrict,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint equipment_assets_tag_not_blank check (btrim(asset_tag) <> '')
);

create table public.inventory_movements (
  id uuid primary key default gen_random_uuid(),
  item_id uuid not null references public.inventory_items(id) on delete restrict,
  location_id uuid not null references public.inventory_locations(id) on delete restrict,
  movement_type text not null
    check (movement_type in ('stock_in', 'stock_out', 'adjustment_in', 'adjustment_out', 'transfer_in', 'transfer_out')),
  quantity numeric(12,3) not null,
  reference_type text,
  reference_id uuid,
  performed_by uuid not null references public.profiles(id) on delete restrict,
  remarks text,
  created_at timestamptz not null default now(),
  constraint inventory_movements_quantity_positive check (quantity > 0)
);

create index inventory_items_category_id_idx on public.inventory_items(category_id);
create index inventory_items_type_status_idx on public.inventory_items(item_type, status);
create index inventory_locations_facility_id_idx on public.inventory_locations(facility_id);
create index inventory_stock_location_id_idx on public.inventory_stock(location_id);
create index equipment_assets_item_id_idx on public.equipment_assets(item_id);
create index equipment_assets_location_status_idx on public.equipment_assets(location_id, status);
create index equipment_assets_status_idx on public.equipment_assets(status);
create index inventory_movements_item_created_idx on public.inventory_movements(item_id, created_at desc);
create index inventory_movements_location_created_idx on public.inventory_movements(location_id, created_at desc);
create index inventory_movements_performed_by_idx on public.inventory_movements(performed_by);
create index inventory_movements_reference_idx on public.inventory_movements(reference_type, reference_id);

-- Source: supabase/migrations/20261107121153_phase_5_borrowing_and_returns.sql
create table public.borrow_requests (
  id uuid primary key default gen_random_uuid(),
  borrower_id uuid not null references public.profiles(id) on delete restrict,
  reservation_id uuid references public.reservations(id) on delete set null,
  status text not null default 'pending'
    check (status in ('pending', 'approved', 'rejected', 'cancelled', 'partially_returned', 'returned')),
  requested_at timestamptz not null default now(),
  expected_return_at timestamptz not null,
  remarks text,
  constraint borrow_requests_expected_return_after_request
    check (expected_return_at > requested_at)
);

create table public.borrow_request_items (
  id uuid primary key default gen_random_uuid(),
  borrow_request_id uuid not null references public.borrow_requests(id) on delete cascade,
  item_id uuid not null references public.inventory_items(id) on delete restrict,
  quantity numeric(12,3) not null,
  remarks text,
  constraint borrow_request_items_quantity_positive check (quantity > 0),
  constraint borrow_request_items_unique unique (borrow_request_id, item_id)
);

create table public.borrow_transactions (
  id uuid primary key default gen_random_uuid(),
  borrow_request_id uuid not null unique references public.borrow_requests(id) on delete restrict,
  released_by uuid not null references public.profiles(id) on delete restrict,
  released_at timestamptz not null default now(),
  remarks text
);

create table public.borrow_transaction_items (
  id uuid primary key default gen_random_uuid(),
  transaction_id uuid not null references public.borrow_transactions(id) on delete restrict,
  item_id uuid not null references public.inventory_items(id) on delete restrict,
  asset_id uuid references public.equipment_assets(id) on delete restrict,
  quantity numeric(12,3) not null default 1,
  constraint borrow_transaction_items_quantity_positive check (quantity > 0),
  constraint borrow_transaction_items_asset_quantity check (asset_id is null or quantity = 1),
  constraint borrow_transaction_items_unique_asset unique (transaction_id, asset_id)
);

create table public.return_transactions (
  id uuid primary key default gen_random_uuid(),
  transaction_id uuid not null references public.borrow_transactions(id) on delete restrict,
  received_by uuid not null references public.profiles(id) on delete restrict,
  returned_at timestamptz not null default now(),
  remarks text
);

create table public.return_transaction_items (
  id uuid primary key default gen_random_uuid(),
  return_id uuid not null references public.return_transactions(id) on delete restrict,
  item_id uuid not null references public.inventory_items(id) on delete restrict,
  asset_id uuid references public.equipment_assets(id) on delete restrict,
  quantity numeric(12,3) not null,
  condition text check (condition in ('new', 'good', 'fair', 'poor', 'damaged')),
  remarks text,
  constraint return_transaction_items_quantity_positive check (quantity > 0),
  constraint return_transaction_items_asset_quantity check (asset_id is null or quantity = 1),
  constraint return_transaction_items_unique_asset unique (return_id, asset_id)
);

create index borrow_requests_borrower_requested_idx on public.borrow_requests(borrower_id, requested_at desc);
create index borrow_requests_status_expected_return_idx on public.borrow_requests(status, expected_return_at);
create index borrow_requests_reservation_id_idx on public.borrow_requests(reservation_id);
create index borrow_request_items_item_id_idx on public.borrow_request_items(item_id);
create index borrow_transactions_released_by_idx on public.borrow_transactions(released_by);
create index borrow_transaction_items_item_id_idx on public.borrow_transaction_items(item_id);
create index borrow_transaction_items_asset_id_idx on public.borrow_transaction_items(asset_id);
create index return_transactions_transaction_returned_idx on public.return_transactions(transaction_id, returned_at desc);
create index return_transactions_received_by_idx on public.return_transactions(received_by);
create index return_transaction_items_asset_id_idx on public.return_transaction_items(asset_id);

-- Source: supabase/migrations/20261107121242_phase_6_maintenance_and_incidents.sql
create table public.maintenance_requests (
  id uuid primary key default gen_random_uuid(),
  facility_id uuid references public.facilities(id) on delete restrict,
  asset_id uuid references public.equipment_assets(id) on delete restrict,
  reported_by uuid not null references public.profiles(id) on delete restrict,
  description text not null,
  priority text not null default 'medium'
    check (priority in ('low', 'medium', 'high', 'critical')),
  status text not null default 'pending'
    check (status in ('pending', 'approved', 'in_progress', 'on_hold', 'completed', 'cancelled')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint maintenance_requests_exactly_one_target check (
    (facility_id is not null and asset_id is null)
    or (facility_id is null and asset_id is not null)
  ),
  constraint maintenance_requests_description_not_blank check (btrim(description) <> '')
);

create table public.maintenance_records (
  id uuid primary key default gen_random_uuid(),
  request_id uuid not null references public.maintenance_requests(id) on delete restrict,
  assigned_to uuid references public.profiles(id) on delete set null,
  started_at timestamptz,
  completed_at timestamptz,
  cost numeric(12,2) not null default 0,
  remarks text,
  created_at timestamptz not null default now(),
  constraint maintenance_records_cost_nonnegative check (cost >= 0),
  constraint maintenance_records_valid_dates check (
    completed_at is null or (started_at is not null and completed_at >= started_at)
  )
);

create table public.maintenance_schedules (
  id uuid primary key default gen_random_uuid(),
  facility_id uuid references public.facilities(id) on delete restrict,
  asset_id uuid references public.equipment_assets(id) on delete restrict,
  title text not null,
  description text,
  frequency text not null
    check (frequency in ('once', 'daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'annual')),
  next_due_at timestamptz not null,
  last_completed_at timestamptz,
  is_active boolean not null default true,
  created_by uuid not null references public.profiles(id) on delete restrict,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint maintenance_schedules_exactly_one_target check (
    (facility_id is not null and asset_id is null)
    or (facility_id is null and asset_id is not null)
  ),
  constraint maintenance_schedules_title_not_blank check (btrim(title) <> '')
);

create table public.incident_reports (
  id uuid primary key default gen_random_uuid(),
  facility_id uuid references public.facilities(id) on delete restrict,
  asset_id uuid references public.equipment_assets(id) on delete restrict,
  reported_by uuid not null references public.profiles(id) on delete restrict,
  incident_type text not null
    check (incident_type in ('damage', 'missing', 'safety', 'facility', 'other')),
  description text not null,
  status text not null default 'open'
    check (status in ('open', 'under_review', 'resolved', 'closed', 'dismissed')),
  resolution text,
  resolved_by uuid references public.profiles(id) on delete set null,
  resolved_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint incident_reports_target_required check (
    facility_id is not null or asset_id is not null
  ),
  constraint incident_reports_description_not_blank check (btrim(description) <> ''),
  constraint incident_reports_resolution_dates check (
    (resolved_at is null and resolved_by is null)
    or (resolved_at is not null and resolved_by is not null)
  )
);

create table public.incident_attachments (
  id uuid primary key default gen_random_uuid(),
  incident_id uuid not null references public.incident_reports(id) on delete cascade,
  file_path text not null,
  file_name text not null,
  uploaded_by uuid not null references public.profiles(id) on delete restrict,
  uploaded_at timestamptz not null default now(),
  constraint incident_attachments_path_not_blank check (btrim(file_path) <> ''),
  constraint incident_attachments_name_not_blank check (btrim(file_name) <> '')
);

create index maintenance_requests_facility_created_idx
  on public.maintenance_requests(facility_id, created_at desc);
create index maintenance_requests_asset_created_idx
  on public.maintenance_requests(asset_id, created_at desc);
create index maintenance_requests_status_priority_idx
  on public.maintenance_requests(status, priority);
create index maintenance_requests_reported_by_idx
  on public.maintenance_requests(reported_by);
create index maintenance_records_request_id_idx
  on public.maintenance_records(request_id);
create index maintenance_records_assigned_to_idx
  on public.maintenance_records(assigned_to);
create index maintenance_schedules_facility_due_idx
  on public.maintenance_schedules(facility_id, next_due_at) where is_active;
create index maintenance_schedules_asset_due_idx
  on public.maintenance_schedules(asset_id, next_due_at) where is_active;
create index incident_reports_facility_created_idx
  on public.incident_reports(facility_id, created_at desc);
create index incident_reports_asset_created_idx
  on public.incident_reports(asset_id, created_at desc);
create index incident_reports_status_created_idx
  on public.incident_reports(status, created_at desc);
create index incident_reports_reported_by_idx
  on public.incident_reports(reported_by);
create index incident_attachments_incident_id_idx
  on public.incident_attachments(incident_id);

-- Source: supabase/migrations/20261107121336_phase_7_notifications_and_auditing.sql
create table public.notifications (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.profiles(id) on delete cascade,
  title text not null,
  message text not null,
  type text not null
    check (type in ('reservation', 'borrowing', 'return', 'inventory', 'maintenance', 'incident', 'announcement', 'system')),
  read_at timestamptz,
  created_at timestamptz not null default now(),
  constraint notifications_title_not_blank check (btrim(title) <> ''),
  constraint notifications_message_not_blank check (btrim(message) <> '')
);

create table public.announcements (
  id uuid primary key default gen_random_uuid(),
  title text not null,
  content text not null,
  created_by uuid not null references public.profiles(id) on delete restrict,
  published_at timestamptz,
  status text not null default 'draft'
    check (status in ('draft', 'published', 'archived')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint announcements_title_not_blank check (btrim(title) <> ''),
  constraint announcements_content_not_blank check (btrim(content) <> ''),
  constraint announcements_published_timestamp check (
    status <> 'published' or published_at is not null
  )
);

create table public.activity_logs (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete set null,
  action text not null,
  module text not null,
  record_id uuid,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  constraint activity_logs_action_not_blank check (btrim(action) <> ''),
  constraint activity_logs_module_not_blank check (btrim(module) <> ''),
  constraint activity_logs_metadata_object check (jsonb_typeof(metadata) = 'object')
);

create table public.audit_logs (
  id uuid primary key default gen_random_uuid(),
  user_id uuid references public.profiles(id) on delete set null,
  table_name text not null,
  record_id uuid not null,
  action text not null check (action in ('INSERT', 'UPDATE', 'DELETE')),
  old_values jsonb,
  new_values jsonb,
  created_at timestamptz not null default now(),
  constraint audit_logs_table_name_not_blank check (btrim(table_name) <> ''),
  constraint audit_logs_snapshot_shape check (
    (action = 'INSERT' and old_values is null and new_values is not null)
    or (action = 'UPDATE' and old_values is not null and new_values is not null)
    or (action = 'DELETE' and old_values is not null and new_values is null)
  )
);

create index notifications_user_created_idx on public.notifications(user_id, created_at desc);
create index notifications_user_unread_idx on public.notifications(user_id, created_at desc)
  where read_at is null;
create index announcements_status_published_idx on public.announcements(status, published_at desc);
create index announcements_created_by_idx on public.announcements(created_by);
create index activity_logs_user_created_idx on public.activity_logs(user_id, created_at desc);
create index activity_logs_module_created_idx on public.activity_logs(module, created_at desc);
create index activity_logs_record_idx on public.activity_logs(module, record_id);
create index audit_logs_table_record_created_idx on public.audit_logs(table_name, record_id, created_at desc);
create index audit_logs_user_created_idx on public.audit_logs(user_id, created_at desc);
create index audit_logs_created_idx on public.audit_logs(created_at desc);

-- Source: supabase/migrations/20261107121508_phase_9_security_and_index_hardening.sql
create index incident_attachments_uploaded_by_idx
  on public.incident_attachments(uploaded_by);
create index incident_reports_resolved_by_idx
  on public.incident_reports(resolved_by);
create index maintenance_schedules_created_by_idx
  on public.maintenance_schedules(created_by);
create index return_transaction_items_item_id_idx
  on public.return_transaction_items(item_id);

revoke all on function public.capture_row_audit() from public, anon, authenticated;
revoke all on function public.handle_new_auth_user() from public, anon, authenticated;
revoke all on function public.handle_auth_user_email_update() from public, anon, authenticated;

revoke all on function public.set_updated_at() from public, anon, authenticated;
revoke all on function public.guard_inventory_stock_nonnegative() from public, anon, authenticated;
revoke all on function public.prevent_audit_log_mutation() from public, anon, authenticated;

-- Reconcile additive columns present in the live final source schema.
alter table public.borrow_requests
  add column if not exists reviewed_by uuid references public.profiles(id),
  add column if not exists reviewed_at timestamptz,
  add column if not exists review_remarks text;

alter table public.borrow_transaction_items
  add column if not exists location_id uuid references public.inventory_locations(id);

alter table public.notifications
  add column if not exists event_key text,
  add column if not exists related_record_type text,
  add column if not exists related_record_id uuid,
  add column if not exists metadata jsonb not null default '{}'::jsonb;

alter table public.notifications
  add constraint notifications_event_key_not_blank
    check (event_key is null or btrim(event_key) <> ''),
  add constraint notifications_related_record_type_check
    check (related_record_type is null or related_record_type = any (array['reservation','borrowing','maintenance','incident','announcement']::text[])),
  add constraint notifications_metadata_object_check
    check (jsonb_typeof(metadata) = 'object');

alter table public.announcements
  add column if not exists category text not null default 'general',
  add column if not exists expires_at timestamptz;

alter table public.announcements
  add constraint announcements_category_check
    check (category = any (array['general','maintenance','facility_updates','system_notices','important_reminders']::text[]));

create unique index if not exists borrow_transaction_items_unique_consumable_per_transaction
  on public.borrow_transaction_items (transaction_id, item_id) where asset_id is null;
create unique index if not exists return_transaction_items_unique_consumable_per_return
  on public.return_transaction_items (return_id, item_id) where asset_id is null;
create index if not exists borrow_requests_reviewed_by_idx
  on public.borrow_requests (reviewed_by) where reviewed_by is not null;
create index if not exists borrow_transaction_items_location_id_idx
  on public.borrow_transaction_items (location_id) where location_id is not null;
create unique index if not exists notifications_user_event_key_unique
  on public.notifications (user_id, event_key);
create index if not exists notifications_related_record_idx
  on public.notifications (related_record_type, related_record_id) where related_record_id is not null;
create index if not exists announcements_category_status_idx
  on public.announcements (category, status, published_at desc);
create index if not exists announcements_expiration_idx
  on public.announcements (status, expires_at) where expires_at is not null;
