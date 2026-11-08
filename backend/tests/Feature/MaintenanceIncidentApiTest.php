<?php

namespace Tests\Feature;

use App\Services\Maintenance\SupabaseMaintenanceIncidentService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class MaintenanceIncidentApiTest extends TestCase
{
    private const ADMIN = '11111111-1111-4111-8111-111111111111';
    private const STAFF = '22222222-2222-4222-8222-222222222222';
    private const ROLE = '33333333-3333-4333-8333-333333333333';
    private const TARGET = '55555555-5555-4555-8555-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'supabase.auth_user_timeout' => 10,
        ]);
    }

    private function fakeUser(string $id, string $role, array $permissions): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response(['id' => $id, 'email' => 'test@example.test'], 200),
            'https://example.supabase.co/rest/v1/profiles*' => Http::response([['id' => $id, 'full_name' => 'Test User', 'email' => 'test@example.test', 'status' => 'active']], 200),
            'https://example.supabase.co/rest/v1/user_roles*' => Http::response([['role_id' => self::ROLE, 'roles' => ['name' => $role]]], 200),
            'https://example.supabase.co/rest/v1/role_permissions*' => Http::response(array_map(fn ($permission) => ['permissions' => ['name' => $permission]], $permissions), 200),
        ]);
    }

    public function test_staff_can_submit_a_maintenance_request_using_authenticated_identity(): void
    {
        $this->fakeUser(self::STAFF, 'staff_sa', ['maintenance.view', 'maintenance.create']);
        $service = Mockery::mock(SupabaseMaintenanceIncidentService::class);
        $service->shouldReceive('createMaintenanceRequest')
            ->once()
            ->with('staff-token', self::STAFF, Mockery::on(fn ($data) => $data['facility_id'] === self::TARGET && !array_key_exists('reported_by', $data)))
            ->andReturn(['id' => self::TARGET, 'status' => 'pending']);
        $this->app->instance(SupabaseMaintenanceIncidentService::class, $service);

        $this->withToken('staff-token')->postJson('/api/v1/maintenance/requests', [
            'facility_id' => self::TARGET,
            'description' => 'The laboratory ceiling light is flickering.',
            'priority' => 'medium',
        ])->assertCreated()->assertJsonPath('data.request.status', 'pending');
    }

    public function test_staff_can_submit_an_incident_report_using_authenticated_identity(): void
    {
        $this->fakeUser(self::STAFF, 'staff_sa', ['incidents.view', 'incidents.create']);
        $service = Mockery::mock(SupabaseMaintenanceIncidentService::class);
        $service->shouldReceive('createIncident')
            ->once()
            ->with('staff-token', self::STAFF, Mockery::on(fn ($data) => $data['incident_type'] === 'damage' && !array_key_exists('reported_by', $data)))
            ->andReturn(['id' => self::TARGET, 'status' => 'open']);
        $this->app->instance(SupabaseMaintenanceIncidentService::class, $service);

        $this->withToken('staff-token')->postJson('/api/v1/incidents', [
            'asset_id' => self::TARGET,
            'incident_type' => 'damage',
            'description' => 'The equipment casing is visibly cracked.',
        ])->assertCreated()->assertJsonPath('data.incident.status', 'open');
    }

    public function test_staff_cannot_update_incident_investigation(): void
    {
        $this->fakeUser(self::STAFF, 'staff_sa', ['incidents.view', 'incidents.create']);
        $service = Mockery::mock(SupabaseMaintenanceIncidentService::class);
        $service->shouldNotReceive('updateIncident');
        $this->app->instance(SupabaseMaintenanceIncidentService::class, $service);

        $this->withToken('staff-token')->patchJson('/api/v1/incidents/'.self::TARGET, [
            'status' => 'resolved',
            'resolution' => 'Repaired.',
        ])->assertForbidden();
    }

    public function test_admin_can_update_incident_investigation(): void
    {
        $this->fakeUser(self::ADMIN, 'admin', ['incidents.view', 'incidents.manage']);
        $service = Mockery::mock(SupabaseMaintenanceIncidentService::class);
        $service->shouldReceive('updateIncident')
            ->once()
            ->with('admin-token', self::ADMIN, self::TARGET, Mockery::on(fn ($data) => $data['status'] === 'under_review'))
            ->andReturn(['id' => self::TARGET, 'status' => 'under_review']);
        $this->app->instance(SupabaseMaintenanceIncidentService::class, $service);

        $this->withToken('admin-token')->patchJson('/api/v1/incidents/'.self::TARGET, [
            'status' => 'under_review',
        ])->assertOk()->assertJsonPath('data.incident.status', 'under_review');
    }


    public function test_active_damage_incidents_and_maintenance_block_equipment_from_borrowing(): void
    {
        Http::fake([
            'https://example.supabase.co/rest/v1/incident_reports*' => Http::response([
                ['asset_id' => self::TARGET],
            ], 200),
            'https://example.supabase.co/rest/v1/maintenance_requests*' => Http::response([
                ['asset_id' => '66666666-6666-4666-8666-666666666666'],
            ], 200),
        ]);

        $service = new SupabaseMaintenanceIncidentService();
        $blocked = $service->blockedEquipmentAssetIds('staff-token');

        $this->assertEqualsCanonicalizing([
            self::TARGET,
            '66666666-6666-4666-8666-666666666666',
        ], $blocked);
    }

    public function test_invalid_maintenance_status_is_rejected_before_service_call(): void
    {
        $this->fakeUser(self::ADMIN, 'admin', ['maintenance.view', 'maintenance.manage']);
        $service = Mockery::mock(SupabaseMaintenanceIncidentService::class);
        $service->shouldNotReceive('updateMaintenanceStatus');
        $this->app->instance(SupabaseMaintenanceIncidentService::class, $service);

        $this->withToken('admin-token')->patchJson('/api/v1/maintenance/requests/'.self::TARGET, [
            'status' => 'rejected',
        ])->assertUnprocessable();
    }
}
