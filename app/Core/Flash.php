<?php

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    public static function age(): void
    {
        $_SESSION['_flash_current'] = $_SESSION['_flash_next'] ?? [];
        $_SESSION['_flash_next'] = [];
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_current'][$key] ?? $default;
    }

    public static function withInput(array $input): void
    {
        unset($input['_token'], $input['password'], $input['password_confirmation']);
        self::set('_old', $input);
    }

    public static function withErrors(array $errors): void
    {
        self::set('_errors', $errors);
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_flash_current']['_old'][$key] ?? $default;
    }

    public static function error(string $key): ?string
    {
        $error = $_SESSION['_flash_current']['_errors'][$key] ?? null;
        return is_array($error) ? (string) reset($error) : ($error !== null ? (string) $error : null);
    }
}
