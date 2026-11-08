<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FacilityManagementController;
use App\Http\Controllers\Api\ReservationManagementController;
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

            Route::get('/profile', [UserManagementController::class, 'profile'])->middleware('permission:profile.view_own')->name('profile.show');
            Route::patch('/profile', [UserManagementController::class, 'updateProfile'])->middleware('permission:profile.update_own')->name('profile.update');

            Route::prefix('facilities')->name('facilities.')->middleware('permission:facilities.view')->group(function (): void {
                Route::get('/lookups', [FacilityManagementController::class, 'lookups'])->name('lookups');
                Route::get('/', [FacilityManagementController::class, 'index'])->name('index');
                Route::get('/{facilityId}/availability', [FacilityManagementController::class, 'availability'])->whereUuid('facilityId')->name('availability');
                Route::get('/{facilityId}', [FacilityManagementController::class, 'show'])->whereUuid('facilityId')->name('show');
                Route::middleware('permission:facilities.manage')->group(function (): void {
                    Route::post('/', [FacilityManagementController::class, 'store'])->name('store');
                    Route::patch('/{facilityId}', [FacilityManagementController::class, 'update'])->whereUuid('facilityId')->name('update');
                });
            });

            Route::prefix('reservations')->name('reservations.')->middleware('permission:reservations.view')->group(function (): void {
                Route::get('/', [ReservationManagementController::class, 'index'])->name('index');
                Route::get('/{reservationId}', [ReservationManagementController::class, 'show'])->whereUuid('reservationId')->name('show');
                Route::middleware('permission:reservations.create')->group(function (): void {
                    Route::post('/', [ReservationManagementController::class, 'store'])->name('store');
                    Route::patch('/{reservationId}', [ReservationManagementController::class, 'update'])->whereUuid('reservationId')->name('update');
                    Route::patch('/{reservationId}/cancel', [ReservationManagementController::class, 'cancel'])->whereUuid('reservationId')->name('cancel');
                });
                Route::patch('/{reservationId}/decision', [ReservationManagementController::class, 'decide'])
                    ->whereUuid('reservationId')->middleware('permission:reservations.manage')->name('decision');
            });
        });
    });
});
