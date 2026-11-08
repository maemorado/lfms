<?php

namespace App\\Http\\Controllers\\Api;

use App\\Support\\Api\\ApiResponse;
use Illuminate\\Http\\JsonResponse;

class HealthController
{
    public function show(): JsonResponse
    {
        return ApiResponse::success(
            ['application' => config('app.name'), 'api_version' => 'v1'],
            'LFMS API is running.',
        );
    }
}
