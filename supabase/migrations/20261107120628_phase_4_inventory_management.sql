
begin;

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
    check (condition in ('new', 'good', 'fair', 'poor', 'damaged')),
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

create trigger set_inventory_items_updated_at
before update on public.inventory_items
for each row execute function public.set_updated_at();

create trigger set_equipment_assets_updated_at
before update on public.equipment_assets
for each row execute function public.set_updated_at();

create or replace function public.guard_inventory_stock_nonnegative()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  if new.quantity < 0 then
    raise exception 'Inventory stock cannot be negative'
      using errcode = '23514';
  end if;
  new.updated_at := now();
  return new;
end;
$$;

create trigger guard_inventory_stock_quantity
before insert or update of quantity on public.inventory_stock
for each row execute function public.guard_inventory_stock_nonnegative();

alter table public.item_categories enable row level security;
alter table public.inventory_items enable row level security;
alter table public.inventory_locations enable row level security;
alter table public.inventory_stock enable row level security;
alter table public.equipment_assets enable row level security;
alter table public.inventory_movements enable row level security;

commit;
