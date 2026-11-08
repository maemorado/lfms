<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardReportingService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DashboardReportingController extends Controller
{
    public function __construct(private readonly DashboardReportingService $service) {}

    private function token(Request $request): string { return (string) $request->bearerToken(); }
    private function actor(Request $request): string { return (string) data_get($request->attributes->get('supabase_user'), 'id', ''); }
    private function isAdmin(Request $request): bool
    {
        $permissions = $request->attributes->get('lfms_authorization')['permissions'] ?? [];
        return is_array($permissions) && in_array('reports.view', $permissions, true);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->dashboard($this->token($request), $this->actor($request), $this->isAdmin($request)),
            'Dashboard data retrieved successfully.',
        );
    }

    public function report(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Administrative reporting access is required.');
        $options = $request->validate([
            'report' => ['required', Rule::in(['reservations','facility-utilization','inventory','inventory-movements','borrowings','returns','maintenance','incidents'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $options = array_merge(['from' => null, 'to' => null, 'status' => null, 'search' => null, 'page' => 1, 'per_page' => 20], $options);
        return ApiResponse::success($this->service->report($this->token($request), $options), 'Report records retrieved successfully.');
    }

    public function activity(Request $request): JsonResponse
    {
        $admin = $this->isAdmin($request);
        $options = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'module' => ['nullable', 'string', 'max:80'],
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $options = array_merge(['user_id' => null, 'module' => null, 'action' => null, 'from' => null, 'to' => null, 'page' => 1, 'per_page' => 20], $options);
        if (!$admin) $options['user_id'] = null;
        return ApiResponse::success($this->service->activity($this->token($request), $this->actor($request), $admin, $options), 'Activity history retrieved successfully.');
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Administrative audit access is required.');
        $options = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'module' => ['nullable', 'string', 'max:80'],
            'action' => ['nullable', Rule::in(['INSERT', 'UPDATE', 'DELETE'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $options = array_merge(['user_id' => null, 'module' => null, 'action' => null, 'from' => null, 'to' => null, 'page' => 1, 'per_page' => 20], $options);
        return ApiResponse::success($this->service->audit($this->token($request), $options), 'Row-level audit history retrieved successfully.');
    }
}
