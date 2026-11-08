<?php
use App\\Exceptions\\BorrowingException;
use App\\Exceptions\\InventoryException;
use App\\Exceptions\\MaintenanceIncidentException;
use App\\Exceptions\\NotificationAnnouncementException;
use App\\Exceptions\\UserManagementException;
use App\\Http\\Middleware\\AuthenticateSupabaseUser;
use App\\Http\\Middleware\\LoadLfmsAuthorizationContext;
use App\\Http\\Middleware\\RequirePermission;
use App\\Http\\Middleware\\RequireRole;
use App\\Support\\Api\\ApiResponse;
use Illuminate\\Auth\\AuthenticationException;
use Illuminate\\Auth\\Access\\AuthorizationException;
use Illuminate\\Database\\Eloquent\\ModelNotFoundException;
use Illuminate\\Database\\QueryException;
use Illuminate\\Foundation\\Application;
use Illuminate\\Foundation\\Configuration\\Exceptions;
use Illuminate\\Foundation\\Configuration\\Middleware;
use Illuminate\\Http\\Request;
use Illuminate\\Validation\\ValidationException;
use Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface;
use Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException;
return Application::configure(basePath: dirname(__DIR__))
 ->withRouting(api:__DIR__.'/../routes/api.php',commands:__DIR__.'/../routes/console.php',health:'/up')
 ->withMiddleware(function(Middleware $middleware):void{$middleware->alias(['supabase.auth'=>AuthenticateSupabaseUser::class,'lfms.authorization'=>LoadLfmsAuthorizationContext::class,'role'=>RequireRole::class,'permission'=>RequirePermission::class]);})
 ->withExceptions(function(Exceptions $exceptions):void{
  $isApi=static fn(Request $r):bool=>$r->is('api/*');
  $exceptions->render(function(ValidationException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error('The submitted data is invalid.',422,'validation_error',$e->errors()):null;});
  $exceptions->render(function(AuthenticationException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error('Authentication is required.',401,'unauthenticated'):null;});
  $exceptions->render(function(AuthorizationException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error('You are not authorized to perform this action.',403,'forbidden'):null;});
  $exceptions->render(function(BorrowingException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error($e->getMessage(),$e->httpStatus,match($e->httpStatus){401=>'unauthenticated',403=>'forbidden',404=>'not_found',409=>'conflict',422=>'validation_error',default=>'borrowing_error'}):null;});
  $exceptions->render(function(InventoryException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error($e->getMessage(),$e->httpStatus,match($e->httpStatus){401=>'unauthenticated',403=>'forbidden',404=>'not_found',409=>'conflict',422=>'validation_error',default=>'inventory_error'}):null;});
  $exceptions->render(function(MaintenanceIncidentException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error($e->getMessage(),$e->httpStatus,match($e->httpStatus){401=>'unauthenticated',403=>'forbidden',404=>'not_found',409=>'conflict',422=>'validation_error',default=>'maintenance_incident_error'}):null;});
  $exceptions->render(function(NotificationAnnouncementException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error($e->getMessage(),$e->httpStatus,match($e->httpStatus){401=>'unauthenticated',403=>'forbidden',404=>'not_found',409=>'conflict',422=>'validation_error',default=>'notification_announcement_error'}):null;});
  $exceptions->render(function(ModelNotFoundException|NotFoundHttpException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error('The requested resource was not found.',404,'not_found'):null;});
  $exceptions->render(function(UserManagementException $e,Request $r)use($isApi){if(!$isApi($r))return null;$s=$e->httpStatus;return ApiResponse::error($e->getMessage(),$s,match($s){403=>'forbidden',404=>'not_found',422=>'validation_error',default=>'user_management_error'});});
  $exceptions->render(function(QueryException $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error('A database operation could not be completed.',500,'database_error'):null;});
  $exceptions->render(function(HttpExceptionInterface $e,Request $r)use($isApi){if(!$isApi($r))return null;$s=$e->getStatusCode();return ApiResponse::error(match($s){403=>'You are not authorized to perform this action.',404=>'The requested resource was not found.',405=>'The requested HTTP method is not supported.',429=>'Too many requests. Please try again later.',default=>'The request could not be completed.'},$s,'http_error');});
  $exceptions->render(function(Throwable $e,Request $r)use($isApi){return $isApi($r)?ApiResponse::error('An unexpected server error occurred.',500,'internal_server_error'):null;});
 })->create();
