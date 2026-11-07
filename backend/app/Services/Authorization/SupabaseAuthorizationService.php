<?php

namespace App\Services\Authorization;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SupabaseAuthorizationService
{
    /**
     * Resolve the caller's profile, assigned roles, and role permissions through
     * PostgREST using the caller's JWT. This deliberately preserves Supabase RLS.
     *
     * @return array{profile: array<string, mixed>, roles: list<string>, permissions: list<string>}|null
     */
    public function resolve(string $accessToken, string $userId): ?array
    {
        if (!Str::isUuid($userId)) {
            return null;
        }

        $baseUrl = rtrim((string) config('supabase.url'), '/');
        $publishableKey = (string) config('supabase.publishable_key');

        if ($baseUrl === '' || $publishableKey === '') {
            throw new \RuntimeException('Supabase authorization is not configured.');
        }

        $client = Http::acceptJson()
            ->withHeaders([
                'apikey' => $publishableKey,
                'Authorization' => 'Bearer '.$accessToken,
            ])
            ->timeout((int) config('supabase.auth_user_timeout', 10));

        try {
            $profileResponse = $client->get($baseUrl.'/rest/v1/profiles', [
                'select' => 'id,full_name,email,employee_id,status',
                'id' => 'eq.'.$userId,
                'limit' => 1,
            ]);

            if (!$profileResponse->successful()) {
                throw new \RuntimeException('Unable to verify the LFMS profile through Supabase.');
            }

            $profiles = $profileResponse->json();
            $profile = is_array($profiles) ? ($profiles[0] ?? null) : null;

            // RLS may intentionally return no profile when the caller has no
            // profile.view_own permission. Treat that as denied, not as a DB bypass.
            if (!is_array($profile) || ($profile['id'] ?? null) !== $userId || ($profile['status'] ?? null) !== 'active') {
                return null;
            }

            $roleResponse = $client->get($baseUrl.'/rest/v1/user_roles', [
                'select' => 'role_id,roles!inner(name)',
                'user_id' => 'eq.'.$userId,
            ]);

            if (!$roleResponse->successful()) {
                throw new \RuntimeException('Unable to retrieve LFMS role assignments.');
            }

            $roleRows = $roleResponse->json();
            if (!is_array($roleRows) || $roleRows === []) {
                return null;
            }

            $recognized = [];
            $roleIds = [];
            foreach ($roleRows as $row) {
                $name = $row['roles']['name'] ?? null;
                $roleId = $row['role_id'] ?? null;
                if (is_string($name) && in_array($name, ['admin', 'staff_sa'], true) && is_string($roleId) && Str::isUuid($roleId)) {
                    $recognized[$name] = true;
                    $roleIds[$roleId] = true;
                }
            }

            $roles = array_keys($recognized);
            if ($roles === [] || $roleIds === []) {
                return null;
            }

            $permissionResponse = $client->get($baseUrl.'/rest/v1/role_permissions', [
                'select' => 'permissions!inner(name)',
                'role_id' => 'in.('.implode(',', array_keys($roleIds)).')',
            ]);

            if (!$permissionResponse->successful()) {
                throw new \RuntimeException('Unable to retrieve LFMS role permissions.');
            }

            $permissionRows = $permissionResponse->json();
            if (!is_array($permissionRows)) {
                throw new \RuntimeException('Supabase returned an invalid permission response.');
            }

            $permissions = [];
            foreach ($permissionRows as $row) {
                $name = $row['permissions']['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $permissions[$name] = true;
                }
            }

            return [
                'profile' => $profile,
                'roles' => $roles,
                'permissions' => array_keys($permissions),
            ];
        } catch (ConnectionException $exception) {
            throw new \RuntimeException('Supabase authorization service is unavailable.', previous: $exception);
        }
    }
}
