<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\LoadLfmsAuthorizationContext;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Version 1 API
|--------------------------------------------------------------------------
| Laravel's API route loader supplies the /api prefix. This file adds /v1,
| resulting in /api/v1. Module endpoints should be registered in their
| corresponding section and protected by the authentication/authorization
| middleware appropriate to their data sensitivity.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', fn () => ApiResponse::success(
        ['application' => config('app.name'), 'api_version' => 'v1'],
        'LFMS API v1 is running.',
    ))->name('health');

    Route::middleware('supabase.auth')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::middleware(LoadLfmsAuthorizationContext::class)->group(function (): void {
            Route::get('/auth/access', [AuthController::class, 'access'])->name('auth.access');

            // User management: accounts and profile administration.
            // Dashboard: summary and dashboard metrics.
            // Facilities: buildings, laboratories, facilities, features.
            // Reservations: reservation requests and approvals.
            // Inventory: inventory catalogue and stock operations.
            // Borrowing: borrowing requests, issues, and returns.
            // Maintenance: maintenance requests and work tracking.
            // Incidents: incident reporting and review.
            // Notifications: user notifications and read state.
            // Announcements: publication and audience visibility.
            // Reports/activity: reporting and permitted activity history.
            //
            // Register actual endpoints only when their module is implemented.
            // Every write route must add a permission check and enforce
            // ownership using the verified identity, never client-supplied IDs.
        });
    });
});
