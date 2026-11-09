
begin;

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

create trigger set_announcements_updated_at
before update on public.announcements
for each row execute function public.set_updated_at();

create or replace function public.capture_row_audit()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  old_row jsonb;
  new_row jsonb;
  target_id uuid;
  actor_id uuid;
begin
  actor_id := nullif(current_setting('app.current_user_id', true), '')::uuid;

  if tg_op = 'INSERT' then
    new_row := to_jsonb(new);
    target_id := (new_row ->> 'id')::uuid;
  elsif tg_op = 'UPDATE' then
    old_row := to_jsonb(old);
    new_row := to_jsonb(new);
    target_id := (new_row ->> 'id')::uuid;
  else
    old_row := to_jsonb(old);
    target_id := (old_row ->> 'id')::uuid;
  end if;

  insert into public.audit_logs (user_id, table_name, record_id, action, old_values, new_values)
  values (actor_id, tg_table_name, target_id, tg_op, old_row, new_row);

  if tg_op = 'DELETE' then
    return old;
  end if;
  return new;
end;
$$;

create trigger audit_profiles
after insert or update or delete on public.profiles
for each row execute function public.capture_row_audit();

create trigger audit_buildings
after insert or update or delete on public.buildings
for each row execute function public.capture_row_audit();

create trigger audit_facilities
after insert or update or delete on public.facilities
for each row execute function public.capture_row_audit();

create trigger audit_reservations
after insert or update or delete on public.reservations
for each row execute function public.capture_row_audit();

create trigger audit_inventory_items
after insert or update or delete on public.inventory_items
for each row execute function public.capture_row_audit();

create trigger audit_inventory_stock
after insert or update or delete on public.inventory_stock
for each row execute function public.capture_row_audit();

create trigger audit_equipment_assets
after insert or update or delete on public.equipment_assets
for each row execute function public.capture_row_audit();

create trigger audit_maintenance_requests
after insert or update or delete on public.maintenance_requests
for each row execute function public.capture_row_audit();

create trigger audit_incident_reports
after insert or update or delete on public.incident_reports
for each row execute function public.capture_row_audit();

create trigger audit_announcements
after insert or update or delete on public.announcements
for each row execute function public.capture_row_audit();

create or replace function public.prevent_audit_log_mutation()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception 'Audit logs are immutable'
    using errcode = '42501';
end;
$$;

create trigger audit_logs_immutable
before update or delete on public.audit_logs
for each row execute function public.prevent_audit_log_mutation();

alter table public.notifications enable row level security;
alter table public.announcements enable row level security;
alter table public.activity_logs enable row level security;
alter table public.audit_logs enable row level security;

commit;
