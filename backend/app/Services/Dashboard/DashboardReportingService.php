<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DashboardReportingService
{
    private const REPORTS = [
        'reservations' => ['table' => 'reservations', 'date' => 'created_at', 'status' => true, 'search' => 'purpose'],
        'facility-utilization' => ['table' => 'reservations', 'date' => 'start_at', 'status' => true, 'search' => 'purpose'],
        'inventory' => ['table' => 'inventory_items', 'date' => 'created_at', 'status' => true, 'search' => 'name'],
        'inventory-movements' => ['table' => 'inventory_movements', 'date' => 'created_at', 'status' => false, 'search' => 'remarks'],
        'borrowings' => ['table' => 'borrow_requests', 'date' => 'requested_at', 'status' => true, 'search' => 'remarks'],
        'returns' => ['table' => 'return_transactions', 'date' => 'returned_at', 'status' => false, 'search' => 'remarks'],
        'maintenance' => ['table' => 'maintenance_requests', 'date' => 'created_at', 'status' => true, 'search' => 'description'],
        'incidents' => ['table' => 'incident_reports', 'date' => 'created_at', 'status' => true, 'search' => 'description'],
    ];

    private function client(string $token)
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $key = (string) config('supabase.publishable_key');
        if ($url === '' || $key === '' || $token === '') {
            throw new HttpException(503, 'Dashboard and reporting services are unavailable for this session.');
        }

        return Http::acceptJson()->withHeaders([
            'apikey' => $key,
            'Authorization' => 'Bearer '.$token,
        ])->timeout((int) config('supabase.auth_user_timeout', 10));
    }

    /**
     * Every dashboard query uses the caller's Supabase JWT. PostgREST therefore
     * applies the existing RLS policies; this service never uses a service key.
     *
     * @return array{rows: array, range: ?string}
     */
    private function get(string $token, string $table, array $query = [], string $prefer = 'count=exact'): array
    {
        $response = $this->client($token)->withHeaders(['Prefer' => $prefer])
            ->get(rtrim((string) config('supabase.url'), '/').'/rest/v1/'.$table, $query);

        if (!$response->successful()) {
            $status = $response->status();
            throw new HttpException(
                in_array($status, [401, 403, 404, 422], true) ? $status : 502,
                $status === 403
                    ? 'Your account cannot access this dashboard data.'
                    : 'The dashboard data could not be retrieved.',
            );
        }

        $rows = $response->json();
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new HttpException(502, 'The dashboard data service returned an invalid response.');
        }

        return ['rows' => $rows, 'range' => $response->header('Content-Range')];
    }

    /**
     * PostgREST exact counts use ranges such as 0-0/42 or a wildcard zero range. A missing,
     * wildcard, or malformed total is an upstream data-integrity failure,
     * never a legitimate zero.
     */
    private function totalFromContentRange(?string $range): int
    {
        if (is_string($range)
            && preg_match('/^(?:\d+-\d+|\*)\/(\d+)$/', trim($range), $matches) === 1
        ) {
            return (int) $matches[1];
        }

        throw new HttpException(502, 'The dashboard data service returned incomplete pagination metadata.');
    }

    private function count(string $token, string $table, array $filters = []): int
    {
        $result = $this->get($token, $table, ['select' => 'id', 'limit' => '1'] + $filters);
        return $this->totalFromContentRange($result['range']);
    }

    private function page(string $token, string $table, array $query, int $page, int $perPage): array
    {
        $query['limit'] = (string) $perPage;
        $query['offset'] = (string) (($page - 1) * $perPage);
        $result = $this->get($token, $table, $query);
        $total = $this->totalFromContentRange($result['range']);

        return [
            'data' => $result['rows'],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function dashboard(string $token, string $userId, bool $admin, bool $canViewInventory): array
    {
        $now = now()->toIso8601String();

        // The dashboard is intentionally limited to inventory analytics,
        // reservation calendar entries, and currently published announcements.
        // All requests continue to use the caller JWT so Supabase RLS remains active.
        $metrics = [];
        if ($canViewInventory) {
            $activeItems = ['status' => 'eq.active'];
            $metrics['inventory'] = [
                'total' => $this->count($token, 'inventory_items', $activeItems),
                'equipment' => $this->count($token, 'inventory_items', $activeItems + ['item_type' => 'eq.equipment']),
                'consumables' => $this->count($token, 'inventory_items', $activeItems + ['item_type' => 'eq.consumable']),
            ];
        }

        $reservationScope = $admin ? [] : ['requester_id' => 'eq.'.$userId];

        $current = $this->page($token, 'reservations', [
            'select' => 'id,purpose,start_at,end_at,status,facilities(id,name,code)',
            'status' => 'eq.approved',
            'start_at' => 'lte.'.$now,
            'end_at' => 'gte.'.$now,
            'order' => 'start_at.asc',
        ] + $reservationScope, 1, 5);

        $upcoming = $this->page($token, 'reservations', [
            'select' => 'id,purpose,start_at,end_at,status,facilities(id,name,code)',
            'status' => 'in.(pending,approved)',
            'start_at' => 'gte.'.$now,
            'order' => 'start_at.asc',
        ] + $reservationScope, 1, 5);

        $published = $this->page($token, 'announcements', [
            'select' => 'id,title,content,category,published_at,expires_at',
            'status' => 'eq.published',
            'published_at' => 'lte.'.$now,
            'or' => '(expires_at.is.null,expires_at.gt.'.$now.')',
            'order' => 'published_at.desc',
        ], 1, 5);

        return [
            'role' => $admin ? 'admin' : 'staff_sa',
            'metrics' => $metrics,
            'current_reservations' => $current['data'],
            'upcoming_reservations' => $upcoming['data'],
            'announcements' => $published['data'],
        ];
    }

    public function report(string $token, array $options): array
    {
        $definition = self::REPORTS[$options['report']] ?? null;
        if ($definition === null) throw ValidationException::withMessages(['report' => 'The requested report is not supported.']);
        $query = ['select' => '*', 'order' => $definition['date'].'.desc'];
        if ($options['from'] && $options['to']) $query['and'] = '('.$definition['date'].'.gte.'.$options['from'].','.$definition['date'].'.lte.'.$options['to'].')';
        elseif ($options['from']) $query[$definition['date']] = 'gte.'.$options['from'];
        elseif ($options['to']) $query[$definition['date']] = 'lte.'.$options['to'];
        if ($options['status'] && $definition['status']) $query['status'] = 'eq.'.$options['status'];
        if ($options['search'] && $definition['search']) $query[$definition['search']] = 'ilike.*'.str_replace(['%','_'], ['\\%','\\_'], $options['search']).'*';
        return $this->page($token, $definition['table'], $query, $options['page'], $options['per_page']);
    }

    public function activity(string $token, string $userId, bool $admin, array $options): array
    {
        $query = [
            'select' => 'id,user_id,action,module,record_id,metadata,created_at,profiles!activity_logs_user_id_fkey(id,full_name)',
            'order' => 'created_at.desc',
        ];
        if (!$admin) $query['user_id'] = 'eq.'.$userId;
        if ($options['module']) $query['module'] = 'eq.'.$options['module'];
        if ($options['action']) $query['action'] = 'ilike.*'.str_replace(['%','_'], ['\\%','\\_'], $options['action']).'*';
        if ($options['from'] && $options['to']) $query['and'] = '(created_at.gte.'.$options['from'].',created_at.lte.'.$options['to'].')';
        elseif ($options['from']) $query['created_at'] = 'gte.'.$options['from'];
        elseif ($options['to']) $query['created_at'] = 'lte.'.$options['to'];
        if ($admin && $options['user_id']) $query['user_id'] = 'eq.'.$options['user_id'];
        return $this->page($token, 'activity_logs', $query, $options['page'], $options['per_page']);
    }

    public function audit(string $token, array $options): array
    {
        $query = [
            'select' => 'id,user_id,table_name,record_id,action,created_at,profiles!audit_logs_user_id_fkey(id,full_name)',
            'order' => 'created_at.desc',
        ];
        if ($options['user_id']) $query['user_id'] = 'eq.'.$options['user_id'];
        if ($options['module']) $query['table_name'] = 'eq.'.$options['module'];
        if ($options['action']) $query['action'] = 'eq.'.strtoupper($options['action']);
        if ($options['from'] && $options['to']) $query['and'] = '(created_at.gte.'.$options['from'].',created_at.lte.'.$options['to'].')';
        elseif ($options['from']) $query['created_at'] = 'gte.'.$options['from'];
        elseif ($options['to']) $query['created_at'] = 'lte.'.$options['to'];
        return $this->page($token, 'audit_logs', $query, $options['page'], $options['per_page']);
    }
}
