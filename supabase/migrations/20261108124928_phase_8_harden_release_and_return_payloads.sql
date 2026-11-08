
create unique index if not exists borrow_transaction_items_unique_consumable_per_transaction
  on public.borrow_transaction_items (transaction_id, item_id)
  where asset_id is null;
create unique index if not exists return_transaction_items_unique_consumable_per_return
  on public.return_transaction_items (return_id, item_id)
  where asset_id is null;

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
  v_actor uuid := auth.uid();
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
  v_actor uuid:=auth.uid();
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
