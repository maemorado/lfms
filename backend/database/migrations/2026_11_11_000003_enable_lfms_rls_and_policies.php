<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
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
