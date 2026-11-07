<?php

use App\Exceptions\FacilityReservationException;
use App\Exceptions\UserManagementException;
use App\Http\Middleware\AuthenticateSupabaseUser;
use App\Http\Middleware\LoadLfmsAuthorizationContext;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'supabase.auth' => AuthenticateSupabaseUser::class,
            'lfms.authorization' => LoadLfmsAuthorizationContext::class,
            'role' => RequireRole::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = static fn (Request $request): bool => $request->is('api/*');

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error('The submitted data is invalid.', 422, 'validation_error', $exception->errors())
                : null;
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error('Authentication is required.', 401, 'unauthenticated')
                : null;
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error('You are not authorized to perform this action.', 403, 'forbidden')
                : null;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error('The requested resource was not found.', 404, 'not_found')
                : null;
        });

        $exceptions->render(function (FacilityReservationException $exception, Request $request) use ($isApi) {
            if (!$isApi($request)) return null;
            $status = $exception->httpStatus;
            $code = match ($status) {
                403 => 'forbidden',
                404 => 'not_found',
                409 => 'reservation_conflict',
                422 => 'validation_error',
                default => 'facility_reservation_error',
            };
            return ApiResponse::error($exception->getMessage(), $status, $code);
        });

        $exceptions->render(function (UserManagementException $exception, Request $request) use ($isApi) {
            if (!$isApi($request)) {
                return null;
            }

            $status = $exception->httpStatus;
            $code = match ($status) {
                403 => 'forbidden',
                404 => 'not_found',
                422 => 'validation_error',
                default => 'user_management_error',
            };

            return ApiResponse::error($exception->getMessage(), $status, $code);
        });

        $exceptions->render(function (QueryException $exception, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error('A database operation could not be completed.', 500, 'database_error')
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($isApi) {
            if (!$isApi($request)) {
                return null;
            }

            $status = $exception->getStatusCode();
            $message = match ($status) {
                403 => 'You are not authorized to perform this action.',
                404 => 'The requested resource was not found.',
                405 => 'The requested HTTP method is not supported.',
                429 => 'Too many requests. Please try again later.',
                default => 'The request could not be completed.',
            };

            return ApiResponse::error($message, $status, 'http_error');
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error('An unexpected server error occurred.', 500, 'internal_server_error')
                : null;
        });
    })
    ->create();
