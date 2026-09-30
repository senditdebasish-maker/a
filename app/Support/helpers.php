<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Translator;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    $configured = rtrim((string) config('app.url', ''), '/');
    if ($configured !== '') {
        return $configured;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(dirname($script), '/.');

    return $scheme . '://' . $host . ($base === '' ? '' : $base);
}

function url(string $path = ''): string
{
    return base_url() . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    return Flash::old($key, $default);
}

function error(string $key): ?string
{
    return Flash::error($key);
}

function flash(string $key, mixed $default = null): mixed
{
    return Flash::get($key, $default);
}

function auth_user(): ?array
{
    return Auth::user();
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function __(string $key, array $replace = []): string
{
    return Translator::get($key, $replace);
}

function current_locale(): string
{
    return Translator::locale();
}

function format_date(?string $date, bool $withTime = false): string
{
    if (!$date) {
        return '—';
    }
    try {
        $format = (string) config('app.date_format', 'd-m-Y') . ($withTime ? ' H:i' : '');
        return (new DateTimeImmutable($date))->format($format);
    } catch (Throwable) {
        return e($date);
    }
}

function money(float|int|string|null $amount): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return '₹' . number_format((float) $amount, 2, '.', ',');
}

function mask_identifier(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $last = substr(preg_replace('/\D+/', '', $value), -4);
    return '•••• •••• ' . $last;
}

function selected(mixed $value, mixed $expected): string
{
    return (string) $value === (string) $expected ? ' selected' : '';
}

function checked(mixed $value): string
{
    return $value ? ' checked' : '';
}
