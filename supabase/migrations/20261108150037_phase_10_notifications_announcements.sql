
-- Phase 10: extend the existing notification and announcement records.
alter table public.notifications
  add column event_key text,
  add column related_record_type text,
  add column related_record_id uuid,
  add column metadata jsonb not null default '{}'::jsonb;

alter table public.notifications
  add constraint notifications_event_key_not_blank
    check (event_key is null or btrim(event_key) <> ''),
  add constraint notifications_related_record_type_check
    check (related_record_type is null or related_record_type = any (array['reservation','borrowing','maintenance','incident','announcement']::text[])),
  add constraint notifications_metadata_object_check
    check (jsonb_typeof(metadata) = 'object');

create unique index notifications_user_event_key_unique
  on public.notifications (user_id, event_key)
  where event_key is not null;

create index notifications_related_record_idx
  on public.notifications (related_record_type, related_record_id)
  where related_record_id is not null;

alter table public.announcements
  add column category text not null default 'general',
  add column expires_at timestamptz;

alter table public.announcements
  add constraint announcements_category_check
    check (category = any (array['general','maintenance','facility_updates','system_notices','important_reminders']::text[]));

create index announcements_category_status_idx
  on public.announcements (category, status, published_at desc);

create index announcements_expiration_idx
  on public.announcements (status, expires_at)
  where expires_at is not null;

-- Internal insert helper. It is not callable by API roles; only trigger code uses it.
create or replace function private.enqueue_lfms_notification(
  p_user_id uuid,
  p_title text,
  p_message text,
  p_type text,
  p_event_key text,
  p_related_record_type text,
  p_related_record_id uuid,
  p_metadata jsonb default '{}'::jsonb
)
returns void
language plpgsql
security definer
set search_path = ''
as $function$
begin
  if p_user_id is null or p_event_key is null or btrim(p_event_key) = '' then
    return;
  end if;

  insert into public.notifications (
    user_id, title, message, type, event_key,
    related_record_type, related_record_id, metadata
  )
  values (
    p_user_id, left(p_title, 180), left(p_message, 2000), p_type, p_event_key,
    p_related_record_type, p_related_record_id, coalesce(p_metadata, '{}'::jsonb)
  )
  on conflict (user_id, event_key) where event_key is not null do nothing;
end;
$function$;

-- All event notifications are inserted inside the source business transaction.
-- A failed notification insert therefore rolls back that business mutation too.
create or replace function private.handle_lfms_notification_event()
returns trigger
language plpgsql
security definer
set search_path = ''
as $function$
declare
  v_recipient uuid;
  v_borrower uuid;
  v_request_id uuid;
  v_event_suffix text;
  v_title text;
  v_message text;
begin
  if tg_table_name = 'reservations' then
    if tg_op = 'INSERT' then
      for v_recipient in
        select distinct p.id
        from public.profiles p
        join public.user_roles ur on ur.user_id = p.id
        join public.roles r on r.id = ur.role_id
        where p.status = 'active' and r.name = 'admin'
      loop
        perform private.enqueue_lfms_notification(
          v_recipient, 'New reservation submitted',
          'A facility reservation is waiting for review.', 'reservation',
          'reservation:submitted:' || new.id::text || ':' || new.created_at::text,
          'reservation', new.id, jsonb_build_object('event','submitted')
        );
      end loop;
    elsif new.status is distinct from old.status then
      perform private.enqueue_lfms_notification(
        new.requester_id,
        case new.status
          when 'approved' then 'Reservation approved'
          when 'rejected' then 'Reservation rejected'
          when 'cancelled' then 'Reservation cancelled'
          else 'Reservation status updated'
        end,
        'Your facility reservation status is now ' || replace(new.status, '_', ' ') || '.',
        'reservation',
        'reservation:status:' || new.id::text || ':' || new.status || ':' || new.updated_at::text,
        'reservation', new.id,
        jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status)
      );

      if new.status = 'cancelled' and auth.uid() = new.requester_id then
        for v_recipient in
          select distinct p.id
          from public.profiles p
          join public.user_roles ur on ur.user_id = p.id
          join public.roles r on r.id = ur.role_id
          where p.status = 'active' and r.name = 'admin'
        loop
          perform private.enqueue_lfms_notification(
            v_recipient, 'Reservation cancelled by requester',
            'A previously submitted facility reservation was cancelled.', 'reservation',
            'reservation:cancelled:' || new.id::text || ':' || new.updated_at::text,
            'reservation', new.id, jsonb_build_object('event','cancelled')
          );
        end loop;
      end if;
    end if;

  elsif tg_table_name = 'borrow_requests' then
    if tg_op = 'INSERT' then
      for v_recipient in
        select distinct p.id
        from public.profiles p
        join public.user_roles ur on ur.user_id = p.id
        join public.roles r on r.id = ur.role_id
        where p.status = 'active' and r.name = 'admin'
      loop
        perform private.enqueue_lfms_notification(
          v_recipient, 'New borrowing request',
          'A borrowing request is waiting for review.', 'borrowing',
          'borrowing:submitted:' || new.id::text || ':' || new.requested_at::text,
          'borrowing', new.id, jsonb_build_object('event','submitted')
        );
      end loop;
    elsif new.status is distinct from old.status then
      perform private.enqueue_lfms_notification(
        new.borrower_id,
        case new.status
          when 'approved' then 'Borrowing request approved'
          when 'rejected' then 'Borrowing request rejected'
          when 'cancelled' then 'Borrowing request cancelled'
          when 'returned' then 'Borrowed items returned'
          when 'partially_returned' then 'Borrowing partially returned'
          else 'Borrowing request status updated'
        end,
        'Your borrowing request status is now ' || replace(new.status, '_', ' ') || '.',
        'borrowing',
        'borrowing:status:' || new.id::text || ':' || new.status || ':' || coalesce(new.reviewed_at, now())::text,
        'borrowing', new.id,
        jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status)
      );

      if new.status = 'cancelled' and auth.uid() = new.borrower_id then
        for v_recipient in
          select distinct p.id
          from public.profiles p
          join public.user_roles ur on ur.user_id = p.id
          join public.roles r on r.id = ur.role_id
          where p.status = 'active' and r.name = 'admin'
        loop
          perform private.enqueue_lfms_notification(
            v_recipient, 'Borrowing request cancelled',
            'A requester cancelled a borrowing request.', 'borrowing',
            'borrowing:cancelled:' || new.id::text || ':' || coalesce(new.reviewed_at, now())::text,
            'borrowing', new.id, jsonb_build_object('event','cancelled')
          );
        end loop;
      end if;
    end if;

  elsif tg_table_name = 'borrow_transactions' and tg_op = 'INSERT' then
    select br.borrower_id, br.id into v_borrower, v_request_id
    from public.borrow_requests br
    where br.id = new.borrow_request_id;

    perform private.enqueue_lfms_notification(
      v_borrower, 'Borrowed items released',
      'The approved items for your borrowing request have been released.', 'borrowing',
      'borrowing:released:' || new.id::text,
      'borrowing', v_request_id, jsonb_build_object('event','items_released','transaction_id',new.id)
    );

  elsif tg_table_name = 'return_transactions' and tg_op = 'INSERT' then
    select br.borrower_id, br.id into v_borrower, v_request_id
    from public.borrow_transactions bt
    join public.borrow_requests br on br.id = bt.borrow_request_id
    where bt.id = new.transaction_id;

    perform private.enqueue_lfms_notification(
      v_borrower, 'Return processed',
      'A return for your borrowed items has been processed.', 'return',
      'return:processed:' || new.id::text,
      'borrowing', v_request_id, jsonb_build_object('event','return_processed','return_id',new.id,'transaction_id',new.transaction_id)
    );

  elsif tg_table_name = 'maintenance_requests' then
    if tg_op = 'INSERT' then
      for v_recipient in
        select distinct p.id
        from public.profiles p
        join public.user_roles ur on ur.user_id = p.id
        join public.roles r on r.id = ur.role_id
        where p.status = 'active' and r.name = 'admin'
      loop
        perform private.enqueue_lfms_notification(
          v_recipient, 'New maintenance request',
          'A maintenance request is waiting for review.', 'maintenance',
          'maintenance:submitted:' || new.id::text || ':' || new.created_at::text,
          'maintenance', new.id, jsonb_build_object('event','submitted','priority',new.priority)
        );
      end loop;
    elsif new.status is distinct from old.status then
      perform private.enqueue_lfms_notification(
        new.reported_by,
        case new.status when 'completed' then 'Maintenance completed' else 'Maintenance status updated' end,
        'Your maintenance request status is now ' || replace(new.status, '_', ' ') || '.',
        'maintenance',
        'maintenance:status:' || new.id::text || ':' || new.status || ':' || new.updated_at::text,
        'maintenance', new.id,
        jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status)
      );
    end if;

  elsif tg_table_name = 'incident_reports' then
    if tg_op = 'INSERT' then
      for v_recipient in
        select distinct p.id
        from public.profiles p
        join public.user_roles ur on ur.user_id = p.id
        join public.roles r on r.id = ur.role_id
        where p.status = 'active' and r.name = 'admin'
      loop
        perform private.enqueue_lfms_notification(
          v_recipient, 'New incident report',
          'An incident report is waiting for investigation.', 'incident',
          'incident:submitted:' || new.id::text || ':' || new.created_at::text,
          'incident', new.id, jsonb_build_object('event','submitted','incident_type',new.incident_type)
        );
      end loop;
    elsif new.status is distinct from old.status or new.resolution is distinct from old.resolution then
      v_event_suffix := case
        when new.status is distinct from old.status then 'status:' || new.status
        else 'resolution'
      end;
      perform private.enqueue_lfms_notification(
        new.reported_by,
        case
          when new.status = 'resolved' and new.status is distinct from old.status then 'Incident resolved'
          when new.status is distinct from old.status then 'Incident status updated'
          else 'Incident investigation updated'
        end,
        case
          when new.status is distinct from old.status then 'Your incident report status is now ' || replace(new.status, '_', ' ') || '.'
          else 'The investigation notes for your incident report have been updated.'
        end,
        'incident',
        'incident:' || v_event_suffix || ':' || new.id::text || ':' || new.updated_at::text,
        'incident', new.id,
        jsonb_build_object('event','investigation_updated','previous_status',old.status,'status',new.status,'resolution_updated',new.resolution is distinct from old.resolution)
      );
    end if;

  elsif tg_table_name = 'announcements' then
    if new.status = 'published'
       and (tg_op = 'INSERT' or old.status is distinct from 'published' or old.published_at is distinct from new.published_at) then
      for v_recipient in
        select distinct p.id
        from public.profiles p
        join public.user_roles ur on ur.user_id = p.id
        join public.roles r on r.id = ur.role_id
        where p.status = 'active' and r.name in ('admin','staff_sa')
      loop
        perform private.enqueue_lfms_notification(
          v_recipient, 'New announcement: ' || new.title,
          'A new announcement has been published.', 'announcement',
          'announcement:published:' || new.id::text || ':' || new.published_at::text,
          'announcement', new.id, jsonb_build_object('event','published','category',new.category)
        );
      end loop;
    end if;
  end if;

  if tg_op = 'DELETE' then
    return old;
  end if;
  return new;
end;
$function$;

revoke all on function private.enqueue_lfms_notification(uuid,text,text,text,text,text,uuid,jsonb) from public, anon, authenticated;
revoke all on function private.handle_lfms_notification_event() from public, anon, authenticated;

create trigger lfms_notifications_reservations
after insert or update on public.reservations
for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_borrow_requests
after insert or update on public.borrow_requests
for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_borrow_transactions
after insert on public.borrow_transactions
for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_return_transactions
after insert on public.return_transactions
for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_maintenance_requests
after insert or update on public.maintenance_requests
for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_incident_reports
after insert or update on public.incident_reports
for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_announcements
after insert or update on public.announcements
for each row execute function private.handle_lfms_notification_event();
