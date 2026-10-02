<?php
$publicSettings = $siteSettings ?? [];
$collegeName = $publicSettings['college_name'] ?? 'Netaji College of Pharmacy';
$collegePhone = $publicSettings['college_phone'] ?? '+91 33 4000 2027';
$collegeEmail = $publicSettings['college_email'] ?? 'admissions@example.edu.in';
$collegeAddress = $publicSettings['college_address'] ?? "New Town, Kolkata\nWest Bengal 700156";
?>
<!doctype html>
<html lang="<?= e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($metaDescription ?? 'Netaji College of Pharmacy — pharmaceutical learning, research and admissions in Kolkata.') ?>">
    <meta name="theme-color" content="#075e61">
    <title><?= e($title ?? config('app.name')) ?> | <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= asset('images/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/ui-polish.css') ?>">
</head>
<body class="public-site">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="utility-bar"><div class="container utility-inner"><span>Admissions help: <a href="tel:<?= e(preg_replace('/[^+0-9]/','',$collegePhone)) ?>"><?= e($collegePhone) ?></a></span><span class="utility-links"><a href="<?= url('notices') ?>">Notices</a><a href="<?= url('faq') ?>">FAQ</a><span class="language-switcher"><a class="<?= current_locale()==='en'?'active':'' ?>" href="<?= url('language/en') ?>">EN</a><a class="<?= current_locale()==='bn'?'active':'' ?>" href="<?= url('language/bn') ?>">বাংলা</a><a class="<?= current_locale()==='hi'?'active':'' ?>" href="<?= url('language/hi') ?>">हिं</a></span></span></div></div>
<header class="site-header" data-header><div class="container nav-wrap">
    <a class="brand" href="<?= url() ?>" aria-label="<?= e($collegeName) ?> home"><span class="brand-mark"><span>N</span><i>Rx</i></span><span class="brand-copy"><strong>Netaji</strong><small>College of Pharmacy</small></span></a>
    <button class="menu-toggle" type="button" aria-label="Open navigation" aria-expanded="false" data-menu-toggle><span></span><span></span><span></span></button>
    <nav class="main-nav" data-menu><a href="<?= url() ?>"><?= __('nav.home') ?></a><a href="<?= url('about') ?>"><?= __('nav.about') ?></a><a href="<?= url('programs') ?>"><?= __('nav.programs') ?></a><a href="<?= url('admissions') ?>"><?= __('nav.admissions') ?></a><div class="nav-dropdown"><button type="button"><?= __('nav.campus') ?> <span>⌄</span></button><div><a href="<?= url('facilities') ?>"><?= __('nav.facilities') ?></a><a href="<?= url('faculty') ?>"><?= __('nav.faculty') ?></a><a href="<?= url('gallery') ?>"><?= __('nav.gallery') ?></a></div></div><a href="<?= url('contact') ?>"><?= __('nav.contact') ?></a></nav>
    <div class="nav-actions"><a class="portal-link" href="<?= auth_user() ? url(App\Core\Auth::hasRole('applicant') ? 'student/dashboard' : 'admin/dashboard') : url('login') ?>"><svg aria-hidden="true"><use href="#icon-user"></use></svg><?= __('nav.portal') ?></a><a class="button button-sm button-gold" href="<?= url('admissions') ?>"><?= __('nav.apply') ?></a></div>
</div></header>
<?php if (flash('success') || flash('warning')): ?><div class="flash-bar <?= flash('success') ? 'success' : 'warning' ?>"><div class="container"><?= e(flash('success') ?? flash('warning')) ?><button type="button" data-dismiss aria-label="Dismiss">×</button></div></div><?php endif ?>
<main id="main-content"><?= $content ?><?php if (!empty($cmsSections)): require BASE_PATH . '/resources/views/public/sections.php'; endif; ?></main>
<footer class="site-footer"><div class="container footer-grid"><div class="footer-brand"><a class="brand brand-light" href="<?= url() ?>"><span class="brand-mark"><span>N</span><i>Rx</i></span><span class="brand-copy"><strong>Netaji</strong><small>College of Pharmacy</small></span></a><p><?= __('footer.tagline') ?></p><div class="approval-note"><?= __('footer.demo') ?></div></div><div><h3>Explore</h3><a href="<?= url('about') ?>">About the college</a><a href="<?= url('programs') ?>">B.Pharm programme</a><a href="<?= url('faculty') ?>">Faculty</a><a href="<?= url('facilities') ?>">Facilities</a></div><div><h3>Admissions</h3><a href="<?= url('admissions') ?>">How to apply</a><a href="<?= url('admissions#eligibility') ?>">Eligibility</a><a href="<?= url('faq') ?>">Applicant FAQ</a><a href="<?= url('login') ?>">Track application</a></div><div><h3>Connect</h3><p><?= nl2br(e($collegeAddress)) ?></p><a href="mailto:<?= e($collegeEmail) ?>"><?= e($collegeEmail) ?></a><a href="tel:<?= e(preg_replace('/[^+0-9]/','',$collegePhone)) ?>"><?= e($collegePhone) ?></a></div></div><div class="container footer-bottom"><span>© <?= date('Y') ?> <?= e($collegeName) ?></span><span><a href="<?= url('privacy') ?>">Privacy</a><a href="<?= url('terms') ?>">Terms</a><a href="<?= url('contact') ?>">Contact</a></span></div></footer>
<svg class="svg-sprite" aria-hidden="true"><symbol id="icon-user" viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol><symbol id="icon-arrow" viewBox="0 0 24 24"><path d="m5 12 14 0m-5-5 5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol></svg>
<script src="<?= asset('js/app.js') ?>" defer></script>
</body></html>
