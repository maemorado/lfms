
begin;

create extension if not exists btree_gist with schema extensions;

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

create trigger set_reservations_updated_at
before update on public.reservations
for each row execute function public.set_updated_at();

alter table public.reservations enable row level security;
alter table public.reservation_participants enable row level security;
alter table public.reservation_approvals enable row level security;
alter table public.reservation_attachments enable row level security;

commit;
