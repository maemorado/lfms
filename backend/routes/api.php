<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\AuthenticateSupabaseUser;
use App\Http\Middleware\LoadLfmsAuthorizationContext;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ApiResponse::success(
    ['application' => config('app.name')],
    'LFMS API is running.',
));

require __DIR__.'/api/v1.php';

// Backward-compatible Phase 1–3 aliases. New API endpoints belong in /api/v1.
Route::middleware(AuthenticateSupabaseUser::class)->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::middleware(LoadLfmsAuthorizationContext::class)->group(function (): void {
        Route::get('/auth/access', [AuthController::class, 'access']);
    });
});
