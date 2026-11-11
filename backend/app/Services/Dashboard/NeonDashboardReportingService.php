<?php

namespace App\Services\Dashboard;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class NeonDashboardReportingService
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

    public function dashboard(bool $admin): array
    {
        $userId = $this->actorId();
        $now = now();

        $metrics = [
            'facilities' => [
                'total' => DB::table('facilities')->count(),
                'available' => DB::table('facilities')->where('status', 'available')->count(),
                'unavailable' => DB::table('facilities')->whereIn('status', ['unavailable', 'maintenance', 'inactive'])->count(),
            ],
            'reservations' => [
                'pending' => DB::table('reservations')->where('status', 'pending')->count(),
                'approved' => DB::table('reservations')->where('status', 'approved')->count(),
                'rejected' => DB::table('reservations')->where('status', 'rejected')->count(),
                'upcoming' => DB::table('reservations')->where('start_at', '>=', $now)->whereIn('status', ['pending', 'approved'])->count(),
            ],
            'inventory' => ['total' => DB::table('inventory_items')->where('status', 'active')->count()],
            'borrowing' => [
                'pending' => DB::table('borrow_requests')->where('status', 'pending')->count(),
                'active' => DB::table('borrow_requests')->whereIn('status', ['approved', 'partially_returned'])->count(),
                'overdue' => DB::table('borrow_requests')->whereIn('status', ['approved', 'partially_returned'])->where('expected_return_at', '<', $now)->count(),
            ],
            'maintenance' => [
                'pending' => DB::table('maintenance_requests')->where('status', 'pending')->count(),
                'ongoing' => DB::table('maintenance_requests')->whereIn('status', ['approved', 'in_progress', 'on_hold'])->count(),
            ],
            'incidents' => [
                'submitted' => DB::table('incident_reports')->count(),
                'unresolved' => DB::table('incident_reports')->whereIn('status', ['open', 'under_review'])->count(),
            ],
            'notifications' => [
                'unread' => DB::table('notifications')->where('user_id', $userId)->whereNull('read_at')->count(),
            ],
            'announcements' => [
                'published' => $this->publishedAnnouncementsQuery()->count(),
            ],
        ];

        $reservationScope = $admin ? null : $userId;
        if (!$admin) {
            $metrics = [
                'reservations' => [
                    'total' => DB::table('reservations')->where('requester_id', $userId)->count(),
                    'pending' => DB::table('reservations')->where('requester_id', $userId)->where('status', 'pending')->count(),
                    'approved' => DB::table('reservations')->where('requester_id', $userId)->where('status', 'approved')->count(),
                    'rejected' => DB::table('reservations')->where('requester_id', $userId)->where('status', 'rejected')->count(),
                    'upcoming' => DB::table('reservations')->where('requester_id', $userId)->where('start_at', '>=', $now)->whereIn('status', ['pending', 'approved'])->count(),
                ],
                'borrowing' => [
                    'requests' => DB::table('borrow_requests')->where('borrower_id', $userId)->count(),
                    'pending' => DB::table('borrow_requests')->where('borrower_id', $userId)->where('status', 'pending')->count(),
                    'active' => DB::table('borrow_requests')->where('borrower_id', $userId)->whereIn('status', ['approved', 'partially_returned'])->count(),
                    'overdue' => DB::table('borrow_requests')->where('borrower_id', $userId)->whereIn('status', ['approved', 'partially_returned'])->where('expected_return_at', '<', $now)->count(),
                ],
                'maintenance' => [
                    'total' => DB::table('maintenance_requests')->where('reported_by', $userId)->count(),
                    'unresolved' => DB::table('maintenance_requests')->where('reported_by', $userId)->whereIn('status', ['pending', 'approved', 'in_progress', 'on_hold'])->count(),
                ],
                'incidents' => [
                    'total' => DB::table('incident_reports')->where('reported_by', $userId)->count(),
                    'unresolved' => DB::table('incident_reports')->where('reported_by', $userId)->whereIn('status', ['open', 'under_review'])->count(),
                ],
                'notifications' => $metrics['notifications'],
                'announcements' => $metrics['announcements'],
            ];
        }

        $activity = $this->activityQuery($admin, ['page' => 1, 'per_page' => 8]);
        $announcements = $this->publishedAnnouncementsQuery()
            ->orderByDesc('published_at')
            ->limit(5)
            ->get(['id', 'title', 'content', 'category', 'published_at', 'expires_at'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $currentReservations = $this->reservationSummaryQuery($reservationScope)
            ->where('status', 'approved')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->orderBy('start_at')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => $this->decodeNested($row, ['facilities']))
            ->all();

        $upcomingReservations = $this->reservationSummaryQuery($reservationScope)
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_at', '>=', $now)
            ->orderBy('start_at')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => $this->decodeNested($row, ['facilities']))
            ->all();

        $recentNotifications = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'message', 'type', 'read_at', 'created_at'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return [
            'role' => $admin ? 'admin' : 'staff_sa',
            'metrics' => $metrics,
            'recent_activity' => $activity['data'],
            'announcements' => $announcements,
            'current_reservations' => $currentReservations,
            'upcoming_reservations' => $upcomingReservations,
            'recent_notifications' => $recentNotifications,
        ];
    }

    public function report(array $options): array
    {
        $definition = self::REPORTS[$options['report'] ?? ''] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages(['report' => 'The requested report is not supported.']);
        }

        $query = DB::table($definition['table']);
        $dateColumn = $definition['date'];
        if (!empty($options['from'])) $query->where($dateColumn, '>=', $options['from']);
        if (!empty($options['to'])) $query->where($dateColumn, '<=', $options['to']);
        if (!empty($options['status']) && $definition['status']) $query->where('status', $options['status']);
        if (!empty($options['search']) && $definition['search']) {
            $query->where($definition['search'], 'ilike', '%'.$this->escapeLike((string) $options['search']).'%');
        }

        return $this->paginate($query, (int) ($options['page'] ?? 1), (int) ($options['per_page'] ?? 20));
    }

    public function activity(bool $admin, array $options): array
    {
        $query = $this->activityQuery($admin, $options);
        return $this->paginate($query, (int) ($options['page'] ?? 1), (int) ($options['per_page'] ?? 20), ['profiles']);
    }

    public function audit(array $options): array
    {
        $query = DB::table('audit_logs as a')
            ->leftJoin('profiles as p', 'p.id', '=', 'a.user_id')
            ->select('a.id', 'a.user_id', 'a.table_name', 'a.record_id', 'a.action', 'a.old_values', 'a.new_values', 'a.created_at')
            ->selectRaw("CASE WHEN p.id IS NULL THEN NULL ELSE jsonb_build_object('id',p.id,'full_name',p.full_name) END AS profiles");

        if (!empty($options['user_id'])) $query->where('a.user_id', $options['user_id']);
        if (!empty($options['module'])) $query->where('a.table_name', $options['module']);
        if (!empty($options['action'])) $query->where('a.action', strtoupper($options['action']));
        $this->applyDateFilters($query, 'a.created_at', $options);

        return $this->paginate($query->orderByDesc('a.created_at'), (int) ($options['page'] ?? 1), (int) ($options['per_page'] ?? 20), ['profiles', 'old_values', 'new_values']);
    }

    private function activityQuery(bool $admin, array $options): Builder
    {
        $userId = $this->actorId();
        $query = DB::table('activity_logs as a')
            ->leftJoin('profiles as p', 'p.id', '=', 'a.user_id')
            ->select('a.id', 'a.user_id', 'a.action', 'a.module', 'a.record_id', 'a.metadata', 'a.created_at')
            ->selectRaw("CASE WHEN p.id IS NULL THEN NULL ELSE jsonb_build_object('id',p.id,'full_name',p.full_name) END AS profiles");

        if (!$admin) $query->where('a.user_id', $userId);
        elseif (!empty($options['user_id'])) $query->where('a.user_id', $options['user_id']);
        if (!empty($options['module'])) $query->where('a.module', $options['module']);
        if (!empty($options['action'])) $query->where('a.action', 'ilike', '%'.$this->escapeLike((string) $options['action']).'%');
        $this->applyDateFilters($query, 'a.created_at', $options);

        return $query->orderByDesc('a.created_at');
    }

    private function reservationSummaryQuery(?string $requesterId): Builder
    {
        $query = DB::table('reservations as r')
            ->leftJoin('facilities as f', 'f.id', '=', 'r.facility_id')
            ->select('r.id', 'r.purpose', 'r.start_at', 'r.end_at', 'r.status')
            ->selectRaw("CASE WHEN f.id IS NULL THEN NULL ELSE jsonb_build_object('id',f.id,'name',f.name,'code',f.code) END AS facilities");
        if ($requesterId !== null) $query->where('r.requester_id', $requesterId);
        return $query;
    }

    private function publishedAnnouncementsQuery(): Builder
    {
        return DB::table('announcements')
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    private function paginate(Builder $query, int $page, int $perPage, array $jsonFields = []): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $total = (clone $query)->count();
        $rows = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $data = $rows->map(function (object $row) use ($jsonFields): array {
            $result = (array) $row;
            foreach ($jsonFields as $field) {
                $result[$field] = $this->decodeJsonValue($result[$field] ?? null);
            }
            if (array_key_exists('metadata', $result)) $result['metadata'] = $this->decodeJsonValue($result['metadata']);
            return $result;
        })->all();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    private function applyDateFilters(Builder $query, string $column, array $options): void
    {
        if (!empty($options['from'])) $query->where($column, '>=', $options['from']);
        if (!empty($options['to'])) $query->where($column, '<=', $options['to']);
    }

    private function decodeNested(object $row, array $fields): array
    {
        $result = (array) $row;
        foreach ($fields as $field) $result[$field] = $this->decodeJsonValue($result[$field] ?? null);
        return $result;
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function actorId(): string
    {
        $row = DB::selectOne('select private.current_user_id()::text as user_id');
        $userId = $row->user_id ?? null;
        if (!is_string($userId) || !Str::isUuid($userId)) {
            throw ValidationException::withMessages(['auth' => 'A verified LFMS identity is required.']);
        }
        return $userId;
    }
}
