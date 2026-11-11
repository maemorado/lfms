<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        $state = DB::selectOne(<<<'SQL'
            SELECT
                (SELECT count(*)::int
                 FROM pg_policies
                 WHERE schemaname = 'public') AS policies,
                (SELECT count(*)::int
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'public'
                   AND c.relkind = 'r'
                   AND c.relrowsecurity) AS rls_tables,
                (SELECT count(*)::int
                 FROM pg_policies
                 WHERE schemaname = 'public'
                   AND roles <> ARRAY['lfms_app']::name[]) AS unexpected_policy_roles
        SQL);

        if (
            (int) $state->policies === 82
            && (int) $state->rls_tables === 35
            && (int) $state->unexpected_policy_roles === 0
        ) {
            return;
        }

        if ((int) $state->policies !== 0 || (int) $state->rls_tables !== 0) {
            throw new RuntimeException(
                'LFMS RLS migration found a partial or unexpected policy state; refusing to replace or remove existing policies.',
            );
        }

        $sql = file_get_contents(__DIR__.'/sql/2026_11_11_000003_enable_lfms_rls_and_policies.sql');

        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('LFMS RLS migration SQL is missing or empty.');
        }

        DB::unprepared($sql);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'LFMS row-level security is forward-only; automatic rollback is disabled to prevent accidental policy removal.',
        );
    }
};
