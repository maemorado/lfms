<?php

return [
    // 32-byte hex HMAC key shared with private.identity_signing_key in Neon.
    // Keep it in server-side environment variables only.
    'identity_hmac_key' => env('LFMS_IDENTITY_HMAC_KEY'),
];
