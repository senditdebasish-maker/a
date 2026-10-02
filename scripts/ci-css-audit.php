<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cssRoot = $root . '/public/assets/css';
$failures = [];
$cssFiles = glob($cssRoot . '/*.css') ?: [];
sort($cssFiles);

$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

$scanBalanced = static function (string $source, string $name) use ($fail): void {
    $curly = 0;
    $round = 0;
    $square = 0;
    $quote = null;
    $comment = false;
    $escaped = false;
    $length = strlen($source);

    for ($index = 0; $index < $length; $index++) {
        $character = $source[$index];
        $next = $index + 1 < $length ? $source[$index + 1] : '';

        if ($comment) {
            if ($character === '*' && $next === '/') {
                $comment = false;
                $index++;
            }
            continue;
        }
        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === '/' && $next === '*') {
            $comment = true;
            $index++;
            continue;
        }
        if ($character === '"' || $character === "'") {
            $quote = $character;
            continue;
        }

        if ($character === '{') $curly++;
        if ($character === '}') {
            $curly--;
            if ($curly < 0) {
                $fail("{$name}: closing brace appears before an opening brace.");
                $curly = 0;
            }
        }
        if ($character === '(') $round++;
        if ($character === ')') {
            $round--;
            if ($round < 0) {
                $fail("{$name}: closing parenthesis appears before an opening parenthesis.");
                $round = 0;
            }
        }
        if ($character === '[') $square++;
        if ($character === ']') {
            $square--;
            if ($square < 0) {
                $fail("{$name}: closing bracket appears before an opening bracket.");
                $square = 0;
            }
        }
    }

    if ($comment) $fail("{$name}: unclosed CSS comment.");
    if ($quote !== null) $fail("{$name}: unclosed quoted string.");
    if ($curly !== 0) $fail("{$name}: unbalanced braces ({$curly}).");
    if ($round !== 0) $fail("{$name}: unbalanced parentheses ({$round}).");
    if ($square !== 0) $fail("{$name}: unbalanced brackets ({$square}).");
};

if (count($cssFiles) < 1) $fail('No CSS assets were found.');
$totalBytes = 0;
$totalReferences = 0;
foreach ($cssFiles as $file) {
    $relative = str_replace($root . '/', '', $file);
    $source = file_get_contents($file);
    if ($source === false || trim($source) === '') {
        $fail("{$relative}: file is empty or unreadable.");
        continue;
    }
    $totalBytes += strlen($source);
    $scanBalanced($source, $relative);

    if (preg_match_all("~url\\(\\s*([\"']?)([^)\"']+)\\1\\s*\\)~i", $source, $matches)) {
        foreach ($matches[2] as $reference) {
            $reference = trim($reference);
            if ($reference === '' || preg_match('#^(?:data:|https?:|//|#|var\()#i', $reference)) continue;
            $reference = preg_split('/[?#]/', $reference, 2)[0];
            $target = realpath(dirname($file) . '/' . $reference);
            $totalReferences++;
            if ($target === false || !is_file($target)) $fail("{$relative}: referenced asset does not exist: {$reference}");
        }
    }
}

$layouts = [
    'resources/views/layouts/public.php' => ['app.css', 'ui-polish.css'],
    'resources/views/layouts/auth.php' => ['app.css', 'ui-polish.css'],
    'resources/views/layouts/student.php' => ['app.css', 'ui-polish.css'],
    'resources/views/layouts/admin.php' => ['app.css', 'ui-polish.css', 'admissions-admin.css'],
];
foreach ($layouts as $layout => $requiredAssets) {
    $source = file_get_contents($root . '/' . $layout);
    if ($source === false) {
        $fail("Missing layout: {$layout}");
        continue;
    }
    foreach ($requiredAssets as $asset) {
        if (!str_contains($source, "asset('css/{$asset}')")) $fail("{$layout}: does not load {$asset}.");
        if (!is_file($cssRoot . '/' . $asset)) $fail("{$layout}: stylesheet does not exist: {$asset}.");
    }
}

$polish = file_get_contents($cssRoot . '/ui-polish.css') ?: '';
foreach ([':focus-visible', 'prefers-reduced-motion:reduce', 'forced-colors:active', 'select[multiple]'] as $accessibilityRule) {
    if (!str_contains($polish, $accessibilityRule)) $fail("ui-polish.css: expected accessibility/responsive rule missing: {$accessibilityRule}");
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL {$failure}\n");
    exit(1);
}

echo 'CSS audit passed: ' . count($cssFiles) . " stylesheets, {$totalBytes} bytes, {$totalReferences} local references, " . count($layouts) . " production layouts.\n";
