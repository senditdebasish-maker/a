<?php

declare(strict_types=1);

namespace App\Core;

final class Translator
{
    private static array $lines = [];

    public static function locale(): string
    {
        $allowed = array_keys((array) Config::get('app.supported_locales', ['en' => 'English']));
        $locale = $_SESSION['locale'] ?? Config::get('app.locale', 'en');
        return in_array($locale, $allowed, true) ? $locale : 'en';
    }

    public static function setLocale(string $locale): bool
    {
        if (!array_key_exists($locale, (array) Config::get('app.supported_locales', []))) {
            return false;
        }
        $_SESSION['locale'] = $locale;
        return true;
    }

    public static function get(string $key, array $replace = []): string
    {
        $locale = self::locale();
        self::load($locale);
        $line = self::$lines[$locale][$key] ?? null;
        if ($line === null && $locale !== 'en') {
            self::load('en');
            $line = self::$lines['en'][$key] ?? null;
        }
        $line ??= $key;
        foreach ($replace as $name => $value) {
            $line = str_replace(':' . $name, (string) $value, $line);
        }
        return $line;
    }

    private static function load(string $locale): void
    {
        if (isset(self::$lines[$locale])) {
            return;
        }
        $file = BASE_PATH . '/resources/lang/' . $locale . '.php';
        self::$lines[$locale] = is_file($file) ? require $file : [];
    }
}
