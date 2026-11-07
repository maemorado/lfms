<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $context = $request->attributes->get('lfms_authorization', []);
        $granted = is_array($context) && is_array($context['permissions'] ?? null) ? $context['permissions'] : [];

        if (array_intersect($permissions, $granted) === []) {
            return ApiResponse::error('You do not have permission to perform this action.', 403, 'forbidden');
        }

        return $next($request);
    }
}
