<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/includes/bootstrap.php';
require_authentication();

$activeNavigation = 'dashboard';
$pageTitle = 'Dashboard';
$dashboardCounts = [
	'active_flocks' => 0,
	'current_birds' => 0,
	'low_stock_items' => 0,
	'due_or_overdue_activities' => 0,
	'open_health_cases' => 0,
	'eggs_last_7_days' => 0,
];
$dashboardError = null;

try {
	$pdo = database_connection();

	$flockRow = $pdo->query(
		"SELECT COUNT(*) AS active_flocks, COALESCE(SUM(current_birds), 0) AS current_birds
		 FROM flocks WHERE deleted_at IS NULL AND status = 'Active'"
	)->fetch();
	$dashboardCounts['active_flocks'] = (int) $flockRow['active_flocks'];
	$dashboardCounts['current_birds'] = (int) $flockRow['current_birds'];

	$dashboardCounts['low_stock_items'] = (int) $pdo->query(
		"SELECT COUNT(*) FROM poultry_inventory WHERE deleted_at IS NULL AND status IN ('Low Stock', 'Out of Stock')"
	)->fetchColumn();

	$dashboardCounts['due_or_overdue_activities'] = (int) $pdo->query(
		"SELECT COUNT(*) FROM farm_activities
		 WHERE deleted_at IS NULL AND status IN ('Scheduled', 'In Progress') AND scheduled_date <= CURDATE()"
	)->fetchColumn();

	$dashboardCounts['open_health_cases'] = (int) $pdo->query(
		"SELECT COUNT(*) FROM health_records WHERE deleted_at IS NULL AND status IN ('Observed', 'Under Treatment')"
	)->fetchColumn();

	$dashboardCounts['eggs_last_7_days'] = (int) $pdo->query(
		"SELECT COALESCE(SUM(eggs_produced), 0) FROM production_records
		 WHERE deleted_at IS NULL AND record_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
	)->fetchColumn();
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
	<div class="col-sm-6 col-lg-4">
		<a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/flocks/index.php')) ?>">
			<div class="stat-label">Active Flocks</div>
			<div class="stat-value"><?= number_format($dashboardCounts['active_flocks']) ?></div>
		</a>
	</div>
	<div class="col-sm-6 col-lg-4">
		<a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/flocks/index.php')) ?>">
			<div class="stat-label">Current Birds</div>
			<div class="stat-value"><?= number_format($dashboardCounts['current_birds']) ?></div>
		</a>
	</div>
	<div class="col-sm-6 col-lg-4">
		<a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/inventory/index.php')) ?>">
			<div class="stat-label">Low / Out-of-Stock Items</div>
			<div class="stat-value"><?= number_format($dashboardCounts['low_stock_items']) ?></div>
		</a>
	</div>
	<div class="col-sm-6 col-lg-4">
		<a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/activities/index.php')) ?>">
			<div class="stat-label">Due Today / Overdue Activities</div>
			<div class="stat-value"><?= number_format($dashboardCounts['due_or_overdue_activities']) ?></div>
		</a>
	</div>
	<div class="col-sm-6 col-lg-4">
		<a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/health/index.php')) ?>">
			<div class="stat-label">Open Health Cases</div>
			<div class="stat-value"><?= number_format($dashboardCounts['open_health_cases']) ?></div>
		</a>
	</div>
	<div class="col-sm-6 col-lg-4">
		<a class="stat-card d-block text-decoration-none text-reset" href="<?= h(app_url('/pms/production/index.php')) ?>">
			<div class="stat-label">Eggs Produced (Last 7 Days)</div>
			<div class="stat-value"><?= number_format($dashboardCounts['eggs_last_7_days']) ?></div>
		</a>
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
