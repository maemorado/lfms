<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\InventoryIndexRequest;
use App\Http\Requests\Api\StoreEquipmentAssetRequest;
use App\Http\Requests\Api\StoreInventoryItemRequest;
use App\Http\Requests\Api\StoreInventoryMovementRequest;
use App\Http\Requests\Api\StoreItemCategoryRequest;
use App\Http\Requests\Api\UpdateEquipmentAssetRequest;
use App\Http\Requests\Api\UpdateInventoryItemRequest;
use App\Http\Requests\Api\UpdateItemCategoryRequest;
use App\Http\Resources\Api\InventoryItemResource;
use App\Http\Resources\Api\InventoryMovementResource;
use App\Services\Inventory\NeonInventoryService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryController extends Controller
{
    public function __construct(private readonly NeonInventoryService $inventory) {}

    public function index(InventoryIndexRequest $request): JsonResponse
    {
        $result = $this->inventory->listItems($request->options());

        return ApiResponse::success(
            ['items' => InventoryItemResource::collection($result['items'])->resolve()],
            'Inventory items retrieved successfully.',
            200,
            ['pagination' => $result['pagination']],
        );
    }

    public function show(Request $request, string $itemId): JsonResponse
    {
        return ApiResponse::success(
            ['item' => new InventoryItemResource($this->inventory->findItem($itemId))],
            'Inventory item retrieved successfully.',
        );
    }

    public function store(StoreInventoryItemRequest $request): JsonResponse
    {
        return ApiResponse::success(
            ['item' => new InventoryItemResource($this->inventory->createItem($request->validated()))],
            'Inventory item created successfully.',
            201,
        );
    }

    public function update(UpdateInventoryItemRequest $request, string $itemId): JsonResponse
    {
        $data = $request->validated();
        if ($data === []) return ApiResponse::error('At least one inventory field is required.', 422, 'validation_error');

        return ApiResponse::success(
            ['item' => new InventoryItemResource($this->inventory->updateItem($itemId, $data))],
            'Inventory item updated successfully.',
        );
    }

    public function archive(Request $request, string $itemId): JsonResponse
    {
        return ApiResponse::success(
            ['item' => new InventoryItemResource($this->inventory->archiveItem($itemId))],
            'Inventory item archived successfully.',
        );
    }

    public function categories(Request $request): JsonResponse
    {
        return ApiResponse::success(['categories' => $this->inventory->categories()], 'Inventory categories retrieved successfully.');
    }

    public function storeCategory(StoreItemCategoryRequest $request): JsonResponse
    {
        return ApiResponse::success(
            ['category' => $this->inventory->createCategory($request->validated())],
            'Inventory category created successfully.',
            201,
        );
    }

    public function updateCategory(UpdateItemCategoryRequest $request, string $categoryId): JsonResponse
    {
        $data = $request->validated();
        if ($data === []) return ApiResponse::error('At least one category field is required.', 422, 'validation_error');

        return ApiResponse::success(
            ['category' => $this->inventory->updateCategory($categoryId, $data)],
            'Inventory category updated successfully.',
        );
    }

    public function deleteCategory(Request $request, string $categoryId): JsonResponse
    {
        $this->inventory->deleteCategory($categoryId);
        return ApiResponse::success(null, 'Inventory category deleted successfully.');
    }

    public function locations(Request $request): JsonResponse
    {
        return ApiResponse::success(['locations' => $this->inventory->locations()], 'Inventory locations retrieved successfully.');
    }

    public function assets(Request $request, string $itemId): JsonResponse
    {
        return ApiResponse::success(['assets' => $this->inventory->assets($itemId)], 'Equipment assets retrieved successfully.');
    }

    public function storeAsset(StoreEquipmentAssetRequest $request, string $itemId): JsonResponse
    {
        return ApiResponse::success(
            ['asset' => $this->inventory->createAsset($itemId, $request->validated())],
            'Equipment asset created successfully.',
            201,
        );
    }

    public function updateAsset(UpdateEquipmentAssetRequest $request, string $itemId, string $assetId): JsonResponse
    {
        $data = $request->validated();
        if ($data === []) return ApiResponse::error('At least one asset field is required.', 422, 'validation_error');

        return ApiResponse::success(
            ['asset' => $this->inventory->updateAsset($itemId, $assetId, $data)],
            'Equipment asset updated successfully.',
        );
    }

    public function movements(Request $request, string $itemId): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $result = $this->inventory->movements(
            $itemId,
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
        );

        return ApiResponse::success(
            ['movements' => InventoryMovementResource::collection($result['movements'])->resolve()],
            'Inventory movement history retrieved successfully.',
            200,
            ['pagination' => $result['pagination']],
        );
    }

    public function storeMovement(StoreInventoryMovementRequest $request, string $itemId): JsonResponse
    {
        $result = $this->inventory->moveStock($itemId, $request->validated());

        return ApiResponse::success(
            ['movement' => $result['movement'], 'item' => new InventoryItemResource($result['item'])],
            'Inventory stock movement recorded successfully.',
            201,
        );
    }
}
