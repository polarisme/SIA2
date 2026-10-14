<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/includes/bootstrap.php';
require_authentication();

$activeNavigation = 'dashboard';
$pageTitle = 'Dashboard';
$dashboardCounts = ['flocks' => 0, 'inventory' => 0];
$dashboardError = null;

try {
	$pdo = database_connection();
	$dashboardCounts['flocks'] = (int) $pdo->query("SELECT COUNT(*) FROM flocks WHERE status = 'Active'")->fetchColumn();
	$dashboardCounts['inventory'] = (int) $pdo->query('SELECT COUNT(*) FROM poultry_inventory')->fetchColumn();
} catch (PDOException $exception) {
	error_log('Dashboard summary query failed: ' . $exception->getMessage());
	$dashboardError = 'Some dashboard totals are unavailable. Check the database connection.';
}

require __DIR__ . '/../shared/includes/header.php';
?>
<div class="page-heading">
	<div class="eyebrow">POULTRY MANAGEMENT</div>
	<h1>Dashboard</h1>
	<p>Farm operations at a glance.</p>
</div>

<?php if ($dashboardError !== null): ?><div class="alert alert-warning" role="status"><?= h($dashboardError) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
	<div class="col-sm-6">
		<div class="stat-card">
			<div class="stat-label">Active flocks</div>
			<div class="stat-value"><?= number_format($dashboardCounts['flocks']) ?></div>
		</div>
	</div>
	<div class="col-sm-6">
		<div class="stat-card">
			<div class="stat-label">Inventory items</div>
			<div class="stat-value"><?= number_format($dashboardCounts['inventory']) ?></div>
		</div>
	</div>
</div>

<section class="mb-4" aria-labelledby="modules-heading">
	<div class="panel-heading px-0"><h2 class="h5 mb-0" id="modules-heading">Management</h2></div>
	<div class="row g-3 pt-3">
		<div class="col-lg-6">
			<a class="dashboard-module-link" href="<?= h(app_url('/pms/flocks/index.php')) ?>">
				<span><strong class="d-block">Flock Management</strong><small class="text-secondary">View and maintain flock records</small></span>
				<i class="bi bi-egg-fried" aria-hidden="true"></i>
			</a>
		</div>
		<div class="col-lg-6">
			<a class="dashboard-module-link" href="<?= h(app_url('/pms/inventory/index.php')) ?>">
				<span><strong class="d-block">Inventory Management</strong><small class="text-secondary">Monitor supplies and stock levels</small></span>
				<i class="bi bi-box-seam" aria-hidden="true"></i>
			</a>
		</div>
	</div>
</section>

<?php require __DIR__ . '/../shared/includes/footer.php'; ?>