<?php

namespace App\Services\Users;

use App\Services\Auth\SupabaseAdminAuthService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Exceptions\UserManagementException;
use Throwable;

final class UserManagementService
{
    public function __construct(private readonly SupabaseAdminAuthService $auth) {}

    public function availableRoles(): array
    {
        return DB::table('roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['admin', 'staff_sa'])
            ->select('roles.name', 'roles.description')
            ->distinct()
            ->orderBy('roles.name')
            ->get()
            ->map(fn ($role) => ['name' => $role->name, 'description' => $role->description])
            ->all();
    }

    public function list(array $options): LengthAwarePaginator
    {
        $query = $this->userQuery();
        if (!empty($options['search'])) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $options['search']).'%';
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('profiles.full_name', 'ilike', $search)
                    ->orWhere('profiles.email', 'ilike', $search)
                    ->orWhere('profiles.employee_id', 'ilike', $search);
            });
        }
        if (!empty($options['status'])) {
            $query->where('profiles.status', $options['status']);
        }
        if (!empty($options['role'])) {
            $role = $options['role'];
            $query->whereExists(function ($subquery) use ($role): void {
                $subquery->selectRaw('1')->from('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->whereColumn('user_roles.user_id', 'profiles.id')
                    ->where('roles.name', $role);
            });
        }

        $sort = in_array($options['sort'] ?? '', ['full_name', 'email', 'status', 'created_at'], true)
            ? $options['sort'] : 'created_at';
        $direction = ($options['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('profiles.'.$sort, $direction)
            ->paginate((int) ($options['per_page'] ?? 20), ['*'], 'page', (int) ($options['page'] ?? 1))
            ->through(fn ($row) => $this->normalize($row));
    }

    public function find(string $userId): array
    {
        $user = $this->userQuery()->where('profiles.id', $userId)->first();
        if (!$user) {
            throw new UserManagementException('The requested account was not found.', 404);
        }
        return $this->normalize($user);
    }

    public function create(string $actorId, array $data): array
    {
        if (DB::table('profiles')->whereRaw('lower(email) = lower(?)', [$data['email']])->exists()) {
            throw new UserManagementException('An LFMS profile already uses this email address.', 422);
        }
        if (!empty($data['employee_id']) && DB::table('profiles')->where('employee_id', $data['employee_id'])->exists()) {
            throw new UserManagementException('That employee ID is already assigned to another account.', 422);
        }
        $role = $this->findAssignableRole($data['role']);
        $userId = $this->auth->invite($data['email'], $data['full_name']);

        try {
            DB::transaction(function () use ($actorId, $userId, $data, $role): void {
                $this->setActor($actorId);
                DB::table('profiles')->insert([
                    'id' => $userId,
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'employee_id' => $data['employee_id'] ?? null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->replaceRole($actorId, $userId, $role->id, $role->name);
                $this->activity($actorId, $userId, 'account.created', ['role' => $role->name]);
            });
        } catch (Throwable $exception) {
            try { $this->auth->deleteUser($userId); } catch (Throwable) { /* surfaced as the original failure; orphan cleanup is logged by the caller */ }
            throw $exception;
        }

        return $this->find($userId);
    }

    public function update(string $actorId, string $userId, array $data): array
    {
        if ($actorId === $userId && array_key_exists('role', $data)) {
            throw new UserManagementException('You cannot change your own role.', 403);
        }

        DB::transaction(function () use ($actorId, $userId, $data): void {
            $this->setActor($actorId);
            $profile = DB::table('profiles')->where('id', $userId)->lockForUpdate()->first();
            if (!$profile) throw new UserManagementException('The requested account was not found.', 404);

            $updates = [];
            foreach (['full_name', 'employee_id'] as $field) {
                if (array_key_exists($field, $data)) $updates[$field] = $data[$field];
            }
            if (!empty($updates['employee_id']) && DB::table('profiles')->where('employee_id', $updates['employee_id'])->where('id', '<>', $userId)->exists()) {
                throw new UserManagementException('That employee ID is already assigned to another account.', 422);
            }
            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('profiles')->where('id', $userId)->update($updates);
            }

            if (array_key_exists('role', $data)) {
                // Serialize privileged role changes so concurrent requests cannot remove every active Admin.
                DB::table('roles')->where('name', 'admin')->lockForUpdate()->first();
                $role = $this->findAssignableRole($data['role']);
                $currentIsAdmin = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->where('user_roles.user_id', $userId)->where('roles.name', 'admin')->exists();
                if ($profile->status === 'active' && $currentIsAdmin && $role->name !== 'admin' && !$this->hasOtherActiveAdmin($userId)) {
                    throw new UserManagementException('This change would remove the last active Admin account.', 422);
                }
                $this->replaceRole($actorId, $userId, $role->id, $role->name);
            }

            if ($updates !== [] || array_key_exists('role', $data)) {
                $this->activity($actorId, $userId, 'account.updated', [
                    'fields' => array_values(array_unique(array_merge(array_keys($updates), array_key_exists('role', $data) ? ['role'] : []))),
                ]);
            }
        });

        return $this->find($userId);
    }

    public function setStatus(string $actorId, string $userId, string $status): array
    {
        if ($actorId === $userId) {
            throw new UserManagementException('You cannot deactivate or reactivate your own account from account management.', 403);
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new UserManagementException('Unsupported account status.', 422);
        }

        $initialProfile = DB::table('profiles')->where('id', $userId)->first();
        if (!$initialProfile) throw new UserManagementException('The requested account was not found.', 404);
        if ($initialProfile->status === $status) return $this->find($userId);

        $authStatusChanged = false;
        $previousStatus = $initialProfile->status;
        try {
            DB::transaction(function () use ($actorId, $userId, $status, &$authStatusChanged, &$previousStatus): void {
                $this->setActor($actorId);
                // Serialize status changes against role changes using the canonical Admin role row.
                DB::table('roles')->where('name', 'admin')->lockForUpdate()->first();
                $profile = DB::table('profiles')->where('id', $userId)->lockForUpdate()->first();
                if (!$profile) throw new UserManagementException('The requested account was not found.', 404);
                if ($profile->status === $status) return;
                $previousStatus = $profile->status;

                $isAdmin = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->where('user_roles.user_id', $userId)->where('roles.name', 'admin')->exists();
                if ($status !== 'active' && $profile->status === 'active' && $isAdmin && !$this->hasOtherActiveAdmin($userId)) {
                    throw new UserManagementException('This change would deactivate the last active Admin account.', 422);
                }

                // Keep the auth ban and profile status transition in one guarded workflow.
                // If a later database operation fails, the catch below restores the prior Auth state.
                $this->auth->setBanned($userId, $status !== 'active');
                $authStatusChanged = true;
                DB::table('profiles')->where('id', $userId)->update(['status' => $status, 'updated_at' => now()]);
                $this->activity($actorId, $userId, $status === 'active' ? 'account.activated' : 'account.deactivated', []);
            });
        } catch (Throwable $exception) {
            if ($authStatusChanged) {
                try { $this->auth->setBanned($userId, $previousStatus !== 'active'); } catch (Throwable) { /* preserve original error */ }
            }
            throw $exception;
        }

        return $this->find($userId);
    }

    public function ownProfile(string $userId): array
    {
        $profile = DB::table('profiles')->where('id', $userId)->first([
            'id', 'full_name', 'email', 'employee_id', 'status', 'created_at', 'updated_at',
        ]);
        if (!$profile || $profile->status !== 'active') {
            throw new UserManagementException('Your active LFMS profile could not be found.', 404);
        }
        return (array) $profile;
    }

    public function updateOwnProfile(string $userId, array $data): array
    {
        DB::transaction(function () use ($userId, $data): void {
            $this->setActor($userId);
            $updates = [];
            foreach (['full_name', 'employee_id'] as $field) {
                if (array_key_exists($field, $data)) $updates[$field] = $data[$field];
            }
            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('profiles')->where('id', $userId)->update($updates);
                $this->activity($userId, $userId, 'profile.updated', ['fields' => array_keys($updates)]);
            }
        });
        return $this->ownProfile($userId);
    }

    private function userQuery(): Builder
    {
        $roles = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->selectRaw("user_roles.user_id, string_agg(distinct roles.name, ',' order by roles.name) as role_names")
            ->groupBy('user_roles.user_id');

        return DB::table('profiles')
            ->leftJoinSub($roles, 'assigned_roles', fn ($join) => $join->on('assigned_roles.user_id', '=', 'profiles.id'))
            ->select('profiles.id', 'profiles.full_name', 'profiles.email', 'profiles.employee_id', 'profiles.status', 'profiles.created_at', 'profiles.updated_at', 'assigned_roles.role_names');
    }

    private function normalize(object $row): array
    {
        $result = (array) $row;
        $result['roles'] = isset($result['role_names']) && $result['role_names'] !== ''
            ? explode(',', $result['role_names']) : [];
        unset($result['role_names']);
        return $result;
    }

    private function findAssignableRole(string $name): object
    {
        if (!in_array($name, ['admin', 'staff_sa'], true)) {
            throw new UserManagementException('Only the existing Admin and Staff/SA roles can be assigned.', 422);
        }
        $role = DB::table('roles')->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->where('roles.name', $name)->select('roles.id', 'roles.name')->first();
        if (!$role) throw new UserManagementException('The selected role is not configured for LFMS.', 422);
        return $role;
    }

    private function replaceRole(string $actorId, string $userId, string $roleId, string $roleName): void
    {
        $existing = DB::table('user_roles')->where('user_id', $userId)->orderBy('created_at')->first();
        $now = now();
        if ($existing) {
            DB::table('user_roles')->where('id', $existing->id)->update(['role_id' => $roleId]);
            DB::table('user_roles')->where('user_id', $userId)->where('id', '<>', $existing->id)->delete();
            DB::table('audit_logs')->insert([
                'user_id' => $actorId, 'table_name' => 'user_roles', 'record_id' => $existing->id,
                'action' => 'UPDATE', 'old_values' => json_encode(['role_id' => $existing->role_id]),
                'new_values' => json_encode(['role_id' => $roleId, 'role' => $roleName]), 'created_at' => $now,
            ]);
        } else {
            $id = (string) Str::uuid();
            DB::table('user_roles')->insert(['id' => $id, 'user_id' => $userId, 'role_id' => $roleId, 'created_at' => $now]);
            DB::table('audit_logs')->insert([
                'user_id' => $actorId, 'table_name' => 'user_roles', 'record_id' => $id,
                'action' => 'INSERT', 'old_values' => null,
                'new_values' => json_encode(['user_id' => $userId, 'role_id' => $roleId, 'role' => $roleName]),
                'created_at' => $now,
            ]);
        }
    }

    private function hasOtherActiveAdmin(string $excludedUserId): bool
    {
        return DB::table('profiles')->join('user_roles', 'user_roles.user_id', '=', 'profiles.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('profiles.status', 'active')->where('roles.name', 'admin')
            ->where('profiles.id', '<>', $excludedUserId)->exists();
    }

    private function setActor(string $actorId): void
    {
        DB::statement("select set_config('app.current_user_id', ?, true)", [$actorId]);
    }

    private function activity(string $actorId, string $targetId, string $action, array $metadata): void
    {
        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $actorId, 'action' => $action,
            'module' => 'user_management', 'record_id' => $targetId,
            'metadata' => json_encode(['affected_user_id' => $targetId] + $metadata),
            'created_at' => now(),
        ]);
    }
}
