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
use App\Services\Maintenance\NeonMaintenanceIncidentService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class MaintenanceIncidentController extends Controller
{
    public function __construct(private readonly NeonMaintenanceIncidentService $service) {}

    public function targets(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->targets(), 'Maintenance targets retrieved successfully.');
    }

    public function maintenanceIndex(MaintenanceIndexRequest $request): JsonResponse
    {
        $result = $this->service->maintenanceRequests($request->options());
        return ApiResponse::success(['requests' => $result['requests']], 'Maintenance requests retrieved successfully.', 200, ['pagination' => $result['pagination']]);
    }

    public function maintenanceStore(StoreMaintenanceRequest $request): JsonResponse
    {
        $record = $this->service->createMaintenanceRequest($request->validated());
        return ApiResponse::success(['request' => $record], 'Maintenance request submitted successfully.', 201);
    }

    public function maintenanceShow(Request $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['request' => $this->service->maintenanceDetail($requestId)], 'Maintenance request retrieved successfully.');
    }

    public function maintenanceUpdate(UpdateMaintenanceRequest $request, string $requestId): JsonResponse
    {
        $data = $request->validated();
        if (!isset($data['status'])) return ApiResponse::error('A new maintenance status is required.', 422, 'validation_error');
        return ApiResponse::success(['request' => $this->service->updateMaintenanceStatus($requestId, $data['status'])], 'Maintenance request status updated successfully.');
    }

    public function maintenanceRecord(StoreMaintenanceRecordRequest $request, string $requestId): JsonResponse
    {
        return ApiResponse::success(['record' => $this->service->addMaintenanceRecord($requestId, $request->validated())], 'Maintenance activity recorded successfully.', 201);
    }

    public function incidentIndex(IncidentIndexRequest $request): JsonResponse
    {
        $result = $this->service->incidents($request->options());
        return ApiResponse::success(['incidents' => $result['incidents']], 'Incident reports retrieved successfully.', 200, ['pagination' => $result['pagination']]);
    }

    public function incidentStore(StoreIncidentRequest $request): JsonResponse
    {
        $record = $this->service->createIncident($request->validated());
        return ApiResponse::success(['incident' => $record], 'Incident report submitted successfully.', 201);
    }

    public function incidentShow(Request $request, string $incidentId): JsonResponse
    {
        return ApiResponse::success(['incident' => $this->service->incidentDetail($incidentId)], 'Incident report retrieved successfully.');
    }

    public function incidentUpdate(UpdateIncidentRequest $request, string $incidentId): JsonResponse
    {
        $data = $request->validated();
        if ($data === []) return ApiResponse::error('At least one incident field is required.', 422, 'validation_error');
        return ApiResponse::success(['incident' => $this->service->updateIncident($incidentId, $data)], 'Incident investigation updated successfully.');
    }

    public function incidentAttachment(StoreIncidentAttachmentRequest $request, string $incidentId): JsonResponse
    {
        $file = $request->file('file');
        if (!$file instanceof UploadedFile) {
            return ApiResponse::error('An evidence file is required.', 422, 'validation_error');
        }

        $actorId = (string) data_get($request->attributes->get('supabase_user'), 'id', '');
        $attachment = $this->service->uploadIncidentAttachment($incidentId, $file, $actorId);

        return ApiResponse::success(['attachment' => $attachment], 'Incident evidence uploaded successfully.', 201);
    }

    public function incidentAttachmentDownload(Request $request, string $incidentId, string $attachmentId): \Illuminate\Http\Response
    {
        $actorId = (string) data_get($request->attributes->get('supabase_user'), 'id', '');
        $file = $this->service->downloadIncidentAttachment($incidentId, $attachmentId, $actorId);
        $fileName = str_replace(["\\r", "\\n", '"', '\\\\'], '_', (string) $file['file_name']) ?: 'evidence';

        return response($file['body'], 200, [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Content-Length' => (string) strlen($file['body']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function incidentAttachmentDelete(Request $request, string $incidentId, string $attachmentId): JsonResponse
    {
        $actorId = (string) data_get($request->attributes->get('supabase_user'), 'id', '');
        $this->service->deleteIncidentAttachment($incidentId, $attachmentId, $actorId);

        return ApiResponse::success(null, 'Incident evidence deleted successfully.');
    }
}
