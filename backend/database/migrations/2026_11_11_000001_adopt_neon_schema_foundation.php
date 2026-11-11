<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

return new class extends Migration
{
    /**
     * Adopt the already-provisioned LFMS Neon foundation without changing
     * application tables or records. This is a verification-only baseline.
     *
     * The migration intentionally does not assert that tables are empty.
     */
    public function up(): void
    {
        $expectedTables = [
            'activity_logs',
            'announcements',
            'audit_logs',
            'borrow_request_items',
            'borrow_requests',
            'borrow_transaction_items',
            'borrow_transactions',
            'buildings',
            'equipment_assets',
            'facilities',
            'facility_feature_assignments',
            'facility_features',
            'facility_types',
            'incident_attachments',
            'incident_reports',
            'inventory_items',
            'inventory_locations',
            'inventory_movements',
            'inventory_stock',
            'item_categories',
            'maintenance_records',
            'maintenance_requests',
            'maintenance_schedules',
            'notifications',
            'permissions',
            'profiles',
            'reservation_approvals',
            'reservation_attachments',
            'reservation_participants',
            'reservations',
            'return_transaction_items',
            'return_transactions',
            'role_permissions',
            'roles',
            'user_roles',
        ];

        $actualTables = array_map(
            static fn (object $row): string => $row->table_name,
            DB::select(<<<'SQL'
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
                  AND table_type = 'BASE TABLE'
                ORDER BY table_name
            SQL),
        );

        if ($actualTables !== $expectedTables) {
            throw new RuntimeException(
                'LFMS Neon baseline mismatch: public base tables differ from the inspected 35-table foundation.',
            );
        }

        $columnSignature = DB::selectOne(<<<'SQL'
            SELECT md5(
                string_agg(
                    format(
                        '%s.%s:%s:%s:%s',
                        c.table_name,
                        c.column_name,
                        c.data_type,
                        c.is_nullable,
                        coalesce(c.column_default, '')
                    ),
                    '|' ORDER BY c.table_name, c.ordinal_position
                )
            ) AS signature
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_schema = c.table_schema
             AND t.table_name = c.table_name
             AND t.table_type = 'BASE TABLE'
            WHERE c.table_schema = 'public'
        SQL)?->signature;

        if ($columnSignature !== '4048058526cb58089d1790bb63420d02') {
            throw new RuntimeException(
                'LFMS Neon baseline mismatch: public table column signature differs from the inspected Supabase source schema.',
            );
        }

        $constraintCounts = DB::selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (WHERE contype = 'p')::int AS primary_keys,
                count(*) FILTER (WHERE contype = 'u')::int AS unique_constraints,
                count(*) FILTER (WHERE contype = 'c')::int AS check_constraints,
                count(*) FILTER (WHERE contype = 'x')::int AS exclusion_constraints,
                count(*) FILTER (WHERE contype = 'f')::int AS foreign_keys
            FROM pg_constraint
            WHERE connamespace = 'public'::regnamespace
        SQL);

        $expectedCounts = [
            'primary_keys' => 35,
            'unique_constraints' => 22,
            'check_constraints' => 74,
            'exclusion_constraints' => 1,
            'foreign_keys' => 58,
        ];

        foreach ($expectedCounts as $key => $expected) {
            if ((int) $constraintCounts->{$key} !== $expected) {
                throw new RuntimeException(
                    "LFMS Neon baseline mismatch: expected {$expected} {$key}, found {$constraintCounts->{$key}}.",
                );
            }
        }

        $externalAuthForeignKeys = (int) DB::selectOne(<<<'SQL'
            SELECT count(*)::int AS aggregate
            FROM pg_constraint c
            JOIN pg_class target_table ON target_table.oid = c.confrelid
            JOIN pg_namespace target_schema ON target_schema.oid = target_table.relnamespace
            WHERE c.contype = 'f'
              AND c.connamespace = 'public'::regnamespace
              AND target_schema.nspname = 'auth'
              AND target_table.relname = 'users'
        SQL)->aggregate;

        if ($externalAuthForeignKeys !== 0) {
            throw new RuntimeException(
                'LFMS Neon baseline must not contain a cross-database foreign key to Supabase auth.users.',
            );
        }

        $extensionExists = (bool) DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'btree_gist') AS present",
        )->present;

        if (! $extensionExists) {
            throw new RuntimeException('LFMS Neon baseline requires the btree_gist extension for reservation overlap protection.');
        }

        $runtimeGroup = DB::selectOne(
            "SELECT rolcanlogin, rolbypassrls FROM pg_roles WHERE rolname = 'lfms_app'",
        );

        if (
            $runtimeGroup === null
            || (bool) $runtimeGroup->rolcanlogin
            || (bool) $runtimeGroup->rolbypassrls
        ) {
            throw new RuntimeException(
                'LFMS Neon baseline requires lfms_app to be a NOLOGIN, non-BYPASSRLS privilege group.',
            );
        }

        $identityHelper = DB::selectOne(
            "SELECT to_regprocedure('private.current_user_id()') IS NOT NULL AS present",
        );

        if (! (bool) $identityHelper->present) {
            throw new RuntimeException('LFMS Neon baseline is missing private.current_user_id().');
        }

        $borrowingGuard = DB::selectOne(
            "SELECT pg_get_functiondef('public.require_borrowing_rpc()'::regprocedure) AS definition",
        )?->definition;

        if (
            ! is_string($borrowingGuard)
            || ! str_contains($borrowingGuard, "TG_TABLE_NAME = 'borrow_requests'")
            || ! str_contains($borrowingGuard, "TG_TABLE_NAME = 'borrow_transaction_items'")
            || ! str_contains($borrowingGuard, 'NEW.status')
            || ! str_contains($borrowingGuard, 'NEW.item_id')
        ) {
            throw new RuntimeException(
                'LFMS Neon baseline is missing the table-specific borrowing trigger correction.',
            );
        }
    }

    /**
     * This adoption migration does not create objects, so rollback must not
     * remove or mutate the pre-existing Neon foundation.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'The LFMS Neon foundation adoption migration is intentionally irreversible; no schema objects are removed.',
        );
    }
};
