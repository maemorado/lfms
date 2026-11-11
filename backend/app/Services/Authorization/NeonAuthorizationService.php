<?php

namespace App\Services\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NeonAuthorizationService
{
    /**
     * Resolve the active profile and its database-backed role permissions.
     *
     * This method must run after NeonIdentityContext has established a signed,
     * transaction-local identity. RLS remains active for every query.
     *
     * @return array{profile: array<string, mixed>, roles: list<string>, permissions: list<string>}|null
     */
    public function resolve(string $userId): ?array
    {
        if (!Str::isUuid($userId)) {
            return null;
        }

        $profile = DB::table('profiles')
            ->where('id', $userId)
            ->where('status', 'active')
            ->first(['id', 'full_name', 'email', 'employee_id', 'status']);

        if (!$profile) {
            return null;
        }

        $roles = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->whereIn('roles.name', ['admin', 'staff_sa'])
            ->distinct()
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();

        if ($roles === []) {
            return null;
        }

        $permissions = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $userId)
            ->whereIn('r.name', $roles)
            ->distinct()
            ->orderBy('p.name')
            ->pluck('p.name')
            ->all();

        return [
            'profile' => (array) $profile,
            'roles' => array_values($roles),
            'permissions' => array_values($permissions),
        ];
    }

    /**
     * Keep the Neon profile email synchronized with the email returned by the
     * Supabase Auth /user endpoint. The database function verifies that the
     * signed transaction identity is the profile owner.
     */
    public function syncAuthEmail(string $userId, ?string $email): void
    {
        if (!is_string($email) || trim($email) === '') {
            return;
        }

        DB::select('select private.sync_auth_email(?, ?)', [$userId, trim($email)]);
    }
}
