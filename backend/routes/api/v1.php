<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Middleware\LoadLfmsAuthorizationContext;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', fn () => ApiResponse::success(
        ['application' => config('app.name'), 'api_version' => 'v1'],
        'LFMS API v1 is running.',
    ))->name('health');

    Route::middleware('supabase.auth')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::middleware(LoadLfmsAuthorizationContext::class)->group(function (): void {
            Route::get('/auth/access', [AuthController::class, 'access'])->name('auth.access');

            // Every endpoint below derives identity from the verified bearer token.
            // Account management: Admin only, enforced by server-side permissions.
            Route::prefix('users')->name('users.')->middleware('permission:accounts.view')->group(function (): void {
                Route::get('/roles', [UserManagementController::class, 'roles'])->name('roles');
                Route::get('/', [UserManagementController::class, 'index'])->name('index');
                Route::get('/{userId}', [UserManagementController::class, 'show'])->whereUuid('userId')->name('show');

                Route::middleware('permission:accounts.manage')->group(function (): void {
                    Route::post('/', [UserManagementController::class, 'store'])->name('store');
                    Route::patch('/{userId}', [UserManagementController::class, 'update'])->whereUuid('userId')->name('update');
                    Route::patch('/{userId}/status', [UserManagementController::class, 'setStatus'])->whereUuid('userId')->name('status');
                });
            });

            // Staff/SA and Admin may read/update only their own profile.
            Route::get('/profile', [UserManagementController::class, 'profile'])
                ->middleware('permission:profile.view_own')->name('profile.show');
            Route::patch('/profile', [UserManagementController::class, 'updateProfile'])
                ->middleware('permission:profile.update_own')->name('profile.update');

            // Password changes use the signed-in user's Supabase session in the browser.
            // Never accept a target user ID or password through an admin API.
        });
    });
});
