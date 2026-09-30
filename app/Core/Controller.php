<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'public'): void
    {
        View::render($view, $data, $layout);
    }

    protected function redirect(string $path, int $status = 302): never
    {
        header('Location: ' . url($path), true, $status);
        exit;
    }

    protected function back(): never
    {
        $target = $_SERVER['HTTP_REFERER'] ?? url();
        header('Location: ' . $target);
        exit;
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
