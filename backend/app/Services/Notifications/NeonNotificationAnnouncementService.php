<?php

namespace App\Services\Notifications;

use App\Exceptions\NotificationAnnouncementException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class NeonNotificationAnnouncementService
{
    public function notifications(array $options): array
    {
        $userId = $this->actorId();
        $query = DB::table('notifications')->where('user_id', $userId);

        if (!empty($options['type'])) $query->where('type', $options['type']);
        if (!empty($options['unread'])) $query->whereNull('read_at');

        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 20)));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'id', 'user_id', 'title', 'message', 'type', 'read_at', 'created_at',
                'event_key', 'related_record_type', 'related_record_id', 'metadata',
            ]);

        return [
            'notifications' => $rows->map(fn (object $row): array => $this->decodeJsonFields($row, ['metadata']))->all(),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    public function notification(string $id): array
    {
        $this->uuid($id);
        $row = DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $this->actorId())
            ->first([
                'id', 'user_id', 'title', 'message', 'type', 'read_at', 'created_at',
                'event_key', 'related_record_type', 'related_record_id', 'metadata',
            ]);

        if (!$row) throw new NotificationAnnouncementException('Notification not found.', 404);
        return $this->decodeJsonFields($row, ['metadata']);
    }

    public function markNotificationRead(string $id): array
    {
        $this->uuid($id);
        DB::table('notifications')
            ->where('id', $id)
            ->where('user_id', $this->actorId())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->notification($id);
    }

    public function markAllNotificationsRead(): int
    {
        return DB::table('notifications')
            ->where('user_id', $this->actorId())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadCount(): int
    {
        return DB::table('notifications')
            ->where('user_id', $this->actorId())
            ->whereNull('read_at')
            ->count();
    }

    public function dispatchOverdueBorrowing(): int
    {
        $requests = DB::table('borrow_requests as br')
            ->join('borrow_transactions as bt', 'bt.borrow_request_id', '=', 'br.id')
            ->whereIn('br.status', ['approved', 'partially_returned'])
            ->where('br.expected_return_at', '<', now())
            ->distinct()
            ->orderBy('br.expected_return_at')
            ->limit(500)
            ->get(['br.id', 'br.borrower_id', 'br.expected_return_at']);

        $created = 0;
        foreach ($requests as $request) {
            $expectedReturnAt = (string) $request->expected_return_at;
            $eventKey = 'borrowing:overdue:'.$request->id.':'.$expectedReturnAt;
            try {
                $created += DB::table('notifications')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'user_id' => $request->borrower_id,
                    'title' => 'Borrowing overdue',
                    'message' => 'A borrowed item is past its expected return date.',
                    'type' => 'borrowing',
                    'event_key' => $eventKey,
                    'related_record_type' => 'borrowing',
                    'related_record_id' => $request->id,
                    'metadata' => $this->encodeJson([
                        'event' => 'overdue',
                        'expected_return_at' => $expectedReturnAt,
                    ]),
                    'created_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $this->translateWriteFailure($exception);
            }
        }

        return $created;
    }

    public function announcements(array $options, bool $admin): array
    {
        $query = DB::table('announcements')
            ->select('id', 'title', 'content', 'created_by', 'published_at', 'status', 'category', 'expires_at', 'created_at', 'updated_at');

        if (!$admin) {
            $query->where('status', 'published')
                ->where('published_at', '<=', now())
                ->where(function (Builder $builder): void {
                    $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });
        } elseif (!empty($options['status'])) {
            $query->where('status', $options['status']);
        }

        if (!empty($options['category'])) $query->where('category', $options['category']);
        if (!empty($options['search'])) {
            $query->where('title', 'ilike', '%'.$this->escapeLike((string) $options['search']).'%');
        }

        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 20)));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'announcements' => $rows->map(fn (object $row): array => (array) $row)->all(),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    public function announcement(string $id, bool $admin): array
    {
        $this->uuid($id);
        $query = DB::table('announcements')
            ->where('id', $id)
            ->select('id', 'title', 'content', 'created_by', 'published_at', 'status', 'category', 'expires_at', 'created_at', 'updated_at');

        if (!$admin) {
            $query->where('status', 'published')
                ->where('published_at', '<=', now())
                ->where(function (Builder $builder): void {
                    $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });
        }

        $row = $query->first();
        if (!$row) throw new NotificationAnnouncementException('Announcement not found.', 404);
        return (array) $row;
    }

    public function createAnnouncement(array $data): array
    {
        $id = (string) Str::uuid();
        try {
            DB::table('announcements')->insert([
                'id' => $id,
                'created_by' => $this->actorId(),
                'title' => trim($data['title']),
                'content' => trim($data['content']),
                'category' => $data['category'] ?? 'general',
                'expires_at' => $data['expires_at'] ?? null,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->announcement($id, true);
    }

    public function updateAnnouncement(string $id, array $data): array
    {
        $this->uuid($id);
        $updates = [];
        foreach (['title', 'content', 'category', 'expires_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = in_array($field, ['title', 'content'], true)
                    ? trim((string) $data[$field])
                    : $data[$field];
            }
        }
        if ($updates === []) throw new NotificationAnnouncementException('At least one announcement field is required.', 422);
        $updates['updated_at'] = now();

        try {
            DB::table('announcements')->where('id', $id)->update($updates);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->announcement($id, true);
    }

    public function setAnnouncementState(string $id, string $state): array
    {
        $this->uuid($id);
        if (!in_array($state, ['draft', 'published', 'archived'], true)) {
            throw new NotificationAnnouncementException('Unsupported announcement state.', 422);
        }

        $current = $this->announcement($id, true);
        $allowed = match ($current['status'] ?? null) {
            'draft' => ['published', 'archived'],
            'published' => ['draft', 'archived'],
            default => [],
        };
        if (!in_array($state, $allowed, true)) {
            throw new NotificationAnnouncementException('That announcement state transition is not allowed.', 409);
        }
        if ($state === 'published' && is_string($current['expires_at'] ?? null) && strtotime($current['expires_at']) <= time()) {
            throw new NotificationAnnouncementException('An expired announcement cannot be published. Clear or update its expiration date first.', 422);
        }

        $updates = ['status' => $state, 'updated_at' => now()];
        if ($state === 'published') $updates['published_at'] = now();
        if ($state === 'draft') $updates['published_at'] = null;

        try {
            DB::table('announcements')->where('id', $id)->update($updates);
        } catch (QueryException $exception) {
            $this->translateWriteFailure($exception);
        }

        return $this->announcement($id, true);
    }

    public function announcementHistory(string $id): array
    {
        $this->uuid($id);
        $rows = DB::table('audit_logs as a')
            ->leftJoin('profiles as p', 'p.id', '=', 'a.user_id')
            ->where('a.table_name', 'announcements')
            ->where('a.record_id', $id)
            ->orderByDesc('a.created_at')
            ->limit(100)
            ->get([
                'a.id', 'a.user_id', 'a.table_name', 'a.record_id', 'a.action',
                'a.old_values', 'a.new_values', 'a.created_at',
                'p.id as profile_ref_id', 'p.full_name as profile_name',
            ]);

        return $rows->map(function (object $row): array {
            $item = (array) $row;
            foreach (['old_values', 'new_values'] as $field) {
                $item[$field] = $this->decodeJsonValue($item[$field] ?? null);
            }
            $item['profiles'] = $row->profile_ref_id === null ? null : [
                'id' => $row->profile_ref_id,
                'full_name' => $row->profile_name,
            ];
            unset($item['profile_ref_id'], $item['profile_name']);
            return $item;
        })->all();
    }

    private function actorId(): string
    {
        $row = DB::selectOne('select private.current_user_id()::text as user_id');
        $userId = $row->user_id ?? null;
        if (!is_string($userId) || !Str::isUuid($userId)) {
            throw new NotificationAnnouncementException('A verified LFMS identity is required for this operation.', 401);
        }
        return $userId;
    }

    private function decodeJsonFields(object $row, array $fields): array
    {
        $result = (array) $row;
        foreach ($fields as $field) $result[$field] = $this->decodeJsonValue($result[$field] ?? null);
        return $result;
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) return $value;
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }

    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new NotificationAnnouncementException('The notification metadata could not be encoded.', 422);
        }
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
        if (!Str::isUuid($id)) throw new NotificationAnnouncementException('The supplied record identifier is invalid.', 422);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function translateWriteFailure(QueryException $exception): never
    {
        $state = $exception->errorInfo[0] ?? (string) $exception->getCode();
        $message = match ($state) {
            '23505' => 'This notification or announcement conflicts with an existing record.',
            '23503' => 'The related LFMS record is invalid.',
            '42501' => 'Your account is not authorized to change this record.',
            '23514', '22023' => 'The submitted value is not supported by the LFMS data model.',
            default => 'The notifications and announcements service is temporarily unavailable.',
        };
        $status = match ($state) {
            '23505' => 409,
            '23503', '23514', '22023' => 422,
            '42501' => 403,
            default => 502,
        };
        throw new NotificationAnnouncementException($message, $status);
    }
}
