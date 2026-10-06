<?php

return [
    'session_name' => env('SESSION_NAME', 'ncp_session'),
    'session_lifetime' => (int) env('SESSION_LIFETIME', 120),
    'session_secure' => filter_var(env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
    // Every account uses a passwordless email code at sign-in. Keep SMTP enabled.
    'login_mode' => (string) env('LOGIN_MODE', 'email_otp'),
    'allowed_upload_mimes' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ],
];
