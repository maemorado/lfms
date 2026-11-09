-- Correct table-specific field access in the shared borrowing guard trigger.
-- This forward migration versions the correction already verified in development.
-- It does not alter trigger attachments, grants, or RLS policies.

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
  v_actor := auth.uid();
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
