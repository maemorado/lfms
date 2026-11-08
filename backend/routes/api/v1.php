<?php
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BorrowingController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MaintenanceIncidentController;
use App\Http\Controllers\Api\NotificationAnnouncementController;
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
   Route::get('/dashboard',[DashboardReportingController::class,'dashboard'])->middleware('permission:profile.view_own')->name('dashboard');
   Route::prefix('reports')->name('reports.')->middleware('permission:reports.view')->group(function():void{
    Route::get('/',[DashboardReportingController::class,'report'])->name('index');
   });
   Route::get('/activity',[DashboardReportingController::class,'activity'])->middleware('permission:profile.view_own')->name('activity.index');

   Route::prefix('users')->name('users.')->middleware('permission:accounts.view')->group(function():void{
    Route::get('/roles',[UserManagementController::class,'roles'])->name('roles');Route::get('/',[UserManagementController::class,'index'])->name('index');Route::get('/{userId}',[UserManagementController::class,'show'])->whereUuid('userId')->name('show');
    Route::middleware('permission:accounts.manage')->group(function():void{Route::post('/',[UserManagementController::class,'store'])->name('store');Route::patch('/{userId}',[UserManagementController::class,'update'])->whereUuid('userId')->name('update');Route::patch('/{userId}/status',[UserManagementController::class,'setStatus'])->whereUuid('userId')->name('status');});
   });
   Route::get('/profile',[UserManagementController::class,'profile'])->middleware('permission:profile.view_own')->name('profile.show');
   Route::patch('/profile',[UserManagementController::class,'updateProfile'])->middleware('permission:profile.update_own')->name('profile.update');
   Route::prefix('notifications')->name('notifications.')->middleware('permission:notifications.view_own')->group(function():void{
    Route::get('/',[NotificationAnnouncementController::class,'notifications'])->name('index');
    Route::get('/unread-count',[NotificationAnnouncementController::class,'unreadCount'])->name('unread-count');
    Route::post('/read-all',[NotificationAnnouncementController::class,'markAllRead'])->name('read-all');
    Route::get('/{notificationId}',[NotificationAnnouncementController::class,'notification'])->whereUuid('notificationId')->name('show');
    Route::patch('/{notificationId}/read',[NotificationAnnouncementController::class,'markRead'])->whereUuid('notificationId')->name('read');
   });
   Route::post('/notifications/dispatch-overdue',[NotificationAnnouncementController::class,'dispatchOverdue'])->middleware('permission:notifications.manage')->name('notifications.dispatch-overdue');
   Route::prefix('announcements')->name('announcements.')->middleware('permission:notifications.view_own')->group(function():void{
    Route::get('/',[NotificationAnnouncementController::class,'announcements'])->name('index');
    Route::get('/{announcementId}/history',[NotificationAnnouncementController::class,'history'])->whereUuid('announcementId')->middleware(['permission:notifications.manage','permission:audit.view'])->name('history');
    Route::get('/{announcementId}',[NotificationAnnouncementController::class,'announcement'])->whereUuid('announcementId')->name('show');
    Route::middleware('permission:notifications.manage')->group(function():void{
     Route::post('/',[NotificationAnnouncementController::class,'createAnnouncement'])->name('store');
     Route::patch('/{announcementId}',[NotificationAnnouncementController::class,'updateAnnouncement'])->whereUuid('announcementId')->name('update');
     Route::post('/{announcementId}/publish',[NotificationAnnouncementController::class,'publish'])->whereUuid('announcementId')->name('publish');
     Route::post('/{announcementId}/unpublish',[NotificationAnnouncementController::class,'unpublish'])->whereUuid('announcementId')->name('unpublish');
     Route::post('/{announcementId}/archive',[NotificationAnnouncementController::class,'archive'])->whereUuid('announcementId')->name('archive');
    });
   });
   Route::prefix('inventory')->name('inventory.')->middleware('permission:inventory.view')->group(function():void{
    Route::get('/',[InventoryController::class,'index'])->name('index');Route::get('/categories',[InventoryController::class,'categories'])->name('categories.index');Route::get('/locations',[InventoryController::class,'locations'])->name('locations.index');
    Route::middleware('permission:inventory.manage')->group(function():void{Route::post('/categories',[InventoryController::class,'storeCategory'])->name('categories.store');Route::patch('/categories/{categoryId}',[InventoryController::class,'updateCategory'])->whereUuid('categoryId')->name('categories.update');Route::delete('/categories/{categoryId}',[InventoryController::class,'deleteCategory'])->whereUuid('categoryId')->name('categories.destroy');Route::post('/',[InventoryController::class,'store'])->name('store');});
    Route::get('/{itemId}',[InventoryController::class,'show'])->whereUuid('itemId')->name('show');Route::get('/{itemId}/assets',[InventoryController::class,'assets'])->whereUuid('itemId')->name('assets.index');Route::get('/{itemId}/movements',[InventoryController::class,'movements'])->whereUuid('itemId')->name('movements.index');
    Route::middleware('permission:inventory.manage')->group(function():void{Route::patch('/{itemId}',[InventoryController::class,'update'])->whereUuid('itemId')->name('update');Route::post('/{itemId}/archive',[InventoryController::class,'archive'])->whereUuid('itemId')->name('archive');Route::post('/{itemId}/movements',[InventoryController::class,'storeMovement'])->whereUuid('itemId')->name('movements.store');Route::post('/{itemId}/assets',[InventoryController::class,'storeAsset'])->whereUuid('itemId')->name('assets.store');Route::patch('/{itemId}/assets/{assetId}',[InventoryController::class,'updateAsset'])->whereUuid('itemId')->whereUuid('assetId')->name('assets.update');});
   });
   Route::prefix('borrowing')->name('borrowing.')->middleware('permission:borrowing.view')->group(function():void{
    Route::get('/inventory',[BorrowingController::class,'inventory'])->middleware('permission:inventory.view')->name('inventory');
    Route::get('/requests',[BorrowingController::class,'index'])->name('requests.index');
    Route::post('/requests',[BorrowingController::class,'store'])->middleware('permission:borrowing.request')->name('requests.store');
    Route::get('/requests/{requestId}',[BorrowingController::class,'show'])->whereUuid('requestId')->name('requests.show');
    Route::post('/requests/{requestId}/cancel',[BorrowingController::class,'cancel'])->whereUuid('requestId')->middleware('permission:borrowing.request')->name('requests.cancel');
    Route::middleware('permission:borrowing.manage')->group(function():void{Route::post('/requests/{requestId}/review',[BorrowingController::class,'review'])->whereUuid('requestId')->name('requests.review');Route::post('/requests/{requestId}/release',[BorrowingController::class,'release'])->whereUuid('requestId')->name('requests.release');});
    Route::get('/overdue',[BorrowingController::class,'overdue'])->middleware('permission:borrowing.manage')->name('overdue');
    Route::get('/records',[BorrowingController::class,'borrowings'])->name('records.index');
    Route::get('/records/{transactionId}',[BorrowingController::class,'borrowingDetail'])->whereUuid('transactionId')->name('records.show');
    Route::get('/records/{transactionId}/returns',[BorrowingController::class,'returns'])->whereUuid('transactionId')->name('records.returns');
    Route::post('/records/{transactionId}/returns',[BorrowingController::class,'processReturn'])->whereUuid('transactionId')->middleware(['permission:borrowing.manage','permission:borrowing.return'])->name('records.returns.store');
   });
   Route::prefix('maintenance')->name('maintenance.')->middleware('permission:maintenance.view')->group(function():void{
    Route::get('/targets',[MaintenanceIncidentController::class,'targets'])->middleware(['permission:facilities.view','permission:inventory.view'])->name('targets');
    Route::get('/requests',[MaintenanceIncidentController::class,'maintenanceIndex'])->name('requests.index');
    Route::post('/requests',[MaintenanceIncidentController::class,'maintenanceStore'])->middleware('permission:maintenance.create')->name('requests.store');
    Route::get('/requests/{requestId}',[MaintenanceIncidentController::class,'maintenanceShow'])->whereUuid('requestId')->name('requests.show');
    Route::middleware('permission:maintenance.manage')->group(function():void{
     Route::patch('/requests/{requestId}',[MaintenanceIncidentController::class,'maintenanceUpdate'])->whereUuid('requestId')->name('requests.update');
     Route::post('/requests/{requestId}/records',[MaintenanceIncidentController::class,'maintenanceRecord'])->whereUuid('requestId')->name('requests.records.store');
    });
   });
   Route::prefix('incidents')->name('incidents.')->middleware('permission:incidents.view')->group(function():void{
    Route::get('/',[MaintenanceIncidentController::class,'incidentIndex'])->name('index');
    Route::post('/',[MaintenanceIncidentController::class,'incidentStore'])->middleware('permission:incidents.create')->name('store');
    Route::get('/{incidentId}',[MaintenanceIncidentController::class,'incidentShow'])->whereUuid('incidentId')->name('show');
    Route::post('/{incidentId}/attachments',[MaintenanceIncidentController::class,'incidentAttachment'])->whereUuid('incidentId')->name('attachments.store');
    Route::patch('/{incidentId}',[MaintenanceIncidentController::class,'incidentUpdate'])->whereUuid('incidentId')->middleware('permission:incidents.manage')->name('update');
   });
  });
 });
});
