<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\FacilityIndexRequest;
use App\Http\Requests\Api\StoreFacilityRequest;
use App\Http\Requests\Api\UpdateFacilityRequest;
use App\Http\Resources\FacilityResource;
use App\Models\Facility;
use App\Services\Facilities\FacilityManagementService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class FacilityManagementController extends Controller
{
    public function __construct(private readonly FacilityManagementService $facilities) {}

    public function index(FacilityIndexRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Facility::class);
        return ApiResponse::paginated($this->facilities->list($request->validated()), 'Facilities retrieved successfully.');
    }

    public function lookups(): JsonResponse
    {
        Gate::authorize('viewAny', Facility::class);
        return ApiResponse::success($this->facilities->lookups(), 'Facility options retrieved successfully.');
    }

    public function availability(Request $request, string $facilityId): JsonResponse
    {
        Gate::authorize('viewAny', Facility::class);
        $data = $request->validate([
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ]);
        return ApiResponse::success($this->facilities->availability($facilityId, $data['start_at'], $data['end_at']), 'Facility availability checked successfully.');
    }

    public function show(string $facilityId): JsonResponse
    {
        $data = $this->facilities->find($facilityId);
        Gate::authorize('view', (new Facility())->forceFill($data));
        return ApiResponse::success(['facility' => new FacilityResource($data)], 'Facility details retrieved successfully.');
    }

    public function store(StoreFacilityRequest $request): JsonResponse
    {
        Gate::authorize('create', Facility::class);
        $actorId = (string)$request->attributes->get('supabase_user')['id'];
        return ApiResponse::success(['facility' => new FacilityResource($this->facilities->create($actorId, $request->validated()))], 'Facility created successfully.', 201);
    }

    public function update(UpdateFacilityRequest $request, string $facilityId): JsonResponse
    {
        Gate::authorize('update', (new Facility())->forceFill(['id' => $facilityId]));
        $actorId = (string)$request->attributes->get('supabase_user')['id'];
        return ApiResponse::success(['facility' => new FacilityResource($this->facilities->update($actorId, $facilityId, $request->validated()))], 'Facility updated successfully.');
    }
}
