<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('CI') !== 'true') {
    fwrite(STDERR, "This route audit runs only in CI.\n");
    exit(2);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Application;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;

Env::load(BASE_PATH . '/.env');
$app = new Application(BASE_PATH);
require BASE_PATH . '/routes/web.php';

$property = new ReflectionProperty(Router::class, 'routes');
$property->setAccessible(true);
$routes = $property->getValue($app->router());
$permissionSlugs = array_map(
    static fn (array $row): string => (string) $row['slug'],
    Database::get()->all('SELECT slug FROM permissions')
);

$seen = [];
$adminCount = 0;
$admissionCount = 0;
foreach ($routes as $route) {
    $method = (string) ($route['method'] ?? '');
    $path = (string) ($route['path'] ?? '');
    $key = $method . ' ' . $path;
    if (isset($seen[$key])) throw new RuntimeException("Duplicate route: {$key}");
    $seen[$key] = true;

    if (!str_starts_with($path, '/admin')) continue;
    $adminCount++;
    if (preg_match('#^/admin/(admissions|applications|merit|payments|reports)(?:/|$)#', $path)) $admissionCount++;

    $middleware = (array) ($route['middleware'] ?? []);
    if (!in_array('auth', $middleware, true)) throw new RuntimeException("Admin route lacks auth middleware: {$key}");
    $permissionRules = array_values(array_filter($middleware, static fn (string $rule): bool => str_starts_with($rule, 'permission:')));
    if (count($permissionRules) !== 1) throw new RuntimeException("Admin route must declare exactly one permission: {$key}");
    $permission = substr($permissionRules[0], strlen('permission:'));
    if (!in_array($permission, $permissionSlugs, true)) throw new RuntimeException("Unknown permission {$permission} on {$key}");

    $handler = $route['handler'] ?? null;
    if (!is_array($handler) || count($handler) !== 2) throw new RuntimeException("Admin route must use a controller action: {$key}");
    [$class, $action] = $handler;
    if (!class_exists($class) || !method_exists($class, $action)) throw new RuntimeException("Missing handler {$class}::{$action} for {$key}");
    $reflection = new ReflectionMethod($class, $action);
    if (!$reflection->isPublic()) throw new RuntimeException("Non-public handler {$class}::{$action} for {$key}");
}

if ($adminCount !== 81) throw new RuntimeException("Expected 81 protected admin routes, found {$adminCount}.");
if ($admissionCount !== 49) throw new RuntimeException("Expected 49 admission/application/merit/payment/report admin routes, found {$admissionCount}.");

$routerSource = file_get_contents(BASE_PATH . '/app/Core/Router.php') ?: '';
if (!str_contains($routerSource, "method === 'POST'") || !str_contains($routerSource, 'Csrf::verify')) {
    throw new RuntimeException('Global POST CSRF enforcement could not be verified.');
}

echo "Admin route audit passed: {$adminCount} routes, {$admissionCount} admission-related routes, handlers and permission slugs verified.\n";
