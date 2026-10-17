<?php
$activeSystem = $activeSystem ?? 'pms';
$pageTitle = $pageTitle ?? 'Flock Management';
$flash = take_flash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | <?= h($appBrandName ?? 'YIELD Poultry Management System') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= h(versioned_asset_url('/shared/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="theme-<?= h(str_replace('_', '-', $activeSystem)) ?><?= isset($pageBodyClass) ? ' ' . h($pageBodyClass) : '' ?>">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="app-shell">
<header class="topbar">
    <div class="container-fluid app-width d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <button class="sidebar-toggle btn btn-outline-secondary" type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <span class="topbar-title"><?= h($pageTitle) ?></span>
        </div>
        <div class="d-flex align-items-center gap-3">
            <?php if (is_authenticated()): ?>
                <span class="section-label d-none d-sm-inline"><i class="bi bi-person-check" aria-hidden="true"></i> Signed in as <?= h(current_username()) ?></span>
                <form method="post" action="<?= h(app_url('/shared/auth/logout.php')) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Sign out</button>
                </form>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-primary" href="<?= h(app_url('/shared/auth/login.php')) ?>">Sign in</a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main class="container-fluid app-width py-4 py-lg-5">
    <?php if (isset($printReportTitle)): ?>
        <div class="print-report-header">
            <h1><?= h($printReportTitle) ?></h1>
            <p>Printed: <?= h(date('Y-m-d H:i:s')) ?> by <?= h(current_display_name()) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($flash !== null): ?>
        <?php $alertType = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info'; ?>
        <div class="alert alert-<?= h($alertType) ?> alert-dismissible fade show" role="alert">
            <?= h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>