<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IncidentIndexRequest;
use App\Http\Requests\Api\MaintenanceIndexRequest;
use App\Http\Requests\Api\StoreIncidentAttachmentRequest;
use App\Http\Requests\Api\StoreIncidentRequest;
use App\Http\Requests\Api\StoreMaintenanceRecordRequest;
use App\Http\Requests\Api\StoreMaintenanceRequest;
use App\Http\Requests\Api\UpdateIncidentRequest;
use App\Http\Requests\Api\UpdateMaintenanceRequest;
use App\Services\Maintenance\SupabaseMaintenanceIncidentService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MaintenanceIncidentController extends Controller
{
    public function __construct(private readonly SupabaseMaintenanceIncidentService $service) {}

    private function token(Request $request): string { return (string) $request->bearerToken(); }
    private function actor(Request $request): string
    {
        return (string) data_get($request->attributes->get('supabase_user'), 'id', '');
    }

    public function targets(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->targets($this->token($request)), 'Maintenance targets retrieved successfully.');
    }

    public function maintenanceIndex(MaintenanceIndexRequest $request): JsonResponse
    {
        $result = $this->service->maintenanceRequests($this->token($request), $request->options());
        return ApiResponse::success(['requests' => $result['requests']], 'Maintenance requests retrieved successfully.', 200, ['pagination' => $result['pagination']]);
    }

    public function maintenanceStore(StoreMaintenanceRequest $request): JsonResponse
    {
        $record = $this->service->createMaintenanceRequest($this->token($request), $this->actor($request), $request->validated());
        return ApiResponse::success(['request' => $record], 'Maintenance request submitted successfully.', 201);
    }

    public function maintenanceShow(Request $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['request' => $this->service->maintenanceDetail($this->token($request), $requestId)], 'Maintenance request retrieved successfully.');
    }

    public function maintenanceUpdate(UpdateMaintenanceRequest $request, string $requestId): JsonResponse
    {
        $data = $request->validated();
        if (!isset($data['status'])) return ApiResponse::error('A new maintenance status is required.', 422, 'validation_error');
        return ApiResponse::success(['request' => $this->service->updateMaintenanceStatus($this->token($request), $requestId, $data['status'])], 'Maintenance request status updated successfully.');
    }

    public function maintenanceRecord(StoreMaintenanceRecordRequest $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['record' => $this->service->addMaintenanceRecord($this->token($request), $requestId, $request->validated())], 'Maintenance activity recorded successfully.', 201);
    }

    public function incidentIndex(IncidentIndexRequest $request): JsonResponse
    {
        $result = $this->service->incidents($this->token($request), $request->options());
        return ApiResponse::success(['incidents' => $result['incidents']], 'Incident reports retrieved successfully.', 200, ['pagination' => $result['pagination']]);
    }

    public function incidentStore(StoreIncidentRequest $request): JsonResponse
    {
        $record = $this->service->createIncident($this->token($request), $this->actor($request), $request->validated());
        return ApiResponse::success(['incident' => $record], 'Incident report submitted successfully.', 201);
    }

    public function incidentShow(Request $request, string $incidentId): JsonResponse
    {
        return ApiResponse::success(['incident' => $this->service->incidentDetail($this->token($request), $incidentId)], 'Incident report retrieved successfully.');
    }

    public function incidentUpdate(UpdateIncidentRequest $request, string $incidentId): JsonResponse
    {
        $data = $request->validated();
        if ($data === []) return ApiResponse::error('At least one incident field is required.', 422, 'validation_error');
        return ApiResponse::success(['incident' => $this->service->updateIncident($this->token($request), $this->actor($request), $incidentId, $data)], 'Incident investigation updated successfully.');
    }

    public function incidentAttachment(StoreIncidentAttachmentRequest $request, string $incidentId): JsonResponse
    {
        $attachment = $this->service->addIncidentAttachment($this->token($request), $this->actor($request), $incidentId, $request->validated());
        return ApiResponse::success(['attachment' => $attachment], 'Incident evidence linked successfully.', 201);
    }
}
