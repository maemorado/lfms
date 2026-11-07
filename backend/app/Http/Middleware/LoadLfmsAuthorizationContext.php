<?php

namespace App\Http\Middleware;

use App\Services\Authorization\SupabaseAuthorizationService;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LoadLfmsAuthorizationContext
{
    public function __construct(private readonly SupabaseAuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('supabase_user');
        $token = $request->bearerToken();

        if (!is_array($identity) || !is_string($identity['id'] ?? null) || !is_string($token) || $token === '') {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            $context = $this->authorization->resolve($token, $identity['id']);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Authorization data is temporarily unavailable.',
            ], 503);
        }

        if ($context === null) {
            return response()->json([
                'success' => false,
                'message' => 'Your LFMS account has no active profile or authorized role. Contact an administrator.',
                'code' => 'role_not_assigned',
            ], 403);
        }

        $request->attributes->set('lfms_authorization', $context);
        Auth::setUser(new GenericUser([
            'id' => $identity['id'],
            'email' => $identity['email'] ?? null,
            'profile' => $context['profile'],
            'roles' => $context['roles'],
            'permissions' => $context['permissions'],
        ]));

        return $next($request);
    }
}
