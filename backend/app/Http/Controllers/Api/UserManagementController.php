<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Users\UserManagementService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UserManagementController extends Controller
{
    public function __construct(private readonly UserManagementService $users) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(['admin', 'staff_sa'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'sort' => ['sometimes', Rule::in(['full_name', 'email', 'status', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ]);

        return ApiResponse::paginated($this->users->list($validated), 'User accounts retrieved successfully.');
    }

    public function show(string $userId): JsonResponse
    {
        return ApiResponse::success(['user' => $this->users->find($userId)], 'User account retrieved successfully.');
    }

    public function roles(): JsonResponse
    {
        return ApiResponse::success(['roles' => $this->users->availableRoles()], 'Assignable roles retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'employee_id' => ['nullable', 'string', 'max:80'],
            'role' => ['required', Rule::in(['admin', 'staff_sa'])],
        ]);

        $actorId = (string) $request->attributes->get('supabase_user')['id'];
        return ApiResponse::success(['user' => $this->users->create($actorId, $data)], 'Invitation sent and LFMS account created.', 201);
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'staff_sa'])],
        ]);
        if ($data === []) return ApiResponse::error('At least one editable field is required.', 422, 'validation_error');

        $actorId = (string) $request->attributes->get('supabase_user')['id'];
        return ApiResponse::success(['user' => $this->users->update($actorId, $userId, $data)], 'User account updated successfully.');
    }

    public function setStatus(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $actorId = (string) $request->attributes->get('supabase_user')['id'];
        return ApiResponse::success(['user' => $this->users->setStatus($actorId, $userId, $data['status'])], 'Account status updated successfully.');
    }

    public function profile(Request $request): JsonResponse
    {
        $userId = (string) $request->attributes->get('supabase_user')['id'];
        return ApiResponse::success(['profile' => $this->users->ownProfile($userId)], 'Your profile was retrieved successfully.');
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'employee_id' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        if ($data === []) return ApiResponse::error('At least one editable field is required.', 422, 'validation_error');

        $userId = (string) $request->attributes->get('supabase_user')['id'];
        return ApiResponse::success(['profile' => $this->users->updateOwnProfile($userId, $data)], 'Your profile was updated successfully.');
    }

}
