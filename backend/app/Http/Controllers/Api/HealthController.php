<?php

namespace App\\Http\\Controllers\\Api;

use App\\Support\\Api\\ApiResponse;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Support\\Facades\\DB;
use Throwable;

final class HealthController
{
    public function show(): JsonResponse
    {
        return $this->respond('LFMS API is running.', ['application' => config('app.name'), 'api_version' => 'v1']);
    }

    public function showVersioned(): JsonResponse
    {
        return $this->respond('LFMS API v1 is running.', ['application' => config('app.name'), 'api_version' => 'v1']);
    }

    public function readiness(): JsonResponse
    {
        return $this->checkDatabaseReadiness();
    }

    public function readinessVersioned(): JsonResponse
    {
        return $this->checkDatabaseReadiness();
    }

    private function checkDatabaseReadiness(): JsonResponse
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'pgsql') {
                return ApiResponse::error('The LFMS database is not ready.', 503, 'database_not_ready');
            }

            $state = $connection->selectOne(<<<'SQL'
                SELECT
                    to_regclass('public.profiles') IS NOT NULL AS profiles_ready,
                    to_regprocedure('private.current_user_id()') IS NOT NULL AS identity_helper_ready,
                    EXISTS (
                        SELECT 1 FROM pg_roles
                        WHERE rolname = 'lfms_app'
                          AND rolcanlogin = false
                          AND rolsuper = false
                          AND rolbypassrls = false
                    ) AS runtime_group_ready,
                    (
                        SELECT r.rolcanlogin
                           AND NOT r.rolsuper
                           AND NOT r.rolbypassrls
                           AND r.rolname <> 'neondb_owner'
                        FROM pg_roles r
                        WHERE r.rolname = current_user
                    ) AS runtime_login_safe,
                    pg_has_role(current_user, 'lfms_app', 'member') AS runtime_group_membership,
                    has_table_privilege(current_user, 'public.profiles', 'SELECT') AS profile_select_granted,
                    has_function_privilege(current_user, 'private.current_user_id()', 'EXECUTE') AS identity_helper_granted,
                    NOT EXISTS (
                        SELECT 1
                        FROM pg_class c
                        JOIN pg_namespace n ON n.oid = c.relnamespace
                        WHERE n.nspname = 'public'
                          AND c.relkind IN ('r', 'p')
                          AND NOT c.relrowsecurity
                    ) AS all_public_tables_rls_enabled,
                    NOT EXISTS (
                        SELECT 1
                        FROM pg_class c
                        JOIN pg_namespace n ON n.oid = c.relnamespace
                        WHERE n.nspname = 'public'
                          AND c.relkind IN ('r', 'p')
                          AND NOT EXISTS (
                              SELECT 1 FROM pg_policies p
                              WHERE p.schemaname = n.nspname
                                AND p.tablename = c.relname
                          )
                    ) AS all_public_tables_have_policies,
                    (
                        SELECT count(*) = 5
                        FROM information_schema.views
                        WHERE table_schema = 'public'
                          AND table_name IN (
                              'dashboard_metrics',
                              'inventory_low_stock',
                              'overdue_borrow_requests',
                              'facility_reservation_summary',
                              'monthly_reservation_trends'
                          )
                    ) AS reporting_views_ready
            SQL);

            $ready = $state !== null
                && (bool) $state->profiles_ready
                && (bool) $state->identity_helper_ready
                && (bool) $state->runtime_group_ready
                && (bool) $state->runtime_login_safe
                && (bool) $state->runtime_group_membership
                && (bool) $state->profile_select_granted
                && (bool) $state->identity_helper_granted
                && (bool) $state->all_public_tables_rls_enabled
                && (bool) $state->all_public_tables_have_policies
                && (bool) $state->reporting_views_ready;

            if (! $ready) {
                return ApiResponse::error('The LFMS database is not ready.', 503, 'database_not_ready');
            }

            return ApiResponse::success(
                ['status' => 'ready', 'database' => 'available'],
                'LFMS database is ready.',
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('The LFMS database is not ready.', 503, 'database_not_ready');
        }
    }

    private function respond(string $message, array $data): JsonResponse
    {
        return ApiResponse::success($data, $message);
    }
}
