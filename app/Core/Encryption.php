<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Encryption
{
    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return $plaintext;
        }
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt sensitive data.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 29) {
            throw new RuntimeException('Invalid encrypted value.');
        }
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt sensitive data.');
        }
        return $plain;
    }

    private static function key(): string
    {
        $configured = (string) config('app.key', '');
        if (str_starts_with($configured, 'base64:')) {
            $key = base64_decode(substr($configured, 7), true);
        } else {
            $key = hash('sha256', $configured, true);
        }
        if (!is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('APP_KEY is missing or invalid.');
        }
        return substr($key, 0, 32);
    }
}
