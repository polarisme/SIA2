<?php
declare(strict_types=1);

// Public entry point. Authenticated users go straight to their own system's
// dashboard (routing by system_access, not a hardcoded system — landing here
// while already authenticated for AIS/Integrated/User Management used to
// bounce through /pms/index.php, which require_authentication() would then
// reject as unauthorized and redirect back here, looping); everyone else sees
// only this landing page (no PMS/AIS/Integrated data of any kind is rendered here).
require_once __DIR__ . '/shared/includes/bootstrap.php';

if (is_authenticated()) {
    $ownDashboard = [
        'pms' => '/pms/index.php',
        'ais' => '/ais/index.php',
        'integrated' => '/integrated/index.php',
        'user_management' => '/shared/user-management/dashboard.php',
    ][$_SESSION['user']['system_access'] ?? ''] ?? '/pms/index.php';
    redirect_to(app_url($ownDashboard));
}

$pageTitle = 'YIELD';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | Poultry Farm Management Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= h(versioned_asset_url('/shared/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="public-body">
<header class="public-nav">
    <div class="public-nav-inner container-fluid app-width">
        <span class="public-brand">
            <a class="public-brand-icon" href="<?= h(app_url('/shared/user-management/dashboard.php')) ?>" aria-label="YIELD">
                <i class="bi bi-flower1" aria-hidden="true"></i>
            </a>
            YIELD
        </span>
    </div>
</header>

<main>
    <section class="hero">
        <div class="hero-inner container-fluid app-width">
            <p class="hero-eyebrow">Poultry Farm Technology Platform</p>
            <h1 class="hero-title">YIELD</h1>
            <p class="hero-subtitle">Poultry Farm Management Platform</p>
            <p class="hero-description">
                YIELD brings together three connected systems that help poultry farm
                operations run efficiently, transparently, and profitably: poultry
                management, accounting information, and integrated farm operations
                and accounting.
            </p>
        </div>
        <i class="bi bi-flower1 hero-decor" aria-hidden="true"></i>
    </section>

    <section class="systems container-fluid app-width">
        <div class="systems-grid">
            <article class="system-card system-card--pms">
                <span class="system-card-icon" aria-hidden="true"><img class="yield-brand-logo system-card-logo" src="<?= h(logo_url('pms')) ?>" alt=""></span>
                <h2>Poultry Management System</h2>
                <p class="section-label mb-2">For: <?= h(SYSTEM_ROLE_MAP['pms']) ?></p>
                <p>Manage poultry flocks, inventory, farm activities, production, and poultry health.</p>
                <a class="btn btn-primary system-card-btn" href="<?= h(app_url('/pms/index.php')) ?>">Access System</a>
            </article>
            <article class="system-card system-card--ais">
                <span class="system-card-icon" aria-hidden="true"><img class="yield-brand-logo system-card-logo" src="<?= h(logo_url('ais')) ?>" alt=""></span>
                <h2>Accounting Information System</h2>
                <p class="section-label mb-2">For: <?= h(SYSTEM_ROLE_MAP['ais']) ?></p>
                <p>Manage financial transactions, accounts receivable, accounts payable, and financial reports.</p>
                <a class="btn btn-primary system-card-btn" href="<?= h(app_url('/ais/index.php')) ?>">Access System</a>
            </article>
            <article class="system-card system-card--integrated">
                <span class="system-card-icon" aria-hidden="true"><img class="yield-brand-logo system-card-logo" src="<?= h(logo_url('integrated')) ?>" alt=""></span>
                <h2>Integrated Poultry Farm Operations &amp; Accounting Management System</h2>
                <p class="section-label mb-2">For: <?= h(SYSTEM_ROLE_MAP['integrated']) ?></p>
                <p>Connect operational and financial information to support farm analysis and decision-making.</p>
                <a class="btn btn-primary system-card-btn" href="<?= h(app_url('/integrated/index.php')) ?>">Access System</a>
            </article>
        </div>
    </section>
</main>

<footer class="public-footer">
    <div class="container-fluid app-width">&copy; <?= h(date('Y')) ?> YIELD Poultry Farm Management Platform</div>
</footer>
</body>
</html>
