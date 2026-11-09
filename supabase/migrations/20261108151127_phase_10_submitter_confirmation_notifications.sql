
create or replace function private.notify_lfms_submitter_confirmation()
returns trigger
language plpgsql
security definer
set search_path = ''
as $function$
begin
  if tg_table_name='reservations' then
    perform private.enqueue_lfms_notification(
      new.requester_id,'Reservation submitted','Your facility reservation was submitted and is awaiting review.','reservation',
      'reservation:submitted:'||new.id::text||':'||new.created_at::text,'reservation',new.id,
      jsonb_build_object('event','submitted','audience','requester')
    );
  elsif tg_table_name='borrow_requests' then
    perform private.enqueue_lfms_notification(
      new.borrower_id,'Borrowing request submitted','Your borrowing request was submitted and is awaiting review.','borrowing',
      'borrowing:submitted:'||new.id::text||':'||new.requested_at::text,'borrowing',new.id,
      jsonb_build_object('event','submitted','audience','requester')
    );
  elsif tg_table_name='maintenance_requests' then
    perform private.enqueue_lfms_notification(
      new.reported_by,'Maintenance request submitted','Your maintenance request was submitted and is awaiting review.','maintenance',
      'maintenance:submitted:'||new.id::text||':'||new.created_at::text,'maintenance',new.id,
      jsonb_build_object('event','submitted','audience','reporter')
    );
  elsif tg_table_name='incident_reports' then
    perform private.enqueue_lfms_notification(
      new.reported_by,'Incident report submitted','Your incident report was submitted and is awaiting investigation.','incident',
      'incident:submitted:'||new.id::text||':'||new.created_at::text,'incident',new.id,
      jsonb_build_object('event','submitted','audience','reporter')
    );
  end if;
  return new;
end;
$function$;

revoke all on function private.notify_lfms_submitter_confirmation() from public,anon,authenticated;

create trigger lfms_notifications_reservation_submitter
after insert on public.reservations
for each row execute function private.notify_lfms_submitter_confirmation();

create trigger lfms_notifications_borrow_submitter
after insert on public.borrow_requests
for each row execute function private.notify_lfms_submitter_confirmation();

create trigger lfms_notifications_maintenance_submitter
after insert on public.maintenance_requests
for each row execute function private.notify_lfms_submitter_confirmation();

create trigger lfms_notifications_incident_submitter
after insert on public.incident_reports
for each row execute function private.notify_lfms_submitter_confirmation();
