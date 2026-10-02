<?php

declare(strict_types=1);

// Router for the PHP built-in server used by CI: serve real public assets
// directly and send application routes through the front controller.
$publicRoot = realpath(dirname(__DIR__) . '/public');
if ($publicRoot === false) {
    http_response_code(500);
    exit('Public web root not found.');
}

$requestPath = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$candidate = realpath($publicRoot . '/' . ltrim($requestPath, '/'));
if (
    $requestPath !== '/'
    && $candidate !== false
    && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)
) {
    return false;
}

require $publicRoot . '/index.php';
