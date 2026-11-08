<?php

namespace Tests\Feature;

use App\Services\Users\UserManagementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    private const ADMIN_ID = '11111111-1111-4111-8111-111111111111';
    private const STAFF_ID = '22222222-2222-4222-8222-222222222222';
    private const ROLE_ID = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'supabase.auth_user_timeout' => 10,
        ]);
    }

    private function fakeIdentity(string $userId, string $role, array $permissions): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => $userId,
                'email' => 'user@example.test',
            ], 200),
            'https://example.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => $userId,
                'full_name' => 'Test User',
                'email' => 'user@example.test',
                'employee_id' => null,
                'status' => 'active',
            ]], 200),
            'https://example.supabase.co/rest/v1/user_roles*' => Http::response([[
                'role_id' => self::ROLE_ID,
                'roles' => ['name' => $role],
            ]], 200),
            'https://example.supabase.co/rest/v1/role_permissions*' => Http::response(
                array_map(fn (string $name) => ['permissions' => ['name' => $name]], $permissions),
                200,
            ),
        ]);
    }

    public function test_admin_can_reach_account_list_and_api_uses_verified_identity(): void
    {
        $this->fakeIdentity(self::ADMIN_ID, 'admin', ['accounts.view', 'accounts.manage']);
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20, 1);
        $service = Mockery::mock(UserManagementService::class);
        $service->shouldReceive('list')->once()->with(Mockery::on(
            fn (array $filters) => $filters === ['search' => 'Mae']
        ))->andReturn($paginator);
        $this->app->instance(UserManagementService::class, $service);

        $this->withToken('valid-admin-token')
            ->getJson('/api/v1/users?search=Mae')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_staff_cannot_access_admin_account_management(): void
    {
        $this->fakeIdentity(self::STAFF_ID, 'staff_sa', ['profile.view_own', 'profile.update_own']);
        $service = Mockery::mock(UserManagementService::class);
        $service->shouldNotReceive('list');
        $this->app->instance(UserManagementService::class, $service);

        $this->withToken('valid-staff-token')
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_staff_profile_endpoint_uses_authenticated_identity_only(): void
    {
        $this->fakeIdentity(self::STAFF_ID, 'staff_sa', ['profile.view_own', 'profile.update_own']);
        $service = Mockery::mock(UserManagementService::class);
        $service->shouldReceive('ownProfile')->once()->with(self::STAFF_ID)->andReturn([
            'id' => self::STAFF_ID,
            'full_name' => 'Test User',
            'email' => 'user@example.test',
            'employee_id' => null,
            'status' => 'active',
        ]);
        $this->app->instance(UserManagementService::class, $service);

        $this->withToken('valid-staff-token')
            ->getJson('/api/v1/profile?user_id='.self::ADMIN_ID)
            ->assertOk()
            ->assertJsonPath('data.profile.id', self::STAFF_ID);
    }

    public function test_invalid_supabase_token_is_rejected_before_account_routes(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response(['message' => 'invalid token'], 401),
        ]);

        $this->withToken('expired-token')
            ->getJson('/api/v1/users')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->getJson('/api/v1/profile')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }
}
