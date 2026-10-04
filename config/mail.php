<?php

return [
    'driver' => env('MAIL_DRIVER', 'log'),
    'host' => env('MAIL_HOST', ''),
    'port' => (int) env('MAIL_PORT', 587),
    'auth' => filter_var(env('MAIL_AUTH', true), FILTER_VALIDATE_BOOL),
    'username' => env('MAIL_USERNAME', ''),
    'password' => env('MAIL_PASSWORD', ''),
    'encryption' => env('MAIL_ENCRYPTION', 'tls'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'admissions@example.edu.in'),
    'from_name' => env('MAIL_FROM_NAME', 'Netaji College of Pharmacy'),
    'timeout' => (int) env('MAIL_TIMEOUT', 20),
];
