<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Router
{
    private array $routes = [];

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function add(string $method, string $path, array|callable $handler, array $middleware = []): void
    {
        $this->routes[] = compact('method', 'path', 'handler', 'middleware');
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $this->requestPath();

        if ($method === 'POST' && !Csrf::verify($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            http_response_code(419);
            View::render('errors/419', ['title' => 'Page expired'], 'auth');
            return;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $parameters = $this->match($route['path'], $path);
            if ($parameters === null) {
                continue;
            }
            if (!$this->middleware($route['middleware'])) {
                return;
            }
            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                $controller->{$action}(...array_values($parameters));
            } else {
                $handler(...array_values($parameters));
            }
            return;
        }

        http_response_code(404);
        View::render('errors/404', ['title' => 'Page not found'], 'public');
    }

    private function requestPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(dirname($script), '/.');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');
        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    private function match(string $route, string $path): ?array
    {
        $names = [];
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $matches) use (&$names): string {
            $names[] = $matches[1];
            return '([^/]+)';
        }, $route);
        if (!preg_match('#^' . $pattern . '$#', $path, $matches)) {
            return null;
        }
        array_shift($matches);
        $values = array_map('urldecode', $matches);
        return $names ? array_combine($names, $values) : [];
    }

    private function middleware(array $middleware): bool
    {
        foreach ($middleware as $rule) {
            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
            if ($name === 'auth' && !Auth::check()) {
                Flash::set('warning', 'Please sign in to continue.');
                header('Location: ' . url('login'));
                return false;
            }
            if ($name === 'guest' && Auth::check()) {
                header('Location: ' . url(Auth::hasRole('applicant') ? 'student/dashboard' : 'admin/dashboard'));
                return false;
            }
            if ($name === 'role' && !Auth::hasRole(explode(',', (string) $parameter))) {
                $this->forbidden();
                return false;
            }
            if ($name === 'permission' && !Auth::can((string) $parameter)) {
                $this->forbidden();
                return false;
            }
        }
        return true;
    }

    private function forbidden(): void
    {
        http_response_code(403);
        View::render('errors/403', ['title' => 'Access denied'], Auth::hasRole('applicant') ? 'student' : 'admin');
    }
}
