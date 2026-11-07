<?php

return [
    'url' => env('SUPABASE_URL', 'https://bwebicixgcnzyqoaccha.supabase.co'),
    // Publishable key is used only for caller-scoped Auth and PostgREST requests.
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY') ?: 'sb_publishable_Q1PpH2QbDD324hvUj8MUag_P6ZrC1eE',
    // Server-only credential used exclusively by Supabase Auth Admin endpoints.
    // Never expose this value to the frontend or commit it to source control.
    'secret_key' => env('SUPABASE_SECRET_KEY'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'auth_user_timeout' => (int) env('SUPABASE_AUTH_USER_TIMEOUT', 10),
];
