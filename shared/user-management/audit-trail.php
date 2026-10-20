<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_authentication();

$activeSystem = 'user_management';
$activeNavigation = 'user_management_audit';
$appBrandName = 'YIELD User Management';
$appFooterName = 'YIELD User Management';
$pageTitle = 'Audit Trail';

$pdo = null;
$databaseError = null;
try {
    $pdo = database_connection();
} catch (PDOException $exception) {
    error_log('Audit trail database connection failed: ' . $exception->getMessage());
    $databaseError = 'The database could not be reached. Check the MySQL service and database configuration.';
}

$tab = (string) ($_GET['tab'] ?? 'account') === 'records' ? 'records' : 'account';
$search = trim((string) ($_GET['search'] ?? ''));
$actionFilter = trim((string) ($_GET['action_filter'] ?? ''));
$systemFilter = trim((string) ($_GET['system_filter'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'event_time_desc');
['page' => $page, 'per_page' => $perPage, 'offset' => $offset] = paginate_params();

$events = [];
$totalRows = 0;

if ($pdo instanceof PDO && $databaseError === null) {
    try {
        if ($tab === 'account') {
            $sortColumns = [
                'event_time_desc' => 'event_time DESC',
                'event_time_asc' => 'event_time ASC',
                'action_asc' => 'action ASC, event_time DESC',
                'actor_asc' => 'actor_username ASC, event_time DESC',
            ];
            $orderBy = $sortColumns[$sort] ?? $sortColumns['event_time_desc'];

            $conditions = [];
            $parameters = [];
            if ($search !== '') {
                $conditions[] = '(actor_username LIKE ? OR target_username LIKE ? OR description LIKE ?)';
                $parameters[] = '%' . $search . '%';
                $parameters[] = '%' . $search . '%';
                $parameters[] = '%' . $search . '%';
            }
            if ($actionFilter !== '' && in_array($actionFilter, USER_MANAGEMENT_AUDIT_ACTIONS, true)) {
                $conditions[] = 'action = ?';
                $parameters[] = $actionFilter;
            }
            if ($systemFilter !== '' && array_key_exists($systemFilter, SYSTEM_LABELS)) {
                $conditions[] = 'system_context = ?';
                $parameters[] = $systemFilter;
            }
            if ($dateFrom !== '') {
                $conditions[] = 'event_time >= ?';
                $parameters[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== '') {
                $conditions[] = 'event_time <= ?';
                $parameters[] = $dateTo . ' 23:59:59';
            }
            $whereClause = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

            $countStatement = $pdo->prepare("SELECT COUNT(*) FROM user_management_audit_log $whereClause");
            $countStatement->execute($parameters);
            $totalRows = (int) $countStatement->fetchColumn();

            $listStatement = $pdo->prepare(
                "SELECT * FROM user_management_audit_log $whereClause ORDER BY $orderBy LIMIT $perPage OFFSET $offset"
            );
            $listStatement->execute($parameters);
            $events = $listStatement->fetchAll();
        } else {
            // "records" tab: pms_audit_log is the one shared CRUD log for PMS/AIS/
            // Integrated data rows (see shared/includes/bootstrap.php's
            // audit_pms_action()). table_name tells us which system a row is from.
            $sortColumns = [
                'event_time_desc' => 'action_at DESC',
                'event_time_asc' => 'action_at ASC',
                'action_asc' => 'action ASC, action_at DESC',
                'actor_asc' => 'username ASC, action_at DESC',
            ];
            $orderBy = $sortColumns[$sort] ?? $sortColumns['event_time_desc'];

            $conditions = [];
            $parameters = [];
            if ($search !== '') {
                $conditions[] = 'username LIKE ?';
                $parameters[] = '%' . $search . '%';
            }
            if ($actionFilter !== '' && in_array($actionFilter, RECORD_AUDIT_ACTIONS, true)) {
                $conditions[] = 'action = ?';
                $parameters[] = $actionFilter;
            }
            if ($systemFilter !== '' && in_array($systemFilter, ['pms', 'ais', 'integrated'], true)) {
                $tablesForSystem = array_keys(array_filter(TABLE_SYSTEM_MAP, static fn ($system) => $system === $systemFilter));
                $conditions[] = 'table_name IN (' . implode(',', array_fill(0, count($tablesForSystem), '?')) . ')';
                $parameters = array_merge($parameters, $tablesForSystem);
            }
            if ($dateFrom !== '') {
                $conditions[] = 'action_at >= ?';
                $parameters[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo !== '') {
                $conditions[] = 'action_at <= ?';
                $parameters[] = $dateTo . ' 23:59:59';
            }
            $whereClause = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

            $countStatement = $pdo->prepare("SELECT COUNT(*) FROM pms_audit_log $whereClause");
            $countStatement->execute($parameters);
            $totalRows = (int) $countStatement->fetchColumn();

            $listStatement = $pdo->prepare(
                "SELECT * FROM pms_audit_log $whereClause ORDER BY $orderBy LIMIT $perPage OFFSET $offset"
            );
            $listStatement->execute($parameters);
            $events = $listStatement->fetchAll();
        }
    } catch (PDOException $exception) {
        error_log('Audit trail query failed: ' . $exception->getMessage());
        $databaseError = 'The audit trail could not be loaded because of a database error.';
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div class="eyebrow">USER MANAGEMENT</div>
    <h1>Audit Trail</h1>
    <p>A centralized, read-only record of account/security events and of every create, update, delete, and restore across PMS, AIS, and Integrated.</p>
</div>
<?php if ($databaseError !== null): ?><div class="alert alert-danger" role="alert"><?= h($databaseError) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'account' ? ' active' : '' ?>" href="audit-trail.php?tab=account">Account &amp; Security Events</a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'records' ? ' active' : '' ?>" href="audit-trail.php?tab=records">Record Changes</a>
    </li>
</ul>

<section class="content-panel mb-4">
    <div class="panel-body">
        <form method="get" action="audit-trail.php" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <?php pagination_preserve_per_page_field($perPage); ?>
            <div class="col-md-3">
                <label class="form-label" for="search">Search</label>
                <input class="form-control" id="search" name="search" value="<?= h($search) ?>" placeholder="<?= $tab === 'account' ? 'Actor, target, description' : 'Username' ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="action_filter">Action</label>
                <select class="form-select" id="action_filter" name="action_filter">
                    <option value="">All actions</option>
                    <?php foreach (($tab === 'account' ? USER_MANAGEMENT_AUDIT_ACTIONS : RECORD_AUDIT_ACTIONS) as $actionOption): ?>
                        <option value="<?= h($actionOption) ?>" <?= $actionFilter === $actionOption ? 'selected' : '' ?>><?= h($actionOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="system_filter">System</label>
                <select class="form-select" id="system_filter" name="system_filter">
                    <option value="">All systems</option>
                    <?php foreach (($tab === 'account' ? SYSTEM_LABELS : array_intersect_key(SYSTEM_LABELS, ['pms' => 1, 'ais' => 1, 'integrated' => 1])) as $systemKey => $systemLabel): ?>
                        <option value="<?= h($systemKey) ?>" <?= $systemFilter === $systemKey ? 'selected' : '' ?>><?= h($systemLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date_from">From</label>
                <input type="date" class="form-control" id="date_from" name="date_from" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date_to">To</label>
                <input type="date" class="form-control" id="date_to" name="date_to" value="<?= h($dateTo) ?>">
            </div>
            <div class="col-md-1 d-flex">
                <button class="btn btn-outline-primary flex-grow-1" type="submit">Go</button>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="sort">Sort by</label>
                <select class="form-select" id="sort" name="sort" onchange="this.form.submit()">
                    <option value="event_time_desc" <?= $sort === 'event_time_desc' ? 'selected' : '' ?>>Newest first</option>
                    <option value="event_time_asc" <?= $sort === 'event_time_asc' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="action_asc" <?= $sort === 'action_asc' ? 'selected' : '' ?>>Action</option>
                    <option value="actor_asc" <?= $sort === 'actor_asc' ? 'selected' : '' ?>>Actor</option>
                </select>
            </div>
            <div class="col-12">
                <a class="btn btn-sm btn-outline-secondary" href="audit-trail.php?tab=<?= h($tab) ?>">Clear filters</a>
            </div>
        </form>
    </div>
</section>

<section class="content-panel">
    <div class="panel-heading d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0">Events</h2>
        <span class="text-secondary small"><?= number_format($totalRows) ?> total</span>
    </div>
    <div class="table-responsive">
        <?php if ($tab === 'account'): ?>
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 13%"><col style="width: 12%"><col style="width: 11%"><col style="width: 12%">
                    <col style="width: 10%"><col style="width: 30%"><col style="width: 12%">
                </colgroup>
                <thead><tr><th>Date / Time</th><th>Actor</th><th>Action</th><th>Target</th><th>System</th><th>Description</th><th>IP Address</th></tr></thead>
                <tbody>
                    <?php if ($events === []): ?>
                        <tr><td colspan="7" class="text-center text-secondary py-4">No audit events match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><span class="cell-id" title="<?= h((string) $event['event_time']) ?>"><?= h((string) $event['event_time']) ?></span></td>
                                <td><span class="cell-id" title="<?= h($event['actor_username'] ?? '—') ?>"><?= h($event['actor_username'] ?? '—') ?></span></td>
                                <td class="cell-wrap"><span class="badge text-bg-secondary"><?= h($event['action']) ?></span></td>
                                <td><span class="cell-id" title="<?= h($event['target_username'] ?? '—') ?>"><?= h($event['target_username'] ?? '—') ?></span></td>
                                <td class="cell-wrap"><?= $event['system_context'] ? h(SYSTEM_LABELS[$event['system_context']] ?? $event['system_context']) : '—' ?></td>
                                <td class="cell-clamp" title="<?= h($event['description'] ?? '') ?>"><?= h($event['description'] ?? '') ?></td>
                                <td><span class="cell-id" title="<?= h($event['ip_address'] ?? '—') ?>"><?= h($event['ip_address'] ?? '—') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table class="table table-hover table-sm mb-0 table-compact">
                <colgroup>
                    <col style="width: 18%"><col style="width: 16%"><col style="width: 12%">
                    <col style="width: 16%"><col style="width: 24%"><col style="width: 14%">
                </colgroup>
                <thead><tr><th>Date / Time</th><th>User</th><th>Action</th><th>System</th><th>Table</th><th>Record ID</th></tr></thead>
                <tbody>
                    <?php if ($events === []): ?>
                        <tr><td colspan="6" class="text-center text-secondary py-4">No record changes match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><span class="cell-id" title="<?= h((string) $event['action_at']) ?>"><?= h((string) $event['action_at']) ?></span></td>
                                <td><span class="cell-id" title="<?= h($event['username']) ?>"><?= h($event['username']) ?></span></td>
                                <td class="cell-wrap"><span class="badge text-bg-secondary"><?= h($event['action']) ?></span></td>
                                <td class="cell-wrap"><?= h(SYSTEM_LABELS[TABLE_SYSTEM_MAP[$event['table_name']] ?? ''] ?? '—') ?></td>
                                <td><span class="cell-id" title="<?= h($event['table_name']) ?>"><?= h($event['table_name']) ?></span></td>
                                <td><?= (int) $event['record_id'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php render_pagination_controls($page, $perPage, $totalRows, $_GET, 'audit-trail.php'); ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
