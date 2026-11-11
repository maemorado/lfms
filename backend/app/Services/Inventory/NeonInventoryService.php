<?php

namespace App\Services\Inventory;

use App\Exceptions\InventoryException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NeonInventoryService
{
    public function listItems(array $options): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 20)));
        $sort = in_array($options['sort'] ?? 'name', ['name', 'item_code', 'item_type', 'status', 'created_at'], true)
            ? (string) ($options['sort'] ?? 'name')
            : 'name';
        $direction = ($options['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = $this->itemQuery();
        if (!empty($options['status'])) $query->where('i.status', $options['status']);
        if (!empty($options['item_type'])) $query->where('i.item_type', $options['item_type']);
        if (!empty($options['category_id'])) $query->where('i.category_id', $options['category_id']);
        if (!empty($options['search'])) {
            $term = '%'.$this->escapeLike((string) $options['search']).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('i.name', 'ilike', $term)
                    ->orWhere('i.item_code', 'ilike', $term);
            });
        }

        $paginator = $query
            ->orderBy('i.'.$sort, $direction)
            ->paginate($perPage, ['i.*', 'c.id as category_ref_id', 'c.name as category_name'], 'page', $page);

        $items = array_map(
            fn (object $row): array => $this->hydrateItem($row),
            $paginator->items(),
        );

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
        ];
    }

    public function findItem(string $id): array
    {
        $this->uuid($id);
        $row = $this->itemQuery()->where('i.id', $id)->first();
        if (!$row) throw new InventoryException('The inventory item was not found.', 404);

        return $this->hydrateItem($row);
    }

    public function createItem(array $data): array
    {
        $id = (string) Str::uuid();
        try {
            DB::table('inventory_items')->insert([
                'id' => $id,
                'name' => trim($data['name']),
                'item_code' => trim($data['item_code']),
                'item_type' => $data['item_type'],
                'category_id' => $data['category_id'] ?? null,
                'unit' => trim($data['unit']),
                'description' => $data['description'] ?? null,
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->findItem($id);
    }

    public function updateItem(string $id, array $data): array
    {
        $this->uuid($id);
        $updates = [];
        foreach (['name', 'item_code', 'category_id', 'unit', 'description', 'minimum_stock', 'status'] as $field) {
            if (!array_key_exists($field, $data)) continue;
            $updates[$field] = in_array($field, ['name', 'item_code', 'unit'], true) && is_string($data[$field])
                ? trim($data[$field])
                : $data[$field];
        }

        if ($updates === []) throw new InventoryException('At least one inventory field is required.', 422);
        $updates['updated_at'] = now();

        try {
            DB::table('inventory_items')->where('id', $id)->update($updates);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->findItem($id);
    }

    public function archiveItem(string $id): array
    {
        $item = $this->findItem($id);
        if ($item['status'] === 'inactive') return $item;
        if ((float) $item['quantity_on_hand'] > 0) {
            throw new InventoryException('This item cannot be archived while stock remains on hand.', 409);
        }

        foreach ($item['equipment_assets'] as $asset) {
            if (!in_array($asset['status'] ?? '', ['retired', 'missing'], true)) {
                throw new InventoryException('Retire or mark every equipment asset missing before archiving this item.', 409);
            }
        }

        return $this->updateItem($id, ['status' => 'inactive']);
    }

    public function categories(): array
    {
        return DB::table('item_categories')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'created_at'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    public function createCategory(array $data): array
    {
        $id = (string) Str::uuid();
        try {
            DB::table('item_categories')->insert([
                'id' => $id,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return (array) DB::table('item_categories')->where('id', $id)->first();
    }

    public function updateCategory(string $id, array $data): array
    {
        $this->uuid($id);
        $updates = [];
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $field === 'name' ? trim((string) $data[$field]) : $data[$field];
            }
        }
        if ($updates === []) throw new InventoryException('At least one category field is required.', 422);

        try {
            DB::table('item_categories')->where('id', $id)->update($updates);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        $row = DB::table('item_categories')->where('id', $id)->first();
        if (!$row) throw new InventoryException('The category was not found.', 404);
        return (array) $row;
    }

    public function deleteCategory(string $id): void
    {
        $this->uuid($id);
        if (DB::table('inventory_items')->where('category_id', $id)->exists()) {
            throw new InventoryException('This category is still assigned to inventory items and cannot be deleted.', 409);
        }

        $deleted = DB::table('item_categories')->where('id', $id)->delete();
        if ($deleted === 0) throw new InventoryException('The category was not found.', 404);
    }

    public function locations(): array
    {
        return DB::table('inventory_locations as l')
            ->leftJoin('facilities as f', 'f.id', '=', 'l.facility_id')
            ->orderBy('l.name')
            ->get([
                'l.id', 'l.name', 'l.description', 'l.facility_id',
                'f.id as facility_ref_id', 'f.name as facility_name', 'f.code as facility_code',
            ])
            ->map(function (object $row): array {
                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'description' => $row->description,
                    'facility_id' => $row->facility_id,
                    'facilities' => $row->facility_ref_id === null ? null : [
                        'id' => $row->facility_ref_id,
                        'name' => $row->facility_name,
                        'code' => $row->facility_code,
                    ],
                ];
            })
            ->all();
    }

    public function assets(string $itemId): array
    {
        $this->uuid($itemId);
        return DB::table('equipment_assets as a')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'a.location_id')
            ->leftJoin('facilities as f', 'f.id', '=', 'l.facility_id')
            ->where('a.item_id', $itemId)
            ->orderBy('a.asset_tag')
            ->get([
                'a.id', 'a.item_id', 'a.asset_tag', 'a.serial_number', 'a.condition', 'a.status',
                'a.location_id', 'a.created_at', 'a.updated_at',
                'l.id as location_ref_id', 'l.id as location_ref_id', 'l.name as location_name', 'l.facility_id',
                'f.id as facility_ref_id', 'f.name as facility_name', 'f.code as facility_code',
            ])
            ->map(fn (object $row): array => $this->normalizeAsset($row))
            ->all();
    }

    public function createAsset(string $itemId, array $data): array
    {
        $this->uuid($itemId);
        if ($this->findItem($itemId)['item_type'] !== 'equipment') {
            throw new InventoryException('Individual assets can only be added to equipment items.', 422);
        }

        $id = (string) Str::uuid();
        try {
            DB::table('equipment_assets')->insert([
                'id' => $id,
                'item_id' => $itemId,
                'asset_tag' => trim($data['asset_tag']),
                'serial_number' => $data['serial_number'] ?? null,
                'condition' => $data['condition'] ?? 'good',
                'status' => $data['status'] ?? 'available',
                'location_id' => $data['location_id'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->findAsset($itemId, $id);
    }

    public function updateAsset(string $itemId, string $assetId, array $data): array
    {
        $this->uuid($itemId);
        $this->uuid($assetId);
        $updates = [];
        foreach (['asset_tag', 'serial_number', 'condition', 'status', 'location_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $field === 'asset_tag' && is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            }
        }
        if ($updates === []) throw new InventoryException('At least one asset field is required.', 422);
        $updates['updated_at'] = now();

        try {
            DB::table('equipment_assets')->where('id', $assetId)->where('item_id', $itemId)->update($updates);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->findAsset($itemId, $assetId);
    }

    public function moveStock(string $itemId, array $data): array
    {
        $this->uuid($itemId);
        if ($this->findItem($itemId)['item_type'] !== 'consumable') {
            throw new InventoryException('Stock movements are for consumable items. Track equipment using individual asset records.', 422);
        }

        try {
            $movement = DB::selectOne(
                'select * from public.apply_inventory_stock_movement(?, ?, ?, ?, ?, ?, ?)',
                [
                    $itemId,
                    $data['location_id'],
                    $data['movement_type'],
                    $data['quantity'],
                    $data['remarks'] ?? null,
                    $data['reference_type'] ?? null,
                    $data['reference_id'] ?? null,
                ],
            );
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        if (!$movement) throw new InventoryException('The stock movement did not return a result.', 502);

        return [
            'movement' => [
                'movement_id' => $movement->movement_id,
                'stock_id' => $movement->stock_id,
                'resulting_quantity' => (float) $movement->resulting_quantity,
            ],
            'item' => $this->findItem($itemId),
        ];
    }

    public function movements(string $itemId, int $page = 1, int $perPage = 20): array
    {
        $this->uuid($itemId);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $paginator = DB::table('inventory_movements as m')
            ->leftJoin('inventory_items as i', 'i.id', '=', 'm.item_id')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'm.location_id')
            ->leftJoin('profiles as p', 'p.id', '=', 'm.performed_by')
            ->where('m.item_id', $itemId)
            ->orderByDesc('m.created_at')
            ->paginate($perPage, [
                'm.*',
                'i.id as item_ref_id', 'i.name as item_name', 'i.item_code', 'i.item_type', 'i.unit',
                'l.id as location_ref_id', 'l.name as location_name',
                'p.id as performer_ref_id', 'p.full_name as performer_name', 'p.email as performer_email',
            ], 'page', $page);

        $movements = $paginator->getCollection()
            ->map(fn (object $row): array => $this->normalizeMovement($row))
            ->all();

        return [
            'movements' => $movements,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
        ];
    }

    private function itemQuery(): Builder
    {
        return DB::table('inventory_items as i')
            ->leftJoin('item_categories as c', 'c.id', '=', 'i.category_id')
            ->select('i.*', 'c.id as category_ref_id', 'c.name as category_name');
    }

    private function hydrateItem(object $row): array
    {
        $item = (array) $row;
        $item['item_categories'] = $item['category_ref_id'] === null ? null : [
            'id' => $item['category_ref_id'],
            'name' => $item['category_name'],
        ];
        unset($item['category_ref_id'], $item['category_name']);

        $item['inventory_stock'] = DB::table('inventory_stock as s')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 's.location_id')
            ->leftJoin('facilities as f', 'f.id', '=', 'l.facility_id')
            ->where('s.item_id', $item['id'])
            ->orderBy('l.name')
            ->get([
                's.id', 's.quantity', 's.location_id',
                'l.id as location_ref_id', 'l.name as location_name', 'l.facility_id',
                'f.id as facility_ref_id', 'f.name as facility_name', 'f.code as facility_code',
            ])
            ->map(function (object $stock): array {
                return [
                    'id' => $stock->id,
                    'quantity' => (float) $stock->quantity,
                    'location_id' => $stock->location_id,
                    'inventory_locations' => $stock->location_ref_id === null ? null : [
                        'id' => $stock->location_ref_id,
                        'name' => $stock->location_name,
                        'facility_id' => $stock->facility_id,
                        'facilities' => $stock->facility_ref_id === null ? null : [
                            'id' => $stock->facility_ref_id,
                            'name' => $stock->facility_name,
                            'code' => $stock->facility_code,
                        ],
                    ],
                ];
            })
            ->all();

        $item['equipment_assets'] = DB::table('equipment_assets')
            ->where('item_id', $item['id'])
            ->orderBy('asset_tag')
            ->get(['id', 'asset_tag', 'serial_number', 'condition', 'status', 'location_id'])
            ->map(fn (object $asset): array => (array) $asset)
            ->all();

        return $item;
    }

    private function findAsset(string $itemId, string $assetId): array
    {
        $row = DB::table('equipment_assets as a')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'a.location_id')
            ->leftJoin('facilities as f', 'f.id', '=', 'l.facility_id')
            ->where('a.item_id', $itemId)
            ->where('a.id', $assetId)
            ->first([
                'a.id', 'a.item_id', 'a.asset_tag', 'a.serial_number', 'a.condition', 'a.status',
                'a.location_id', 'a.created_at', 'a.updated_at',
                'l.id as location_ref_id', 'l.name as location_name', 'l.facility_id',
                'f.id as facility_ref_id', 'f.name as facility_name', 'f.code as facility_code',
            ]);

        if (!$row) throw new InventoryException('The equipment asset was not found.', 404);
        return $this->normalizeAsset($row);
    }

    private function normalizeAsset(object $row): array
    {
        $asset = (array) $row;
        $asset['inventory_locations'] = $row->location_ref_id === null ? null : [
            'id' => $row->location_ref_id,
            'name' => $row->location_name,
            'facility_id' => $row->facility_id,
            'facilities' => $row->facility_ref_id === null ? null : [
                'id' => $row->facility_ref_id,
                'name' => $row->facility_name,
                'code' => $row->facility_code,
            ],
        ];
        unset(
            $asset['location_ref_id'], $asset['location_name'], $asset['facility_id'],
            $asset['facility_ref_id'], $asset['facility_name'], $asset['facility_code'],
        );

        return $asset;
    }

    private function normalizeMovement(object $row): array
    {
        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'inventory_items' => $row->item_ref_id === null ? null : [
                'id' => $row->item_ref_id,
                'name' => $row->item_name,
                'item_code' => $row->item_code,
                'item_type' => $row->item_type,
                'unit' => $row->unit,
            ],
            'location_id' => $row->location_id,
            'inventory_locations' => $row->location_ref_id === null ? null : [
                'id' => $row->location_ref_id,
                'name' => $row->location_name,
            ],
            'movement_type' => $row->movement_type,
            'quantity' => (float) $row->quantity,
            'reference_type' => $row->reference_type,
            'reference_id' => $row->reference_id,
            'performed_by' => $row->performed_by,
            'profiles' => $row->performer_ref_id === null ? null : [
                'id' => $row->performer_ref_id,
                'full_name' => $row->performer_name,
                'email' => $row->performer_email,
            ],
            'remarks' => $row->remarks,
            'created_at' => $row->created_at,
        ];
    }

    private function uuid(string $id): void
    {
        if (!Str::isUuid($id)) throw new InventoryException('The supplied inventory identifier is invalid.', 422);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function translateWriteFailure(QueryException $exception): never
    {
        $state = $exception->errorInfo[0] ?? (string) $exception->getCode();
        $raw = strtolower($exception->getMessage());
        if (str_contains($raw, 'insufficient stock')) {
            throw new InventoryException('There is not enough stock at this location for that withdrawal.', 409, $exception);
        }
        [$message, $status] = match ($state) {
            '23505' => ['The inventory change conflicts with an existing record.', 409],
            '23503' => ['The selected category, location, or related record is invalid.', 422],
            '23514', '22023' => ['The inventory data violates an existing database rule.', 422],
            '42501' => ['Your account does not have permission to perform this inventory operation.', 403],
            '28000' => ['Your authenticated identity is required for this inventory operation.', 401],
            default => ['The inventory service could not complete the request.', 502],
        };

        throw new InventoryException($message, $status, $exception);
    }
}
