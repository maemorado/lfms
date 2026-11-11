<?php

return [
    'url' => env('SUPABASE_URL'),
    // Publishable key is used only to validate caller sessions with Supabase Auth.
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY'),
    // Server-only credential used by Supabase Auth Admin and private Storage API calls.
    // Never expose this value to the frontend or commit it to source control.
    'secret_key' => env('SUPABASE_SECRET_KEY'),
    'frontend_url' => env('FRONTEND_URL'),
    'auth_user_timeout' => (int) env('SUPABASE_AUTH_USER_TIMEOUT', 10),
];
