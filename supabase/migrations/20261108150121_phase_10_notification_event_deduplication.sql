-- Use a non-partial unique index so PostgREST on_conflict can safely deduplicate overdue notices.
drop index if exists public.notifications_user_event_key_unique;
create unique index notifications_user_event_key_unique on public.notifications (user_id,event_key);

-- Avoid duplicate borrower notices when the return transaction and request status are updated
-- by the same atomic return workflow. The return_transactions trigger owns that notification.
create or replace function private.handle_lfms_notification_event()
returns trigger language plpgsql security definer set search_path = '' as $function$
declare
 v_recipient uuid; v_borrower uuid; v_request_id uuid; v_event_suffix text;
begin
 if tg_table_name='reservations' then
  if tg_op='INSERT' then
   for v_recipient in select distinct p.id from public.profiles p join public.user_roles ur on ur.user_id=p.id join public.roles r on r.id=ur.role_id where p.status='active' and r.name='admin'
   loop perform private.enqueue_lfms_notification(v_recipient,'New reservation submitted','A facility reservation is waiting for review.','reservation','reservation:submitted:'||new.id::text||':'||new.created_at::text,'reservation',new.id,jsonb_build_object('event','submitted')); end loop;
  elsif new.status is distinct from old.status then
   perform private.enqueue_lfms_notification(new.requester_id,case new.status when 'approved' then 'Reservation approved' when 'rejected' then 'Reservation rejected' when 'cancelled' then 'Reservation cancelled' else 'Reservation status updated' end,'Your facility reservation status is now '||replace(new.status,'_',' ')||'.','reservation','reservation:status:'||new.id::text||':'||new.status||':'||new.updated_at::text,'reservation',new.id,jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status));
   if new.status='cancelled' and auth.uid()=new.requester_id then
    for v_recipient in select distinct p.id from public.profiles p join public.user_roles ur on ur.user_id=p.id join public.roles r on r.id=ur.role_id where p.status='active' and r.name='admin'
    loop perform private.enqueue_lfms_notification(v_recipient,'Reservation cancelled by requester','A previously submitted facility reservation was cancelled.','reservation','reservation:cancelled:'||new.id::text||':'||new.updated_at::text,'reservation',new.id,jsonb_build_object('event','cancelled')); end loop;
   end if;
  end if;
 elsif tg_table_name='borrow_requests' then
  if tg_op='INSERT' then
   for v_recipient in select distinct p.id from public.profiles p join public.user_roles ur on ur.user_id=p.id join public.roles r on r.id=ur.role_id where p.status='active' and r.name='admin'
   loop perform private.enqueue_lfms_notification(v_recipient,'New borrowing request','A borrowing request is waiting for review.','borrowing','borrowing:submitted:'||new.id::text||':'||new.requested_at::text,'borrowing',new.id,jsonb_build_object('event','submitted')); end loop;
  elsif new.status is distinct from old.status and new.status not in ('returned','partially_returned') then
   perform private.enqueue_lfms_notification(new.borrower_id,case new.status when 'approved' then 'Borrowing request approved' when 'rejected' then 'Borrowing request rejected' when 'cancelled' then 'Borrowing request cancelled' else 'Borrowing request status updated' end,'Your borrowing request status is now '||replace(new.status,'_',' ')||'.','borrowing','borrowing:status:'||new.id::text||':'||new.status||':'||coalesce(new.reviewed_at,now())::text,'borrowing',new.id,jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status));
   if new.status='cancelled' and auth.uid()=new.borrower_id then
    for v_recipient in select distinct p.id from public.profiles p join public.user_roles ur on ur.user_id=p.id join public.roles r on r.id=ur.role_id where p.status='active' and r.name='admin'
    loop perform private.enqueue_lfms_notification(v_recipient,'Borrowing request cancelled','A requester cancelled a borrowing request.','borrowing','borrowing:cancelled:'||new.id::text||':'||coalesce(new.reviewed_at,now())::text,'borrowing',new.id,jsonb_build_object('event','cancelled')); end loop;
   end if;
  end if;
 elsif tg_table_name='borrow_transactions' and tg_op='INSERT' then
  select br.borrower_id,br.id into v_borrower,v_request_id from public.borrow_requests br where br.id=new.borrow_request_id;
  perform private.enqueue_lfms_notification(v_borrower,'Borrowed items released','The approved items for your borrowing request have been released.','borrowing','borrowing:released:'||new.id::text,'borrowing',v_request_id,jsonb_build_object('event','items_released','transaction_id',new.id));
 elsif tg_table_name='return_transactions' and tg_op='INSERT' then
  select br.borrower_id,br.id into v_borrower,v_request_id from public.borrow_transactions bt join public.borrow_requests br on br.id=bt.borrow_request_id where bt.id=new.transaction_id;
  perform private.enqueue_lfms_notification(v_borrower,'Return processed','A return for your borrowed items has been processed.','return','return:processed:'||new.id::text,'borrowing',v_request_id,jsonb_build_object('event','return_processed','return_id',new.id,'transaction_id',new.transaction_id));
 elsif tg_table_name='maintenance_requests' then
  if tg_op='INSERT' then
   for v_recipient in select distinct p.id from public.profiles p join public.user_roles ur on ur.user_id=p.id join public.roles r on r.id=ur.role_id where p.status='active' and r.name='admin'
   loop perform private.enqueue_lfms_notification(v_recipient,'New maintenance request','A maintenance request is waiting for review.','maintenance','maintenance:submitted:'||new.id::text||':'||new.created_at::text,'maintenance',new.id,jsonb_build_object('event','submitted','priority',new.priority)); end loop;
  elsif new.status is distinct from old.status then
   perform private.enqueue_lfms_notification(new.reported_by,case new.status when 'completed' then 'Maintenance completed' else 'Maintenance status updated' end,'Your maintenance request status is now '||replace(new.status,'_',' ')||'.','maintenance','maintenance:status:'||new.id::text||':'||new.status||':'||new.updated_at::text,'maintenance',new.id,jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status));
  end if;
 elsif tg_table_name='incident_reports' then
  if tg_op='INSERT' then
   for v_recipient in select distinct p.id from public.profiles p join public.user_roles ur on ur.user_id=p.id join public.roles r on r.id=ur.role_id where p.status='active' and r.name='admin'
   loop perform private.enqueue_lfms_notification(v_recipient,'New incident report','An incident report is waiting for investigation.','incident','incident:submitted:'||new.id::text||':'||new.created_at::text,'incident',new.id,jsonb_build_object('event','submitted','incident_type',new.incident_type)); end loop;
  elsif new.status is distinct from old.status or new.resolution is distinct from old.resolution then
   v_event_suffix:=case when new.status is distinct from old.status then 'status:'||new.status else 'resolution' end;
   perform private.enqueue_lfms_notification(new.reported_by,case when new.status='resolved' and new.status is distinct from old.status then 'Incident resolved' when new.status is distinct from old.status then 'Incident status updated' else 'Incident investigation updated' end,case when new.status is distinct from old.status then 'Your incident report status is now '||replace(new.status,'_',' ')||'.' else 'The investigation notes for your incident report have been updated.' end,'incident','incident:'||v_event_suffix||':'||new.id::text||':'||new.updated_at::text,'incident',new.id,jsonb_build_object('event','investigation_updated','previous_status',old.status,'status',new.status,'resolution_updated',new.resolution is distinct from old.resolution));
  end if;
 elsif tg_table_name='announcements' then
  if new.status='published' and (tg_op='INSERT' or old.status is distinct from 'published' or old.published_at is distinct from new.published_at) then
   for v_recipient in select distinct p.id from public.profiles p join public.user_roles ur on ur.user_id=p.id join public.roles r on r.id=ur.role_id where p.status='active' and r.name in ('admin','staff_sa')
   loop perform private.enqueue_lfms_notification(v_recipient,'New announcement: '||new.title,'A new announcement has been published.','announcement','announcement:published:'||new.id::text||':'||new.published_at::text,'announcement',new.id,jsonb_build_object('event','published','category',new.category)); end loop;
  end if;
 end if;
 return new;
end;
$function$;
revoke all on function private.handle_lfms_notification_event() from public,anon,authenticated;
