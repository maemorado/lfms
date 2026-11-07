<?php

namespace Tests\Feature;

use App\Services\Auth\SupabaseAdminAuthService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseAdminAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.secret_key' => 'test-server-secret',
            'supabase.frontend_url' => 'http://localhost:5173',
        ]);
    }

    public function test_invitation_uses_server_secret_and_returns_auth_user_id(): void
    {
        $userId = '44444444-4444-4444-8444-444444444444';
        Http::fake([
            'https://example.supabase.co/auth/v1/invite*' => Http::response([
                'id' => $userId,
                'email' => 'new.user@example.test',
            ], 200),
        ]);

        $result = app(SupabaseAdminAuthService::class)->invite('new.user@example.test', 'New User');

        $this->assertSame($userId, $result);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/auth/v1/invite')
                && $request->hasHeader('apikey', 'test-server-secret')
                && $request->hasHeader('Authorization', 'Bearer test-server-secret')
                && $request['email'] === 'new.user@example.test';
        });
    }

    public function test_account_deactivation_bans_auth_user(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/admin/users/*' => Http::response(['id' => '44444444-4444-4444-8444-444444444444'], 200),
        ]);

        app(SupabaseAdminAuthService::class)->setBanned('44444444-4444-4444-8444-444444444444', true);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->hasHeader('apikey', 'test-server-secret')
            && $request['ban_duration'] === '876000h');
    }

    public function test_missing_server_secret_fails_closed(): void
    {
        config(['supabase.secret_key' => null]);

        $this->expectException(\RuntimeException::class);
        app(SupabaseAdminAuthService::class)->invite('new.user@example.test', 'New User');
    }
}
