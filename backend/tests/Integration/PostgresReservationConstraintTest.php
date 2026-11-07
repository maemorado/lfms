<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostgresReservationConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('This integration test requires PostgreSQL.');
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('DROP TABLE IF EXISTS reservations');
        DB::statement('DROP TABLE IF EXISTS facilities');
        DB::statement('CREATE TABLE facilities (id uuid PRIMARY KEY)');
        DB::statement('CREATE TABLE reservations (
            id uuid PRIMARY KEY,
            facility_id uuid NOT NULL REFERENCES facilities(id),
            start_at timestamptz NOT NULL,
            end_at timestamptz NOT NULL,
            status text NOT NULL,
            CONSTRAINT reservation_period_valid CHECK (end_at > start_at)
        )');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_no_approved_overlap
            EXCLUDE USING gist (
                facility_id WITH =,
                tstzrange(start_at, end_at, '[)') WITH &&
            ) WHERE (status = 'approved')");
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS reservations');
            DB::statement('DROP TABLE IF EXISTS facilities');
        }
        parent::tearDown();
    }

    public function test_database_rejects_overlapping_approved_reservations_for_same_facility(): void
    {
        $facilityId = (string)Str::uuid();
        DB::table('facilities')->insert(['id' => $facilityId]);
        DB::table('reservations')->insert([
            'id' => (string)Str::uuid(), 'facility_id' => $facilityId,
            'start_at' => '2030-04-01 09:00:00+00', 'end_at' => '2030-04-01 10:00:00+00',
            'status' => 'approved',
        ]);

        try {
            DB::table('reservations')->insert([
                'id' => (string)Str::uuid(), 'facility_id' => $facilityId,
                'start_at' => '2030-04-01 09:30:00+00', 'end_at' => '2030-04-01 10:30:00+00',
                'status' => 'approved',
            ]);
            self::fail('PostgreSQL should reject overlapping approved reservations.');
        } catch (QueryException $exception) {
            self::assertSame('23P01', $exception->errorInfo[0] ?? null);
        }
    }

    public function test_half_open_ranges_allow_adjacent_bookings_and_pending_requests_do_not_reserve_a_slot(): void
    {
        $facilityId = (string)Str::uuid();
        $otherFacilityId = (string)Str::uuid();
        DB::table('facilities')->insert([['id' => $facilityId], ['id' => $otherFacilityId]]);

        DB::table('reservations')->insert([
            'id' => (string)Str::uuid(), 'facility_id' => $facilityId,
            'start_at' => '2030-04-01 09:00:00+00', 'end_at' => '2030-04-01 10:00:00+00',
            'status' => 'approved',
        ]);
        DB::table('reservations')->insert([
            'id' => (string)Str::uuid(), 'facility_id' => $facilityId,
            'start_at' => '2030-04-01 10:00:00+00', 'end_at' => '2030-04-01 11:00:00+00',
            'status' => 'approved',
        ]);
        DB::table('reservations')->insert([
            'id' => (string)Str::uuid(), 'facility_id' => $facilityId,
            'start_at' => '2030-04-01 09:30:00+00', 'end_at' => '2030-04-01 10:30:00+00',
            'status' => 'pending',
        ]);
        DB::table('reservations')->insert([
            'id' => (string)Str::uuid(), 'facility_id' => $otherFacilityId,
            'start_at' => '2030-04-01 09:30:00+00', 'end_at' => '2030-04-01 10:30:00+00',
            'status' => 'approved',
        ]);

        self::assertSame(4, DB::table('reservations')->count());
    }
}
