<?php

return [
    'url' => env('SUPABASE_URL'),
    'publishable_key' => env('SUPABASE_PUBLISHABLE_KEY'),
    'auth_user_timeout' => (int) env('SUPABASE_AUTH_USER_TIMEOUT', 10),
];
