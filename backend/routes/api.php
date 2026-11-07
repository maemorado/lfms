<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\AuthenticateSupabaseUser;
use App\Http\Middleware\LoadLfmsAuthorizationContext;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'LFMS API is running.',
        'data' => ['application' => config('app.name')],
    ]);
});

Route::middleware(AuthenticateSupabaseUser::class)->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::middleware(LoadLfmsAuthorizationContext::class)->group(function () {
        Route::get('/auth/access', [AuthController::class, 'access']);
        // Future protected module routes must be declared inside this group
        // and additionally require their specific permission middleware/Gates.
    });
});
