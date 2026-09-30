<?php

return [
    'name' => env('APP_NAME', 'Netaji College of Pharmacy'),
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => env('APP_URL', ''),
    'key' => env('APP_KEY', ''),
    'timezone' => env('APP_TIMEZONE', 'Asia/Kolkata'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'currency' => env('APP_CURRENCY', 'INR'),
    'date_format' => env('APP_DATE_FORMAT', 'd-m-Y'),
    'supported_locales' => ['en' => 'English', 'bn' => 'বাংলা', 'hi' => 'हिन्दी'],
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 5),
];
