<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;

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
        unset($_SESSION['user_id'], $_SESSION['email_otp_verified_at'], $_SESSION['email_otp_pending_email'], $_SESSION['email_otp_requested_at'], $_SESSION['email_otp_pending_user_id'], $_SESSION['email_otp_challenge_id']);
        self::$user = null;
        self::$resolved = true;
        session_regenerate_id(true);
        Csrf::rotate();
    }
}
