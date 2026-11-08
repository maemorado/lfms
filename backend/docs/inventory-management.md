# Phase 7 — Inventory Management

## Existing data model
The module uses the existing Supabase tables only: `inventory_items`, `item_categories`, `equipment_assets`, `inventory_locations`, `inventory_stock`, and `inventory_movements`. Facility association is through `inventory_locations.facility_id`. No schema migration is introduced by this feature.

## API
All endpoints require the Supabase bearer token and the existing LFMS authorization context.
- `GET /api/v1/inventory` — search, item type/category/status filters, sort, pagination.
- `GET /api/v1/inventory/{itemId}` — item details, stock by location and equipment assets.
- `POST /api/v1/inventory`, `PATCH /api/v1/inventory/{itemId}` — Admin item create/update.
- `POST /api/v1/inventory/{itemId}/archive` — Admin archive; requires zero consumable stock and all equipment assets retired or missing.
- `GET/POST/PATCH/DELETE /api/v1/inventory/categories...` — category read and Admin CRUD.
- `GET /api/v1/inventory/locations` — location/facility lookup.
- `GET/POST /api/v1/inventory/{itemId}/assets...` — equipment asset records.
- `GET/POST /api/v1/inventory/{itemId}/movements...` — history and stock movement.

## Authorization and data safety
- Read endpoints require `inventory.view`; mutations require `inventory.manage`.
- Laravel forwards the verified caller JWT and publishable key to Supabase PostgREST. It does not use a service-role/secret key to read or mutate inventory.
- Stock-in/out and adjustments call `apply_inventory_stock_movement` with the caller JWT. Laravel never writes `inventory_stock` or inserts `inventory_movements` directly.
- Database constraints and the existing RPC enforce positive movement quantities and prevent negative stock.
- Equipment status and condition values are restricted to the existing database allowlists.
- Archive uses the existing `inactive` item status; no archive column is added.

## Known limitations requiring follow-up
- The existing `inventory_movements` INSERT RLS policy still permits an authorized manager to insert a movement row directly without an associated stock change. The application never uses that path; closing it requires a separately approved database policy change.
- Archive eligibility is checked before changing item status over PostgREST. If strict serialization against a simultaneous stock movement is required, a dedicated atomic archive RPC/database change needs separate approval.
- The Supabase project has not been used for live inventory movement tests. Feature tests use HTTP fakes and mocked service boundaries only.
