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
    private const ROLE_ID = '33333333-3333-4333-8333-333333333333';
    private const TOKEN = 'test-admin-token';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'supabase.auth_user_timeout' => 10,
        ]);

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/auth/v1/user')) {
                return Http::response(['id' => self::ADMIN_ID, 'email' => 'admin@example.test'], 200);
            }

            if (str_contains($url, '/rest/v1/profiles')) {
                return Http::response([[
                    'id' => self::ADMIN_ID,
                    'full_name' => 'Test Admin',
                    'email' => 'admin@example.test',
                    'employee_id' => null,
                    'status' => 'active',
                ]], 200);
            }

            if (str_contains($url, '/rest/v1/user_roles')) {
                return Http::response([[
                    'role_id' => self::ROLE_ID,
                    'roles' => ['name' => 'admin'],
                ]], 200);
            }

            if (str_contains($url, '/rest/v1/role_permissions')) {
                return Http::response(array_map(
                    fn (string $name) => ['permissions' => ['name' => $name]],
                    ['reports.view', 'profile.view_own', 'audit.view'],
                ), 200);
            }

            return Http::response([], 200, ['Content-Range' => '*/0']);
        });
    }

    private function parseQuery(ClientRequest $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        return $query;
    }

    public function test_date_only_bounds_cover_the_entire_selected_day_for_reports_activity_and_audit(): void
    {
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

    public function test_dashboard_uses_assigned_admin_role_for_global_data_scope_not_reports_permission(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/auth/v1/user')) {
                return Http::response(['id' => self::ADMIN_ID, 'email' => 'staff@example.test'], 200);
            }

            if (str_contains($url, '/rest/v1/profiles')) {
                return Http::response([[
                    'id' => self::ADMIN_ID, 'full_name' => 'Test Staff',
                    'email' => 'staff@example.test', 'employee_id' => null, 'status' => 'active',
                ]], 200);
            }

            if (str_contains($url, '/rest/v1/user_roles')) {
                return Http::response([[
                    'role_id' => self::ROLE_ID, 'roles' => ['name' => 'staff_sa'],
                ]], 200);
            }

            if (str_contains($url, '/rest/v1/role_permissions')) {
                return Http::response(array_map(
                    fn (string $name) => ['permissions' => ['name' => $name]],
                    ['reports.view', 'profile.view_own', 'audit.view'],
                ), 200);
            }

            return Http::response([], 200, ['Content-Range' => '*/0']);
        });

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'staff_sa');

        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/activity_logs')
            && ($this->parseQuery($request)['user_id'] ?? null) === 'eq.'.self::ADMIN_ID
        );
        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/reservations')
            && ($this->parseQuery($request)['requester_id'] ?? null) === 'eq.'.self::ADMIN_ID
        );
    }

    public function test_dashboard_does_not_report_zero_when_postgrest_omits_exact_count_metadata(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/auth/v1/user')) {
                return Http::response(['id' => self::ADMIN_ID, 'email' => 'admin@example.test'], 200);
            }
            if (str_contains($url, '/rest/v1/profiles')) {
                return Http::response([[
                    'id' => self::ADMIN_ID, 'full_name' => 'Test Admin',
                    'email' => 'admin@example.test', 'employee_id' => null, 'status' => 'active',
                ]], 200);
            }
            if (str_contains($url, '/rest/v1/user_roles')) {
                return Http::response([['role_id' => self::ROLE_ID, 'roles' => ['name' => 'admin']]], 200);
            }
            if (str_contains($url, '/rest/v1/role_permissions')) {
                return Http::response(array_map(
                    fn (string $name) => ['permissions' => ['name' => $name]],
                    ['reports.view', 'profile.view_own', 'audit.view'],
                ), 200);
            }
            if (str_contains($url, '/rest/v1/facilities')) {
                return Http::response([], 200);
            }

            return Http::response([], 200, ['Content-Range' => '*/0']);
        });

        $this->withToken(self::TOKEN)
            ->getJson('/api/v1/dashboard')
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }

    public function test_dashboard_published_announcement_metric_excludes_expired_announcements(): void
    {
        app(DashboardReportingService::class)->dashboard(self::TOKEN, self::ADMIN_ID, true);

        Http::assertSent(fn (ClientRequest $request) =>
            str_contains($request->url(), '/rest/v1/announcements')
            && str_contains((string) ($this->parseQuery($request)['or'] ?? ''), 'expires_at.is.null')
            && str_contains((string) ($this->parseQuery($request)['or'] ?? ''), 'expires_at.gt.')
        );
    }
}
