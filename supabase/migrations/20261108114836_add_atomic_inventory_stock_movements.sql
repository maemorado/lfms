
begin;

create policy inventory_stock_select_inventory_manage
on public.inventory_stock
for select
to authenticated
using ((select private.has_permission('inventory.manage')));

create policy inventory_stock_insert_inventory_manage
on public.inventory_stock
for insert
to authenticated
with check ((select private.has_permission('inventory.manage')));

create policy inventory_stock_update_inventory_manage
on public.inventory_stock
for update
to authenticated
using ((select private.has_permission('inventory.manage')))
with check ((select private.has_permission('inventory.manage')));

grant insert, update on public.inventory_stock to authenticated;

create or replace function public.require_inventory_stock_rpc()
returns trigger
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_expected_marker text;
begin
  if auth.uid() is null
     or not private.has_permission('inventory.manage') then
    raise exception 'Permission denied: inventory.manage is required'
      using errcode = '42501';
  end if;

  v_expected_marker := auth.uid()::text || ':' || txid_current()::text;

  if current_setting('app.inventory_stock_rpc', true) is distinct from v_expected_marker then
    raise exception 'Inventory stock must be changed through apply_inventory_stock_movement'
      using errcode = '42501';
  end if;

  return new;
end;
$function$;

create trigger require_inventory_stock_rpc
before insert or update on public.inventory_stock
for each row
execute function public.require_inventory_stock_rpc();

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
  v_actor := auth.uid();

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

revoke all on function public.require_inventory_stock_rpc() from public, anon, authenticated;
revoke all on function public.apply_inventory_stock_movement(uuid, uuid, text, numeric, text, text, uuid) from public, anon;
grant execute on function public.apply_inventory_stock_movement(uuid, uuid, text, numeric, text, text, uuid) to authenticated;

commit;
