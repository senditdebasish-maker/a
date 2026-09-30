<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $loaded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        [$file, $nested] = array_pad(explode('.', $key, 2), 2, null);
        if (!array_key_exists($file, self::$loaded)) {
            $path = BASE_PATH . '/config/' . $file . '.php';
            self::$loaded[$file] = is_file($path) ? require $path : [];
        }
        if ($nested === null) {
            return self::$loaded[$file] ?? $default;
        }
        $value = self::$loaded[$file];
        foreach (explode('.', $nested) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
