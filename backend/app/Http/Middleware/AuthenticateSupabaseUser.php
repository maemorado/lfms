<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSupabaseUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $supabaseUrl = rtrim((string) config('supabase.url'), '/');
        $publishableKey = (string) config('supabase.publishable_key');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'A bearer token is required.',
            ], 401);
        }

        if ($supabaseUrl === '' || $publishableKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Authentication is not configured on the server.',
            ], 503);
        }

        try {
            $authResponse = Http::acceptJson()
                ->withHeaders(['apikey' => $publishableKey])
                ->withToken($token)
                ->timeout((int) config('supabase.auth_user_timeout', 10))
                ->get($supabaseUrl . '/auth/v1/user');
        } catch (ConnectionException) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication service is temporarily unavailable.',
            ], 503);
        }

        if (!$authResponse->successful()) {
            if ($authResponse->clientError()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The provided access token is invalid or expired.',
                ], 401);
            }

            return response()->json([
                'success' => false,
                'message' => 'Authentication service is temporarily unavailable.',
            ], 503);
        }

        $user = $authResponse->json();

        if (!is_array($user) || !isset($user['id']) || !is_string($user['id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication service returned an invalid user response.',
            ], 503);
        }

        $request->attributes->set('supabase_user', [
            'id' => $user['id'],
            'email' => isset($user['email']) && is_string($user['email']) ? $user['email'] : null,
        ]);

        return $next($request);
    }
}
