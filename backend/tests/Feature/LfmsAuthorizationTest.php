<?php

namespace Tests\Feature;

use App\Services\Authorization\SupabaseAuthorizationService;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LfmsAuthorizationTest extends TestCase
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

    public function test_unassigned_user_is_authenticated_but_denied_access(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'email' => 'person@example.test',
            ], 200),
            'https://example.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'full_name' => 'Test Person',
                'email' => 'person@example.test',
                'status' => 'active',
            ]], 200),
            'https://example.supabase.co/rest/v1/user_roles*' => Http::response([], 200),
        ]);

        $this->withToken('verified-token')
            ->getJson('/api/auth/access')
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_assigned');
    }

    public function test_staff_context_comes_from_database_not_client_input(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'email' => 'person@example.test',
            ], 200),
            'https://example.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'full_name' => 'Test Person',
                'email' => 'person@example.test',
                'status' => 'active',
            ]], 200),
            'https://example.supabase.co/rest/v1/user_roles*' => Http::response([[
                'role_id' => 'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1',
                'roles' => ['name' => 'staff_sa'],
            ]], 200),
            'https://example.supabase.co/rest/v1/role_permissions*' => Http::response([
                ['permissions' => ['name' => 'facilities.view']],
                ['permissions' => ['name' => 'reservations.create']],
            ], 200),
        ]);

        $this->withToken('verified-token')
            ->getJson('/api/auth/access?role=admin&permissions[]=accounts.manage')
            ->assertOk()
            ->assertJsonPath('data.roles.0', 'staff_sa')
            ->assertJsonPath('data.permissions.0', 'facilities.view')
            ->assertJsonMissingPath('data.permissions.2');
    }

    public function test_versioned_access_route_returns_profile_roles_and_permissions(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'email' => 'admin@example.test',
            ], 200),
            'https://example.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'full_name' => 'LFMS Administrator',
                'email' => 'admin@example.test',
                'employee_id' => null,
                'status' => 'active',
            ]], 200),
            'https://example.supabase.co/rest/v1/user_roles*' => Http::response([[
                'role_id' => 'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1',
                'roles' => ['name' => 'admin'],
            ]], 200),
            'https://example.supabase.co/rest/v1/role_permissions*' => Http::response([
                ['permissions' => ['name' => 'accounts.view']],
                ['permissions' => ['name' => 'accounts.manage']],
            ], 200),
        ]);

        $this->withToken('verified-token')
            ->getJson('/api/v1/auth/access')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.email', 'admin@example.test')
            ->assertJsonPath('data.profile.status', 'active')
            ->assertJsonPath('data.roles.0', 'admin')
            ->assertJsonPath('data.permissions.0', 'accounts.view');
    }

    public function test_authorization_upstream_failure_fails_closed_with_503(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1',
                'email' => 'person@example.test',
            ], 200),
            'https://example.supabase.co/rest/v1/profiles*' => Http::response(['message' => 'unavailable'], 500),
        ]);

        $this->withToken('verified-token')
            ->getJson('/api/auth/access')
            ->assertStatus(503);
    }

    public function test_authorization_service_rejects_invalid_identity_uuid(): void
    {
        $service = app(SupabaseAuthorizationService::class);
        $this->assertNull($service->resolve('verified-token', 'not-a-uuid'));
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
