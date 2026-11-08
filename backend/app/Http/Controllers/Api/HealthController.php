<?php

namespace App\Http\Controllers\Api;

use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class HealthController
{
    public function show(): JsonResponse
    {
        return $this->respond('LFMS API is running.');
    }

    public function showVersioned(): JsonResponse
    {
        return $this->respond('LFMS API v1 is running.');
    }

    private function respond(string $message): JsonResponse
    {
        return ApiResponse::success(
            ['application' => config('app.name'), 'api_version' => 'v1'],
            $message,
        );
    }
}
