<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Middleware\LoadLfmsAuthorizationContext;
use App\Support\Api\ApiResponse;
use Illuminate\Support\Facades\Route;
Route::prefix('v1')->name('api.v1.')->group(function():void{
 Route::get('/health',fn()=>ApiResponse::success(['application'=>config('app.name'),'api_version'=>'v1'],'LFMS API v1 is running.'))->name('health');
 Route::middleware('supabase.auth')->group(function():void{
  Route::get('/auth/me',[AuthController::class,'me'])->name('auth.me');
  Route::middleware(LoadLfmsAuthorizationContext::class)->group(function():void{
   Route::get('/auth/access',[AuthController::class,'access'])->name('auth.access');
   Route::prefix('users')->name('users.')->middleware('permission:accounts.view')->group(function():void{
    Route::get('/roles',[UserManagementController::class,'roles'])->name('roles');Route::get('/',[UserManagementController::class,'index'])->name('index');Route::get('/{userId}',[UserManagementController::class,'show'])->whereUuid('userId')->name('show');
    Route::middleware('permission:accounts.manage')->group(function():void{Route::post('/',[UserManagementController::class,'store'])->name('store');Route::patch('/{userId}',[UserManagementController::class,'update'])->whereUuid('userId')->name('update');Route::patch('/{userId}/status',[UserManagementController::class,'setStatus'])->whereUuid('userId')->name('status');});
   });
   Route::get('/profile',[UserManagementController::class,'profile'])->middleware('permission:profile.view_own')->name('profile.show');
   Route::patch('/profile',[UserManagementController::class,'updateProfile'])->middleware('permission:profile.update_own')->name('profile.update');
   Route::prefix('inventory')->name('inventory.')->middleware('permission:inventory.view')->group(function():void{
    Route::get('/',[InventoryController::class,'index'])->name('index');
    Route::get('/categories',[InventoryController::class,'categories'])->name('categories.index');
    Route::get('/locations',[InventoryController::class,'locations'])->name('locations.index');
    Route::middleware('permission:inventory.manage')->group(function():void{
     Route::post('/categories',[InventoryController::class,'storeCategory'])->name('categories.store');
     Route::patch('/categories/{categoryId}',[InventoryController::class,'updateCategory'])->whereUuid('categoryId')->name('categories.update');
     Route::delete('/categories/{categoryId}',[InventoryController::class,'deleteCategory'])->whereUuid('categoryId')->name('categories.destroy');
     Route::post('/',[InventoryController::class,'store'])->name('store');
    });
    Route::get('/{itemId}',[InventoryController::class,'show'])->whereUuid('itemId')->name('show');
    Route::get('/{itemId}/assets',[InventoryController::class,'assets'])->whereUuid('itemId')->name('assets.index');
    Route::get('/{itemId}/movements',[InventoryController::class,'movements'])->whereUuid('itemId')->name('movements.index');
    Route::middleware('permission:inventory.manage')->group(function():void{
     Route::patch('/{itemId}',[InventoryController::class,'update'])->whereUuid('itemId')->name('update');
     Route::post('/{itemId}/archive',[InventoryController::class,'archive'])->whereUuid('itemId')->name('archive');
     Route::post('/{itemId}/movements',[InventoryController::class,'storeMovement'])->whereUuid('itemId')->name('movements.store');
     Route::post('/{itemId}/assets',[InventoryController::class,'storeAsset'])->whereUuid('itemId')->name('assets.store');
     Route::patch('/{itemId}/assets/{assetId}',[InventoryController::class,'updateAsset'])->whereUuid('itemId')->whereUuid('assetId')->name('assets.update');
    });
   });
  });
 });
});