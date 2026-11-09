
begin;

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

create trigger set_maintenance_requests_updated_at
before update on public.maintenance_requests
for each row execute function public.set_updated_at();

create trigger set_maintenance_schedules_updated_at
before update on public.maintenance_schedules
for each row execute function public.set_updated_at();

create trigger set_incident_reports_updated_at
before update on public.incident_reports
for each row execute function public.set_updated_at();

alter table public.maintenance_requests enable row level security;
alter table public.maintenance_records enable row level security;
alter table public.maintenance_schedules enable row level security;
alter table public.incident_reports enable row level security;
alter table public.incident_attachments enable row level security;

commit;
