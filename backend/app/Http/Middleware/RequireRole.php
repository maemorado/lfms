<?php

namespace App\Http\Middleware;

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
            return response()->json([
                'success' => false,
                'message' => 'You do not have the required role.',
            ], 403);
        }

        return $next($request);
    }
}
