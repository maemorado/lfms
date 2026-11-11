<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        $viewCount = (int) DB::selectOne(<<<'SQL'
            SELECT count(*)::int AS aggregate
            FROM information_schema.views
            WHERE table_schema = 'public'
              AND table_name IN (
                'dashboard_metrics',
                'inventory_low_stock',
                'overdue_borrow_requests',
                'facility_reservation_summary',
                'monthly_reservation_trends'
              )
        SQL)->aggregate;

        if ($viewCount === 5) {
            return;
        }

        if ($viewCount !== 0) {
            throw new RuntimeException(
                'LFMS reporting-view migration found a partial view set; inspect the Neon schema before proceeding.',
            );
        }

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
