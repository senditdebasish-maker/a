<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        return is_string($token) && hash_equals((string) ($_SESSION['_csrf_token'] ?? ''), $token);
    }

    public static function rotate(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
}
