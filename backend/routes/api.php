<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Middleware\AuthenticateSupabaseUser;
use App\Http\Middleware\LoadLfmsAuthorizationContext;
use Illuminate\Support\Facades\Route;

// All API traffic is rate-limited. The limiter is configured in AppServiceProvider.
Route::middleware('throttle:api')->group(function (): void {
    Route::get('/health', [HealthController::class, 'show']);
    Route::get('/health/ready', [HealthController::class, 'readiness']);

    require __DIR__.'/api/v1.php';

    // Backward-compatible Phase 1–3 aliases. New API endpoints belong in /api/v1.
    Route::middleware(AuthenticateSupabaseUser::class)->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::middleware(LoadLfmsAuthorizationContext::class)->group(function (): void {
            Route::get('/auth/access', [AuthController::class, 'access']);
        });
    });
});
