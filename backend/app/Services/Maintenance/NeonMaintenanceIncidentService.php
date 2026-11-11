<?php

namespace App\Services\Maintenance;

use App\Exceptions\MaintenanceIncidentException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class NeonMaintenanceIncidentService
{
    public function targets(): array
    {
        $facilities = DB::table('facilities')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $assets = DB::table('equipment_assets as a')
            ->leftJoin('inventory_items as i', 'i.id', '=', 'a.item_id')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'a.location_id')
            ->orderBy('a.asset_tag')
            ->get([
                'a.id', 'a.asset_tag', 'a.condition', 'a.status', 'a.location_id',
                'i.id as item_ref_id', 'i.name as item_name', 'i.item_code',
                'l.id as location_ref_id', 'l.name as location_name', 'l.facility_id',
            ])
            ->map(function (object $row): array {
                return [
                    'id' => $row->id,
                    'asset_tag' => $row->asset_tag,
                    'condition' => $row->condition,
                    'status' => $row->status,
                    'location_id' => $row->location_id,
                    'inventory_items' => [
                        'id' => $row->item_ref_id,
                        'name' => $row->item_name,
                        'item_code' => $row->item_code,
                    ],
                    'inventory_locations' => $row->location_ref_id === null ? null : [
                        'id' => $row->location_ref_id,
                        'name' => $row->location_name,
                        'facility_id' => $row->facility_id,
                    ],
                ];
            })
            ->all();

        return ['facilities' => $facilities, 'assets' => $assets];
    }

    public function blockedEquipmentAssetIds(): array
    {
        $incidentIds = DB::table('incident_reports')
            ->whereNotNull('asset_id')
            ->whereIn('incident_type', ['damage', 'missing'])
            ->whereIn('status', ['open', 'under_review'])
            ->pluck('asset_id');

        $maintenanceIds = DB::table('maintenance_requests')
            ->whereNotNull('asset_id')
            ->whereIn('status', ['approved', 'in_progress', 'on_hold'])
            ->pluck('asset_id');

        return $incidentIds->merge($maintenanceIds)->filter()->unique()->values()->all();
    }

    public function maintenanceRequests(array $options): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 20)));
        $query = $this->maintenanceQuery();

        if (!empty($options['status'])) $query->where('mr.status', $options['status']);
        if (!empty($options['priority'])) $query->where('mr.priority', $options['priority']);
        if (!empty($options['facility_id'])) $query->where('mr.facility_id', $options['facility_id']);
        if (!empty($options['asset_id'])) $query->where('mr.asset_id', $options['asset_id']);
        if (!empty($options['search'])) {
            $query->where('mr.description', 'ilike', '%'.$this->escapeLike((string) $options['search']).'%');
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('mr.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'requests' => $rows->map(fn (object $row): array => $this->decodeNested($row, ['facilities', 'equipment_assets', 'profiles', 'maintenance_records']))->all(),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    public function maintenanceDetail(string $id): array
    {
        $this->uuid($id);
        $row = $this->maintenanceQuery()->where('mr.id', $id)->first();
        if (!$row) throw new MaintenanceIncidentException('Maintenance request not found.', 404);

        return $this->decodeNested($row, ['facilities', 'equipment_assets', 'profiles', 'maintenance_records']);
    }

    public function createMaintenanceRequest(array $data): array
    {
        $id = (string) Str::uuid();
        $actorId = $this->actorId();

        try {
            DB::transaction(function () use ($id, $actorId, $data): void {
                DB::table('maintenance_requests')->insert([
                    'id' => $id,
                    'reported_by' => $actorId,
                    'facility_id' => $data['facility_id'] ?? null,
                    'asset_id' => $data['asset_id'] ?? null,
                    'description' => trim($data['description']),
                    'priority' => $data['priority'] ?? 'medium',
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->maintenanceDetail($id);
    }

    public function updateMaintenanceStatus(string $id, string $status): array
    {
        $current = $this->maintenanceDetail($id);
        $allowed = [
            'pending' => ['approved', 'cancelled'],
            'approved' => ['in_progress', 'on_hold', 'cancelled'],
            'in_progress' => ['on_hold', 'completed'],
            'on_hold' => ['in_progress', 'completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];
        if (!in_array($status, $allowed[$current['status']] ?? [], true)) {
            throw new MaintenanceIncidentException('That maintenance status transition is not allowed.', 409);
        }

        try {
            DB::table('maintenance_requests')
                ->where('id', $id)
                ->update(['status' => $status, 'updated_at' => now()]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->maintenanceDetail($id);
    }

    public function addMaintenanceRecord(string $requestId, array $data): array
    {
        $this->maintenanceDetail($requestId);
        $id = (string) Str::uuid();

        try {
            DB::table('maintenance_records')->insert([
                'id' => $id,
                'request_id' => $requestId,
                'assigned_to' => $data['assigned_to'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'completed_at' => $data['completed_at'] ?? null,
                'cost' => $data['cost'] ?? 0,
                'remarks' => $data['remarks'] ?? null,
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        $row = DB::table('maintenance_records')->where('id', $id)->first();
        if (!$row) throw new MaintenanceIncidentException('Maintenance activity was not recorded.', 502);
        return (array) $row;
    }

    public function incidents(array $options): array
    {
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 20)));
        $query = $this->incidentQuery();

        if (!empty($options['status'])) $query->where('ir.status', $options['status']);
        if (!empty($options['incident_type'])) $query->where('ir.incident_type', $options['incident_type']);
        if (!empty($options['search'])) {
            $query->where('ir.description', 'ilike', '%'.$this->escapeLike((string) $options['search']).'%');
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('ir.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'incidents' => $rows->map(fn (object $row): array => $this->decodeNested($row, ['facilities', 'equipment_assets', 'profiles', 'incident_attachments']))->all(),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    public function incidentDetail(string $id): array
    {
        $this->uuid($id);
        $row = $this->incidentQuery()->where('ir.id', $id)->first();
        if (!$row) throw new MaintenanceIncidentException('Incident report not found.', 404);

        return $this->decodeNested($row, ['facilities', 'equipment_assets', 'profiles', 'incident_attachments']);
    }

    public function createIncident(array $data): array
    {
        $id = (string) Str::uuid();
        $actorId = $this->actorId();

        try {
            DB::table('incident_reports')->insert([
                'id' => $id,
                'reported_by' => $actorId,
                'facility_id' => $data['facility_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'incident_type' => $data['incident_type'],
                'description' => trim($data['description']),
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->incidentDetail($id);
    }

    public function updateIncident(string $id, array $data): array
    {
        $current = $this->incidentDetail($id);
        $status = $data['status'] ?? $current['status'];
        $allowed = [
            'open' => ['under_review', 'dismissed'],
            'under_review' => ['open', 'resolved', 'dismissed'],
            'resolved' => ['open', 'closed'],
            'closed' => [],
            'dismissed' => [],
        ];
        if (isset($data['status']) && $status !== $current['status'] && !in_array($status, $allowed[$current['status']] ?? [], true)) {
            throw new MaintenanceIncidentException('That incident status transition is not allowed.', 409);
        }

        $updates = [];
        if (array_key_exists('status', $data)) $updates['status'] = $status;
        if (array_key_exists('resolution', $data)) $updates['resolution'] = $data['resolution'];
        if ($status === 'resolved' && $current['status'] !== 'resolved') {
            $updates['resolved_by'] = $this->actorId();
            $updates['resolved_at'] = now();
        } elseif ($status === 'open' && $current['status'] === 'resolved') {
            $updates['resolved_by'] = null;
            $updates['resolved_at'] = null;
        }
        if ($updates === []) throw new MaintenanceIncidentException('At least one incident field is required.', 422);
        $updates['updated_at'] = now();

        try {
            DB::table('incident_reports')->where('id', $id)->update($updates);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->incidentDetail($id);
    }

    public function addIncidentAttachment(string $incidentId, array $data): array
    {
        $this->incidentDetail($incidentId);
        $prefix = 'incidents/'.$incidentId.'/';
        if (!str_starts_with($data['file_path'], $prefix) || str_contains($data['file_path'], '..')) {
            throw new MaintenanceIncidentException('The evidence path does not belong to this incident.', 422);
        }

        $id = (string) Str::uuid();
        try {
            DB::table('incident_attachments')->insert([
                'id' => $id,
                'incident_id' => $incidentId,
                'file_path' => $data['file_path'],
                'file_name' => $data['file_name'],
                'uploaded_by' => $this->actorId(),
                'uploaded_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        $row = DB::table('incident_attachments')->where('id', $id)->first();
        if (!$row) throw new MaintenanceIncidentException('Incident attachment metadata was not saved.', 502);
        return (array) $row;
    }

    private function maintenanceQuery(): Builder
    {
        return DB::table('maintenance_requests as mr')
            ->leftJoin('facilities as f', 'f.id', '=', 'mr.facility_id')
            ->leftJoin('equipment_assets as a', 'a.id', '=', 'mr.asset_id')
            ->leftJoin('inventory_items as i', 'i.id', '=', 'a.item_id')
            ->leftJoin('profiles as reporter', 'reporter.id', '=', 'mr.reported_by')
            ->select('mr.*')
            ->selectRaw("CASE WHEN f.id IS NULL THEN NULL ELSE jsonb_build_object('id',f.id,'name',f.name,'code',f.code) END AS facilities")
            ->selectRaw("CASE WHEN a.id IS NULL THEN NULL ELSE jsonb_build_object(
                'id',a.id,'asset_tag',a.asset_tag,'condition',a.condition,'status',a.status,
                'inventory_items',CASE WHEN i.id IS NULL THEN NULL ELSE jsonb_build_object('id',i.id,'name',i.name,'item_code',i.item_code) END
            ) END AS equipment_assets")
            ->selectRaw("CASE WHEN reporter.id IS NULL THEN NULL ELSE jsonb_build_object('id',reporter.id,'full_name',reporter.full_name,'email',reporter.email) END AS profiles")
            ->selectRaw('(' . $this->maintenanceRecordsSubquery() . ') AS maintenance_records');
    }

    private function maintenanceRecordsSubquery(): string
    {
        return "SELECT coalesce(jsonb_agg(jsonb_build_object(
            'id', mr2.id, 'assigned_to', mr2.assigned_to, 'started_at', mr2.started_at,
            'completed_at', mr2.completed_at, 'cost', mr2.cost, 'remarks', mr2.remarks,
            'profiles', CASE WHEN assignee.id IS NULL THEN NULL ELSE jsonb_build_object('id',assignee.id,'full_name',assignee.full_name,'email',assignee.email) END
        ) ORDER BY mr2.created_at), '[]'::jsonb)
        FROM public.maintenance_records mr2
        LEFT JOIN public.profiles assignee ON assignee.id = mr2.assigned_to
        WHERE mr2.request_id = mr.id";
    }

    private function incidentQuery(): Builder
    {
        return DB::table('incident_reports as ir')
            ->leftJoin('facilities as f', 'f.id', '=', 'ir.facility_id')
            ->leftJoin('equipment_assets as a', 'a.id', '=', 'ir.asset_id')
            ->leftJoin('inventory_items as i', 'i.id', '=', 'a.item_id')
            ->leftJoin('profiles as reporter', 'reporter.id', '=', 'ir.reported_by')
            ->select('ir.*')
            ->selectRaw("CASE WHEN f.id IS NULL THEN NULL ELSE jsonb_build_object('id',f.id,'name',f.name,'code',f.code) END AS facilities")
            ->selectRaw("CASE WHEN a.id IS NULL THEN NULL ELSE jsonb_build_object(
                'id',a.id,'asset_tag',a.asset_tag,'condition',a.condition,'status',a.status,
                'inventory_items',jsonb_build_object('id',i.id,'name',i.name,'item_code',i.item_code)
            ) END AS equipment_assets")
            ->selectRaw("jsonb_build_object('id',reporter.id,'full_name',reporter.full_name,'email',reporter.email) AS profiles")
            ->selectRaw('(' . $this->incidentAttachmentsSubquery() . ') AS incident_attachments');
    }

    private function incidentAttachmentsSubquery(): string
    {
        return "SELECT coalesce(jsonb_agg(jsonb_build_object(
            'id', ia.id, 'file_path', ia.file_path, 'file_name', ia.file_name,
            'uploaded_by', ia.uploaded_by, 'uploaded_at', ia.uploaded_at
        ) ORDER BY ia.uploaded_at), '[]'::jsonb)
        FROM public.incident_attachments ia
        WHERE ia.incident_id = ir.id";
    }

    private function actorId(): string
    {
        $row = DB::selectOne('select private.current_user_id()::text as user_id');
        $userId = $row->user_id ?? null;
        if (!is_string($userId) || !Str::isUuid($userId)) {
            throw new MaintenanceIncidentException('A verified LFMS identity is required for this operation.', 401);
        }
        return $userId;
    }

    private function decodeNested(object $row, array $fields): array
    {
        $result = (array) $row;
        foreach ($fields as $field) {
            $value = $result[$field] ?? null;
            if (is_string($value)) {
                try {
                    $result[$field] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $result[$field] = null;
                }
            }
        }
        return $result;
    }

    private function pagination(int $total, int $page, int $perPage): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    private function uuid(string $id): void
    {
        if (!Str::isUuid($id)) throw new MaintenanceIncidentException('The supplied record identifier is invalid.', 422);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function translateWriteFailure(QueryException $exception): never
    {
        $state = $exception->errorInfo[0] ?? (string) $exception->getCode();
        $message = match ($state) {
            '23505' => 'This maintenance or incident record conflicts with an existing record.',
            '23503' => 'The selected facility, equipment, or assigned user is invalid.',
            '23514', '22023' => 'The submitted value is not supported by the LFMS data model.',
            '42501' => 'Your account is not authorized to change this record.',
            default => 'The maintenance and incident service is temporarily unavailable.',
        };
        $status = match ($state) {
            '23505' => 409,
            '23503', '23514', '22023' => 422,
            '42501' => 403,
            default => 502,
        };

        throw new MaintenanceIncidentException($message, $status);
    }
}
