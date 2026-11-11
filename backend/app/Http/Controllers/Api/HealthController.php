<?php

namespace App\Http\Controllers\Api;

use App\Support\Api\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /**
     * Readiness is separate from liveness. Render must not route traffic to
     * this instance until Laravel can acquire a working PostgreSQL connection.
     */
    public function ready(): JsonResponse
    {
        try {
            DB::selectOne('select 1 as ready');
        } catch (Throwable) {
            return ApiResponse::error(
                'LFMS database is temporarily unavailable.',
                503,
                'database_unavailable',
            );
        }

        return ApiResponse::success(
            ['application' => config('app.name'), 'api_version' => 'v1', 'database' => 'ready'],
            'LFMS API and database are ready.',
        );
    }

    private function respond(string $message): JsonResponse
    {
        return ApiResponse::success(
            ['application' => config('app.name'), 'api_version' => 'v1'],
            $message,
        );
    }
}
