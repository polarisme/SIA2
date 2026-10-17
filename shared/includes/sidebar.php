<?php
$activeSystem = $activeSystem ?? 'pms';
$activeNavigation = $activeNavigation ?? '';
?>
<div class="sidebar-backdrop" data-sidebar-close hidden></div>
<?php if ($activeSystem === 'user_management'): ?>
    <?php
    $navigationItems = [
        ['key' => 'user_management_dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => app_url('/shared/user-management/index.php')],
        ['key' => 'user_management_users', 'label' => 'User Management', 'icon' => 'bi-people', 'url' => app_url('/shared/user-management/index.php')],
        ['key' => 'user_management_audit', 'label' => 'Audit Trail', 'icon' => 'bi-journal-text', 'url' => app_url('/shared/user-management/audit-trail.php')],
    ];
    ?>
    <aside class="app-sidebar" id="app-sidebar" aria-label="User management navigation">
        <a class="sidebar-brand" href="<?= h(app_url('/shared/user-management/index.php')) ?>">
            <span class="sidebar-brand-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
            <span class="sidebar-brand-copy">
                <strong>YIELD</strong>
                <small>User Management</small>
            </span>
        </a>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <div class="sidebar-caption">ADMINISTRATION</div>
            <?php foreach ($navigationItems as $navigationItem): ?>
                <a class="sidebar-link<?= $activeNavigation === $navigationItem['key'] ? ' is-active' : '' ?>"
                   href="<?= h($navigationItem['url']) ?>"
                   <?= $activeNavigation === $navigationItem['key'] ? 'aria-current="page"' : '' ?>
                   title="<?= h($navigationItem['label']) ?>">
                    <i class="bi <?= h($navigationItem['icon']) ?>" aria-hidden="true"></i>
                    <span><?= h($navigationItem['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">USER MANAGEMENT · <?= h(SYSTEM_ROLE_MAP['user_management']) ?></div>
    </aside>
<?php elseif ($activeSystem === 'integrated'): ?>
    <?php
    $navigationItems = [
        ['key' => 'integrated_dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => app_url('/integrated/index.php')],
        ['key' => 'integrated_batch_costing', 'label' => 'Batch Costing & Profitability', 'icon' => 'bi-cash-coin', 'url' => app_url('/integrated/batch-costing/index.php')],
        ['key' => 'integrated_cost_efficiency', 'label' => 'Cost Efficiency Analytics', 'icon' => 'bi-speedometer2', 'url' => app_url('/integrated/cost-efficiency/index.php')],
        ['key' => 'integrated_replenishment', 'label' => 'Inventory Replenishment', 'icon' => 'bi-box-seam', 'url' => app_url('/integrated/inventory-replenishment/index.php')],
        ['key' => 'integrated_performance', 'label' => 'Production & Financial Performance', 'icon' => 'bi-graph-up-arrow', 'url' => app_url('/integrated/performance/index.php')],
        ['key' => 'integrated_scenarios', 'label' => 'Scenario Analysis', 'icon' => 'bi-lightbulb', 'url' => app_url('/integrated/scenarios/index.php')],
        ['key' => 'integrated_recycle_bin', 'label' => 'Recycle Bin', 'icon' => 'bi-trash3', 'url' => app_url('/integrated/recycle-bin/index.php')],
    ];
    ?>
    <aside class="app-sidebar" id="app-sidebar" aria-label="Integrated System navigation">
        <a class="sidebar-brand" href="<?= h(app_url('/integrated/index.php')) ?>">
            <span class="sidebar-brand-icon" aria-hidden="true"><img class="yield-brand-logo sidebar-logo" src="<?= h(logo_url('integrated')) ?>" alt=""></span>
            <span class="sidebar-brand-copy">
                <strong>YIELD</strong>
                <small>Integrated Farm Operations &amp; Accounting</small>
            </span>
        </a>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <div class="sidebar-caption">WORKSPACE</div>
            <?php foreach ($navigationItems as $navigationItem): ?>
                <?php if ($navigationItem['key'] === 'integrated_recycle_bin' && !is_poultry_admin()): ?><?php continue; ?><?php endif; ?>
                <?php if ($navigationItem['key'] === 'integrated_batch_costing'): ?><div class="sidebar-caption">ANALYSIS</div><?php endif; ?>
                <?php if ($navigationItem['key'] === 'integrated_scenarios'): ?><div class="sidebar-caption">PLANNING</div><?php endif; ?>
                <?php if ($navigationItem['key'] === 'integrated_recycle_bin'): ?><div class="sidebar-caption">SYSTEM</div><?php endif; ?>
                <a class="sidebar-link<?= $activeNavigation === $navigationItem['key'] ? ' is-active' : '' ?>"
                   href="<?= h($navigationItem['url']) ?>"
                   <?= $activeNavigation === $navigationItem['key'] ? 'aria-current="page"' : '' ?>
                   title="<?= h($navigationItem['label']) ?>">
                    <i class="bi <?= h($navigationItem['icon']) ?>" aria-hidden="true"></i>
                    <span><?= h($navigationItem['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">INTEGRATED SYSTEM · <?= h(SYSTEM_ROLE_MAP['integrated']) ?></div>
    </aside>
<?php elseif ($activeSystem === 'ais'): ?>
    <?php
    $navigationItems = [
        ['key' => 'ais_dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => app_url('/ais/index.php')],
        ['key' => 'ais_transactions_all', 'label' => 'All Transactions', 'icon' => 'bi-receipt-cutoff', 'url' => app_url('/ais/transactions/index.php')],
        ['key' => 'ais_transactions_income', 'label' => 'Income', 'icon' => 'bi-graph-up-arrow', 'url' => app_url('/ais/transactions/index.php') . '?type=Income'],
        ['key' => 'ais_transactions_expense', 'label' => 'Expenses', 'icon' => 'bi-graph-down-arrow', 'url' => app_url('/ais/transactions/index.php') . '?type=Expense'],
        ['key' => 'ais_receivables', 'label' => 'Accounts Receivable', 'icon' => 'bi-cash-stack', 'url' => app_url('/ais/receivables/index.php')],
        ['key' => 'ais_payables', 'label' => 'Accounts Payable', 'icon' => 'bi-wallet2', 'url' => app_url('/ais/payables/index.php')],
        ['key' => 'ais_accounts', 'label' => 'Accounts', 'icon' => 'bi-journal-text', 'url' => app_url('/ais/accounts/index.php')],
        ['key' => 'ais_reports', 'label' => 'Reports', 'icon' => 'bi-file-earmark-bar-graph', 'url' => app_url('/ais/reports/index.php')],
        ['key' => 'ais_recycle_bin', 'label' => 'Recycle Bin', 'icon' => 'bi-trash3', 'url' => app_url('/ais/recycle-bin/index.php')],
    ];
    ?>
    <aside class="app-sidebar" id="app-sidebar" aria-label="Accounting Information System navigation">
        <a class="sidebar-brand" href="<?= h(app_url('/ais/index.php')) ?>">
            <span class="sidebar-brand-icon" aria-hidden="true"><img class="yield-brand-logo sidebar-logo" src="<?= h(logo_url('ais')) ?>" alt=""></span>
            <span class="sidebar-brand-copy">
                <strong>YIELD</strong>
                <small>Accounting Information System</small>
            </span>
        </a>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <div class="sidebar-caption">WORKSPACE</div>
            <?php foreach ($navigationItems as $navigationItem): ?>
                <?php if ($navigationItem['key'] === 'ais_recycle_bin' && !is_poultry_admin()): ?><?php continue; ?><?php endif; ?>
                <?php if ($navigationItem['key'] === 'ais_transactions_all'): ?><div class="sidebar-caption">TRANSACTIONS</div><?php endif; ?>
                <?php if ($navigationItem['key'] === 'ais_receivables'): ?><div class="sidebar-caption">RECEIVABLES &amp; PAYABLES</div><?php endif; ?>
                <?php if ($navigationItem['key'] === 'ais_accounts'): ?><div class="sidebar-caption">SYSTEM</div><?php endif; ?>
                <a class="sidebar-link<?= $activeNavigation === $navigationItem['key'] ? ' is-active' : '' ?>"
                   href="<?= h($navigationItem['url']) ?>"
                   <?= $activeNavigation === $navigationItem['key'] ? 'aria-current="page"' : '' ?>
                   title="<?= h($navigationItem['label']) ?>">
                    <i class="bi <?= h($navigationItem['icon']) ?>" aria-hidden="true"></i>
                    <span><?= h($navigationItem['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">ACCOUNTING SYSTEM · <?= h(SYSTEM_ROLE_MAP['ais']) ?></div>
    </aside>
<?php else: ?>
    <?php
    $navigationItems = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => app_url('/pms/index.php'), 'available' => true],
        ['key' => 'flocks', 'label' => 'Flock Management', 'icon' => 'bi-egg-fried', 'url' => app_url('/pms/flocks/index.php'), 'available' => true],
        ['key' => 'inventory', 'label' => 'Inventory Management', 'icon' => 'bi-box-seam', 'url' => app_url('/pms/inventory/index.php'), 'available' => true],
        ['key' => 'activities', 'label' => 'Farm Activities', 'icon' => 'bi-calendar-check', 'url' => app_url('/pms/activities/index.php'), 'available' => true],
        ['key' => 'production', 'label' => 'Production Monitoring', 'icon' => 'bi-graph-up-arrow', 'url' => app_url('/pms/production/index.php'), 'available' => true],
        ['key' => 'health', 'label' => 'Poultry Health & Disease', 'icon' => 'bi-heart-pulse', 'url' => app_url('/pms/health/index.php'), 'available' => true],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bi-file-earmark-pdf', 'url' => app_url('/pms/reports/index.php'), 'available' => true],
        ['key' => 'recycle', 'label' => 'Recycle Bin', 'icon' => 'bi-trash3', 'url' => app_url('/pms/recycle-bin/index.php'), 'available' => true],
    ];
    ?>
    <aside class="app-sidebar" id="app-sidebar" aria-label="Poultry Management navigation">
        <a class="sidebar-brand" href="<?= h(app_url('/pms/index.php')) ?>">
            <span class="sidebar-brand-icon" aria-hidden="true"><img class="yield-brand-logo sidebar-logo" src="<?= h(logo_url('pms')) ?>" alt=""></span>
            <span class="sidebar-brand-copy">
                <strong>YIELD</strong>
                <small>Poultry Management System</small>
            </span>
        </a>
        <nav class="sidebar-nav" aria-label="Main navigation">
            <div class="sidebar-caption">WORKSPACE</div>
            <?php foreach ($navigationItems as $navigationItem): ?>
                <?php if ($navigationItem['key'] === 'reports' && is_poultry_admin()): ?><div class="sidebar-caption">SYSTEM</div><?php endif; ?>
                <?php if (($navigationItem['key'] !== 'reports' && $navigationItem['key'] !== 'recycle') || is_poultry_admin()): ?>
                    <?php if ($navigationItem['available']): ?>
                    <a class="sidebar-link<?= $activeNavigation === $navigationItem['key'] ? ' is-active' : '' ?>"
                       href="<?= h($navigationItem['url']) ?>"
                       <?= $activeNavigation === $navigationItem['key'] ? 'aria-current="page"' : '' ?>
                       title="<?= h($navigationItem['label']) ?>">
                        <i class="bi <?= h($navigationItem['icon']) ?>" aria-hidden="true"></i>
                        <span><?= h($navigationItem['label']) ?></span>
                    </a>
                        <?php else: ?>
                            <div class="sidebar-link is-disabled" aria-disabled="true" title="<?= h($navigationItem['label']) ?> is not implemented yet">
                                <i class="bi <?= h($navigationItem['icon']) ?>" aria-hidden="true"></i>
                                <span><?= h($navigationItem['label']) ?></span>
                                <small>Later</small>
                            </div>
                        <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">POULTRY MANAGEMENT · <?= h(SYSTEM_ROLE_MAP['pms']) ?></div>
    </aside>
<?php endif; ?>
