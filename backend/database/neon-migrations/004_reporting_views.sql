-- LFMS Neon reporting views.
-- Ported from supabase/migrations/20261107121408_phase_8_dashboard_and_reporting_views.sql.
-- Views execute with the caller's privileges so underlying table RLS remains active.

CREATE OR REPLACE VIEW public.dashboard_metrics
WITH (security_invoker = true)
AS
SELECT
  (SELECT count(*) FROM public.facilities) AS total_facilities,
  (SELECT count(*) FROM public.facilities WHERE status = 'available') AS available_facilities,
  (SELECT count(*) FROM public.facilities WHERE status = 'maintenance') AS facilities_under_maintenance,
  (SELECT count(*) FROM public.reservations WHERE status = 'pending') AS pending_reservations,
  (SELECT count(*) FROM public.reservations WHERE status = 'approved') AS approved_reservations,
  (SELECT count(*) FROM public.inventory_items WHERE status = 'active') AS active_inventory_items,
  (SELECT coalesce(sum(quantity), 0) FROM public.inventory_stock) AS total_stock_quantity,
  (SELECT count(*) FROM public.equipment_assets WHERE status = 'borrowed') AS borrowed_assets,
  (SELECT count(*) FROM public.borrow_requests
    WHERE status IN ('approved', 'partially_returned')
      AND expected_return_at < now()) AS overdue_borrow_requests;

CREATE OR REPLACE VIEW public.inventory_low_stock
WITH (security_invoker = true)
AS
SELECT
  i.id AS item_id,
  i.item_code,
  i.name AS item_name,
  i.unit,
  i.minimum_stock,
  coalesce(sum(s.quantity), 0)::numeric(12,3) AS current_quantity
FROM public.inventory_items i
LEFT JOIN public.inventory_stock s ON s.item_id = i.id
WHERE i.status = 'active'
GROUP BY i.id, i.item_code, i.name, i.unit, i.minimum_stock
HAVING coalesce(sum(s.quantity), 0) <= i.minimum_stock;

CREATE OR REPLACE VIEW public.overdue_borrow_requests
WITH (security_invoker = true)
AS
SELECT
  br.id AS borrow_request_id,
  br.borrower_id,
  p.full_name AS borrower_name,
  br.expected_return_at,
  br.status,
  now() - br.expected_return_at AS overdue_duration
FROM public.borrow_requests br
JOIN public.profiles p ON p.id = br.borrower_id
WHERE br.status IN ('approved', 'partially_returned')
  AND br.expected_return_at < now();

CREATE OR REPLACE VIEW public.facility_reservation_summary
WITH (security_invoker = true)
AS
SELECT
  f.id AS facility_id,
  f.code AS facility_code,
  f.name AS facility_name,
  count(r.id) FILTER (WHERE r.status = 'approved') AS approved_reservations,
  count(r.id) FILTER (WHERE r.status = 'pending') AS pending_reservations,
  count(r.id) FILTER (WHERE r.status = 'completed') AS completed_reservations
FROM public.facilities f
LEFT JOIN public.reservations r ON r.facility_id = f.id
GROUP BY f.id, f.code, f.name;

CREATE OR REPLACE VIEW public.monthly_reservation_trends
WITH (security_invoker = true)
AS
SELECT
  date_trunc('month', start_at) AS reservation_month,
  status,
  count(*) AS reservation_count
FROM public.reservations
GROUP BY date_trunc('month', start_at), status;

GRANT SELECT ON
  public.dashboard_metrics,
  public.inventory_low_stock,
  public.overdue_borrow_requests,
  public.facility_reservation_summary,
  public.monthly_reservation_trends
TO lfms_app;
