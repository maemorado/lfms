<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class SupabaseAdminAuthService
{
    private function client()
    {
        $url = rtrim((string) config('supabase.url'), '/');
        $secret = (string) config('supabase.secret_key');

        if ($url === '' || $secret === '') {
            throw new RuntimeException('Supabase Auth administration is not configured. Set SUPABASE_SECRET_KEY in the Laravel environment.');
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
            $message = $response->json('msg') ?? $response->json('message') ?? 'Supabase could not send the account invitation.';
            throw new RuntimeException(is_string($message) ? $message : 'Supabase could not send the account invitation.');
        }

        $id = $response->json('id');
        if (!is_string($id) || !Str::isUuid($id)) {
            throw new RuntimeException('Supabase Auth returned an invalid invited-user identity.');
        }

        return $id;
    }

    /** Ban duration of 100 years effectively blocks sign-in and token refresh. */
    public function setBanned(string $userId, bool $banned): void
    {
        $response = $this->client()->patch(
            rtrim((string) config('supabase.url'), '/').'/auth/v1/admin/users/'.$userId,
            ['ban_duration' => $banned ? '876000h' : 'none'],
        );

        if (!$response->successful()) {
            throw new RuntimeException('Supabase could not update the account authentication status.');
        }
    }

    public function deleteUser(string $userId): void
    {
        $response = $this->client()->delete(
            rtrim((string) config('supabase.url'), '/').'/auth/v1/admin/users/'.$userId,
        );

        if (!$response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Supabase could not roll back the newly-created Auth account.');
        }
    }
}
