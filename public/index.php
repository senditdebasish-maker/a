<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);

$composer = BASE_PATH . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    require BASE_PATH . '/app/Support/helpers.php';
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

use App\Core\Application;
use App\Core\Env;

Env::load(BASE_PATH . '/.env');

if (is_file(BASE_PATH . '/storage/maintenance.lock')) {
    http_response_code(503);
    header('Retry-After: 300');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Maintenance</title></head><body style="font-family:system-ui;background:#eef4f3;color:#16353c;display:grid;place-items:center;min-height:100vh;margin:0"><main style="max-width:520px;background:white;padding:40px;border-radius:16px"><h1>Scheduled maintenance</h1><p>The college portal is temporarily unavailable while a protected system operation completes. Please try again shortly.</p></main></body></html>';
    exit;
}

if (!Env::get('APP_INSTALLED', false) && !str_contains($_SERVER['REQUEST_URI'] ?? '', '/install')) {
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    header('Location: ' . $scriptDir . '/install/');
    exit;
}

$app = new Application(BASE_PATH);
require BASE_PATH . '/routes/web.php';
$app->run();
