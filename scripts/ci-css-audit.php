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
    'resources/views/layouts/admin.php' => ['app.css', 'ui-polish.css', 'admissions-admin.css', 'admission-wizard.css'],
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
foreach ([':focus-visible', 'prefers-reduced-motion:reduce', 'forced-colors:active', 'select[multiple]', '[data-section-panel][hidden]', '.cms-page-sections'] as $accessibilityRule) {
    if (!str_contains($polish, $accessibilityRule)) $fail("ui-polish.css: expected accessibility/responsive rule missing: {$accessibilityRule}");
}

foreach ([
    'resources/views/student/application.php',
    'resources/views/admin/admissions/show.php',
    'resources/views/admin/applications/show.php',
    'resources/views/admin/settings.php',
] as $workspaceView) {
    $source = file_get_contents($root . '/' . $workspaceView) ?: '';
    if (!str_contains($source, 'data-section-workspace') || !str_contains($source, 'data-section-tabs')) $fail("{$workspaceView}: sliding workspace hooks are missing.");
}
$admissionWizard = file_get_contents($root . '/resources/views/admin/admissions/show.php') ?: '';
foreach (['admission-stepper','id="notice"','id="programmes"','id="rules"','id="fees"','id="form-builder"','id="documents"','id="review-publish"','Publish admission notice'] as $contract) {
    if (!str_contains($admissionWizard, $contract)) $fail("Guided admission wizard is missing {$contract}.");
}
$wizardCss = file_get_contents($cssRoot . '/admission-wizard.css') ?: '';
foreach (['.admission-wizard','.admission-stepper','.wizard-panel-actions','.publication-review-grid','.step-guide','.seat-equation','.eligibility-sentence','.form-design-grid','.form-map-preview','.student-form-preview','.option-help','@media(max-width:760px)'] as $contract) {
    if (!str_contains($wizardCss, $contract)) $fail("admission-wizard.css: expected guided setup rule missing: {$contract}");
}
foreach (['data-seat-matrix','data-seat-balance','data-field-builder','data-field-options','data-key-builder','data-open-editor','student-form-preview'] as $contract) {
    if (!str_contains($admissionWizard, $contract)) $fail("Guided admission setup is missing plain-language editor hook {$contract}.");
}
$javascript = file_get_contents($root . '/public/assets/js/app.js') ?: '';
foreach (['[data-seat-matrix]','[data-seat-balance]','[data-field-builder]','[data-key-builder]','optionFieldTypes','admissionOptionHelp','[data-open-editor]'] as $contract) {
    if (!str_contains($javascript, $contract)) $fail("app.js: guided admission editor contract is missing {$contract}.");
}
foreach (['history.pushState', "addEventListener('popstate'", "setAttribute('aria-selected'", 'panel.hidden'] as $contract) {
    if (!str_contains($javascript, $contract)) $fail("app.js: sliding workspace contract is missing {$contract}.");
}
$workflowIndex = file_get_contents($root . '/resources/views/admin/applications/index.php') ?: '';
$workflowShow = file_get_contents($root . '/resources/views/admin/applications/show.php') ?: '';
foreach (['data-application-board','data-workflow-card','data-application-select','data-confirm-bulk','data-workflow-dialog'] as $contract) {
    if (!str_contains($workflowIndex, $contract)) $fail("Application pipeline view is missing {$contract}.");
}
foreach (['application-lifecycle','guided-review-summary','aria-current="step"'] as $contract) {
    if (!str_contains($workflowShow, $contract)) $fail("Guided application review is missing {$contract}.");
}
foreach (['dragstart','dataset.dropStatus','showModal','requestSubmit','data-confirm-bulk'] as $contract) {
    if (!str_contains($javascript, $contract)) $fail("app.js: graphical application workflow contract is missing {$contract}.");
}
foreach (['data-auto-upload','X-Requested-With','new FormData(form)','is-uploading'] as $contract) {
    if (!str_contains($javascript, $contract)) $fail("app.js: automatic document persistence contract is missing {$contract}.");
}
$applicantApplication = file_get_contents($root . '/resources/views/student/application.php') ?: '';
foreach (['$configuredFieldsByKey','$fieldLabel','$fieldHint','$sectionTitle','$sectionDescription'] as $contract) {
    if (!str_contains($applicantApplication, $contract)) $fail("Applicant form is missing configurable student-view contract {$contract}.");
}
foreach (['Save & next','data-auto-upload','reapply-prompt','continue_to'] as $contract) {
    if (!str_contains($applicantApplication, $contract)) $fail("Applicant step form is missing {$contract}.");
}
foreach (['.application-board','.workflow-column','.workflow-card','.bulk-workflow-bar','.application-lifecycle','.guided-review-summary'] as $contract) {
    if (!str_contains($polish, $contract)) $fail("ui-polish.css: graphical workflow rule is missing {$contract}.");
}
foreach (['database/migrations/004_cms_page_builder.php','database/migrations/005_application_reapply_attempts.php','resources/views/public/sections.php','resources/views/admin/cms/section-fields.php'] as $builderFile) {
    if (!is_file($root . '/' . $builderFile)) $fail("CMS page builder file is missing: {$builderFile}");
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL {$failure}\n");
    exit(1);
}

echo 'CSS audit passed: ' . count($cssFiles) . " stylesheets, {$totalBytes} bytes, {$totalReferences} local references, " . count($layouts) . " production layouts.\n";
