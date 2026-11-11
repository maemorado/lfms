<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BorrowingIndexRequest;
use App\Http\Requests\Api\ProcessBorrowReturnRequest;
use App\Http\Requests\Api\ReleaseBorrowRequest;
use App\Http\Requests\Api\ReviewBorrowRequest;
use App\Http\Requests\Api\StoreBorrowRequest;
use App\Services\Borrowing\NeonBorrowingService;
use App\Services\Inventory\NeonInventoryService;
use App\Services\Maintenance\NeonMaintenanceIncidentService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BorrowingController extends Controller
{
    public function __construct(
        private readonly NeonBorrowingService $borrowing,
        private readonly NeonInventoryService $inventory,
        private readonly NeonMaintenanceIncidentService $maintenance,
    ) {}

    public function inventory(BorrowingIndexRequest $request): JsonResponse
    {
        $result = $this->inventory->listItems([
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 20),
            'status' => 'active',
            'sort' => 'name',
            'direction' => 'asc',
            'search' => $request->query('search'),
        ]);

        $blocked = array_fill_keys($this->maintenance->blockedEquipmentAssetIds(), true);
        $items = array_values(array_filter(array_map(function (array $item) use ($blocked): array {
            if (($item['item_type'] ?? '') === 'equipment') {
                $assets = array_values(array_filter(
                    $item['equipment_assets'] ?? [],
                    fn (array $asset): bool => ($asset['status'] ?? null) === 'available'
                        && !isset($blocked[$asset['id'] ?? '']),
                ));
                $item['equipment_assets'] = $assets;
                $item['available_assets'] = count($assets);
                $item['availability'] = $assets !== [] ? 'available' : 'unavailable';
                return $item;
            }

            return $item;
        }, $result['items']), fn (array $item): bool => ($item['item_type'] ?? '') === 'equipment'
            ? (int) ($item['available_assets'] ?? 0) > 0
            : (float) ($item['quantity_on_hand'] ?? 0) > 0));

        return ApiResponse::success(
            ['items' => $items],
            'Borrowable inventory retrieved successfully.',
            200,
            ['pagination' => $result['pagination']],
        );
    }

    public function index(BorrowingIndexRequest $request): JsonResponse
    {
        $result = $this->borrowing->requests($request->options());
        return ApiResponse::success(['requests' => $result['requests']], 'Borrowing requests retrieved successfully.', 200, ['pagination' => $result['pagination']]);
    }

    public function store(StoreBorrowRequest $request): JsonResponse
    {
        return ApiResponse::success(['request' => $this->borrowing->createRequest($request->validated())], 'Borrowing request submitted successfully.', 201);
    }

    public function show(Request $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['request' => $this->borrowing->requestDetail($requestId)], 'Borrowing request retrieved successfully.');
    }

    public function cancel(Request $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['request' => $this->borrowing->cancelRequest($requestId)], 'Borrowing request cancelled successfully.');
    }

    public function review(ReviewBorrowRequest $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['request' => $this->borrowing->reviewRequest($requestId, $request->validated())], 'Borrowing request reviewed successfully.');
    }

    public function release(ReleaseBorrowRequest $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['borrowing' => $this->borrowing->releaseRequest($requestId, $request->validated())], 'Borrowing items released successfully.', 201);
    }

    public function borrowings(BorrowingIndexRequest $request): JsonResponse
    {
        $result = $this->borrowing->borrowings($request->options());
        return ApiResponse::success(['borrowings' => $result['borrowings']], 'Borrowing records retrieved successfully.', 200, ['pagination' => $result['pagination']]);
    }

    public function overdue(BorrowingIndexRequest $request): JsonResponse
    {
        $options = $request->options();
        $options['overdue'] = true;
        $result = $this->borrowing->borrowings($options);
        return ApiResponse::success(['borrowings' => $result['borrowings']], 'Overdue borrowings retrieved successfully.', 200, ['pagination' => $result['pagination']]);
    }

    public function borrowingDetail(Request $request, string $transactionId): JsonResponse
    {
        return ApiResponse::success(['borrowing' => $this->borrowing->borrowingDetail($transactionId)], 'Borrowing details retrieved successfully.');
    }

    public function returns(Request $request, string $transactionId): JsonResponse
    {
        return ApiResponse::success(['returns' => $this->borrowing->returns($transactionId)], 'Return history retrieved successfully.');
    }

    public function processReturn(ProcessBorrowReturnRequest $request, string $transactionId): JsonResponse
    {
        return ApiResponse::success(['return' => $this->borrowing->processReturn($transactionId, $request->validated())], 'Borrowing return processed successfully.', 201);
    }
}
