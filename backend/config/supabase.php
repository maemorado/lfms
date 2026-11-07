<?php

return [
    'url' => env('SUPABASE_URL', 'https://bwebicixgcnzyqoaccha.supabase.co'),
    // Publishable keys are safe for server-to-Supabase Auth API requests.
    // Never use a service-role or secret key in the frontend.
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY') ?: 'sb_publishable_Q1PpH2QbDD324hvUj8MUag_P6ZrC1eE',
    'auth_user_timeout' => (int) env('SUPABASE_AUTH_USER_TIMEOUT', 10),
];
