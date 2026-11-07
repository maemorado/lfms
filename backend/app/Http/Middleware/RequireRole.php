<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $context = $request->attributes->get('lfms_authorization', []);
        $assigned = is_array($context) && is_array($context['roles'] ?? null) ? $context['roles'] : [];

        if (array_intersect($roles, $assigned) === []) {
            return ApiResponse::error('You do not have the required role.', 403, 'forbidden');
        }

        return $next($request);
    }
}
