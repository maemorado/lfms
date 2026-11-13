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
     * PostgREST exact counts use ranges such as 0-0/42 or */0. A missing,
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

    public function dashboard(string $token, string $userId, bool $admin): array
    {
        $now = now()->toIso8601String();

        // Shared widgets are explicitly scoped to the authenticated user or
        // to currently published announcements. RLS remains authoritative.
        $metrics = [
            'notifications' => [
                'unread' => $this->count($token, 'notifications', [
                    'user_id' => 'eq.'.$userId,
                    'read_at' => 'is.null',
                ]),
            ],
            'announcements' => [
                'published' => $this->count($token, 'announcements', [
                    'status' => 'eq.published',
                    'published_at' => 'lte.'.$now,
                    'or' => '(expires_at.is.null,expires_at.gt.'.$now.')',
                ]),
            ],
        ];

        if ($admin) {
            // Only an assigned admin role receives global operational metrics.
            $metrics = [
                'facilities' => [
                    'total' => $this->count($token, 'facilities'),
                    'available' => $this->count($token, 'facilities', ['status' => 'eq.available']),
                    'unavailable' => $this->count($token, 'facilities', ['status' => 'in.(unavailable,maintenance,inactive)']),
                ],
                'reservations' => [
                    'pending' => $this->count($token, 'reservations', ['status' => 'eq.pending']),
                    'approved' => $this->count($token, 'reservations', ['status' => 'eq.approved']),
                    'rejected' => $this->count($token, 'reservations', ['status' => 'eq.rejected']),
                    'upcoming' => $this->count($token, 'reservations', [
                        'start_at' => 'gte.'.$now,
                        'status' => 'in.(pending,approved)',
                    ]),
                ],
                'inventory' => [
                    'total' => $this->count($token, 'inventory_items', ['status' => 'eq.active']),
                ],
                'borrowing' => [
                    'pending' => $this->count($token, 'borrow_requests', ['status' => 'eq.pending']),
                    'active' => $this->count($token, 'borrow_requests', ['status' => 'in.(approved,partially_returned)']),
                    'overdue' => $this->count($token, 'borrow_requests', [
                        'status' => 'in.(approved,partially_returned)',
                        'expected_return_at' => 'lt.'.$now,
                    ]),
                ],
                'maintenance' => [
                    'pending' => $this->count($token, 'maintenance_requests', ['status' => 'eq.pending']),
                    'ongoing' => $this->count($token, 'maintenance_requests', ['status' => 'in.(approved,in_progress,on_hold)']),
                ],
                'incidents' => [
                    'submitted' => $this->count($token, 'incident_reports'),
                    'unresolved' => $this->count($token, 'incident_reports', ['status' => 'in.(open,under_review)']),
                ],
                'notifications' => $metrics['notifications'],
                'announcements' => $metrics['announcements'],
            ];
        } else {
            // Staff requests are filtered before they reach PostgREST. Do not
            // issue global count requests and then overwrite their results:
            // those requests can fail under RLS and are unnecessary.
            $reservationScope = ['requester_id' => 'eq.'.$userId];
            $borrowingScope = ['borrower_id' => 'eq.'.$userId];
            $maintenanceScope = ['reported_by' => 'eq.'.$userId];
            $incidentScope = ['reported_by' => 'eq.'.$userId];

            $metrics = [
                'reservations' => [
                    'total' => $this->count($token, 'reservations', $reservationScope),
                    'pending' => $this->count($token, 'reservations', $reservationScope + ['status' => 'eq.pending']),
                    'approved' => $this->count($token, 'reservations', $reservationScope + ['status' => 'eq.approved']),
                    'rejected' => $this->count($token, 'reservations', $reservationScope + ['status' => 'eq.rejected']),
                    'upcoming' => $this->count($token, 'reservations', $reservationScope + [
                        'start_at' => 'gte.'.$now,
                        'status' => 'in.(pending,approved)',
                    ]),
                ],
                'borrowing' => [
                    'requests' => $this->count($token, 'borrow_requests', $borrowingScope),
                    'pending' => $this->count($token, 'borrow_requests', $borrowingScope + ['status' => 'eq.pending']),
                    'active' => $this->count($token, 'borrow_requests', $borrowingScope + ['status' => 'in.(approved,partially_returned)']),
                    'overdue' => $this->count($token, 'borrow_requests', $borrowingScope + [
                        'status' => 'in.(approved,partially_returned)',
                        'expected_return_at' => 'lt.'.$now,
                    ]),
                ],
                'maintenance' => [
                    'total' => $this->count($token, 'maintenance_requests', $maintenanceScope),
                    'unresolved' => $this->count($token, 'maintenance_requests', $maintenanceScope + [
                        'status' => 'in.(pending,approved,in_progress,on_hold)',
                    ]),
                ],
                'incidents' => [
                    'total' => $this->count($token, 'incident_reports', $incidentScope),
                    'unresolved' => $this->count($token, 'incident_reports', $incidentScope + [
                        'status' => 'in.(open,under_review)',
                    ]),
                ],
                'notifications' => $metrics['notifications'],
                'announcements' => $metrics['announcements'],
            ];
        }

        $activity = $this->page($token, 'activity_logs', [
            'select' => 'id,user_id,action,module,record_id,metadata,created_at,profiles!activity_logs_user_id_fkey(id,full_name)',
            'order' => 'created_at.desc',
        ] + ($admin ? [] : ['user_id' => 'eq.'.$userId]), 1, 8);

        $published = $this->page($token, 'announcements', [
            'select' => 'id,title,content,category,published_at,expires_at',
            'status' => 'eq.published',
            'published_at' => 'lte.'.$now,
            'or' => '(expires_at.is.null,expires_at.gt.'.$now.')',
            'order' => 'published_at.desc',
        ], 1, 5);

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

        $recentNotifications = $this->page($token, 'notifications', [
            'select' => 'id,title,message,type,read_at,created_at',
            'user_id' => 'eq.'.$userId,
            'order' => 'created_at.desc',
        ], 1, 5);

        return [
            'role' => $admin ? 'admin' : 'staff_sa',
            'metrics' => $metrics,
            'recent_activity' => $activity['data'],
            'announcements' => $published['data'],
            'current_reservations' => $current['data'],
            'upcoming_reservations' => $upcoming['data'],
            'recent_notifications' => $recentNotifications['data'],
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
