<?php

namespace Tests\\Feature;

use App\\Http\\Middleware\\RequirePermission;
use App\\Http\\Middleware\\RequireRole;
use App\\Services\\Authorization\\NeonAuthorizationService;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Http;
use Mockery;
use RuntimeException;
use Tests\\TestCase;

final class LfmsAuthorizationTest extends TestCase
{
    private const USER_ID = '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'supabase.auth_user_timeout' => 10,
        ]);
    }

    private function fakeVerifiedAuthUser(string $email = 'person@example.test'): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => self::USER_ID,
                'email' => $email,
            ], 200),
        ]);
    }

    public function test_unassigned_user_is_authenticated_but_denied_access(): void
    {
        $this->fakeVerifiedAuthUser();

        $this->mock(NeonAuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->with(self::USER_ID)->andReturn(null);
        });

        $this->withToken('verified-token')
            ->getJson('/api/auth/access')
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_assigned');

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://example.supabase.co/auth/v1/user'
            && $request->hasHeader('Authorization', 'Bearer verified-token')
        );
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/v1/'));
    }

    public function test_staff_authorization_is_loaded_from_neon_not_client_input(): void
    {
        $this->fakeVerifiedAuthUser();

        $this->mock(NeonAuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->with(self::USER_ID)->andReturn([
                'profile' => [
                    'id' => self::USER_ID,
                    'full_name' => 'Test Person',
                    'email' => 'person@example.test',
                    'employee_id' => null,
                    'status' => 'active',
                ],
                'roles' => ['staff_sa'],
                'permissions' => ['facilities.view', 'reservations.create'],
            ]);
        });

        $this->withToken('verified-token')
            ->getJson('/api/auth/access?role=admin&permissions[]=accounts.manage')
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'staff_sa')
            ->assertJsonPath('data.permissions.0', 'facilities.view')
            ->assertJsonMissingPath('data.permissions.2');
    }

    public function test_versioned_access_route_returns_profile_roles_and_permissions(): void
    {
        $this->fakeVerifiedAuthUser('admin@example.test');

        $this->mock(NeonAuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->with(self::USER_ID)->andReturn([
                'profile' => [
                    'id' => self::USER_ID,
                    'full_name' => 'LFMS Administrator',
                    'email' => 'admin@example.test',
                    'employee_id' => null,
                    'status' => 'active',
                ],
                'roles' => ['admin'],
                'permissions' => ['accounts.view', 'accounts.manage'],
            ]);
        });

        $this->withToken('verified-token')
            ->getJson('/api/v1/auth/access')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.email', 'admin@example.test')
            ->assertJsonPath('data.profile.status', 'active')
            ->assertJsonPath('data.roles.0', 'admin')
            ->assertJsonPath('data.permissions.0', 'accounts.view');
    }

    public function test_neon_authorization_failure_fails_closed_with_503(): void
    {
        $this->fakeVerifiedAuthUser();

        $this->mock(NeonAuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->with(self::USER_ID)
                ->andThrow(new RuntimeException('Neon authorization unavailable.'));
        });

        $this->withToken('verified-token')
            ->getJson('/api/auth/access')
            ->assertStatus(503)
            ->assertJsonPath('code', 'authorization_unavailable');
    }

    public function test_authorization_service_rejects_invalid_identity_uuid_before_database_access(): void
    {
        $service = app(NeonAuthorizationService::class);

        $this->assertNull($service->resolve('not-a-uuid'));
    }

    public function test_permission_middleware_denies_missing_permissions(): void
    {
        $request = Request::create('/api/test');
        $request->attributes->set('lfms_authorization', [
            'roles' => ['staff_sa'],
            'permissions' => ['facilities.view'],
        ]);

        $response = (new RequirePermission())->handle(
            $request,
            fn () => response()->json(['success' => true]),
            'facilities.manage',
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_role_middleware_denies_staff_from_admin_only_route(): void
    {
        $request = Request::create('/api/test');
        $request->attributes->set('lfms_authorization', [
            'roles' => ['staff_sa'],
            'permissions' => ['facilities.view'],
        ]);

        $response = (new RequireRole())->handle(
            $request,
            fn () => response()->json(['success' => true]),
            'admin',
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
