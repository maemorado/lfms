<?php

namespace App\\Http\\Middleware;

use App\\Services\\Authorization\\NeonAuthorizationService;
use App\\Support\\Api\\ApiResponse;
use Closure;
use Illuminate\\Auth\\GenericUser;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\DB;
use Symfony\\Component\\HttpFoundation\\Response;
use Throwable;

final class LoadLfmsAuthorizationContext
{
    public function __construct(private readonly NeonAuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('supabase_user');

        if (! is_array($identity) || ! is_string($identity['id'] ?? null)) {
            return ApiResponse::error('Authentication is required.', 401, 'unauthenticated');
        }

        $connection = DB::connection();

        // Keep the verified Supabase subject and all downstream business queries
        // on one transaction. SET LOCAL semantics prevent pooled-connection
        // identity from leaking into the next request.
        return $connection->transaction(function () use ($request, $next, $identity, $connection): Response {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->selectOne(
                    "SELECT set_config('app.current_user_id', ?, true)",
                    [$identity['id']],
                );
            }

            try {
                $context = $this->authorization->resolve($identity['id']);
            } catch (Throwable $exception) {
                report($exception);

                return ApiResponse::error(
                    'Authorization data is temporarily unavailable.',
                    503,
                    'authorization_unavailable',
                );
            }

            if ($context === null) {
                return ApiResponse::error(
                    'Your LFMS account has no active profile or authorized role. Contact an administrator.',
                    403,
                    'role_not_assigned',
                );
            }

            $request->attributes->set('lfms_authorization', $context);
            Auth::setUser(new GenericUser([
                'id' => $identity['id'],
                'email' => $identity['email'] ?? $context['profile']['email'] ?? null,
                'profile' => $context['profile'],
                'roles' => $context['roles'],
                'permissions' => $context['permissions'],
            ]));

            return $next($request);
        });
    }
}
