<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $request->attributes->get('supabase_user'),
            ],
        ]);
    }

    public function access(Request $request): JsonResponse
    {
        $context = $request->attributes->get('lfms_authorization');

        return response()->json([
            'success' => true,
            'data' => [
                'profile' => $context['profile'],
                'roles' => $context['roles'],
                'permissions' => $context['permissions'],
            ],
        ]);
    }
}
