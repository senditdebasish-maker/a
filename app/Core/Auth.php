<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

    public static function attempt(string $email, string $password): bool
    {
        $email = mb_strtolower(trim($email));
        $db = Database::get();
        $user = $db->fetch('SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1', ['email' => $email]);
        if (!$user || !password_verify($password, (string) $user['password_hash']) || $user['status'] !== 'active') {
            self::recordAttempt($email, false);
            return false;
        }

        self::recordAttempt($email, true);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        Csrf::rotate();
        $db->update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => self::ip()], 'id = :id', ['id' => $user['id']]);
        self::$user = null;
        self::$resolved = false;
        return true;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        self::$user = Database::get()->fetch(
            'SELECT u.*, GROUP_CONCAT(r.slug) AS role_slugs FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL GROUP BY u.id LIMIT 1',
            ['id' => $id]
        );
        if (!self::$user || self::$user['status'] !== 'active') {
            self::logout();
            return null;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function roles(): array
    {
        $roles = (string) (self::user()['role_slugs'] ?? '');
        return array_values(array_filter(explode(',', $roles)));
    }

    public static function hasRole(string|array $roles): bool
    {
        return array_intersect((array) $roles, self::roles()) !== [];
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::hasRole('super-admin')) {
            return true;
        }
        $result = Database::get()->scalar(
            'SELECT COUNT(*) FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :user_id AND p.slug = :permission',
            ['user_id' => self::id(), 'permission' => $permission]
        );
        return (int) $result > 0;
    }

    public static function loginById(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['last_activity'] = time();
        self::$user = null;
        self::$resolved = false;
        Csrf::rotate();
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['mfa_verified_at']);
        self::$user = null;
        self::$resolved = true;
        session_regenerate_id(true);
        Csrf::rotate();
    }

    public static function throttled(string $email): bool
    {
        $minutes = (int) config('security.login_decay_minutes', 15);
        $max = (int) config('security.login_max_attempts', 5);
        $count = Database::get()->scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE (email = :email OR ip_address = :ip) AND successful = 0 AND attempted_at >= :since',
            ['email' => mb_strtolower(trim($email)), 'ip' => self::ip(), 'since' => date('Y-m-d H:i:s', time() - ($minutes * 60))]
        );
        return (int) $count >= $max;
    }

    private static function recordAttempt(string $email, bool $successful): void
    {
        Database::get()->insert('login_attempts', [
            'email' => $email,
            'ip_address' => self::ip(),
            'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
            'successful' => $successful ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function ip(): string
    {
        return mb_substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
    }
}
