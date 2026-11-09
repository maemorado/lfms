
begin;

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

create or replace function public.set_updated_at()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

create trigger set_buildings_updated_at
before update on public.buildings
for each row execute function public.set_updated_at();

create trigger set_facilities_updated_at
before update on public.facilities
for each row execute function public.set_updated_at();

alter table public.buildings enable row level security;
alter table public.facility_types enable row level security;
alter table public.facilities enable row level security;
alter table public.facility_features enable row level security;
alter table public.facility_feature_assignments enable row level security;

commit;
