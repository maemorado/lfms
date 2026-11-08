
create or replace function public.require_borrowing_rpc()
returns trigger
language plpgsql
security invoker
set search_path = ''
as $function$
declare
  v_actor uuid;
  v_marker text;
begin
  v_actor := auth.uid();
  v_marker := current_setting('app.borrowing_rpc', true);

  if v_actor is null
     or v_marker is distinct from (v_actor::text || ':' || txid_current()::text) then
    raise exception 'Borrowing records must be changed through the authorized borrowing RPC'
      using errcode = '42501';
  end if;

  if tg_table_name = 'borrow_requests'
     and tg_op = 'UPDATE'
     and new.status = 'approved'
     and exists (
       select 1
       from public.borrow_request_items bri
       join public.inventory_items i on i.id = bri.item_id
       where bri.borrow_request_id = new.id
         and i.status <> 'active'
     ) then
    raise exception 'Inactive inventory items cannot be approved for borrowing'
      using errcode = '23514';
  end if;

  if tg_table_name = 'borrow_transaction_items'
     and tg_op = 'INSERT'
     and not exists (
       select 1 from public.inventory_items i
       where i.id = new.item_id and i.status = 'active'
     ) then
    raise exception 'Inactive inventory items cannot be released'
      using errcode = '23514';
  end if;

  if tg_op = 'DELETE' then return old; end if;
  return new;
end;
$function$;
