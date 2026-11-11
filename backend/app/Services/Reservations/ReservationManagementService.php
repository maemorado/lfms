<?php

namespace App\Services\Reservations;

use App\Exceptions\FacilityReservationException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReservationManagementService
{
    public function list(array $filters, string $actorId, bool $isAdmin): LengthAwarePaginator
    {
        $query = $this->query();
        if (!$isAdmin) $query->where('r.requester_id', $actorId);
        if (!empty($filters['status'])) $query->where('r.status', $filters['status']);
        if (!empty($filters['facility_id'])) $query->where('r.facility_id', $filters['facility_id']);
        if ($isAdmin && !empty($filters['requester_id'])) $query->where('r.requester_id', $filters['requester_id']);
        if (!empty($filters['from_date'])) $query->where('r.start_at', '>=', CarbonImmutable::parse($filters['from_date'])->startOfDay());
        if (!empty($filters['to_date'])) $query->where('r.start_at', '<=', CarbonImmutable::parse($filters['to_date'])->endOfDay());
        if (!empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(fn (Builder $q) => $q->where('r.purpose', 'ilike', $term)
                ->orWhere('f.name', 'ilike', $term)
                ->orWhere('p.full_name', 'ilike', $term));
        }
        return $query->orderByDesc('r.start_at')
            ->paginate((int)($filters['per_page'] ?? 20), ['*'], 'page', (int)($filters['page'] ?? 1))
            ->through(fn ($row) => $this->normalize($row));
    }

    public function find(string $id, string $actorId, bool $isAdmin): array
    {
        $query = $this->query()->where('r.id', $id);
        if (!$isAdmin) $query->where('r.requester_id', $actorId);
        $row = $query->first();
        if (!$row) throw new FacilityReservationException('Reservation not found.', 404);
        return $this->normalize($row, true);
    }

    public function create(string $actorId, array $data): array
    {
        $start = $this->date($data['start_at']);
        $end = $this->date($data['end_at']);
        $this->assertPeriod($start, $end);
        $id = DB::transaction(function () use ($actorId, $data, $start, $end): string {
            $this->setActor($actorId);
            $facility = DB::table('facilities')->where('id', $data['facility_id'])->lockForUpdate()->first();
            $this->assertFacilityCanHost($facility, (int)($data['participant_count'] ?? 1));
            if ($this->hasApprovedConflict($data['facility_id'], $start, $end)) {
                throw new FacilityReservationException('The selected facility already has an approved reservation during that time.', 409);
            }
            $id = (string)Str::uuid();
            DB::table('reservations')->insert([
                'id' => $id, 'facility_id' => $data['facility_id'], 'requester_id' => $actorId,
                'start_at' => $start, 'end_at' => $end, 'purpose' => trim($data['purpose']),
                'subject_course' => $data['subject_course'] ?? null,
                'participant_count' => (int)($data['participant_count'] ?? 1),
                'status' => 'pending', 'cancellation_reason' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity($actorId, $id, 'reservation.submitted', ['facility_id' => $data['facility_id'], 'start_at' => $start->toIso8601String(), 'end_at' => $end->toIso8601String()]);
            return $id;
        }, 3);
        return $this->find($id, $actorId, false);
    }

    public function update(string $actorId, string $id, array $data, bool $isAdmin): array
    {
        $this->runWithConflictMapping(function () use ($actorId, $id, $data, $isAdmin): void {
            DB::transaction(function () use ($actorId, $id, $data, $isAdmin): void {
                $this->setActor($actorId);
                $reservation = DB::table('reservations')->where('id', $id)->lockForUpdate()->first();
                if (!$reservation) throw new FacilityReservationException('Reservation not found.', 404);
                if (!$isAdmin && $reservation->requester_id !== $actorId) throw new FacilityReservationException('You may only modify your own reservations.', 403);
                if ($reservation->status !== 'pending') throw new FacilityReservationException('Only pending reservations can be edited.', 409);
                $facilityId = $data['facility_id'] ?? $reservation->facility_id;
                $start = $this->date($data['start_at'] ?? $reservation->start_at);
                $end = $this->date($data['end_at'] ?? $reservation->end_at);
                $this->assertPeriod($start, $end);
                $facility = DB::table('facilities')->where('id', $facilityId)->lockForUpdate()->first();
                $count = (int)($data['participant_count'] ?? $reservation->participant_count);
                $this->assertFacilityCanHost($facility, $count);
                if ($this->hasApprovedConflict($facilityId, $start, $end, $id)) {
                    throw new FacilityReservationException('The selected facility already has an approved reservation during that time.', 409);
                }
                $updates = [
                    'facility_id' => $facilityId, 'start_at' => $start, 'end_at' => $end,
                    'participant_count' => $count, 'updated_at' => now(),
                ];
                foreach (['purpose','subject_course'] as $field) {
                    if (array_key_exists($field, $data)) $updates[$field] = $field === 'purpose' ? trim($data[$field]) : $data[$field];
                }
                DB::table('reservations')->where('id', $id)->update($updates);
                $this->activity($actorId, $id, 'reservation.updated', ['fields' => array_keys($updates)]);
            }, 3);
        });
        return $this->find($id, $actorId, $isAdmin);
    }

    public function decide(string $actorId, string $id, string $decision, ?string $remarks): array
    {
        $this->runWithConflictMapping(function () use ($actorId, $id, $decision, $remarks): void {
            DB::transaction(function () use ($actorId, $id, $decision, $remarks): void {
                $this->setActor($actorId);
                $reservation = DB::table('reservations')->where('id', $id)->lockForUpdate()->first();
                if (!$reservation) throw new FacilityReservationException('Reservation not found.', 404);
                if ($reservation->status !== 'pending') throw new FacilityReservationException('Only pending reservations can be approved or rejected.', 409);
                if ($decision === 'approved') {
                    $facility = DB::table('facilities')->where('id', $reservation->facility_id)->lockForUpdate()->first();
                    $this->assertFacilityCanHost($facility, (int)$reservation->participant_count);
                    $start = $this->date($reservation->start_at);
                    $end = $this->date($reservation->end_at);
                    $this->assertPeriod($start, $end);
                    if ($this->hasApprovedConflict($reservation->facility_id, $start, $end, $id)) {
                        throw new FacilityReservationException('Another approved reservation conflicts with this request.', 409);
                    }
                }
                DB::table('reservations')->where('id', $id)->update(['status' => $decision, 'updated_at' => now()]);
                DB::table('reservation_approvals')->insert([
                    'id' => (string)Str::uuid(), 'reservation_id' => $id, 'reviewer_id' => $actorId,
                    'decision' => $decision, 'remarks' => $remarks, 'reviewed_at' => now(),
                ]);
                $this->activity($actorId, $id, 'reservation.'.$decision, ['remarks' => $remarks]);
            }, 3);
        });
        return $this->find($id, $actorId, true);
    }

    public function cancel(string $actorId, string $id, string $reason, bool $isAdmin): array
    {
        DB::transaction(function () use ($actorId, $id, $reason, $isAdmin): void {
            $this->setActor($actorId);
            $reservation = DB::table('reservations')->where('id', $id)->lockForUpdate()->first();
            if (!$reservation) throw new FacilityReservationException('Reservation not found.', 404);
            if (!$isAdmin && $reservation->requester_id !== $actorId) throw new FacilityReservationException('You may only cancel your own reservations.', 403);
            if (!in_array($reservation->status, ['pending','approved'], true)) throw new FacilityReservationException('This reservation can no longer be cancelled.', 409);
            if ($this->date($reservation->start_at)->lessThanOrEqualTo(now())) throw new FacilityReservationException('A reservation cannot be cancelled after its start time.', 409);
            DB::table('reservations')->where('id', $id)->update([
                'status' => 'cancelled', 'cancellation_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $this->activity($actorId, $id, 'reservation.cancelled', ['reason' => trim($reason)]);
        }, 3);
        return $this->find($id, $actorId, $isAdmin);
    }

    private function query(): Builder
    {
        return DB::table('reservations as r')
            ->join('facilities as f', 'f.id', '=', 'r.facility_id')
            ->join('profiles as p', 'p.id', '=', 'r.requester_id')
            ->select('r.*', 'f.name as facility_name', 'f.code as facility_code', 'f.status as facility_status', 'p.full_name as requester_name');
    }

    private function normalize(object $row, bool $includeApprovals = false): array
    {
        $result = (array)$row;
        $result['facility'] = ['id' => $result['facility_id'], 'name' => $result['facility_name'], 'code' => $result['facility_code'], 'status' => $result['facility_status']];
        $result['requester'] = ['id' => $result['requester_id'], 'full_name' => $result['requester_name']];
        if ($includeApprovals) {
            $result['approvals'] = DB::table('reservation_approvals as a')->join('profiles as p', 'p.id', '=', 'a.reviewer_id')
                ->where('a.reservation_id', $result['id'])->orderByDesc('a.reviewed_at')
                ->get(['a.decision','a.remarks','a.reviewed_at','p.full_name as reviewer_name'])->all();
        }
        unset($result['facility_name'], $result['facility_code'], $result['facility_status'], $result['requester_name']);
        return $result;
    }

    private function assertFacilityCanHost(?object $facility, int $participantCount): void
    {
        if (!$facility) throw new FacilityReservationException('Facility not found.', 404);
        if ($facility->status !== 'available') throw new FacilityReservationException('This facility is not currently available for reservations.', 409);
        if ($participantCount > (int)$facility->capacity) throw new FacilityReservationException('Participant count exceeds this facility capacity.', 422);
    }

    private function assertPeriod(CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($end->lessThanOrEqualTo($start)) throw new FacilityReservationException('Reservation end time must be after its start time.', 422);
        if ($start->lessThanOrEqualTo(now())) throw new FacilityReservationException('Reservation start time must be in the future.', 422);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value);
    }

    private function hasApprovedConflict(string $facilityId, CarbonImmutable $start, CarbonImmutable $end, ?string $excludeId = null): bool
    {
        $query = DB::table('reservations')->where('facility_id', $facilityId)->where('status', 'approved')
            ->where('start_at', '<', $end)->where('end_at', '>', $start);
        if ($excludeId) $query->where('id', '<>', $excludeId);
        return $query->exists();
    }

    private function setActor(string $actorId): void
    {
        // This ID comes from the Supabase Auth-verified request context.
        DB::statement("select set_config('app.current_user_id', ?, true)", [$actorId]);
    }

    private function activity(string $actorId, string $recordId, string $action, array $metadata): void
    {
        DB::table('activity_logs')->insert([
            'id' => (string)Str::uuid(), 'user_id' => $actorId, 'action' => $action,
            'module' => 'reservations', 'record_id' => $recordId,
            'metadata' => json_encode($metadata), 'created_at' => now(),
        ]);
    }

    private function runWithConflictMapping(callable $callback): void
    {
        try { $callback(); }
        catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? (string)$exception->getCode();
            if ($state === '23P01') throw new FacilityReservationException('The reservation conflicts with another approved booking.', 409);
            throw $exception;
        }
    }
}
