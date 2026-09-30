<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'public'): void
    {
        $viewFile = BASE_PATH . '/resources/views/' . $view . '.php';
        $layoutFile = BASE_PATH . '/resources/views/layouts/' . $layout . '.php';
        if (!is_file($viewFile) || !is_file($layoutFile)) {
            throw new RuntimeException("View [{$view}] or layout [{$layout}] not found.");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();
        require $layoutFile;
    }
}
