<?php

namespace Tests\Feature;

use App\Services\Dashboard\DashboardReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DashboardReportingApiTest extends TestCase
{
    private const ADMIN_ID = '11111111-1111-4111-8111-111111111111';
    private const STAFF_ID = '22222222-2222-4222-8222-222222222222';
    private const ROLE_ID = '33333333-3333-4333-8333-333333333333';
    private const TOKEN = 'test-user-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'supabase.auth_user_timeout' => 10,
        ]);
    }

    /**
     * Tests use isolated HTTP responses for Supabase. Production code still
     * sends the caller's bearer token and relies on PostgREST/RLS.
     *
     * @param list<string> $permissions
     * @param array<string, list<array<string, mixed>>> $tableRows
     */
    private function fakeSupabase(
        string $role = 'admin',
        array $permissions = ['reports.view', 'profile.view_own', 'audit.view'],
        string $userId = self::ADMIN_ID,
        array $tableRows = [],
        ?string $missingCountTable = null,
    ): void {
        Http::fake(function (ClientRequest $request) use ($role, $permissions, $userId, $tableRows, $missingCountTable) {
            $url = $request->url();

            if (str_contains($url, '/auth/v1/user')) {
                return Http::response(['id' => $userId, 'email' => $role.'@example.test'], 200);
            }

            if (str_contains($url, '/rest/v1/profiles')) {
                return Http::response([[
                    'id' => $userId,
                    'full_name' => $role === 'admin' ? 'Test Admin' : 'Test Staff',
                    'email' => $role.'@example.test',
                    'employee_id' => null,
                    'status' => 'active',
                ]], 200);
            }

            if (str_contains($url, '/rest/v1/user_roles')) {
                return Http::response([[
                    'role_id' => self::ROLE_ID,
                    'roles' => ['name' => $role],
                ]], 200);
            }

            if (str_contains($url, '/rest/v1/role_permissions')) {
                return Http::response(array_map(
                    fn (string $name) => ['permissions' => ['name' => $name]],
                    $permissions,
                ), 200);
            }

            if (str_contains($url, '/rest/v1/')) {
                $table = basename((string) parse_url($url, PHP_URL_PATH));
                if ($table === $missingCountTable) {
                    return Http::response([], 200);
                }

                $rows = $tableRows[$table] ?? [];
                $count = count($rows);
                $range = $count > 0 ? '0-'.($count - 1).'/'.$count : '*/0';

                return Http::response($rows, 200, ['Content-Range' => $range]);
            }

            return Http::response([], 200);
        });
    }

    private function parseQuery(ClientRequest $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        return $query;
    }

    public function test_date_only_bounds_cover_the_entire_selected_day_for_reports_activity_and_audit(): void
    {
        $this->fakeSupabase();
        $date = '2026-11-08';
        $from = CarbonImmutable::parse($date, (string) config('app.timezone'))
            ->startOfDay()->format('Y-m-d\\TH:i:s.uP');
        $to = CarbonImmutable::parse($date, (string) config('app.timezone'))
            ->endOfDay()->format('Y-m-d\\TH:i:s.uP');

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/reports?report=reservations&from='.$date.'&to='.$date)
            ->assertOk();

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/activity?from='.$date.'&to='.$date)
            ->assertOk();

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/audit?from='.$date.'&to='.$date)
            ->assertOk();

        $expected = '(created_at.gte.'.$from.',created_at.lte.'.$to.')';
        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/reservations')
            && ($this->parseQuery($request)['and'] ?? null) === $expected
        );
        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/activity_logs')
            && ($this->parseQuery($request)['and'] ?? null) === $expected
        );
        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/audit_logs')
            && ($this->parseQuery($request)['and'] ?? null) === $expected
        );
    }

    public function test_admin_role_controls_global_dashboard_scope_without_requiring_reports_permission(): void
    {
        $this->fakeSupabase('admin', ['profile.view_own', 'audit.view', 'facilities.view', 'facilities.manage', 'reservations.view', 'reservations.manage', 'inventory.view', 'inventory.manage', 'borrowing.view', 'borrowing.manage', 'maintenance.view', 'maintenance.manage', 'incidents.view', 'incidents.manage', 'notifications.view_own', 'notifications.manage']);

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/facilities')
            && !array_key_exists('user_id', $this->parseQuery($request))
        );
        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/reservations')
            && !array_key_exists('requester_id', $this->parseQuery($request))
        );

        // Dashboard scope is role-based, but reports remain permission-based.
        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/reports?report=reservations')
            ->assertForbidden();
    }

    public function test_staff_with_reports_permission_remains_scoped_to_their_own_records(): void
    {
        $this->fakeSupabase('staff_sa', ['profile.view_own', 'reports.view', 'reservations.view', 'facilities.view', 'inventory.view', 'borrowing.view', 'maintenance.view', 'incidents.view', 'notifications.view_own'], self::STAFF_ID);

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'staff_sa');

        Http::assertNotSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/facilities')
            || str_contains($request->url(), '/rest/v1/inventory_items')
        );

        $ownershipFilters = [
            'reservations' => 'requester_id',
            'borrow_requests' => 'borrower_id',
            'maintenance_requests' => 'reported_by',
            'incident_reports' => 'reported_by',
            'activity_logs' => 'user_id',
            'notifications' => 'user_id',
        ];

        foreach ($ownershipFilters as $table => $column) {
            Http::assertSent(fn (ClientRequest $request) =>
                str_contains($request->url(), '/rest/v1/'.$table)
                && ($this->parseQuery($request)[$column] ?? null) === 'eq.'.self::STAFF_ID
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            );
        }

        // Reporting permission cannot elevate the assigned staff role.
        Http::assertNotSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/activity_logs')
            && !array_key_exists('user_id', $this->parseQuery($request))
        );
    }

    public function test_dashboard_returns_supabase_activity_announcements_reservations_and_notifications(): void
    {
        $activity = [[
            'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'user_id' => self::STAFF_ID,
            'action' => 'reservation_created',
            'module' => 'reservations',
            'record_id' => null,
            'metadata' => [],
            'created_at' => '2026-11-13T08:00:00Z',
            'profiles' => ['id' => self::STAFF_ID, 'full_name' => 'Test Staff'],
        ]];
        $announcements = [[
            'id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'title' => 'Laboratory update',
            'content' => 'The laboratory schedule has been updated.',
            'category' => 'general',
            'published_at' => '2026-11-13T07:00:00Z',
            'expires_at' => null,
        ]];
        $reservations = [[
            'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'purpose' => 'Chemistry laboratory session',
            'start_at' => '2026-11-14T09:00:00Z',
            'end_at' => '2026-11-14T10:00:00Z',
            'status' => 'approved',
            'facilities' => ['id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'name' => 'Chemistry Lab', 'code' => 'LAB-01'],
        ]];
        $notifications = [[
            'id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'title' => 'Reservation update',
            'message' => 'Your reservation has been approved.',
            'type' => 'reservation',
            'read_at' => null,
            'created_at' => '2026-11-13T09:00:00Z',
        ]];

        $this->fakeSupabase('staff_sa', ['profile.view_own', 'reservations.view', 'facilities.view', 'inventory.view', 'borrowing.view', 'maintenance.view', 'incidents.view', 'notifications.view_own'], self::STAFF_ID, [
            'activity_logs' => $activity,
            'announcements' => $announcements,
            'reservations' => $reservations,
            'notifications' => $notifications,
        ]);

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.recent_activity.0.action', 'reservation_created')
            ->assertJsonPath('data.recent_activity.0.profiles.full_name', 'Test Staff')
            ->assertJsonPath('data.announcements.0.title', 'Laboratory update')
            ->assertJsonPath('data.current_reservations.0.facilities.name', 'Chemistry Lab')
            ->assertJsonPath('data.recent_notifications.0.title', 'Reservation update')
            ->assertJsonPath('data.metrics.reservations.total', 1)
            ->assertJsonPath('data.metrics.borrowing.requests', 0)
            ->assertJsonPath('data.metrics.notifications.unread', 1)
            ->assertJsonPath('data.metrics.announcements.published', 1);
    }

    public function test_dashboard_does_not_report_zero_when_postgrest_omits_exact_count_metadata(): void
    {
        $this->fakeSupabase('admin', ['profile.view_own', 'audit.view', 'facilities.view', 'facilities.manage', 'reservations.view', 'reservations.manage', 'inventory.view', 'inventory.manage', 'borrowing.view', 'borrowing.manage', 'maintenance.view', 'maintenance.manage', 'incidents.view', 'incidents.manage', 'notifications.view_own', 'notifications.manage'], self::ADMIN_ID, [], 'facilities');

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/dashboard')
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }

    public function test_missing_authentication_and_missing_dashboard_permission_are_rejected(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();

        $this->fakeSupabase('staff_sa', []);
        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    public function test_report_and_audit_permissions_are_checked_independently(): void
    {
        $this->fakeSupabase('admin', ['profile.view_own', 'facilities.view', 'facilities.manage', 'reservations.view', 'reservations.manage', 'inventory.view', 'inventory.manage', 'borrowing.view', 'borrowing.manage', 'maintenance.view', 'maintenance.manage', 'incidents.view', 'incidents.manage', 'notifications.view_own', 'notifications.manage']);

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/reports?report=reservations')
            ->assertForbidden();

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/audit')
            ->assertForbidden();
    }

    public function test_dashboard_published_announcement_metric_excludes_expired_announcements(): void
    {
        $this->fakeSupabase();
        app(DashboardReportingService::class)->dashboard(self::TOKEN, self::ADMIN_ID, true);

        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/announcements')
            && str_contains((string) ($this->parseQuery($request)['or'] ?? ''), 'expires_at.is.null')
            && str_contains((string) ($this->parseQuery($request)['or'] ?? ''), 'expires_at.gt.')
        );
    }
}
