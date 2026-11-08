<?php

namespace App\Services\Maintenance;

use App\Exceptions\MaintenanceIncidentException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SupabaseMaintenanceIncidentService
{
    private function client(string $token)
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $key = (string) config('supabase.publishable_key');
        if ($url === '' || $key === '' || $token === '') {
            throw new MaintenanceIncidentException('Maintenance and incident services are not configured for this authenticated session.', 503);
        }

        return Http::acceptJson()
            ->withHeaders(['apikey' => $key, 'Authorization' => 'Bearer '.$token])
            ->timeout((int) config('supabase.auth_user_timeout', 10));
    }

    private function request(string $token, string $method, string $path, array $query = [], ?array $body = null, string $prefer = 'return=representation'): array
    {
        $url = rtrim((string) config('supabase.url'), '/').'/rest/v1/'.$path;
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $client = $this->client($token)->withHeaders(['Prefer' => $prefer]);
        $response = match (strtoupper($method)) {
            'GET' => $client->get($url),
            'POST' => $client->post($url, $body ?? []),
            'PATCH' => $client->patch($url, $body ?? []),
            default => throw new MaintenanceIncidentException('Unsupported maintenance or incident operation.', 405),
        };

        if (!$response->successful()) {
            $status = $response->status();
            $bodyText = strtolower($response->body());
            $message = match (true) {
                $status === 401 => 'Your session is no longer valid. Sign in again.',
                $status === 403 => 'Your account is not authorized to access this record.',
                $status === 404 => 'The requested record was not found.',
                $status === 409 => 'This record changed and conflicts with the requested operation.',
                str_contains($bodyText, 'foreign key') => 'The selected facility, equipment, or assigned user is invalid.',
                str_contains($bodyText, 'check constraint') => 'The submitted value is not supported by the existing LFMS data model.',
                $status >= 500 => 'The maintenance and incident service is temporarily unavailable.',
                default => 'The requested maintenance or incident operation could not be completed.',
            };
            throw new MaintenanceIncidentException($message, in_array($status, [401,403,404,409,422], true) ? $status : ($status === 400 ? 422 : 502));
        }

        return ['data' => $response->json() ?? [], 'range' => $response->header('Content-Range')];
    }

    private function pagination(?string $range, int $page, int $perPage): array
    {
        $total = 0;
        if (is_string($range) && str_contains($range, '/')) {
            $raw = substr($range, strrpos($range, '/') + 1);
            if (ctype_digit($raw)) $total = (int) $raw;
        }

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

    private function one(array $rows, string $message): array
    {
        $row = $rows[0] ?? null;
        if (!is_array($row)) throw new MaintenanceIncidentException($message, 404);
        return $row;
    }

    public function targets(string $token): array
    {
        $facilities = $this->request($token, 'GET', 'facilities', [
            'select' => 'id,name,code',
            'order' => 'name.asc',
            'limit' => '500',
        ])['data'];

        $assets = $this->request($token, 'GET', 'equipment_assets', [
            'select' => 'id,asset_tag,condition,status,location_id,inventory_items!equipment_assets_item_id_fkey(id,name,item_code),inventory_locations(id,name,facility_id)',
            'order' => 'asset_tag.asc',
            'limit' => '1000',
        ])['data'];

        return ['facilities' => $facilities, 'assets' => $assets];
    }

    public function maintenanceRequests(string $token, array $options): array
    {
        $page = $options['page'];
        $perPage = $options['per_page'];
        $query = [
            'select' => '*,facilities(id,name,code),equipment_assets(id,asset_tag,condition,status,inventory_items!equipment_assets_item_id_fkey(id,name,item_code)),profiles!maintenance_requests_reported_by_fkey(id,full_name,email),maintenance_records(id,assigned_to,started_at,completed_at,cost,remarks,profiles!maintenance_records_assigned_to_fkey(id,full_name,email))',
            'order' => 'created_at.desc',
            'limit' => (string) $perPage,
            'offset' => (string) (($page - 1) * $perPage),
        ];
        if ($options['status']) $query['status'] = 'eq.'.$options['status'];
        if ($options['priority']) $query['priority'] = 'eq.'.$options['priority'];
        if ($options['search']) $query['description'] = 'ilike.*'.str_replace(['%','_'], ['\\\\%','\\\\_'], $options['search']).'*';

        $result = $this->request($token, 'GET', 'maintenance_requests', $query, null, 'count=exact');
        return [
            'requests' => is_array($result['data']) ? $result['data'] : [],
            'pagination' => $this->pagination($result['range'] ?? null, $page, $perPage),
        ];
    }

    public function maintenanceDetail(string $token, string $id): array
    {
        $this->uuid($id);
        $rows = $this->request($token, 'GET', 'maintenance_requests', [
            'select' => '*,facilities(id,name,code),equipment_assets(id,asset_tag,condition,status,inventory_items!equipment_assets_item_id_fkey(id,name,item_code)),profiles!maintenance_requests_reported_by_fkey(id,full_name,email),maintenance_records(id,assigned_to,started_at,completed_at,cost,remarks,profiles!maintenance_records_assigned_to_fkey(id,full_name,email))',
            'id' => 'eq.'.$id,
            'limit' => '1',
        ])['data'];

        return $this->one($rows, 'Maintenance request not found.');
    }

    public function createMaintenanceRequest(string $token, string $actorId, array $data): array
    {
        $payload = [
            'reported_by' => $actorId,
            'facility_id' => $data['facility_id'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'description' => trim($data['description']),
            'priority' => $data['priority'] ?? 'medium',
        ];
        $rows = $this->request($token, 'POST', 'maintenance_requests', [], $payload)['data'];
        return $this->one($rows, 'Maintenance request was not returned after submission.');
    }

    public function updateMaintenanceStatus(string $token, string $id, string $status): array
    {
        $current = $this->maintenanceDetail($token, $id);
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

        $payload = ['status' => $status, 'updated_at' => now()->toIso8601String()];
        $rows = $this->request($token, 'PATCH', 'maintenance_requests', ['id' => 'eq.'.$id], $payload)['data'];
        return $this->one($rows, 'Maintenance request was not updated.');
    }

    public function addMaintenanceRecord(string $token, string $requestId, array $data): array
    {
        $this->maintenanceDetail($token, $requestId);
        $payload = ['request_id' => $requestId] + $data;
        $rows = $this->request($token, 'POST', 'maintenance_records', [], $payload)['data'];
        return $this->one($rows, 'Maintenance activity was not recorded.');
    }

    public function incidents(string $token, array $options): array
    {
        $page = $options['page'];
        $perPage = $options['per_page'];
        $query = [
            'select' => '*,facilities(id,name,code),equipment_assets(id,asset_tag,condition,status,inventory_items!equipment_assets_item_id_fkey(id,name,item_code)),profiles!incident_reports_reported_by_fkey(id,full_name,email),incident_attachments(id,file_path,file_name,uploaded_by,uploaded_at)',
            'order' => 'created_at.desc',
            'limit' => (string) $perPage,
            'offset' => (string) (($page - 1) * $perPage),
        ];
        if ($options['status']) $query['status'] = 'eq.'.$options['status'];
        if ($options['incident_type']) $query['incident_type'] = 'eq.'.$options['incident_type'];
        if ($options['search']) $query['description'] = 'ilike.*'.str_replace(['%','_'], ['\\\\%','\\\\_'], $options['search']).'*';

        $result = $this->request($token, 'GET', 'incident_reports', $query, null, 'count=exact');
        return [
            'incidents' => is_array($result['data']) ? $result['data'] : [],
            'pagination' => $this->pagination($result['range'] ?? null, $page, $perPage),
        ];
    }

    public function incidentDetail(string $token, string $id): array
    {
        $this->uuid($id);
        $rows = $this->request($token, 'GET', 'incident_reports', [
            'select' => '*,facilities(id,name,code),equipment_assets(id,asset_tag,condition,status,inventory_items!equipment_assets_item_id_fkey(id,name,item_code)),profiles!incident_reports_reported_by_fkey(id,full_name,email),incident_attachments(id,file_path,file_name,uploaded_by,uploaded_at)',
            'id' => 'eq.'.$id,
            'limit' => '1',
        ])['data'];

        return $this->one($rows, 'Incident report not found.');
    }

    public function createIncident(string $token, string $actorId, array $data): array
    {
        $payload = [
            'reported_by' => $actorId,
            'facility_id' => $data['facility_id'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'incident_type' => $data['incident_type'],
            'description' => trim($data['description']),
        ];
        $rows = $this->request($token, 'POST', 'incident_reports', [], $payload)['data'];
        return $this->one($rows, 'Incident report was not returned after submission.');
    }

    public function updateIncident(string $token, string $actorId, string $id, array $data): array
    {
        $current = $this->incidentDetail($token, $id);
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

        $payload = [];
        if (array_key_exists('status', $data)) $payload['status'] = $status;
        if (array_key_exists('resolution', $data)) $payload['resolution'] = $data['resolution'];
        if ($status === 'resolved' && $current['status'] !== 'resolved') {
            $payload['resolved_by'] = $actorId;
            $payload['resolved_at'] = now()->toIso8601String();
        } elseif ($status === 'open' && $current['status'] === 'resolved') {
            $payload['resolved_by'] = null;
            $payload['resolved_at'] = null;
        }
        $payload['updated_at'] = now()->toIso8601String();

        $rows = $this->request($token, 'PATCH', 'incident_reports', ['id' => 'eq.'.$id], $payload)['data'];
        return $this->one($rows, 'Incident report was not updated.');
    }

    public function addIncidentAttachment(string $token, string $actorId, string $incidentId, array $data): array
    {
        $this->incidentDetail($token, $incidentId);
        $prefix = 'incidents/'.$incidentId.'/';
        if (!str_starts_with($data['file_path'], $prefix) || str_contains($data['file_path'], '..')) {
            throw new MaintenanceIncidentException('The evidence path does not belong to this incident.', 422);
        }

        $rows = $this->request($token, 'POST', 'incident_attachments', [], [
            'incident_id' => $incidentId,
            'file_path' => $data['file_path'],
            'file_name' => $data['file_name'],
            'uploaded_by' => $actorId,
        ])['data'];
        return $this->one($rows, 'Incident attachment metadata was not saved.');
    }
}
