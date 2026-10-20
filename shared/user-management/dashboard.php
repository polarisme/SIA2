<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_authentication();

$activeSystem = 'user_management';
$activeNavigation = 'user_management_dashboard';
$appBrandName = 'YIELD User Management';
$appFooterName = 'YIELD User Management';
$pageTitle = 'IT Admin Dashboard';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('IT Admin dashboard database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

// --- Reporting period (same pattern as the AIS dashboard) --------------------------
$range = (string) ($_GET['range'] ?? 'month');
if (!in_array($range, ['today', 'week', 'month', 'year', 'custom'], true)) {
    $range = 'month';
}
$today = new DateTimeImmutable('today');
$customFrom = trim((string) ($_GET['date_from'] ?? ''));
$customTo = trim((string) ($_GET['date_to'] ?? ''));
switch ($range) {
    case 'today':
        $rangeFrom = $today;
        $rangeTo = $today;
        break;
    case 'week':
        $rangeFrom = $today->modify('monday this week');
        $rangeTo = $today;
        break;
    case 'year':
        $rangeFrom = $today->modify('first day of january this year');
        $rangeTo = $today;
        break;
    case 'custom':
        $rangeFrom = ais_valid_date($customFrom) ? new DateTimeImmutable($customFrom) : $today->modify('first day of this month');
        $rangeTo = ais_valid_date($customTo) ? new DateTimeImmutable($customTo) : $today;
        if ($rangeFrom > $rangeTo) {
            [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
        }
        break;
    case 'month':
    default:
        $rangeFrom = $today->modify('first day of this month');
        $rangeTo = $today;
        break;
}
$dateFrom = $rangeFrom->format('Y-m-d') . ' 00:00:00';
$dateTo = $rangeTo->format('Y-m-d') . ' 23:59:59';

$counts = ['total' => 0, 'active' => 0, 'inactive' => 0, 'pms' => 0, 'ais' => 0, 'integrated' => 0, 'user_management' => 0];
$recentAccountChanges = [];
$recentSignInActivity = [];
$recentPasswordResets = [];
$recentActivation = [];
$failedLoginCount = 0;
$accountChangeCount = 0;
$signInTrend = [];
$activityTrend = [];
$repeatedFailures = [];

if ($pdo instanceof PDO && $databaseError === null) {
    try {
        $row = $pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'Active') AS active,
                    SUM(status = 'Inactive') AS inactive,
                    SUM(system_access = 'pms') AS pms,
                    SUM(system_access = 'ais') AS ais,
                    SUM(system_access = 'integrated') AS integrated,
                    SUM(system_access = 'user_management') AS user_management
             FROM users WHERE deleted_at IS NULL"
        )->fetch();
        $counts = array_map('intval', $row);

        $recentAccountChanges = $pdo->query(
            "SELECT * FROM user_management_audit_log
             WHERE action IN ('USER_CREATED','USER_EDITED','USER_ACTIVATED','USER_DEACTIVATED','USER_DELETED','USER_RESTORED','USER_PERMANENTLY_DELETED','PASSWORD_RESET')
             ORDER BY event_time DESC LIMIT 5"
        )->fetchAll();

        $recentSignInActivity = $pdo->query(
            "SELECT * FROM user_management_audit_log
             WHERE action IN ('LOGIN','LOGIN_FAILED','UNAUTHORIZED_ACCESS')
             ORDER BY event_time DESC LIMIT 5"
        )->fetchAll();

        $recentPasswordResets = $pdo->query(
            "SELECT * FROM user_management_audit_log WHERE action = 'PASSWORD_RESET' ORDER BY event_time DESC LIMIT 5"
        )->fetchAll();

        $recentActivation = $pdo->query(
            "SELECT * FROM user_management_audit_log WHERE action IN ('USER_ACTIVATED','USER_DEACTIVATED') ORDER BY event_time DESC LIMIT 5"
        )->fetchAll();

        $periodStatement = $pdo->prepare(
            "SELECT COUNT(*) FROM user_management_audit_log WHERE action = 'LOGIN_FAILED' AND event_time BETWEEN ? AND ?"
        );
        $periodStatement->execute([$dateFrom, $dateTo]);
        $failedLoginCount = (int) $periodStatement->fetchColumn();

        $changeStatement = $pdo->prepare(
            "SELECT COUNT(*) FROM user_management_audit_log
             WHERE action IN ('USER_CREATED','USER_EDITED','USER_ACTIVATED','USER_DEACTIVATED','USER_DELETED','USER_RESTORED','USER_PERMANENTLY_DELETED','PASSWORD_RESET')
             AND event_time BETWEEN ? AND ?"
        );
        $changeStatement->execute([$dateFrom, $dateTo]);
        $accountChangeCount = (int) $changeStatement->fetchColumn();

        // Successful vs failed sign-ins, grouped by day, within the selected period.
        $signInStatement = $pdo->prepare(
            "SELECT DATE(event_time) AS d, action, COUNT(*) AS n FROM user_management_audit_log
             WHERE action IN ('LOGIN', 'LOGIN_FAILED') AND event_time BETWEEN ? AND ?
             GROUP BY d, action ORDER BY d"
        );
        $signInStatement->execute([$dateFrom, $dateTo]);
        $signInMap = [];
        foreach ($signInStatement->fetchAll() as $row) {
            $signInMap[$row['d']][$row['action']] = (int) $row['n'];
        }
        ksort($signInMap);
        foreach ($signInMap as $day => $values) {
            $signInTrend[] = ['label' => $day, 'success' => $values['LOGIN'] ?? 0, 'failed' => $values['LOGIN_FAILED'] ?? 0];
        }

        // Account change activity (CRUD events) by day, within the selected period.
        $activityStatement = $pdo->prepare(
            "SELECT DATE(event_time) AS d, COUNT(*) AS n FROM user_management_audit_log
             WHERE action IN ('USER_CREATED','USER_EDITED','USER_ACTIVATED','USER_DEACTIVATED','USER_DELETED','USER_RESTORED','USER_PERMANENTLY_DELETED','PASSWORD_RESET')
             AND event_time BETWEEN ? AND ? GROUP BY d ORDER BY d"
        );
        $activityStatement->execute([$dateFrom, $dateTo]);
        foreach ($activityStatement->fetchAll() as $row) {
            $activityTrend[] = ['label' => $row['d'], 'value' => (int) $row['n']];
        }

        // Repeated failed sign-in attempts against the same account, within the period —
        // 3 or more failures is treated as worth a manual review, not an automatic lockout
        // (this application has no lockout mechanism; this is informational only).
        $repeatedStatement = $pdo->prepare(
            "SELECT COALESCE(target_username, description) AS who, COUNT(*) AS attempts, MAX(event_time) AS last_attempt
             FROM user_management_audit_log
             WHERE action = 'LOGIN_FAILED' AND event_time BETWEEN ? AND ?
             GROUP BY who HAVING attempts >= 3 ORDER BY attempts DESC LIMIT 5"
        );
        $repeatedStatement->execute([$dateFrom, $dateTo]);
        $repeatedFailures = $repeatedStatement->fetchAll();
    } catch (PDOException $exception) {
        error_log('IT Admin dashboard query failed: ' . $exception->getMessage());
        $databaseError = 'Dashboard figures could not be loaded because of a database error.';
    }
}

function user_mgmt_audit_badge(string $action): string
{
    return match ($action) {
        'USER_CREATED', 'USER_RESTORED', 'USER_ACTIVATED', 'LOGIN' => 'success',
        'USER_EDITED', 'PASSWORD_RESET' => 'primary',
        'USER_DEACTIVATED' => 'secondary',
        'USER_DELETED', 'USER_PERMANENTLY_DELETED', 'LOGIN_FAILED', 'UNAUTHORIZED_ACCESS' => 'danger',
        default => 'secondary',
    };
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">IT ADMINISTRATION</div>
    <h1>IT Admin Dashboard</h1>
    <p>Account status, security monitoring, and audit activity at a glance.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<section class="content-panel mb-3">
    <div class="panel-body">
        <form method="get" action="dashboard.php" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="range">Reporting period (security activity)</label>
                <select class="form-select" id="range" name="range" onchange="document.getElementById('customRangeFields').classList.toggle('d-none', this.value !== 'custom')">
                    <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $range === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="year" <?= $range === 'year' ? 'selected' : '' ?>>This Year</option>
                    <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                </select>
            </div>
            <div class="col-md-6" id="customRangeFields"<?= $range === 'custom' ? '' : ' class="col-md-6 d-none"' ?>>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label" for="date_from">From</label><input class="form-control" type="date" id="date_from" name="date_from" value="<?= h($customFrom) ?>"></div>
                    <div class="col-6"><label class="form-label" for="date_to">To</label><input class="form-control" type="date" id="date_to" name="date_to" value="<?= h($customTo) ?>"></div>
                </div>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply</button></div>
        </form>
        <p class="small text-secondary mt-2 mb-0">Account totals below are current as of now; sign-in and account-change activity reflect <?= h($rangeFrom->format('Y-m-d')) ?> to <?= h($rangeTo->format('Y-m-d')) ?>.</p>
    </div>
</section>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3">
        <a class="stat-card d-block text-decoration-none text-reset" href="index.php">
            <div class="stat-label">Total Registered Accounts</div>
            <div class="stat-value"><?= number_format($counts['total']) ?></div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a class="stat-card d-block text-decoration-none text-reset" href="index.php">
            <div class="stat-label">Active / Inactive</div>
            <div class="stat-value"><span class="text-success"><?= number_format($counts['active']) ?></span> / <?= number_format($counts['inactive']) ?></div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a class="stat-card d-block text-decoration-none text-reset" href="audit-trail.php?action_filter=LOGIN_FAILED">
            <div class="stat-label">Failed Sign-Ins (selected period)</div>
            <div class="stat-value <?= $failedLoginCount > 0 ? 'text-danger' : '' ?>"><?= number_format($failedLoginCount) ?></div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a class="stat-card d-block text-decoration-none text-reset" href="audit-trail.php">
            <div class="stat-label">Account Changes (selected period)</div>
            <div class="stat-value"><?= number_format($accountChangeCount) ?></div>
        </a>
    </div>
</div>

<?php if ($repeatedFailures !== []): ?>
<div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3" role="alert">
    <span><i class="bi bi-shield-exclamation" aria-hidden="true"></i> <strong><?= count($repeatedFailures) ?></strong> account(s) with 3 or more failed sign-in attempts in the selected period — review recommended.</span>
    <a class="btn btn-sm btn-outline-danger" href="audit-trail.php?action_filter=LOGIN_FAILED">Review Failed Sign-Ins</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-4">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Accounts by System</h2></div>
            <div class="panel-body">
                <?php if ($counts['total'] === 0): ?>
                    <p class="text-secondary mb-0">No accounts recorded yet.</p>
                <?php else: ?>
                    <canvas id="systemDonut" height="180" role="img" aria-label="Accounts by assigned system"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="content-panel h-100">
            <div class="panel-heading"><h2 class="h5 mb-0">Successful vs Failed Sign-Ins</h2></div>
            <div class="panel-body">
                <?php if ($signInTrend === []): ?>
                    <p class="text-secondary mb-0">No sign-in activity recorded in this period.</p>
                <?php else: ?>
                    <canvas id="signInChart" height="140" role="img" aria-label="Successful versus failed sign-ins per day"></canvas>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<section class="content-panel mb-3">
    <div class="panel-heading"><h2 class="h5 mb-0">Account Activity Over Time</h2></div>
    <div class="panel-body">
        <?php if ($activityTrend === []): ?>
            <p class="text-secondary mb-0">No account changes recorded in this period.</p>
        <?php else: ?>
            <canvas id="activityChart" height="120" role="img" aria-label="Account changes per day"></canvas>
        <?php endif; ?>
    </div>
</section>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Security Activity</h2>
                <a class="small" href="audit-trail.php">View full audit trail &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($recentSignInActivity === []): ?>
                            <tr><td class="text-center text-secondary py-3">No sign-in activity recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentSignInActivity as $event): ?>
                                <tr>
                                    <td>
                                        <span class="badge text-bg-<?= user_mgmt_audit_badge($event['action']) ?>"><?= h(str_replace('_', ' ', $event['action'])) ?></span>
                                        <div class="small text-secondary mt-1"><?= h((string) ($event['target_username'] ?? $event['actor_username'] ?? 'unknown')) ?> &middot; <?= h((string) $event['system_context']) ?> &middot; <?= h((string) $event['event_time']) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Recent Account Changes</h2>
                <a class="small" href="audit-trail.php">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($recentAccountChanges === []): ?>
                            <tr><td class="text-center text-secondary py-3">No account changes recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentAccountChanges as $event): ?>
                                <tr>
                                    <td>
                                        <span class="badge text-bg-<?= user_mgmt_audit_badge($event['action']) ?>"><?= h(str_replace('_', ' ', $event['action'])) ?></span>
                                        <div class="small text-secondary mt-1"><?= h((string) $event['target_username']) ?> &middot; by <?= h((string) $event['actor_username']) ?> &middot; <?= h((string) $event['event_time']) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Recent Password Resets</h2>
                <a class="small" href="audit-trail.php?action_filter=PASSWORD_RESET">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($recentPasswordResets === []): ?>
                            <tr><td class="text-center text-secondary py-3">No password resets recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentPasswordResets as $event): ?>
                                <tr>
                                    <td>
                                        <strong><?= h((string) $event['target_username']) ?></strong>
                                        <div class="small text-secondary mt-1">by <?= h((string) $event['actor_username']) ?> &middot; <?= h((string) $event['event_time']) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="content-panel h-100">
            <div class="panel-heading d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Recent Activation / Deactivation</h2>
                <a class="small" href="audit-trail.php">View all &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <?php if ($recentActivation === []): ?>
                            <tr><td class="text-center text-secondary py-3">No activation/deactivation events recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentActivation as $event): ?>
                                <tr>
                                    <td>
                                        <span class="badge text-bg-<?= user_mgmt_audit_badge($event['action']) ?>"><?= h(str_replace('_', ' ', $event['action'])) ?></span>
                                        <div class="small text-secondary mt-1"><?= h((string) $event['target_username']) ?> &middot; by <?= h((string) $event['actor_username']) ?> &middot; <?= h((string) $event['event_time']) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<section class="content-panel">
    <div class="panel-heading"><h2 class="h5 mb-0">Quick Actions</h2></div>
    <div class="panel-body d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="create.php">Create User</a>
        <a class="btn btn-outline-secondary" href="index.php">Manage Users</a>
        <a class="btn btn-outline-secondary" href="audit-trail.php">View Audit Trail</a>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
<?php if ($counts['total'] > 0): ?>
new Chart(document.getElementById('systemDonut'), {
    type: 'doughnut',
    data: {
        labels: ['PMS', 'AIS', 'Integrated', 'IT Admin'],
        datasets: [{
            data: [<?= $counts['pms'] ?>, <?= $counts['ais'] ?>, <?= $counts['integrated'] ?>, <?= $counts['user_management'] ?>],
            backgroundColor: ['#ed7e04', '#0256c4', '#4fae72', '#a0a0a0']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
});
<?php endif; ?>
<?php if ($signInTrend !== []): ?>
new Chart(document.getElementById('signInChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($signInTrend, 'label')) ?>,
        datasets: [
            { label: 'Successful', data: <?= json_encode(array_column($signInTrend, 'success')) ?>, backgroundColor: '#222222' },
            { label: 'Failed', data: <?= json_encode(array_column($signInTrend, 'failed')) ?>, backgroundColor: '#a0a0a0' }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom' } } }
});
<?php endif; ?>
<?php if ($activityTrend !== []): ?>
new Chart(document.getElementById('activityChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($activityTrend, 'label')) ?>,
        datasets: [{ label: 'Account changes', data: <?= json_encode(array_column($activityTrend, 'value')) ?>, borderColor: '#222222', backgroundColor: '#222222', tension: .3 }]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
