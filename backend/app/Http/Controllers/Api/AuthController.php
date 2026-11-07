<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['user' => $request->attributes->get('supabase_user')],
            'Authenticated identity retrieved successfully.',
        );
    }

    public function access(Request $request): JsonResponse
    {
        $context = $request->attributes->get('lfms_authorization');

        return ApiResponse::success([
            'profile' => $context['profile'],
            'roles' => $context['roles'],
            'permissions' => $context['permissions'],
        ], 'Authorization context retrieved successfully.');
    }
}
