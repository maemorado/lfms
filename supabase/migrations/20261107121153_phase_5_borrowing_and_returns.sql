
begin;

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

alter table public.borrow_requests enable row level security;
alter table public.borrow_request_items enable row level security;
alter table public.borrow_transactions enable row level security;
alter table public.borrow_transaction_items enable row level security;
alter table public.return_transactions enable row level security;
alter table public.return_transaction_items enable row level security;

commit;
