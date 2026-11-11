<?php

namespace Tests\Feature;

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Services\Authorization\NeonAuthorizationService;
use App\Services\Security\NeonIdentityContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class LfmsAuthorizationTest extends TestCase
{
    private const USER_ID = '9d7f0c4e-38f8-4f30-8d5a-27d46e5db1e1';
    private const ROLE_ID = 'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1';
    private const PERMISSION_ID = 'b2b2b2b2-b2b2-4b2b-8b2b-b2b2b2b2b2b2';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'supabase.auth_user_timeout' => 10,
        ]);

        Schema::create('profiles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('employee_id')->nullable();
            $table->string('status');
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->unique();
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->string('user_id');
            $table->string('role_id');
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->unique();
        });
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->string('role_id');
            $table->string('permission_id');
        });
    }

    protected function tearDown(): void
    {
        foreach (['role_permissions', 'permissions', 'user_roles', 'roles', 'profiles'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_authenticated_user_without_an_active_neon_profile_is_denied(): void
    {
        $this->mockIdentity();
        $this->mockEmailSync(0);
        $this->fakeSupabaseUser();

        $this->withToken('verified-token')
            ->getJson('/api/v1/auth/access')
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_assigned');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/v1/'));
    }

    public function test_inactive_neon_profile_is_denied_even_when_auth_token_is_valid(): void
    {
        DB::table('profiles')->insert([
            'id' => self::USER_ID,
            'full_name' => 'Inactive User',
            'email' => 'person@example.test',
            'status' => 'inactive',
        ]);
        $this->mockIdentity();
        $this->mockEmailSync(0);
        $this->fakeSupabaseUser();

        $this->withToken('verified-token')
            ->getJson('/api/v1/auth/access')
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_assigned');
    }

    public function test_staff_context_and_permissions_are_loaded_from_neon_not_client_input(): void
    {
        $this->seedAuthorization('staff_sa', ['facilities.view', 'reservations.create']);
        $this->mockIdentity();
        $this->mockEmailSync(1);
        $this->fakeSupabaseUser();

        $this->withToken('verified-token')
            ->getJson('/api/v1/auth/access?role=admin&permissions[]=accounts.manage')
            ->assertOk()
            ->assertJsonPath('data.profile.id', self::USER_ID)
            ->assertJsonPath('data.profile.status', 'active')
            ->assertJsonPath('data.roles.0', 'staff_sa')
            ->assertJsonPath('data.permissions.0', 'facilities.view')
            ->assertJsonMissing(['accounts.manage']);

        Http::assertSent(fn ($request) => $request->url() === 'https://example.supabase.co/auth/v1/user');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/v1/'));
    }

    public function test_neon_admin_role_and_permissions_are_resolved_from_role_join_tables(): void
    {
        $this->seedAuthorization('admin', ['accounts.view', 'accounts.manage']);
        $service = app(NeonAuthorizationService::class);
        $context = $service->resolve(self::USER_ID);

        self::assertSame(['admin'], $context['roles']);
        self::assertSame(['accounts.manage', 'accounts.view'], $context['permissions']);
    }

    public function test_neon_authorization_service_rejects_invalid_identity_uuid(): void
    {
        self::assertNull(app(NeonAuthorizationService::class)->resolve('not-a-uuid'));
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

        self::assertSame(403, $response->getStatusCode());
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

        self::assertSame(403, $response->getStatusCode());
    }

    private function seedAuthorization(string $roleName, array $permissionNames): void
    {
        DB::table('profiles')->insert([
            'id' => self::USER_ID,
            'full_name' => 'Test User',
            'email' => 'person@example.test',
            'employee_id' => 'T-001',
            'status' => 'active',
        ]);
        DB::table('roles')->insert(['id' => self::ROLE_ID, 'name' => $roleName]);
        DB::table('user_roles')->insert(['user_id' => self::USER_ID, 'role_id' => self::ROLE_ID]);

        foreach ($permissionNames as $index => $permissionName) {
            $permissionId = sprintf('b2b2b2b2-b2b2-4b2b-8b2b-%012d', $index + 1);
            DB::table('permissions')->insert(['id' => $permissionId, 'name' => $permissionName]);
            DB::table('role_permissions')->insert(['role_id' => self::ROLE_ID, 'permission_id' => $permissionId]);
        }
    }

    private function mockIdentity(): void
    {
        $identity = Mockery::mock(NeonIdentityContext::class);
        $identity->shouldReceive('set')->once()->with(self::USER_ID);
        $this->app->instance(NeonIdentityContext::class, $identity);
    }

    private function mockEmailSync(int $times): void
    {
        $authorization = Mockery::mock(NeonAuthorizationService::class)->makePartial();
        if ($times > 0) {
            $authorization->shouldReceive('syncAuthEmail')
                ->times($times)
                ->with(self::USER_ID, 'person@example.test');
        } else {
            $authorization->shouldNotReceive('syncAuthEmail');
        }
        $this->app->instance(NeonAuthorizationService::class, $authorization);
    }

    private function fakeSupabaseUser(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => self::USER_ID,
                'email' => 'person@example.test',
            ], 200),
        ]);
    }
}
