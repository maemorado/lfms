-- LFMS Neon identity, workflow functions, and trigger migration.

-- Source-derived function bodies are adapted for Neon; all private.current_user_id() calls become the trusted transaction-local identity helper.

-- Supabase auth.users triggers are intentionally not created: Laravel provisions profiles using the exact Supabase Auth UUID.

create schema if not exists private;

do $role$ begin if not exists (select 1 from pg_roles where rolname='lfms_app') then create role lfms_app nologin nosuperuser nocreatedb nocreaterole noinherit nobypassrls; end if; if exists (select 1 from pg_roles where rolname='lfms_app' and (rolcanlogin or rolsuper or rolbypassrls or rolcreatedb or rolcreaterole)) then raise exception 'lfms_app exists with unsafe role attributes'; end if; end $role$;

revoke all on schema private from public;

grant usage on schema private to lfms_app;

create or replace function private.current_user_id()
returns uuid
language sql
stable
set search_path = ''
as $function$
  select nullif(current_setting('app.current_user_id', true), '')::uuid;
$function$;

-- Adapted from supabase/migrations/20261107120028_phase_2_facility_management.sql
create or replace function public.set_updated_at()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

-- Adapted from supabase/migrations/20261107120628_phase_4_inventory_management.sql
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

-- Adapted from supabase/migrations/20261107121336_phase_7_notifications_and_auditing.sql
create or replace function public.capture_row_audit()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  old_row jsonb;
  new_row jsonb;
  target_id uuid;
  actor_id uuid;
begin
  actor_id := nullif(current_setting('app.current_user_id', true), '')::uuid;

  if tg_op = 'INSERT' then
    new_row := to_jsonb(new);
    target_id := (new_row ->> 'id')::uuid;
  elsif tg_op = 'UPDATE' then
    old_row := to_jsonb(old);
    new_row := to_jsonb(new);
    target_id := (new_row ->> 'id')::uuid;
  else
    old_row := to_jsonb(old);
    target_id := (old_row ->> 'id')::uuid;
  end if;

  insert into public.audit_logs (user_id, table_name, record_id, action, old_values, new_values)
  values (actor_id, tg_table_name, target_id, tg_op, old_row, new_row);

  if tg_op = 'DELETE' then
    return old;
  end if;
  return new;
end;
$$;

-- Adapted from supabase/migrations/20261107121336_phase_7_notifications_and_auditing.sql
create or replace function public.prevent_audit_log_mutation()
returns trigger
language plpgsql
set search_path = ''
as $$
begin
  raise exception 'Audit logs are immutable'
    using errcode = '42501';
end;
$$;

-- Adapted from supabase/migrations/20261107133622_phase_10_role_based_rls_policies.sql
create or replace function private.has_permission(required_permission text)
returns boolean
language sql
stable
security definer
set search_path = ''
as $$
  select exists (
    select 1
    from public.profiles p
    join public.user_roles ur on ur.user_id = p.id
    join public.role_permissions rp on rp.role_id = ur.role_id
    join public.permissions perm on perm.id = rp.permission_id
    where p.id = (select private.current_user_id())
      and p.status = 'active'
      and perm.name = required_permission
  );
$$;

-- Adapted from supabase/migrations/20261108114836_add_atomic_inventory_stock_movements.sql
create or replace function public.require_inventory_stock_rpc()
returns trigger
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_expected_marker text;
begin
  if private.current_user_id() is null
     or not private.has_permission('inventory.manage') then
    raise exception 'Permission denied: inventory.manage is required'
      using errcode = '42501';
  end if;

  v_expected_marker := private.current_user_id()::text || ':' || txid_current()::text;

  if current_setting('app.inventory_stock_rpc', true) is distinct from v_expected_marker then
    raise exception 'Inventory stock must be changed through apply_inventory_stock_movement'
      using errcode = '42501';
  end if;

  return new;
end;
$function$;

-- Adapted from supabase/migrations/20261108114836_add_atomic_inventory_stock_movements.sql
create or replace function public.apply_inventory_stock_movement(
  p_item_id uuid,
  p_location_id uuid,
  p_movement_type text,
  p_quantity numeric,
  p_remarks text default null,
  p_reference_type text default null,
  p_reference_id uuid default null
)
returns table (
  movement_id uuid,
  stock_id uuid,
  resulting_quantity numeric
)
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_actor uuid;
  v_delta numeric;
  v_stock_id uuid;
  v_resulting_quantity numeric;
  v_movement_id uuid;
  v_marker text;
begin
  v_actor := private.current_user_id();

  if v_actor is null then
    raise exception 'Authentication is required'
      using errcode = '28000';
  end if;

  if not private.has_permission('inventory.manage') then
    raise exception 'Permission denied: inventory.manage is required'
      using errcode = '42501';
  end if;

  if p_quantity is null
     or p_quantity <= 0
     or p_quantity::text in ('NaN', 'Infinity', '-Infinity') then
    raise exception 'Quantity must be a finite positive number'
      using errcode = '22023';
  end if;

  if p_movement_type not in ('stock_in', 'stock_out', 'adjustment_in', 'adjustment_out') then
    raise exception 'Unsupported movement type. Transfers require paired source and destination handling.'
      using errcode = '22023';
  end if;

  if not exists (
    select 1
    from public.inventory_items i
    where i.id = p_item_id
      and i.status = 'active'
  ) then
    raise exception 'Inventory item does not exist or is not active'
      using errcode = '22023';
  end if;

  if not exists (
    select 1
    from public.inventory_locations l
    where l.id = p_location_id
  ) then
    raise exception 'Inventory location does not exist'
      using errcode = '22023';
  end if;

  v_delta := case
    when p_movement_type in ('stock_in', 'adjustment_in') then p_quantity
    else -p_quantity
  end;

  v_marker := v_actor::text || ':' || txid_current()::text;

  perform set_config('app.current_user_id', v_actor::text, true);
  perform set_config('app.inventory_stock_rpc', v_marker, true);

  if v_delta > 0 then
    insert into public.inventory_stock (item_id, location_id, quantity)
    values (p_item_id, p_location_id, v_delta)
    on conflict (item_id, location_id)
    do update set quantity = public.inventory_stock.quantity + excluded.quantity
    returning id, quantity
    into v_stock_id, v_resulting_quantity;
  else
    update public.inventory_stock s
       set quantity = s.quantity + v_delta
     where s.item_id = p_item_id
       and s.location_id = p_location_id
       and s.quantity >= p_quantity
    returning s.id, s.quantity
    into v_stock_id, v_resulting_quantity;

    if not found then
      raise exception 'Insufficient stock for this movement'
        using errcode = '23514';
    end if;
  end if;

  perform set_config('app.inventory_stock_rpc', '', true);

  insert into public.inventory_movements (
    item_id,
    location_id,
    movement_type,
    quantity,
    reference_type,
    reference_id,
    performed_by,
    remarks
  )
  values (
    p_item_id,
    p_location_id,
    p_movement_type,
    p_quantity,
    p_reference_type,
    p_reference_id,
    v_actor,
    p_remarks
  )
  returning id into v_movement_id;

  return query
  select v_movement_id, v_stock_id, v_resulting_quantity;

exception
  when others then
    perform set_config('app.inventory_stock_rpc', '', true);
    raise;
end;
$function$;

-- Adapted from supabase/migrations/20261109084548_fix_borrowing_rpc_trigger_column_access.sql
CREATE OR REPLACE FUNCTION public.require_borrowing_rpc()
RETURNS trigger
LANGUAGE plpgsql
SECURITY INVOKER
SET search_path = ''
AS $function$
DECLARE
  v_actor uuid;
  v_marker text;
BEGIN
  v_actor := private.current_user_id();
  v_marker := current_setting('app.borrowing_rpc', true);

  IF v_actor IS NULL
     OR v_marker IS DISTINCT FROM (v_actor::text || ':' || txid_current()::text) THEN
    RAISE EXCEPTION 'Borrowing records must be changed through the authorized borrowing RPC'
      USING ERRCODE = '42501';
  END IF;

  -- Access only columns that exist on the table currently firing this trigger.
  IF TG_TABLE_NAME = 'borrow_requests' THEN
    IF TG_OP = 'UPDATE' AND NEW.status = 'approved' THEN
      IF EXISTS (
        SELECT 1
        FROM public.borrow_request_items bri
        JOIN public.inventory_items i ON i.id = bri.item_id
        WHERE bri.borrow_request_id = NEW.id
          AND i.status <> 'active'
      ) THEN
        RAISE EXCEPTION 'Inactive inventory items cannot be approved for borrowing'
          USING ERRCODE = '23514';
      END IF;
    END IF;

  ELSIF TG_TABLE_NAME = 'borrow_transaction_items' THEN
    IF TG_OP = 'INSERT' THEN
      IF NOT EXISTS (
        SELECT 1
        FROM public.inventory_items i
        WHERE i.id = NEW.item_id
          AND i.status = 'active'
      ) THEN
        RAISE EXCEPTION 'Inactive inventory items cannot be released'
          USING ERRCODE = '23514';
      END IF;
    END IF;
  END IF;

  IF TG_OP = 'DELETE' THEN
    RETURN OLD;
  END IF;
  RETURN NEW;
END;
$function$;

-- Adapted from supabase/migrations/20261108123440_phase_8_borrowing_returns_atomic_workflows.sql
create or replace function public.create_borrow_request(
  p_expected_return_at timestamptz,
  p_remarks text,
  p_items jsonb,
  p_reservation_id uuid default null
)
returns jsonb
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_actor uuid := private.current_user_id();
  v_request_id uuid;
  v_line jsonb;
  v_item_id uuid;
  v_quantity numeric;
  v_item_type text;
  v_count integer;
  v_distinct integer;
begin
  if v_actor is null then
    raise exception 'Authentication is required' using errcode = '28000';
  end if;
  if not private.has_permission('borrowing.request') then
    raise exception 'Permission denied: borrowing.request is required' using errcode = '42501';
  end if;
  if not private.has_permission('inventory.view') then
    raise exception 'Permission denied: inventory.view is required' using errcode = '42501';
  end if;
  if p_expected_return_at is null or p_expected_return_at <= now() then
    raise exception 'Expected return date must be in the future' using errcode = '22023';
  end if;
  if jsonb_typeof(p_items) is distinct from 'array' or jsonb_array_length(p_items) = 0 then
    raise exception 'At least one borrowing item is required' using errcode = '22023';
  end if;

  select count(*), count(distinct (x.value->>'item_id'))
    into v_count, v_distinct
  from jsonb_array_elements(p_items) as x(value);
  if v_count <> v_distinct then
    raise exception 'Each inventory item may only appear once in a request' using errcode = '22023';
  end if;

  if p_reservation_id is not null and not exists (
    select 1 from public.reservations r
    where r.id = p_reservation_id and r.requester_id = v_actor
  ) then
    raise exception 'The linked reservation does not belong to the borrower' using errcode = '42501';
  end if;

  for v_line in select value from jsonb_array_elements(p_items) as x(value)
  loop
    v_item_id := (v_line->>'item_id')::uuid;
    v_quantity := (v_line->>'quantity')::numeric;
    if v_quantity is null or v_quantity <= 0 or v_quantity::text in ('NaN','Infinity','-Infinity') then
      raise exception 'Requested quantities must be finite positive numbers' using errcode = '22023';
    end if;
    select i.item_type into v_item_type
    from public.inventory_items i
    where i.id = v_item_id and i.status = 'active';
    if not found then
      raise exception 'An inventory item does not exist or is not active' using errcode = '22023';
    end if;
    if v_item_type = 'equipment' and v_quantity <> trunc(v_quantity) then
      raise exception 'Equipment quantities must be whole numbers' using errcode = '22023';
    end if;
  end loop;

  perform set_config('app.borrowing_rpc', v_actor::text || ':' || txid_current()::text, true);

  insert into public.borrow_requests (borrower_id, reservation_id, status, requested_at, expected_return_at, remarks)
  values (v_actor, p_reservation_id, 'pending', now(), p_expected_return_at, nullif(btrim(p_remarks), ''))
  returning id into v_request_id;

  for v_line in select value from jsonb_array_elements(p_items) as x(value)
  loop
    insert into public.borrow_request_items (borrow_request_id, item_id, quantity, remarks)
    values (
      v_request_id,
      (v_line->>'item_id')::uuid,
      (v_line->>'quantity')::numeric,
      nullif(btrim(v_line->>'remarks'), '')
    );
  end loop;

  perform set_config('app.borrowing_rpc', '', true);
  return jsonb_build_object('id', v_request_id, 'status', 'pending');
exception when others then
  perform set_config('app.borrowing_rpc', '', true);
  raise;
end;
$function$;

-- Adapted from supabase/migrations/20261108123440_phase_8_borrowing_returns_atomic_workflows.sql
create or replace function public.review_borrow_request(
  p_request_id uuid,
  p_decision text,
  p_remarks text default null
)
returns jsonb
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_actor uuid := private.current_user_id();
  v_request public.borrow_requests%rowtype;
  v_item record;
  v_available numeric;
  v_reserved numeric;
begin
  if v_actor is null then raise exception 'Authentication is required' using errcode = '28000'; end if;
  if not private.has_permission('borrowing.manage') then raise exception 'Permission denied: borrowing.manage is required' using errcode = '42501'; end if;
  if p_decision not in ('approved','rejected') then raise exception 'Decision must be approved or rejected' using errcode = '22023'; end if;
  if p_decision = 'rejected' and nullif(btrim(p_remarks), '') is null then
    raise exception 'A rejection reason is required' using errcode = '22023';
  end if;

  select * into v_request from public.borrow_requests where id = p_request_id for update;
  if not found then raise exception 'Borrowing request not found' using errcode = 'P0002'; end if;
  if v_request.status <> 'pending' then raise exception 'Only pending requests can be reviewed' using errcode = '23514'; end if;

  -- Serialize approvals for overlapping inventory items.
  perform i.id
  from public.inventory_items i
  join public.borrow_request_items bri on bri.item_id = i.id
  where bri.borrow_request_id = p_request_id
  order by i.id
  for update of i;

  if p_decision = 'approved' then
    for v_item in
      select i.id, i.item_type, bri.quantity
      from public.inventory_items i
      join public.borrow_request_items bri on bri.item_id = i.id
      where bri.borrow_request_id = p_request_id
      order by i.id
    loop
      if v_item.item_type = 'equipment' then
        select count(*)::numeric into v_available
        from public.equipment_assets ea
        where ea.item_id = v_item.id and ea.status = 'available';
      else
        select coalesce(sum(s.quantity), 0) into v_available
        from public.inventory_stock s
        where s.item_id = v_item.id;
      end if;

      select coalesce(sum(bri2.quantity), 0) into v_reserved
      from public.borrow_request_items bri2
      join public.borrow_requests br2 on br2.id = bri2.borrow_request_id
      left join public.borrow_transactions bt2 on bt2.borrow_request_id = br2.id
      where bri2.item_id = v_item.id
        and br2.status = 'approved'
        and br2.id <> p_request_id
        and bt2.id is null;

      if v_available - v_reserved < v_item.quantity then
        raise exception 'Insufficient available inventory to approve this request'
          using errcode = '23514';
      end if;
    end loop;
  end if;

  perform set_config('app.borrowing_rpc', v_actor::text || ':' || txid_current()::text, true);
  update public.borrow_requests
  set status = p_decision,
      reviewed_by = v_actor,
      reviewed_at = now(),
      review_remarks = nullif(btrim(p_remarks), '')
  where id = p_request_id;
  perform set_config('app.borrowing_rpc', '', true);

  return jsonb_build_object('id', p_request_id, 'status', p_decision, 'reviewed_by', v_actor);
exception when others then
  perform set_config('app.borrowing_rpc', '', true);
  raise;
end;
$function$;

-- Adapted from supabase/migrations/20261108123440_phase_8_borrowing_returns_atomic_workflows.sql
create or replace function public.cancel_borrow_request(p_request_id uuid)
returns jsonb
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_actor uuid := private.current_user_id();
  v_request public.borrow_requests%rowtype;
begin
  if v_actor is null then raise exception 'Authentication is required' using errcode = '28000'; end if;
  if not private.has_permission('borrowing.request') then raise exception 'Permission denied: borrowing.request is required' using errcode = '42501'; end if;
  select * into v_request from public.borrow_requests where id = p_request_id for update;
  if not found then raise exception 'Borrowing request not found' using errcode = 'P0002'; end if;
  if v_request.borrower_id <> v_actor then raise exception 'You may only cancel your own request' using errcode = '42501'; end if;
  if v_request.status <> 'pending' then raise exception 'Only pending requests can be cancelled' using errcode = '23514'; end if;

  perform set_config('app.borrowing_rpc', v_actor::text || ':' || txid_current()::text, true);
  update public.borrow_requests set status = 'cancelled' where id = p_request_id;
  perform set_config('app.borrowing_rpc', '', true);
  return jsonb_build_object('id', p_request_id, 'status', 'cancelled');
exception when others then
  perform set_config('app.borrowing_rpc', '', true);
  raise;
end;
$function$;

-- Adapted from supabase/migrations/20261108124928_phase_8_harden_release_and_return_payloads.sql
create or replace function public.release_borrow_request(
  p_request_id uuid,
  p_items jsonb,
  p_remarks text default null
)
returns jsonb
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_actor uuid := private.current_user_id();
  v_request public.borrow_requests%rowtype;
  v_transaction_id uuid;
  v_item record;
  v_line jsonb;
  v_quantity numeric;
  v_location_id uuid;
  v_asset_ids uuid[];
  v_asset_count integer;
  v_expected_count integer;
  v_rows integer;
  v_payload_count integer;
  v_payload_distinct integer;
begin
  if v_actor is null then raise exception 'Authentication is required' using errcode = '28000'; end if;
  if not private.has_permission('borrowing.manage') or not private.has_permission('inventory.manage') then
    raise exception 'Permission denied: borrowing.manage and inventory.manage are required' using errcode = '42501';
  end if;
  if jsonb_typeof(p_items) is distinct from 'array' or jsonb_array_length(p_items) = 0 then
    raise exception 'Release details are required' using errcode = '22023';
  end if;
  select count(*),count(distinct x.value->>'item_id') into v_payload_count,v_payload_distinct
  from jsonb_array_elements(p_items) as x(value);
  if v_payload_count <> v_payload_distinct then
    raise exception 'Each requested item must appear exactly once in release details' using errcode = '22023';
  end if;

  select * into v_request from public.borrow_requests where id = p_request_id for update;
  if not found then raise exception 'Borrowing request not found' using errcode = 'P0002'; end if;
  if v_request.status <> 'approved' then raise exception 'Only approved requests can be released' using errcode = '23514'; end if;
  if exists (select 1 from public.borrow_transactions where borrow_request_id = p_request_id) then
    raise exception 'This borrowing request has already been released' using errcode = '23505';
  end if;

  perform i.id from public.inventory_items i
  join public.borrow_request_items bri on bri.item_id=i.id
  where bri.borrow_request_id=p_request_id order by i.id for update of i;

  if v_payload_count <> (select count(*) from public.borrow_request_items where borrow_request_id=p_request_id) then
    raise exception 'Release details must include every requested inventory item exactly once' using errcode = '22023';
  end if;

  perform set_config('app.borrowing_rpc',v_actor::text||':'||txid_current()::text,true);
  insert into public.borrow_transactions (borrow_request_id,released_by,released_at,remarks)
  values (p_request_id,v_actor,now(),nullif(btrim(p_remarks),''))
  returning id into v_transaction_id;

  for v_item in
    select bri.item_id,bri.quantity as approved_quantity,i.item_type
    from public.borrow_request_items bri join public.inventory_items i on i.id=bri.item_id
    where bri.borrow_request_id=p_request_id order by bri.item_id
  loop
    v_line := null;
    select x.value into v_line from jsonb_array_elements(p_items) as x(value)
    where (x.value->>'item_id')::uuid=v_item.item_id;
    if v_line is null or (v_line->>'item_id')::uuid is distinct from v_item.item_id then
      raise exception 'A requested item is missing from the release details' using errcode = '22023';
    end if;

    v_quantity := (v_line->>'quantity')::numeric;
    v_location_id := (v_line->>'location_id')::uuid;
    if v_quantity is null or v_quantity<=0 or v_quantity::text in ('NaN','Infinity','-Infinity') or v_quantity>v_item.approved_quantity then
      raise exception 'Released quantity must be positive and cannot exceed the approved quantity' using errcode = '22023';
    end if;
    if not exists(select 1 from public.inventory_locations l where l.id=v_location_id) then
      raise exception 'A valid release inventory location is required' using errcode = '22023';
    end if;

    if v_item.item_type='equipment' then
      if v_quantity<>trunc(v_quantity) or jsonb_typeof(v_line->'asset_ids') is distinct from 'array' or jsonb_array_length(v_line->'asset_ids')<>v_quantity then
        raise exception 'Equipment releases require one unique asset ID for each released unit' using errcode = '22023';
      end if;
      select array_agg(z.value::uuid order by z.value::uuid),count(*),count(distinct z.value)
      into v_asset_ids,v_expected_count,v_asset_count
      from jsonb_array_elements_text(v_line->'asset_ids') as z(value);
      if v_expected_count<>v_asset_count then raise exception 'Equipment asset IDs must be unique' using errcode = '22023'; end if;
      perform ea.id from public.equipment_assets ea
      where ea.id=any(v_asset_ids) and ea.item_id=v_item.item_id and ea.status='available'
      order by ea.id for update;
      select count(*) into v_rows from public.equipment_assets ea
      where ea.id=any(v_asset_ids) and ea.item_id=v_item.item_id and ea.status='available';
      if v_rows<>v_expected_count then raise exception 'One or more selected equipment assets are no longer available' using errcode = '23514'; end if;
      update public.equipment_assets set status='borrowed',location_id=v_location_id,updated_at=now()
      where id=any(v_asset_ids) and item_id=v_item.item_id and status='available';
      get diagnostics v_rows=row_count;
      if v_rows<>v_expected_count then raise exception 'Equipment availability changed during release' using errcode = '23514'; end if;
      insert into public.borrow_transaction_items(transaction_id,item_id,asset_id,quantity,location_id)
      select v_transaction_id,v_item.item_id,x.asset_id,1,v_location_id from unnest(v_asset_ids) as x(asset_id);
    else
      perform public.apply_inventory_stock_movement(v_item.item_id,v_location_id,'stock_out',v_quantity,'Borrow request release','borrow_transaction',v_transaction_id);
      insert into public.borrow_transaction_items(transaction_id,item_id,asset_id,quantity,location_id)
      values(v_transaction_id,v_item.item_id,null,v_quantity,v_location_id);
    end if;
  end loop;
  perform set_config('app.borrowing_rpc','',true);
  return jsonb_build_object('id',v_transaction_id,'borrow_request_id',p_request_id,'released_by',v_actor);
exception when others then
  perform set_config('app.borrowing_rpc','',true);
  raise;
end;
$function$;

-- Adapted from supabase/migrations/20261108124928_phase_8_harden_release_and_return_payloads.sql
create or replace function public.process_borrow_return(
  p_transaction_id uuid,
  p_items jsonb,
  p_remarks text default null
)
returns jsonb
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_actor uuid:=private.current_user_id();
  v_request_id uuid;
  v_return_id uuid;
  v_line jsonb;
  v_item_id uuid;
  v_asset_id uuid;
  v_quantity numeric;
  v_condition text;
  v_item_remarks text;
  v_borrow_item public.borrow_transaction_items%rowtype;
  v_returned numeric;
  v_item_type text;
  v_rows integer;
  v_has_outstanding boolean;
  v_count integer;
  v_distinct integer;
begin
  if v_actor is null then raise exception 'Authentication is required' using errcode='28000'; end if;
  if not private.has_permission('borrowing.manage') or not private.has_permission('borrowing.return') then
    raise exception 'Permission denied: borrowing.manage and borrowing.return are required' using errcode='42501';
  end if;
  if jsonb_typeof(p_items) is distinct from 'array' or jsonb_array_length(p_items)=0 then
    raise exception 'Return details are required' using errcode='22023';
  end if;
  select count(*),count(distinct (x.value->>'item_id')||':'||coalesce(x.value->>'asset_id',''))
  into v_count,v_distinct from jsonb_array_elements(p_items) as x(value);
  if v_count<>v_distinct then raise exception 'Each borrowed item or asset may appear only once per return' using errcode='22023'; end if;

  select br.id into v_request_id from public.borrow_transactions bt
  join public.borrow_requests br on br.id=bt.borrow_request_id
  where bt.id=p_transaction_id for update of bt,br;
  if not found then raise exception 'Borrow transaction not found' using errcode='P0002'; end if;

  perform i.id from public.inventory_items i
  join public.borrow_transaction_items bti on bti.item_id=i.id
  where bti.transaction_id=p_transaction_id order by i.id for update of i;

  perform set_config('app.borrowing_rpc',v_actor::text||':'||txid_current()::text,true);
  insert into public.return_transactions(transaction_id,received_by,returned_at,remarks)
  values(p_transaction_id,v_actor,now(),nullif(btrim(p_remarks),'')) returning id into v_return_id;

  for v_line in select value from jsonb_array_elements(p_items) as x(value)
  loop
    v_item_id:=(v_line->>'item_id')::uuid;
    v_asset_id:=nullif(v_line->>'asset_id','')::uuid;
    v_quantity:=(v_line->>'quantity')::numeric;
    v_condition:=v_line->>'condition';
    v_item_remarks:=nullif(btrim(v_line->>'remarks'),'');
    if v_quantity is null or v_quantity<=0 or v_quantity::text in ('NaN','Infinity','-Infinity') then raise exception 'Returned quantities must be finite and positive' using errcode='22023'; end if;
    if v_condition not in ('new','good','fair','poor','damaged','missing') then raise exception 'A valid return condition is required' using errcode='22023'; end if;

    select * into v_borrow_item from public.borrow_transaction_items bti
    where bti.transaction_id=p_transaction_id and bti.item_id=v_item_id and bti.asset_id is not distinct from v_asset_id for update;
    if not found then raise exception 'The returned item does not belong to this borrowing transaction' using errcode='22023'; end if;
    select i.item_type into v_item_type from public.inventory_items i where i.id=v_item_id;
    if v_item_type='equipment' and (v_asset_id is null or v_quantity<>1) then raise exception 'Equipment assets must be returned individually' using errcode='22023'; end if;
    if v_item_type='consumable' and v_asset_id is not null then raise exception 'Consumable returns cannot specify an equipment asset' using errcode='22023'; end if;

    select coalesce(sum(rti.quantity),0) into v_returned
    from public.return_transactions rt join public.return_transaction_items rti on rti.return_id=rt.id
    where rt.transaction_id=p_transaction_id and rti.item_id=v_item_id and rti.asset_id is not distinct from v_asset_id;
    if v_returned+v_quantity>v_borrow_item.quantity then raise exception 'Returned quantity exceeds the outstanding borrowed quantity' using errcode='23514'; end if;

    insert into public.return_transaction_items(return_id,item_id,asset_id,quantity,condition,remarks)
    values(v_return_id,v_item_id,v_asset_id,v_quantity,v_condition,v_item_remarks);

    if v_item_type='equipment' then
      update public.equipment_assets
      set condition=case when v_condition='missing' then condition else v_condition end,
          status=case when v_condition='missing' then 'missing' when v_condition='damaged' then 'damaged' when v_condition='poor' then 'under_repair' else 'available' end,
          updated_at=now()
      where id=v_asset_id and status='borrowed';
      get diagnostics v_rows=row_count;
      if v_rows<>1 then raise exception 'Equipment asset is not currently marked as borrowed' using errcode='23514'; end if;
    elsif v_condition in ('new','good','fair') then
      if v_borrow_item.location_id is null then raise exception 'Original stock location is missing for this return' using errcode='23514'; end if;
      perform public.apply_inventory_stock_movement(v_item_id,v_borrow_item.location_id,'stock_in',v_quantity,'Borrow return received in usable condition','return_transaction',v_return_id);
    end if;
  end loop;

  select exists(
    select 1 from public.borrow_transaction_items bti
    where bti.transaction_id=p_transaction_id and bti.quantity>(
      select coalesce(sum(rti.quantity),0) from public.return_transactions rt
      join public.return_transaction_items rti on rti.return_id=rt.id
      where rt.transaction_id=p_transaction_id and rti.item_id=bti.item_id and rti.asset_id is not distinct from bti.asset_id
    )
  ) into v_has_outstanding;
  update public.borrow_requests set status=case when v_has_outstanding then 'partially_returned' else 'returned' end where id=v_request_id;
  perform set_config('app.borrowing_rpc','',true);
  return jsonb_build_object('id',v_return_id,'transaction_id',p_transaction_id,'status',case when v_has_outstanding then 'partially_returned' else 'returned' end,'received_by',v_actor);
exception when others then
  perform set_config('app.borrowing_rpc','',true);
  raise;
end;
$function$;

-- Adapted from supabase/migrations/20261108150037_phase_10_notifications_announcements.sql
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

-- Adapted from supabase/migrations/20261108150121_phase_10_notification_event_deduplication.sql
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
begin
  if tg_table_name = 'reservations' then
    if tg_op = 'INSERT' then
      for v_recipient in
        select distinct p.id from public.profiles p
        join public.user_roles ur on ur.user_id=p.id
        join public.roles r on r.id=ur.role_id
        where p.status='active' and r.name='admin'
      loop
        perform private.enqueue_lfms_notification(v_recipient,'New reservation submitted','A facility reservation is waiting for review.','reservation',
          'reservation:submitted:'||new.id::text||':'||new.created_at::text,'reservation',new.id,jsonb_build_object('event','submitted'));
      end loop;
    elsif new.status is distinct from old.status then
      perform private.enqueue_lfms_notification(new.requester_id,
        case new.status when 'approved' then 'Reservation approved' when 'rejected' then 'Reservation rejected' when 'cancelled' then 'Reservation cancelled' else 'Reservation status updated' end,
        'Your facility reservation status is now '||replace(new.status,'_',' ')||'.','reservation',
        'reservation:status:'||new.id::text||':'||new.status||':'||new.updated_at::text,'reservation',new.id,
        jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status));
      if new.status='cancelled' and private.current_user_id()=new.requester_id then
        for v_recipient in
          select distinct p.id from public.profiles p
          join public.user_roles ur on ur.user_id=p.id
          join public.roles r on r.id=ur.role_id
          where p.status='active' and r.name='admin'
        loop
          perform private.enqueue_lfms_notification(v_recipient,'Reservation cancelled by requester','A previously submitted facility reservation was cancelled.','reservation',
            'reservation:cancelled:'||new.id::text||':'||new.updated_at::text,'reservation',new.id,jsonb_build_object('event','cancelled'));
        end loop;
      end if;
    end if;

  elsif tg_table_name='borrow_requests' then
    if tg_op='INSERT' then
      for v_recipient in
        select distinct p.id from public.profiles p
        join public.user_roles ur on ur.user_id=p.id
        join public.roles r on r.id=ur.role_id
        where p.status='active' and r.name='admin'
      loop
        perform private.enqueue_lfms_notification(v_recipient,'New borrowing request','A borrowing request is waiting for review.','borrowing',
          'borrowing:submitted:'||new.id::text||':'||new.requested_at::text,'borrowing',new.id,jsonb_build_object('event','submitted'));
      end loop;
    elsif new.status is distinct from old.status and new.status not in ('returned','partially_returned') then
      perform private.enqueue_lfms_notification(new.borrower_id,
        case new.status when 'approved' then 'Borrowing request approved' when 'rejected' then 'Borrowing request rejected' when 'cancelled' then 'Borrowing request cancelled' else 'Borrowing request status updated' end,
        'Your borrowing request status is now '||replace(new.status,'_',' ')||'.','borrowing',
        'borrowing:status:'||new.id::text||':'||new.status||':'||coalesce(new.reviewed_at,now())::text,'borrowing',new.id,
        jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status));
      if new.status='cancelled' and private.current_user_id()=new.borrower_id then
        for v_recipient in
          select distinct p.id from public.profiles p
          join public.user_roles ur on ur.user_id=p.id
          join public.roles r on r.id=ur.role_id
          where p.status='active' and r.name='admin'
        loop
          perform private.enqueue_lfms_notification(v_recipient,'Borrowing request cancelled','A requester cancelled a borrowing request.','borrowing',
            'borrowing:cancelled:'||new.id::text||':'||coalesce(new.reviewed_at,now())::text,'borrowing',new.id,jsonb_build_object('event','cancelled'));
        end loop;
      end if;
    end if;

  elsif tg_table_name='borrow_transactions' and tg_op='INSERT' then
    select br.borrower_id,br.id into v_borrower,v_request_id from public.borrow_requests br where br.id=new.borrow_request_id;
    perform private.enqueue_lfms_notification(v_borrower,'Borrowed items released','The approved items for your borrowing request have been released.','borrowing',
      'borrowing:released:'||new.id::text,'borrowing',v_request_id,jsonb_build_object('event','items_released','transaction_id',new.id));

  elsif tg_table_name='return_transactions' and tg_op='INSERT' then
    select br.borrower_id,br.id into v_borrower,v_request_id
    from public.borrow_transactions bt join public.borrow_requests br on br.id=bt.borrow_request_id
    where bt.id=new.transaction_id;
    perform private.enqueue_lfms_notification(v_borrower,'Return processed','A return for your borrowed items has been processed.','return',
      'return:processed:'||new.id::text,'borrowing',v_request_id,jsonb_build_object('event','return_processed','return_id',new.id,'transaction_id',new.transaction_id));

  elsif tg_table_name='maintenance_requests' then
    if tg_op='INSERT' then
      for v_recipient in
        select distinct p.id from public.profiles p
        join public.user_roles ur on ur.user_id=p.id
        join public.roles r on r.id=ur.role_id
        where p.status='active' and r.name='admin'
      loop
        perform private.enqueue_lfms_notification(v_recipient,'New maintenance request','A maintenance request is waiting for review.','maintenance',
          'maintenance:submitted:'||new.id::text||':'||new.created_at::text,'maintenance',new.id,jsonb_build_object('event','submitted','priority',new.priority));
      end loop;
    elsif new.status is distinct from old.status then
      perform private.enqueue_lfms_notification(new.reported_by,
        case new.status when 'completed' then 'Maintenance completed' else 'Maintenance status updated' end,
        'Your maintenance request status is now '||replace(new.status,'_',' ')||'.','maintenance',
        'maintenance:status:'||new.id::text||':'||new.status||':'||new.updated_at::text,'maintenance',new.id,
        jsonb_build_object('event','status_changed','previous_status',old.status,'status',new.status));
    end if;

  elsif tg_table_name='incident_reports' then
    if tg_op='INSERT' then
      for v_recipient in
        select distinct p.id from public.profiles p
        join public.user_roles ur on ur.user_id=p.id
        join public.roles r on r.id=ur.role_id
        where p.status='active' and r.name='admin'
      loop
        perform private.enqueue_lfms_notification(v_recipient,'New incident report','An incident report is waiting for investigation.','incident',
          'incident:submitted:'||new.id::text||':'||new.created_at::text,'incident',new.id,jsonb_build_object('event','submitted','incident_type',new.incident_type));
      end loop;
    elsif new.status is distinct from old.status or new.resolution is distinct from old.resolution then
      v_event_suffix:=case when new.status is distinct from old.status then 'status:'||new.status else 'resolution' end;
      perform private.enqueue_lfms_notification(new.reported_by,
        case when new.status='resolved' and new.status is distinct from old.status then 'Incident resolved'
          when new.status is distinct from old.status then 'Incident status updated' else 'Incident investigation updated' end,
        case when new.status is distinct from old.status then 'Your incident report status is now '||replace(new.status,'_',' ')||'.'
          else 'The investigation notes for your incident report have been updated.' end,
        'incident','incident:'||v_event_suffix||':'||new.id::text||':'||new.updated_at::text,'incident',new.id,
        jsonb_build_object('event','investigation_updated','previous_status',old.status,'status',new.status,'resolution_updated',new.resolution is distinct from old.resolution));
    end if;

  elsif tg_table_name='announcements' then
    if new.status='published' and (tg_op='INSERT' or old.status is distinct from 'published' or old.published_at is distinct from new.published_at) then
      for v_recipient in
        select distinct p.id from public.profiles p
        join public.user_roles ur on ur.user_id=p.id
        join public.roles r on r.id=ur.role_id
        where p.status='active' and r.name in ('admin','staff_sa')
      loop
        perform private.enqueue_lfms_notification(v_recipient,'New announcement: '||new.title,'A new announcement has been published.','announcement',
          'announcement:published:'||new.id::text||':'||new.published_at::text,'announcement',new.id,jsonb_build_object('event','published','category',new.category));
      end loop;
    end if;
  end if;

  return new;
end;
$function$;

-- Adapted from supabase/migrations/20261108151127_phase_10_submitter_confirmation_notifications.sql
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

-- Trigger attachments preserved from the live final Supabase schema.

create trigger set_buildings_updated_at before update on public.buildings for each row execute function public.set_updated_at();

create trigger set_facilities_updated_at before update on public.facilities for each row execute function public.set_updated_at();

create trigger set_reservations_updated_at before update on public.reservations for each row execute function public.set_updated_at();

create trigger set_inventory_items_updated_at before update on public.inventory_items for each row execute function public.set_updated_at();

create trigger set_equipment_assets_updated_at before update on public.equipment_assets for each row execute function public.set_updated_at();

create trigger set_maintenance_requests_updated_at before update on public.maintenance_requests for each row execute function public.set_updated_at();

create trigger set_maintenance_schedules_updated_at before update on public.maintenance_schedules for each row execute function public.set_updated_at();

create trigger set_incident_reports_updated_at before update on public.incident_reports for each row execute function public.set_updated_at();

create trigger set_announcements_updated_at before update on public.announcements for each row execute function public.set_updated_at();

create trigger audit_profiles after insert or update or delete on public.profiles for each row execute function public.capture_row_audit();

create trigger audit_buildings after insert or update or delete on public.buildings for each row execute function public.capture_row_audit();

create trigger audit_facilities after insert or update or delete on public.facilities for each row execute function public.capture_row_audit();

create trigger audit_reservations after insert or update or delete on public.reservations for each row execute function public.capture_row_audit();

create trigger audit_inventory_items after insert or update or delete on public.inventory_items for each row execute function public.capture_row_audit();

create trigger audit_inventory_stock after insert or update or delete on public.inventory_stock for each row execute function public.capture_row_audit();

create trigger audit_equipment_assets after insert or update or delete on public.equipment_assets for each row execute function public.capture_row_audit();

create trigger audit_maintenance_requests after insert or update or delete on public.maintenance_requests for each row execute function public.capture_row_audit();

create trigger audit_incident_reports after insert or update or delete on public.incident_reports for each row execute function public.capture_row_audit();

create trigger audit_announcements after insert or update or delete on public.announcements for each row execute function public.capture_row_audit();

create trigger audit_logs_immutable before update or delete on public.audit_logs for each row execute function public.prevent_audit_log_mutation();

create trigger guard_inventory_stock_quantity before insert or update of quantity on public.inventory_stock for each row execute function public.guard_inventory_stock_nonnegative();

create trigger require_inventory_stock_rpc before insert or update on public.inventory_stock for each row execute function public.require_inventory_stock_rpc();

create trigger require_borrowing_rpc_borrow_requests before insert or update or delete on public.borrow_requests for each row execute function public.require_borrowing_rpc();

create trigger require_borrowing_rpc_borrow_request_items before insert or update or delete on public.borrow_request_items for each row execute function public.require_borrowing_rpc();

create trigger require_borrowing_rpc_borrow_transactions before insert or update or delete on public.borrow_transactions for each row execute function public.require_borrowing_rpc();

create trigger require_borrowing_rpc_borrow_transaction_items before insert or update or delete on public.borrow_transaction_items for each row execute function public.require_borrowing_rpc();

create trigger require_borrowing_rpc_return_transactions before insert or update or delete on public.return_transactions for each row execute function public.require_borrowing_rpc();

create trigger require_borrowing_rpc_return_transaction_items before insert or update or delete on public.return_transaction_items for each row execute function public.require_borrowing_rpc();

create trigger lfms_notifications_reservations after insert or update on public.reservations for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_borrow_requests after insert or update on public.borrow_requests for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_borrow_transactions after insert on public.borrow_transactions for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_return_transactions after insert on public.return_transactions for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_maintenance_requests after insert or update on public.maintenance_requests for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_incident_reports after insert or update on public.incident_reports for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_announcements after insert or update on public.announcements for each row execute function private.handle_lfms_notification_event();

create trigger lfms_notifications_reservations_submitter after insert on public.reservations for each row execute function private.notify_lfms_submitter_confirmation();

create trigger lfms_notifications_borrow_requests_submitter after insert on public.borrow_requests for each row execute function private.notify_lfms_submitter_confirmation();

create trigger lfms_notifications_maintenance_requests_submitter after insert on public.maintenance_requests for each row execute function private.notify_lfms_submitter_confirmation();

create trigger lfms_notifications_incident_reports_submitter after insert on public.incident_reports for each row execute function private.notify_lfms_submitter_confirmation();

-- Restrict direct execution. Only the RPCs called by Laravel are executable by lfms_app.

revoke all on all functions in schema private from public;

revoke all on function public.set_updated_at() from public, lfms_app;

revoke all on function public.guard_inventory_stock_nonnegative() from public, lfms_app;

revoke all on function public.capture_row_audit() from public, lfms_app;

revoke all on function public.prevent_audit_log_mutation() from public, lfms_app;

revoke all on function public.require_inventory_stock_rpc() from public, lfms_app;

revoke all on function public.require_borrowing_rpc() from public, lfms_app;

revoke all on function private.handle_lfms_notification_event() from public, lfms_app;

revoke all on function private.notify_lfms_submitter_confirmation() from public, lfms_app;

revoke all on function private.enqueue_lfms_notification(uuid,text,text,text,text,text,uuid,jsonb) from public, lfms_app;

grant execute on function private.current_user_id() to lfms_app;

grant execute on function private.has_permission(text) to lfms_app;

grant execute on function public.apply_inventory_stock_movement(p_item_id uuid, p_location_id uuid, p_movement_type text, p_quantity numeric, p_remarks text default null, p_reference_type text default null, p_reference_id uuid default null) to lfms_app;

grant execute on function public.create_borrow_request(p_expected_return_at timestamptz, p_remarks text, p_items jsonb, p_reservation_id uuid default null) to lfms_app;

grant execute on function public.review_borrow_request(p_request_id uuid, p_decision text, p_remarks text default null) to lfms_app;

grant execute on function public.cancel_borrow_request(p_request_id uuid) to lfms_app;

grant execute on function public.release_borrow_request(p_request_id uuid, p_items jsonb, p_remarks text default null) to lfms_app;

grant execute on function public.process_borrow_return(p_transaction_id uuid, p_items jsonb, p_remarks text default null) to lfms_app;