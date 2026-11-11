CREATE OR REPLACE VIEW public.dashboard_metrics
with (security_invoker = true)
as
select
  (select count(*) from public.facilities) as total_facilities,
  (select count(*) from public.facilities where status = 'available') as available_facilities,
  (select count(*) from public.facilities where status = 'maintenance') as facilities_under_maintenance,
  (select count(*) from public.reservations where status = 'pending') as pending_reservations,
  (select count(*) from public.reservations where status = 'approved') as approved_reservations,
  (select count(*) from public.inventory_items where status = 'active') as active_inventory_items,
  (select coalesce(sum(quantity), 0) from public.inventory_stock) as total_stock_quantity,
  (select count(*) from public.equipment_assets where status = 'borrowed') as borrowed_assets,
  (select count(*) from public.borrow_requests
    where status in ('approved', 'partially_returned')
      and expected_return_at < now()) as overdue_borrow_requests;

CREATE OR REPLACE VIEW public.inventory_low_stock
with (security_invoker = true)
as
select
  i.id as item_id,
  i.item_code,
  i.name as item_name,
  i.unit,
  i.minimum_stock,
  coalesce(sum(s.quantity), 0)::numeric(12,3) as current_quantity
from public.inventory_items i
left join public.inventory_stock s on s.item_id = i.id
where i.status = 'active'
group by i.id, i.item_code, i.name, i.unit, i.minimum_stock
having coalesce(sum(s.quantity), 0) <= i.minimum_stock;

CREATE OR REPLACE VIEW public.overdue_borrow_requests
with (security_invoker = true)
as
select
  br.id as borrow_request_id,
  br.borrower_id,
  p.full_name as borrower_name,
  br.expected_return_at,
  br.status,
  now() - br.expected_return_at as overdue_duration
from public.borrow_requests br
join public.profiles p on p.id = br.borrower_id
where br.status in ('approved', 'partially_returned')
  and br.expected_return_at < now();

CREATE OR REPLACE VIEW public.facility_reservation_summary
with (security_invoker = true)
as
select
  f.id as facility_id,
  f.code as facility_code,
  f.name as facility_name,
  count(r.id) filter (where r.status = 'approved') as approved_reservations,
  count(r.id) filter (where r.status = 'pending') as pending_reservations,
  count(r.id) filter (where r.status = 'completed') as completed_reservations
from public.facilities f
left join public.reservations r on r.facility_id = f.id
group by f.id, f.code, f.name;

CREATE OR REPLACE VIEW public.monthly_reservation_trends
with (security_invoker = true)
as
select
  date_trunc('month', start_at) as reservation_month,
  status,
  count(*) as reservation_count
from public.reservations
group by date_trunc('month', start_at), status;
