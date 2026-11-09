
begin;

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

commit;
