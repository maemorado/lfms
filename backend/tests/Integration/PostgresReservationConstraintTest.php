<?php

namespace Tests\Integration;

use App\Exceptions\FacilityReservationException;
use App\Services\Reservations\ReservationManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;
use Throwable;
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

        // This suite drops and recreates its own tables. Refuse every database
        // except the dedicated local lfms_test database before running any DDL.
        $connection = DB::connection();
        $config = $connection->getConfig();
        $host = $config['host'] ?? null;
        $url = $config['url'] ?? null;
        if (
            getenv('LFMS_ALLOW_DESTRUCTIVE_TESTS') !== 'true'
            || $connection->getDatabaseName() !== 'lfms_test'
            || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || (is_string($url) && trim($url) !== '')
        ) {
            self::fail('Refusing destructive PostgreSQL integration tests outside the local lfms_test database.');
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('DROP TABLE IF EXISTS reservation_approvals');
        DB::statement('DROP TABLE IF EXISTS activity_logs');
        DB::statement('DROP TABLE IF EXISTS reservations');
        DB::statement('DROP TABLE IF EXISTS facilities');
        DB::statement('DROP TABLE IF EXISTS profiles');
        DB::statement('CREATE TABLE profiles (id uuid PRIMARY KEY, full_name text NOT NULL)');
        DB::statement('CREATE TABLE facilities (
            id uuid PRIMARY KEY, name text NOT NULL, code text NOT NULL,
            status text NOT NULL, capacity integer NOT NULL
        )');
        DB::statement('CREATE TABLE reservations (
            id uuid PRIMARY KEY,
            facility_id uuid NOT NULL REFERENCES facilities(id),
            requester_id uuid NOT NULL REFERENCES profiles(id),
            start_at timestamptz NOT NULL,
            end_at timestamptz NOT NULL,
            purpose text NOT NULL,
            subject_course text NULL,
            participant_count integer NOT NULL,
            status text NOT NULL,
            cancellation_reason text NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT reservation_period_valid CHECK (end_at > start_at)
        )');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_no_approved_overlap
            EXCLUDE USING gist (
                facility_id WITH =,
                tstzrange(start_at, end_at, '[)') WITH &&
            ) WHERE (status = 'approved')");
        DB::statement('CREATE TABLE reservation_approvals (
            id uuid PRIMARY KEY, reservation_id uuid NOT NULL, reviewer_id uuid NOT NULL,
            decision text NOT NULL, remarks text NULL, reviewed_at timestamptz NOT NULL
        )');
        DB::statement('CREATE TABLE activity_logs (
            id uuid PRIMARY KEY, user_id uuid NULL, action text NOT NULL, module text NOT NULL,
            record_id uuid NULL, metadata jsonb NOT NULL, created_at timestamptz NOT NULL
        )');
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS reservation_approvals');
            DB::statement('DROP TABLE IF EXISTS activity_logs');
            DB::statement('DROP TABLE IF EXISTS reservations');
            DB::statement('DROP TABLE IF EXISTS facilities');
            DB::statement('DROP TABLE IF EXISTS profiles');
        }
        parent::tearDown();
    }

    public function test_database_rejects_overlapping_approved_reservations_for_same_facility(): void
    {
        $facilityId = (string)Str::uuid();
        $requesterId = $this->seedRequester();
        DB::table('facilities')->insert(['id' => $facilityId, 'name' => 'Test Lab', 'code' => 'TEST-LAB', 'status' => 'available', 'capacity' => 30]);
        $this->insertReservation((string)Str::uuid(), $facilityId, $requesterId, '2030-04-01 09:00:00+00', '2030-04-01 10:00:00+00', 'approved');

        try {
            $this->insertReservation((string)Str::uuid(), $facilityId, $requesterId, '2030-04-01 09:30:00+00', '2030-04-01 10:30:00+00', 'approved');
            self::fail('PostgreSQL should reject overlapping approved reservations.');
        } catch (QueryException $exception) {
            self::assertSame('23P01', $exception->errorInfo[0] ?? null);
        }
    }

    public function test_half_open_ranges_allow_adjacent_bookings_and_pending_requests_do_not_reserve_a_slot(): void
    {
        $facilityId = (string)Str::uuid();
        $otherFacilityId = (string)Str::uuid();
        $requesterId = $this->seedRequester();
        DB::table('facilities')->insert([
            ['id' => $facilityId, 'name' => 'Test Lab A', 'code' => 'TEST-A', 'status' => 'available', 'capacity' => 30],
            ['id' => $otherFacilityId, 'name' => 'Test Lab B', 'code' => 'TEST-B', 'status' => 'available', 'capacity' => 30],
        ]);

        $this->insertReservation((string)Str::uuid(), $facilityId, $requesterId, '2030-04-01 09:00:00+00', '2030-04-01 10:00:00+00', 'approved');
        $this->insertReservation((string)Str::uuid(), $facilityId, $requesterId, '2030-04-01 10:00:00+00', '2030-04-01 11:00:00+00', 'approved');
        $this->insertReservation((string)Str::uuid(), $facilityId, $requesterId, '2030-04-01 09:30:00+00', '2030-04-01 10:30:00+00', 'pending');
        $this->insertReservation((string)Str::uuid(), $otherFacilityId, $requesterId, '2030-04-01 09:30:00+00', '2030-04-01 10:30:00+00', 'approved');

        self::assertSame(4, DB::table('reservations')->count());
    }

    public function test_reservation_service_refuses_a_second_overlapping_approval(): void
    {
        $facilityId = (string)Str::uuid();
        $requesterId = (string)Str::uuid();
        $reviewerId = (string)Str::uuid();
        $firstId = (string)Str::uuid();
        $secondId = (string)Str::uuid();
        DB::table('profiles')->insert([
            ['id' => $requesterId, 'full_name' => 'Test Requester'],
            ['id' => $reviewerId, 'full_name' => 'Test Reviewer'],
        ]);
        DB::table('facilities')->insert([
            'id' => $facilityId, 'name' => 'Test Laboratory', 'code' => 'TEST-LAB',
            'status' => 'available', 'capacity' => 30,
        ]);
        $start = CarbonImmutable::now()->addDays(2)->startOfHour();
        $end = $start->addHour();
        foreach ([$firstId, $secondId] as $id) {
            DB::table('reservations')->insert([
                'id' => $id, 'facility_id' => $facilityId, 'requester_id' => $requesterId,
                'start_at' => $start, 'end_at' => $end, 'purpose' => 'Integration test',
                'subject_course' => null, 'participant_count' => 10, 'status' => 'pending',
            ]);
        }

        $service = app(ReservationManagementService::class);
        $first = $service->decide($reviewerId, $firstId, 'approved', 'Approved in integration test');
        self::assertSame('approved', $first['status']);

        try {
            $service->decide($reviewerId, $secondId, 'approved', 'Must conflict');
            self::fail('The second overlapping approval should be rejected.');
        } catch (FacilityReservationException $exception) {
            self::assertSame(409, $exception->httpStatus);
        }

        self::assertSame('pending', DB::table('reservations')->where('id', $secondId)->value('status'));
        self::assertSame(1, DB::table('reservation_approvals')->count());
    }


    public function test_postgres_exclusion_constraint_rejects_overlapping_concurrent_transactions(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The concurrent transaction test requires the pcntl extension.');
        }

        $facilityId = (string)Str::uuid();
        $requesterId = $this->seedRequester();
        DB::table('facilities')->insert(['id' => $facilityId, 'name' => 'Test Lab', 'code' => 'TEST-LAB', 'status' => 'available', 'capacity' => 30]);
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $childPid = pcntl_fork();
        if ($childPid === -1) self::fail('Could not fork a child process for the concurrency test.');

        if ($childPid === 0) {
            fclose($parentSocket);
            try {
                $pdo = $this->newPostgresConnection();
                $pdo->beginTransaction();
                $statement = $pdo->prepare('INSERT INTO reservations (id, facility_id, requester_id, start_at, end_at, purpose, participant_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $statement->execute([(string)Str::uuid(), $facilityId, $requesterId, '2030-04-01 09:00:00+00', '2030-04-01 10:00:00+00', 'Concurrent test', 10, 'approved']);
                fwrite($childSocket, "READY\n");
                usleep(400000);
                $pdo->commit();
                fwrite($childSocket, "COMMITTED\n");
                fclose($childSocket);
                exit(0);
            } catch (Throwable $exception) {
                fwrite($childSocket, 'ERROR: '.$exception->getMessage()."\n");
                fclose($childSocket);
                exit(1);
            }
        }

        fclose($childSocket);
        $ready = trim((string)fgets($parentSocket));
        self::assertSame('READY', $ready);
        $pdo = $this->newPostgresConnection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('INSERT INTO reservations (id, facility_id, requester_id, start_at, end_at, purpose, participant_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([(string)Str::uuid(), $facilityId, $requesterId, '2030-04-01 09:30:00+00', '2030-04-01 10:30:00+00', 'Concurrent test', 10, 'approved']);
            self::fail('The concurrent overlapping reservation should violate the exclusion constraint.');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            self::assertSame('23P01', $exception->errorInfo[0] ?? null);
        } finally {
            $committed = trim((string)fgets($parentSocket));
            fclose($parentSocket);
            pcntl_waitpid($childPid, $childStatus);
        }
        self::assertSame('COMMITTED', $committed);
        self::assertSame(0, pcntl_wexitstatus($childStatus));
    }


    private function seedRequester(): string
    {
        $id = (string)Str::uuid();
        DB::table('profiles')->insert(['id' => $id, 'full_name' => 'Test Requester']);
        return $id;
    }

    private function insertReservation(string $id, string $facilityId, string $requesterId, string $start, string $end, string $status): void
    {
        DB::table('reservations')->insert([
            'id' => $id, 'facility_id' => $facilityId, 'requester_id' => $requesterId,
            'start_at' => $start, 'end_at' => $end, 'purpose' => 'Constraint test',
            'subject_course' => null, 'participant_count' => 10, 'status' => $status,
            'cancellation_reason' => null,
        ]);
    }

    private function newPostgresConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        return new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

}
