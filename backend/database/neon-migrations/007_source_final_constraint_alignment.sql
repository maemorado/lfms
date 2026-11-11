-- Forward correction for two check constraints that differ from the live
-- final Supabase schema. This migration intentionally replaces only the two
-- incorrect CHECK constraints; it does not alter or delete application rows.
--
-- DO NOT EXECUTE until separately approved. The preflight fails closed if any
-- current Neon row would violate the source-final constraint definitions.

DO $preflight$
BEGIN
  IF EXISTS (
    SELECT 1
    FROM public.equipment_assets
    WHERE condition NOT IN ('new', 'good', 'fair', 'poor', 'damaged')
  ) THEN
    RAISE EXCEPTION
      'Cannot align equipment_assets.condition: existing rows contain values not allowed by the source-final schema';
  END IF;

  IF EXISTS (
    SELECT 1
    FROM public.return_transaction_items
    WHERE condition IS NOT NULL
      AND condition NOT IN ('new', 'good', 'fair', 'poor', 'damaged', 'missing')
  ) THEN
    RAISE EXCEPTION
      'Cannot align return_transaction_items.condition: existing rows contain values not allowed by the source-final schema';
  END IF;
END
$preflight$;

ALTER TABLE public.equipment_assets
  DROP CONSTRAINT equipment_assets_condition_check;

ALTER TABLE public.equipment_assets
  ADD CONSTRAINT equipment_assets_condition_check
  CHECK (condition IN ('new', 'good', 'fair', 'poor', 'damaged'));

ALTER TABLE public.return_transaction_items
  DROP CONSTRAINT return_transaction_items_condition_check;

ALTER TABLE public.return_transaction_items
  ADD CONSTRAINT return_transaction_items_condition_check
  CHECK (condition IN ('new', 'good', 'fair', 'poor', 'damaged', 'missing'));
