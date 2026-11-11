<?php

namespace App\Services\Facilities;

use App\Services\Security\NeonIdentityContext;use App\Exceptions\FacilityReservationException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FacilityManagementService
{
    public function __construct(private readonly NeonIdentityContext $identity) {}
    public function list(array $filters): LengthAwarePaginator
    {
        $query = $this->query();
        if (!empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(fn (Builder $q) => $q->where('f.name', 'ilike', $term)
                ->orWhere('f.code', 'ilike', $term)->orWhere('b.name', 'ilike', $term));
        }
        foreach (['status' => 'f.status', 'building_id' => 'f.building_id', 'facility_type_id' => 'f.facility_type_id'] as $key => $column) {
            if (!empty($filters[$key])) $query->where($column, $filters[$key]);
        }
        $sort = in_array($filters['sort'] ?? '', ['name','code','capacity','created_at'], true) ? $filters['sort'] : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        return $query->orderBy('f.'.$sort, $direction)
            ->paginate((int)($filters['per_page'] ?? 20), ['*'], 'page', (int)($filters['page'] ?? 1))
            ->through(fn ($row) => $this->normalize($row));
    }

    public function find(string $id): array
    {
        $row = $this->query()->where('f.id', $id)->first();
        if (!$row) throw new FacilityReservationException('Facility not found.', 404);
        return $this->normalize($row, true);
    }

    public function lookups(): array
    {
        return [
            'buildings' => DB::table('buildings')->orderBy('name')->get(['id','name','location']),
            'facility_types' => DB::table('facility_types')->where('is_active', true)->orderBy('name')->get(['id','name','description']),
            'features' => DB::table('facility_features')->orderBy('name')->get(['id','name','description']),
            'statuses' => ['available','unavailable','maintenance','inactive'],
        ];
    }

    public function availability(string $id, string $startAt, string $endAt): array
    {
        $start = CarbonImmutable::parse($startAt);
        $end = CarbonImmutable::parse($endAt);
        if ($end->lessThanOrEqualTo($start) || $start->lessThanOrEqualTo(now())) {
            throw new FacilityReservationException('Choose a future start time and an end time after it.', 422);
        }
        $facility = DB::table('facilities')->where('id', $id)->first(['id','name','status','capacity']);
        if (!$facility) throw new FacilityReservationException('Facility not found.', 404);
        $conflict = DB::table('reservations')->where('facility_id', $id)->where('status', 'approved')
            ->where('start_at', '<', $end)->where('end_at', '>', $start)->exists();
        return [
            'facility_id' => $id, 'facility_status' => $facility->status,
            'is_available' => $facility->status === 'available' && !$conflict,
            'has_approved_conflict' => $conflict, 'capacity' => (int)$facility->capacity,
            'checked_start_at' => $start->toIso8601String(), 'checked_end_at' => $end->toIso8601String(),
        ];
    }

    public function create(string $actorId, array $data): array
    {
        return DB::transaction(function () use ($actorId, $data): array {
            $this->setActor($actorId);
            $this->assertReferences($data['building_id'], $data['facility_type_id']);
            $id = (string) Str::uuid();
            DB::table('facilities')->insert([
                'id' => $id, 'building_id' => $data['building_id'], 'facility_type_id' => $data['facility_type_id'],
                'name' => trim($data['name']), 'code' => trim($data['code']),
                'capacity' => $data['capacity'] ?? 1, 'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'available', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity($actorId, $id, 'facility.created', ['name' => $data['name'], 'code' => $data['code']]);
            return $this->find($id);
        });
    }

    public function update(string $actorId, string $id, array $data): array
    {
        return DB::transaction(function () use ($actorId, $id, $data): array {
            $this->setActor($actorId);
            $facility = DB::table('facilities')->where('id', $id)->lockForUpdate()->first();
            if (!$facility) throw new FacilityReservationException('Facility not found.', 404);
            $buildingId = $data['building_id'] ?? $facility->building_id;
            $typeId = $data['facility_type_id'] ?? $facility->facility_type_id;
            $this->assertReferences($buildingId, $typeId, $typeId !== $facility->facility_type_id);

            $nextStatus = $data['status'] ?? $facility->status;
            $hasFutureApproved = DB::table('reservations')->where('facility_id', $id)
                ->where('status', 'approved')->where('end_at', '>', now())->exists();
            if ($nextStatus !== 'available' && $hasFutureApproved) {
                throw new FacilityReservationException('This facility has approved upcoming reservations. Resolve those reservations before changing its availability.', 409);
            }
            $nextCapacity = (int)($data['capacity'] ?? $facility->capacity);
            $oversizedBooking = DB::table('reservations')->where('facility_id', $id)->where('status', 'approved')
                ->where('end_at', '>', now())->where('participant_count', '>', $nextCapacity)->exists();
            if ($oversizedBooking) throw new FacilityReservationException('Capacity cannot be reduced below an upcoming approved reservation participant count.', 409);

            $updates = [];
            foreach (['name','code','capacity','description','status'] as $field) {
                if (array_key_exists($field, $data)) $updates[$field] = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            }
            if (array_key_exists('building_id', $data)) $updates['building_id'] = $buildingId;
            if (array_key_exists('facility_type_id', $data)) $updates['facility_type_id'] = $typeId;
            if ($updates) {
                $updates['updated_at'] = now();
                DB::table('facilities')->where('id', $id)->update($updates);
                $this->activity($actorId, $id, 'facility.updated', ['fields' => array_keys($updates)]);
            }
            return $this->find($id);
        });
    }

    private function query(): Builder
    {
        return DB::table('facilities as f')
            ->join('buildings as b', 'b.id', '=', 'f.building_id')
            ->join('facility_types as t', 't.id', '=', 'f.facility_type_id')
            ->select('f.*', 'b.name as building_name', 'b.location as building_location', 't.name as facility_type_name');
    }

    private function normalize(object $row, bool $includeFeatures = false): array
    {
        $result = (array)$row;
        $result['building'] = ['id' => $result['building_id'], 'name' => $result['building_name'], 'location' => $result['building_location']];
        $result['facility_type'] = ['id' => $result['facility_type_id'], 'name' => $result['facility_type_name']];
        if ($includeFeatures) {
            $result['features'] = DB::table('facility_feature_assignments as a')
                ->join('facility_features as ff', 'ff.id', '=', 'a.feature_id')
                ->where('a.facility_id', $result['id'])->orderBy('ff.name')
                ->get(['ff.id','ff.name','ff.description'])->all();
        }
        unset($result['building_name'], $result['building_location'], $result['facility_type_name']);
        return $result;
    }

    private function assertReferences(string $buildingId, string $typeId, bool $requireActiveType = true): void
    {
        if (!DB::table('buildings')->where('id', $buildingId)->exists()) throw new FacilityReservationException('Selected building does not exist.', 422);
        $typeQuery = DB::table('facility_types')->where('id', $typeId);
        if ($requireActiveType) $typeQuery->where('is_active', true);
        if (!$typeQuery->exists()) throw new FacilityReservationException('Selected facility type is inactive or does not exist.', 422);
    }

    private function setActor(string $actorId): void
    {
        $this->identity->set($actorId);
    }

    private function activity(string $actorId, string $recordId, string $action, array $metadata): void
    {
        DB::table('activity_logs')->insert([
            'id' => (string)Str::uuid(), 'user_id' => $actorId, 'action' => $action,
            'module' => 'facility_management', 'record_id' => $recordId,
            'metadata' => json_encode($metadata), 'created_at' => now(),
        ]);
    }
}
