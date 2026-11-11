<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSupabaseUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $supabaseUrl = rtrim((string) config('supabase.url'), '/');
        $publishableKey = (string) config('supabase.publishable_key');

        if (!$token) {
            return ApiResponse::error('A bearer token is required.', 401, 'unauthenticated');
        }

        if ($supabaseUrl === '' || $publishableKey === '') {
            return ApiResponse::error('Authentication is not configured on the server.', 503, 'authentication_unavailable');
        }

        try {
            $authResponse = Http::acceptJson()
                ->withHeaders(['apikey' => $publishableKey])
                ->withToken($token)
                ->timeout((int) config('supabase.auth_user_timeout', 10))
                ->get($supabaseUrl.'/auth/v1/user');
        } catch (ConnectionException) {
            return ApiResponse::error('Authentication service is temporarily unavailable.', 503, 'authentication_unavailable');
        }

        if (!$authResponse->successful()) {
            if (in_array($authResponse->status(), [400, 401, 403], true)) {
                return ApiResponse::error('The provided access token is invalid or expired.', 401, 'unauthenticated');
            }

            return ApiResponse::error('Authentication service is temporarily unavailable.', 503, 'authentication_unavailable');
        }

        $user = $authResponse->json();
        if (!is_array($user) || !is_string($user['id'] ?? null) || !Str::isUuid($user['id'])) {
            return ApiResponse::error('Authentication service returned an invalid user response.', 503, 'authentication_unavailable');
        }

        $request->attributes->set('supabase_user', [
            'id' => Str::lower($user['id']),
            'email' => isset($user['email']) && is_string($user['email']) ? $user['email'] : null,
        ]);

        return $next($request);
    }
}
