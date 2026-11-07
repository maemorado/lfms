<?php

namespace Tests\Feature;

use App\Exceptions\FacilityReservationException;
use App\Services\Facilities\FacilityManagementService;
use App\Services\Reservations\ReservationManagementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

final class FacilityReservationApiTest extends TestCase
{
    private const ADMIN_ID = '11111111-1111-4111-8111-111111111111';
    private const STAFF_ID = '22222222-2222-4222-8222-222222222222';
    private const RESOURCE_ID = '33333333-3333-4333-8333-333333333333';
    private const ROLE_ID = '44444444-4444-4444-8444-444444444444';

    private function authenticate(string $id, string $role, array $permissions): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response(['id' => $id, 'email' => 'user@example.test'], 200),
            'https://example.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => $id, 'full_name' => 'Test User', 'email' => 'user@example.test',
                'employee_id' => null, 'status' => 'active',
            ]], 200),
            'https://example.supabase.co/rest/v1/user_roles*' => Http::response([[
                'role_id' => self::ROLE_ID, 'roles' => ['name' => $role],
            ]], 200),
            'https://example.supabase.co/rest/v1/role_permissions*' => Http::response(
                array_map(fn (string $name) => ['permissions' => ['name' => $name]], $permissions), 200,
            ),
        ]);
    }

    public function test_staff_can_list_facilities_using_facility_view_permission(): void
    {
        $this->authenticate(self::STAFF_ID, 'staff_sa', ['facilities.view']);
        $service = Mockery::mock(FacilityManagementService::class);
        $service->shouldReceive('list')->once()->andReturn(new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20));
        $this->app->instance(FacilityManagementService::class, $service);

        $this->withToken('staff-token')->getJson('/api/v1/facilities')
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_staff_cannot_create_or_update_facilities(): void
    {
        $this->authenticate(self::STAFF_ID, 'staff_sa', ['facilities.view']);
        $service = Mockery::mock(FacilityManagementService::class);
        $service->shouldNotReceive('create');
        $service->shouldNotReceive('update');
        $this->app->instance(FacilityManagementService::class, $service);

        $this->withToken('staff-token')->postJson('/api/v1/facilities', [])->assertForbidden();
        $this->withToken('staff-token')->patchJson('/api/v1/facilities/'.self::RESOURCE_ID, [])->assertForbidden();
    }

    public function test_staff_reservation_list_is_scoped_to_verified_user_identity(): void
    {
        $this->authenticate(self::STAFF_ID, 'staff_sa', ['reservations.view']);
        $service = Mockery::mock(ReservationManagementService::class);
        $service->shouldReceive('list')->once()
            ->with(Mockery::type('array'), self::STAFF_ID, false)
            ->andReturn(new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20));
        $this->app->instance(ReservationManagementService::class, $service);

        $this->withToken('staff-token')->getJson('/api/v1/reservations?requester_id='.self::ADMIN_ID)
            ->assertOk()->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_staff_cannot_approve_or_reject_reservations(): void
    {
        $this->authenticate(self::STAFF_ID, 'staff_sa', ['reservations.view','reservations.create']);
        $service = Mockery::mock(ReservationManagementService::class);
        $service->shouldNotReceive('decide');
        $this->app->instance(ReservationManagementService::class, $service);

        $this->withToken('staff-token')->patchJson('/api/v1/reservations/'.self::RESOURCE_ID.'/decision', [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_admin_can_review_a_pending_reservation(): void
    {
        $this->authenticate(self::ADMIN_ID, 'admin', ['reservations.view','reservations.manage']);
        $service = Mockery::mock(ReservationManagementService::class);
        $service->shouldReceive('decide')->once()
            ->with(self::ADMIN_ID, self::RESOURCE_ID, 'approved', 'Reviewed')
            ->andReturn([
                'id' => self::RESOURCE_ID, 'facility_id' => self::RESOURCE_ID,
                'requester_id' => self::STAFF_ID, 'start_at' => '2027-01-01T10:00:00Z',
                'end_at' => '2027-01-01T11:00:00Z', 'purpose' => 'Lab session',
                'subject_course' => null, 'participant_count' => 10, 'status' => 'approved',
                'cancellation_reason' => null, 'facility' => [], 'requester' => [], 'approvals' => [],
            ]);
        $this->app->instance(ReservationManagementService::class, $service);

        $this->withToken('admin-token')->patchJson('/api/v1/reservations/'.self::RESOURCE_ID.'/decision', [
            'decision' => 'approved', 'remarks' => 'Reviewed',
        ])->assertOk()->assertJsonPath('data.reservation.status', 'approved');
    }

    public function test_staff_cannot_fetch_another_users_reservation(): void
    {
        $this->authenticate(self::STAFF_ID, 'staff_sa', ['reservations.view']);
        $service = Mockery::mock(ReservationManagementService::class);
        $service->shouldReceive('find')->once()
            ->with(self::RESOURCE_ID, self::STAFF_ID, false)
            ->andThrow(new FacilityReservationException('Reservation not found.', 404));
        $this->app->instance(ReservationManagementService::class, $service);

        $this->withToken('staff-token')->getJson('/api/v1/reservations/'.self::RESOURCE_ID)
            ->assertNotFound()->assertJsonPath('code', 'not_found');
    }
}
