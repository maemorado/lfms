
begin;

create table public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
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

create or replace function public.handle_new_auth_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  resolved_name text;
begin
  resolved_name := coalesce(
    nullif(btrim(new.raw_user_meta_data ->> 'full_name'), ''),
    nullif(split_part(coalesce(new.email, ''), '@', 1), ''),
    'New user'
  );

  insert into public.profiles (id, full_name, email)
  values (new.id, resolved_name, new.email)
  on conflict (id) do update
    set email = excluded.email,
        updated_at = now();

  return new;
end;
$$;

create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_auth_user();

create or replace function public.handle_auth_user_email_update()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  if new.email is distinct from old.email then
    update public.profiles
       set email = new.email,
           updated_at = now()
     where id = new.id;
  end if;
  return new;
end;
$$;

create trigger on_auth_user_email_updated
  after update of email on auth.users
  for each row
  when (new.email is distinct from old.email)
  execute function public.handle_auth_user_email_update();

alter table public.profiles enable row level security;
alter table public.roles enable row level security;
alter table public.user_roles enable row level security;
alter table public.permissions enable row level security;
alter table public.role_permissions enable row level security;

insert into public.roles (name, description) values
  ('admin', 'Full administrative access to LFMS.'),
  ('staff_sa', 'Staff or Student Assistant access to permitted LFMS functions.')
on conflict (name) do nothing;

insert into public.permissions (name, description) values
  ('dashboard.view', 'View dashboard information.'),
  ('profile.view_own', 'View own profile.'),
  ('profile.update_own', 'Update own profile.'),
  ('accounts.view', 'View user accounts.'),
  ('accounts.manage', 'Create, update, activate, and deactivate accounts and assign roles.'),
  ('facilities.view', 'View buildings, facilities, and facility features.'),
  ('facilities.manage', 'Manage buildings, facilities, types, and features.'),
  ('reservations.view', 'View reservations and schedules.'),
  ('reservations.create', 'Submit reservation requests.'),
  ('reservations.manage', 'Approve, reject, cancel, reschedule, and complete reservations.'),
  ('inventory.view', 'View inventory, stock, and equipment.'),
  ('inventory.manage', 'Manage inventory items, stock, assets, and movements.'),
  ('borrowing.view', 'View borrowing and return transactions.'),
  ('borrowing.request', 'Submit equipment borrowing requests.'),
  ('borrowing.manage', 'Approve requests, release equipment, and process returns.'),
  ('maintenance.view', 'View maintenance requests and records.'),
  ('maintenance.create', 'Submit maintenance requests.'),
  ('maintenance.manage', 'Assign and manage maintenance work.'),
  ('incidents.view', 'View incident reports.'),
  ('incidents.create', 'Submit incident reports.'),
  ('incidents.manage', 'Manage incident status and resolution.'),
  ('reports.view', 'View system reports and analytics.'),
  ('notifications.view_own', 'View own notifications.'),
  ('notifications.manage', 'Manage notifications and announcements.'),
  ('audit.view', 'View activity and audit history.'),
  ('settings.manage', 'Manage system settings.')
on conflict (name) do nothing;

insert into public.role_permissions (role_id, permission_id)
select r.id, p.id
from public.roles r
cross join public.permissions p
where r.name = 'admin'
on conflict (role_id, permission_id) do nothing;

insert into public.role_permissions (role_id, permission_id)
select r.id, p.id
from public.roles r
join public.permissions p
  on p.name = any (array[
    'dashboard.view',
    'profile.view_own',
    'profile.update_own',
    'facilities.view',
    'reservations.view',
    'reservations.create',
    'inventory.view',
    'borrowing.view',
    'borrowing.request',
    'maintenance.view',
    'maintenance.create',
    'incidents.view',
    'incidents.create',
    'notifications.view_own'
  ])
where r.name = 'staff_sa'
on conflict (role_id, permission_id) do nothing;

commit;
