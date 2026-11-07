<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthenticateSupabaseUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'supabase.auth_user_timeout' => 10,
        ]);
    }

    public function test_identity_route_rejects_a_missing_bearer_token(): void
    {
        Http::fake();

        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }

    public function test_identity_route_returns_the_verified_supabase_user(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'email' => 'staff@example.test',
            ], 200),
        ]);

        $this->withToken('valid-test-token')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1')
            ->assertJsonPath('data.user.email', 'staff@example.test');

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://example.supabase.co/auth/v1/user'
            && $request->hasHeader('apikey', 'test-publishable-key')
            && $request->hasHeader('Authorization', 'Bearer valid-test-token')
        );
    }

    public function test_identity_route_rejects_an_invalid_or_expired_token(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'message' => 'Invalid JWT',
            ], 401),
        ]);

        $this->withToken('invalid-test-token')
            ->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_identity_route_returns_service_unavailable_when_auth_is_unavailable(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'message' => 'Auth unavailable',
            ], 500),
        ]);

        $this->withToken('valid-test-token')
            ->getJson('/api/auth/me')
            ->assertServiceUnavailable()
            ->assertJsonPath('success', false);
    }
}
