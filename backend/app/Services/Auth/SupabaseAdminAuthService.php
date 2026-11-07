<?php

namespace App\Services\Auth;

use App\Exceptions\UserManagementException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SupabaseAdminAuthService
{
    private function client()
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $secret = (string) config('supabase.secret_key');

        if ($url === '' || $secret === '') {
            throw new UserManagementException('Account administration is not configured on the server.', 503);
        }

        return Http::acceptJson()
            ->withHeaders(['apikey' => $secret, 'Authorization' => 'Bearer '.$secret])
            ->timeout((int) config('supabase.auth_user_timeout', 10));
    }

    /** Send Supabase's invitation email and return the newly-created Auth user ID. */
    public function invite(string $email, string $fullName): string
    {
        $redirect = rtrim((string) config('supabase.frontend_url'), '/').'/auth/accept-invite';
        $response = $this->client()->post(
            rtrim((string) config('supabase.url'), '/').'/auth/v1/invite?redirect_to='.urlencode($redirect),
            ['email' => $email, 'data' => ['full_name' => $fullName]],
        );

        if (!$response->successful()) {
            if (in_array($response->status(), [400, 409, 422], true)) {
                throw new UserManagementException('The invitation could not be sent. Confirm the email is not already registered and the invitation redirect URL is allow-listed.', 422);
            }
            throw new UserManagementException('Supabase could not send the account invitation.', 502);
        }

        $id = $response->json('id');
        if (!is_string($id) || !Str::isUuid($id)) {
            throw new UserManagementException('Supabase Auth returned an invalid invitation response.', 502);
        }

        return $id;
    }

    /** Ban duration of 100 years effectively blocks sign-in and token refresh. */
    public function setBanned(string $userId, bool $banned): void
    {
        $response = $this->client()->put(
            rtrim((string) config('supabase.url'), '/').'/auth/v1/admin/users/'.$userId,
            ['ban_duration' => $banned ? '876000h' : 'none'],
        );

        if (!$response->successful()) {
            throw new UserManagementException('Supabase could not update the account authentication status.', 502);
        }
    }

    public function deleteUser(string $userId): void
    {
        $response = $this->client()->delete(
            rtrim((string) config('supabase.url'), '/').'/auth/v1/admin/users/'.$userId,
        );

        if (!$response->successful() && $response->status() !== 404) {
            throw new UserManagementException('Supabase could not complete account rollback. Contact an administrator to verify the invited Auth account.', 502);
        }
    }
}
