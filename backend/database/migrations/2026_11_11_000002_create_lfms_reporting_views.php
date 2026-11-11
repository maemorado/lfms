<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        $sql = file_get_contents(__DIR__.'/sql/2026_11_11_000002_create_lfms_reporting_views.sql');

        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('LFMS reporting-view migration SQL is missing or empty.');
        }

        DB::unprepared($sql);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'LFMS reporting views are retained during forward-only migration; automatic rollback is disabled.',
        );
    }
};
