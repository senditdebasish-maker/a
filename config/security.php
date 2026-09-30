<?php

return [
    'session_name' => env('SESSION_NAME', 'ncp_session'),
    'session_lifetime' => (int) env('SESSION_LIFETIME', 120),
    'session_secure' => filter_var(env('SESSION_SECURE', false), FILTER_VALIDATE_BOOL),
    'require_staff_mfa' => filter_var(env('REQUIRE_STAFF_MFA', true), FILTER_VALIDATE_BOOL),
    'password_min_length' => 10,
    'login_max_attempts' => 5,
    'login_decay_minutes' => 15,
    'allowed_upload_mimes' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ],
];
