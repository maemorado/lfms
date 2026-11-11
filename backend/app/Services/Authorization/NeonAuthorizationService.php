<?php

namespace App\\Services\\Authorization;

use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;

final class NeonAuthorizationService
{
    /**
     * Resolve the authenticated Supabase subject against LFMS authorization
     * records stored in Neon. No role or permission claims from the JWT are used.
     *
     * The caller must establish app.current_user_id transaction-locally before
     * invoking this method so Neon RLS can scope the authorization queries.
     *
     * @return array{profile: array<string, mixed>, roles: list<string>, permissions: list<string>}|null
     */
    public function resolve(string $userId): ?array
    {
        if (! Str::isUuid($userId)) {
            return null;
        }

        $connection = DB::connection();

        $profile = $connection->table('profiles')
            ->select(['id', 'full_name', 'email', 'employee_id', 'status'])
            ->where('id', $userId)
            ->where('status', 'active')
            ->first();

        if ($profile === null || (string) $profile->id !== $userId) {
            return null;
        }

        $roleRows = $connection->table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)
            ->whereIn('r.name', ['admin', 'staff_sa'])
            ->select(['r.id', 'r.name'])
            ->distinct()
            ->orderBy('r.name')
            ->get();

        if ($roleRows->isEmpty()) {
            return null;
        }

        $roles = $roleRows->pluck('name')->unique()->values()->all();
        $roleIds = $roleRows->pluck('id')->unique()->values()->all();

        $permissions = $connection->table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $roleIds)
            ->distinct()
            ->orderBy('p.name')
            ->pluck('p.name')
            ->filter(static fn ($name): bool => is_string($name) && $name !== '')
            ->values()
            ->all();

        return [
            'profile' => (array) $profile,
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }
}
