<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Application
{
    private Router $router;

    public function __construct(private readonly string $basePath)
    {
        date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Kolkata'));
        $this->startSession();
        Flash::age();
        $this->router = new Router();
        $this->securityHeaders();
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        try {
            $this->router->dispatch();
        } catch (Throwable $exception) {
            $this->report($exception);
            http_response_code(500);
            if ((bool) Config::get('app.debug', false)) {
                echo '<pre style="padding:24px;font:14px/1.6 monospace;white-space:pre-wrap">' . e((string) $exception) . '</pre>';
                return;
            }
            View::render('errors/500', ['title' => 'Something went wrong'], 'auth');
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string) Config::get('security.session_name', 'ncp_session'));
        session_set_cookie_params([
            'lifetime' => (int) Config::get('security.session_lifetime', 120) * 60,
            'path' => '/',
            'secure' => (bool) Config::get('security.session_secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > (int) Config::get('security.session_lifetime', 120) * 60) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = time();
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self' https://test.payu.in https://secure.payu.in");
    }

    private function report(Throwable $exception): void
    {
        $dir = $this->basePath . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $message = sprintf("[%s] %s\n%s\n\n", date('c'), $exception->getMessage(), $exception->getTraceAsString());
        @file_put_contents($dir . '/app.log', $message, FILE_APPEND | LOCK_EX);
    }
}
