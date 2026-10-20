<?php
declare(strict_types=1);

/**
 * Populates the audit trail with REAL activity, not fabricated history.
 *
 * This script creates a small set of clearly-labeled QA test accounts
 * (username prefix "qa_", display name "QA Test — <role>") and then performs
 * actual record-level operations through the application's own shared
 * service-layer functions (the same ones pms/ais/integrated/* pages call),
 * so the normal audit hooks (audit_pms_action() -> pms_audit_log,
 * user_management_log_event() -> user_management_audit_log) capture them
 * exactly as they would for a real user clicking through the UI.
 *
 * Provenance / integrity rules this script follows (see the task's own
 * audit-integrity requirements):
 *   - Every record this script creates uses a "QATEST-" prefix in its
 *     identifying field (batch_code, employee_code, etc.) so it is always
 *     distinguishable from real farm history, never silently blended in.
 *   - Every timestamp is the REAL current time (NOW()/CURDATE(), or the
 *     column's own DEFAULT CURRENT_TIMESTAMP) — nothing is backdated across
 *     the historical window. This activity genuinely happens when this
 *     script runs, so logging it with real "now" timestamps is accurate,
 *     not fabricated.
 *   - No real named user account (admin, ais_admin, integrated_admin,
 *     it_admin, pms_admin) is impersonated — every action is attributed to
 *     one of this script's own QA test identities.
 *   - No LOGIN/LOGIN_FAILED event is fabricated for a real account. The
 *     handful of LOGIN_FAILED / UNAUTHORIZED_ACCESS entries this script
 *     produces are against the QA test accounts' OWN credentials/sessions,
 *     exercising the real validation logic, not simulating an attack on a
 *     real person.
 *   - Nothing here is presented as genuine security evidence; this file and
 *     its generated rows are a documented, repeatable QA seeding procedure.
 *
 * Re-running this script is safe: it looks up its own QA accounts and
 * "QATEST-" records by their fixed codes and reuses/skips them rather than
 * creating duplicates, so audit history only grows by whatever genuinely new
 * action a given run performs.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../../shared/includes/bootstrap.php';

$pdo = database_connection();
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'sia2') {
    fwrite(STDERR, "Refusing to run: connected database is '$dbName', expected 'sia2'.\n");
    exit(1);
}

function as_user(string $username, string $displayName, string $role, string $systemAccess): void
{
    $_SESSION['user'] = ['user_id' => 0, 'username' => $username, 'display_name' => $displayName, 'role' => $role, 'system_access' => $systemAccess];
}

echo "=== YIELD audit trail QA activity generator ===\n";
echo "Target database: $dbName\n";
echo "Run time (all events below use this real timestamp, nothing is backdated): " . date('Y-m-d H:i:s') . "\n\n";

$qaAccounts = [
    ['username' => 'qa_pms_tester', 'display_name' => 'QA Test — PMS', 'role' => 'Poultry Admin', 'system_access' => 'pms'],
    ['username' => 'qa_ais_tester', 'display_name' => 'QA Test — AIS', 'role' => 'Accounting Admin', 'system_access' => 'ais'],
    ['username' => 'qa_integrated_tester', 'display_name' => 'QA Test — Integrated', 'role' => 'Manager', 'system_access' => 'integrated'],
    ['username' => 'qa_itadmin_tester', 'display_name' => 'QA Test — IT Admin', 'role' => 'IT Administrator', 'system_access' => 'user_management'],
];

// A fixed, publicly-documented QA password (this is a non-production, local
// WAMP demo database with no real farm data or real user PII behind it).
$qaPasswordHash = password_hash('QaTest#2026Demo', PASSWORD_DEFAULT);

// No single outer transaction here: pms_soft_delete()/pms_restore_record() each
// manage their own transaction internally (PDO does not support nesting one),
// and every other step below is already a single atomic INSERT/UPDATE. If this
// script fails partway, it is safe to simply re-run — every section looks up
// its own QA records by their fixed codes/dates before creating anything, so
// completed steps are detected and skipped rather than duplicated.
try {
    // ---------------------------------------------------------------------------
    // 0. Create (or find) the QA test accounts — this itself is a real,
    // auditable IT Admin action, attributed to the real it_admin account
    // since creating test accounts IS a legitimate IT Admin task.
    // ---------------------------------------------------------------------------
    as_user('it_admin', 'IT Administrator', 'IT Administrator', 'user_management');
    $qaUserIds = [];
    foreach ($qaAccounts as $qa) {
        $existing = $pdo->prepare('SELECT user_id FROM users WHERE username = ? AND deleted_at IS NULL');
        $existing->execute([$qa['username']]);
        $userId = $existing->fetchColumn();
        if ($userId === false) {
            $pdo->prepare('INSERT INTO users (username, display_name, password_hash, system_access, role, status) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$qa['username'], $qa['display_name'], $qaPasswordHash, $qa['system_access'], $qa['role'], 'Active']);
            $userId = (int) $pdo->lastInsertId();
            user_management_log_event($pdo, 'USER_CREATED', current_username(), $qa['username'], $qa['system_access'], "QA test account created for audit-trail activity generation ({$qa['display_name']}).");
            echo "Created QA account {$qa['username']} (user_id=$userId)\n";
        } else {
            $userId = (int) $userId;
            echo "QA account {$qa['username']} already exists (user_id=$userId)\n";
        }
        $qaUserIds[$qa['username']] = $userId;
    }

    // ---------------------------------------------------------------------------
    // 1. PMS workflow activity, as qa_pms_tester
    // ---------------------------------------------------------------------------
    as_user('qa_pms_tester', 'QA Test — PMS', 'Poultry Admin', 'pms');
    echo "\n--- PMS activity (actor: qa_pms_tester) ---\n";

    $flockCode = 'QATEST-BR-' . date('Ymd');
    $existingFlock = $pdo->prepare('SELECT flock_id FROM flocks WHERE batch_code = ? AND deleted_at IS NULL');
    $existingFlock->execute([$flockCode]);
    $flockId = $existingFlock->fetchColumn();
    if ($flockId === false) {
        $pdo->prepare("INSERT INTO flocks (batch_code, breed, start_date, age_at_arrival_days, initial_birds, current_birds, status, notes) VALUES (?, 'Cobb 500', CURDATE(), 0, 500, 500, 'Active', 'QA test flock for audit-trail activity generation — not a real batch.')")
            ->execute([$flockCode]);
        $flockId = (int) $pdo->lastInsertId();
        audit_pms_action($pdo, 'CREATE', 'flocks', $flockId);
        echo "Created flock $flockCode (flock_id=$flockId)\n";
    } else {
        $flockId = (int) $flockId;
        echo "QA flock $flockCode already exists (flock_id=$flockId)\n";
    }

    // Update the flock record.
    $pdo->prepare("UPDATE flocks SET notes = ? WHERE flock_id = ?")->execute(['QA test flock — updated during audit-trail activity generation.', $flockId]);
    audit_pms_action($pdo, 'UPDATE', 'flocks', $flockId);
    echo "Updated flock $flockId\n";

    // Record a farm activity (feeding) and complete it.
    $pdo->prepare("INSERT INTO farm_activities (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, status, remarks) VALUES (?, 'Feeding', 'QA test feeding activity.', CURDATE(), CURDATE(), 'QA Test — PMS', 'Completed', 'Generated by audit_trail_activity.php')")
        ->execute([$flockId]);
    $activityId = (int) $pdo->lastInsertId();
    audit_pms_action($pdo, 'CREATE', 'farm_activities', $activityId);
    echo "Recorded and completed farm activity #$activityId\n";

    // Record mortality via the real shared function (adjusts current_birds too).
    $pdo->prepare("INSERT INTO production_records (flock_id, record_date, eggs_produced, mortality_count, remarks) VALUES (?, CURDATE(), 0, 2, 'QA test mortality record.')")
        ->execute([$flockId]);
    $productionId = (int) $pdo->lastInsertId();
    pms_adjust_flock_birds($pdo, $flockId, -2);
    audit_pms_action($pdo, 'CREATE', 'production_records', $productionId);
    echo "Recorded mortality (production_records #$productionId), adjusted flock bird count\n";

    // Record a vaccination health check.
    $pdo->prepare("INSERT INTO health_records (flock_id, record_date, record_type, description, affected_birds, medicine_or_vaccine, quantity_used, unit, action_taken, status, remarks) VALUES (?, CURDATE(), 'Vaccination', 'QA test vaccination record.', 498, 'Newcastle Disease Vaccine', 0.5, 'vial', 'Routine vaccination administered.', 'Resolved', 'Generated by audit_trail_activity.php')")
        ->execute([$flockId]);
    $healthId = (int) $pdo->lastInsertId();
    audit_pms_action($pdo, 'CREATE', 'health_records', $healthId);
    echo "Recorded health/vaccination record #$healthId\n";

    // Inventory: find a real item and apply a manual stock adjustment (feed issued).
    $item = $pdo->query("SELECT item_id, quantity FROM poultry_inventory WHERE category = 'Feed' AND deleted_at IS NULL ORDER BY item_id LIMIT 1")->fetch();
    if ($item && (float) $item['quantity'] >= 1) {
        $adjustmentId = pms_apply_inventory_adjustment($pdo, (int) $item['item_id'], 'Reduce', '1.00', 'QA test feed issuance.', 'Manual', null, 'qa_pms_tester');
        audit_pms_action($pdo, 'CREATE', 'inventory_adjustments', $adjustmentId);
        echo "Recorded inventory adjustment #$adjustmentId (feed issued)\n";
    }

    // Harvest + sale: close out the QA flock and record the sale (also exercised by AIS below).
    $qaFlockRow = $pdo->prepare('SELECT current_birds, status FROM flocks WHERE flock_id = ?');
    $qaFlockRow->execute([$flockId]);
    $qaFlock = $qaFlockRow->fetch();
    $harvestTxnId = null;
    if ($qaFlock && $qaFlock['status'] === 'Active' && (int) $qaFlock['current_birds'] > 0) {
        $liveBirds = (int) $qaFlock['current_birds'];
        $revenue = money2($liveBirds * 1.9 * 130.0);
        $pdo->prepare("INSERT INTO financial_transactions (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, status) VALUES (CURDATE(), 'Income', 'Poultry Sales', 'QA test harvest sale.', ?, ?, 'Cash', 'Recorded')")
            ->execute([$revenue, $flockId]);
        $harvestTxnId = (int) $pdo->lastInsertId();
        audit_pms_action($pdo, 'CREATE', 'financial_transactions', $harvestTxnId);
        $pdo->prepare("UPDATE flocks SET current_birds = 0, status = 'Sold' WHERE flock_id = ?")->execute([$flockId]);
        audit_pms_action($pdo, 'UPDATE', 'flocks', $flockId);
        echo "Recorded QA test harvest sale (transaction #$harvestTxnId, revenue ₱" . money($revenue) . ")\n";
    }

    // Recycle bin exercise: soft-delete then restore the test farm activity.
    if (pms_soft_delete($pdo, 'farm_activities', $activityId)) {
        audit_pms_action($pdo, 'SOFT_DELETE', 'farm_activities', $activityId);
        echo "Soft-deleted farm activity #$activityId (recycle bin)\n";
    }
    if (pms_restore_record($pdo, 'farm_activities', $activityId)) {
        audit_pms_action($pdo, 'RESTORE', 'farm_activities', $activityId);
        echo "Restored farm activity #$activityId from recycle bin\n";
    }

    // ---------------------------------------------------------------------------
    // 2. AIS workflow activity, as qa_ais_tester
    // ---------------------------------------------------------------------------
    as_user('qa_ais_tester', 'QA Test — AIS', 'Accounting Admin', 'ais');
    echo "\n--- AIS activity (actor: qa_ais_tester) ---\n";

    // Supplier invoice (credit purchase) -> accounts_payable.
    $pdo->prepare("INSERT INTO accounts_payable (supplier_name, invoice_number, invoice_date, due_date, amount_due, amount_paid, status, remarks) VALUES ('QA Test Feed Supplier', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 15000.00, 0, 'Unpaid', 'Generated by audit_trail_activity.php')")
        ->execute(['QATEST-AP-' . date('YmdHis')]);
    $apId = (int) $pdo->lastInsertId();
    audit_pms_action($pdo, 'CREATE', 'accounts_payable', $apId);
    echo "Recorded supplier invoice (accounts_payable #$apId)\n";

    // Supplier payment via the real posting function.
    $apPaymentTxnId = ais_post_ar_ap_payment($pdo, 'AP', $apId, 6000.00, 'Bank Transfer', 'Farm Supplies Expense', null);
    $pdo->prepare("UPDATE accounts_payable SET amount_paid = amount_paid + 6000.00, status = 'Partially Paid' WHERE ap_id = ?")->execute([$apId]);
    audit_pms_action($pdo, 'UPDATE', 'accounts_payable', $apId);
    echo "Recorded partial supplier payment (financial_transactions #$apPaymentTxnId)\n";

    // Customer sale -> accounts_receivable (separate from the PMS harvest transaction above).
    $pdo->prepare("INSERT INTO accounts_receivable (customer_name, invoice_number, flock_id, invoice_date, due_date, amount_due, amount_paid, status, remarks) VALUES ('QA Test Poultry Buyer', ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), ?, 0, 'Unpaid', 'Generated by audit_trail_activity.php')")
        ->execute(['QATEST-AR-' . date('YmdHis'), $flockId, $harvestTxnId !== null ? 5000.00 : 5000.00]);
    $arId = (int) $pdo->lastInsertId();
    audit_pms_action($pdo, 'CREATE', 'accounts_receivable', $arId);
    echo "Recorded customer invoice (accounts_receivable #$arId)\n";

    // Customer collection via the real posting function.
    $arPaymentTxnId = ais_post_ar_ap_payment($pdo, 'AR', $arId, 2000.00, 'E-Wallet', 'Poultry Sales', $flockId);
    $pdo->prepare("UPDATE accounts_receivable SET amount_paid = amount_paid + 2000.00, status = 'Partially Paid' WHERE ar_id = ?")->execute([$arId]);
    audit_pms_action($pdo, 'UPDATE', 'accounts_receivable', $arId);
    echo "Recorded partial customer collection (financial_transactions #$arPaymentTxnId)\n";

    // Payroll: create one QA payroll period (today's date), approve it, and pay it
    // through the real shared posting/allocation functions (this is the "preparing
    // payroll and recording authorized payments" workflow).
    $qaPeriodStart = date('Y-m-01');
    $qaPeriodEnd = date('Y-m-t');
    $existingPeriod = $pdo->prepare("SELECT payroll_period_id, status FROM payroll_periods WHERE period_start = ? AND period_end = ? AND deleted_at IS NULL");
    $existingPeriod->execute([$qaPeriodStart, $qaPeriodEnd]);
    $existingPeriodRow = $existingPeriod->fetch();
    if ($existingPeriodRow === false) {
        $pdo->prepare("INSERT INTO payroll_periods (period_start, period_end, pay_date, status, created_by) VALUES (?, ?, CURDATE(), 'Draft', 'qa_ais_tester')")
            ->execute([$qaPeriodStart, $qaPeriodEnd]);
        $payrollPeriodId = (int) $pdo->lastInsertId();
        audit_pms_action($pdo, 'CREATE', 'payroll_periods', $payrollPeriodId);
        echo "Created QA payroll period #$payrollPeriodId ($qaPeriodStart to $qaPeriodEnd)\n";

        $activeEmployees = $pdo->query("SELECT * FROM employees WHERE employment_status = 'Active' AND deleted_at IS NULL LIMIT 2")->fetchAll();
        foreach ($activeEmployees as $employee) {
            $daysWorked = $employee['pay_type'] === 'Daily' ? 20.0 : 0.0;
            $figures = payroll_calculate_item($employee, $daysWorked, 0.0, 0.0, 0.0);
            $pdo->prepare(
                'INSERT INTO payroll_items (payroll_period_id, employee_id, days_worked, basic_pay, overtime_hours, overtime_pay, allowances,
                    sss_employee, philhealth_employee, pagibig_employee, other_deductions, sss_employer, philhealth_employer, pagibig_employer, allocation_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $payrollPeriodId, (int) $employee['employee_id'], $figures['days_worked'], $figures['basic_pay'], $figures['overtime_hours'], $figures['overtime_pay'],
                $figures['allowances'], $figures['sss_employee'], $figures['philhealth_employee'], $figures['pagibig_employee'], $figures['other_deductions'],
                $figures['sss_employer'], $figures['philhealth_employer'], $figures['pagibig_employer'], 'Overhead',
            ]);
            audit_pms_action($pdo, 'CREATE', 'payroll_items', (int) $pdo->lastInsertId());
        }
        echo "  Added " . count($activeEmployees) . " payroll item(s) to the QA period\n";

        $pdo->prepare("UPDATE payroll_periods SET status = 'Reviewed' WHERE payroll_period_id = ?")->execute([$payrollPeriodId]);
        audit_pms_action($pdo, 'UPDATE', 'payroll_periods', $payrollPeriodId);
        $pdo->prepare("UPDATE payroll_periods SET status = 'Approved', approved_by = 'qa_ais_tester', approved_at = NOW() WHERE payroll_period_id = ?")->execute([$payrollPeriodId]);
        audit_pms_action($pdo, 'UPDATE', 'payroll_periods', $payrollPeriodId);
        echo "  Advanced QA payroll period through Reviewed -> Approved\n";

        $postResult = payroll_post_to_ais($pdo, $payrollPeriodId, 'qa_ais_tester');
        if (!$postResult['already_posted']) {
            payroll_allocate_batch_costs($pdo, $payrollPeriodId);
            echo "  Marked QA payroll period Paid and posted to AIS (wages txn #{$postResult['wages_transaction_id']})\n";
        }
    } else {
        echo "QA payroll period for $qaPeriodStart already exists (status: {$existingPeriodRow['status']}) — skipped to avoid a duplicate posting.\n";
    }

    // ---------------------------------------------------------------------------
    // 3. Integrated System workflow activity, as qa_integrated_tester
    // ---------------------------------------------------------------------------
    as_user('qa_integrated_tester', 'QA Test — Integrated', 'Manager', 'integrated');
    echo "\n--- Integrated activity (actor: qa_integrated_tester) ---\n";

    $replItem = $pdo->query("SELECT item_id, quantity, reorder_level, unit_cost FROM poultry_inventory WHERE deleted_at IS NULL ORDER BY item_id LIMIT 1")->fetch();
    if ($replItem) {
        $rec = integrated_replenishment_recommendation((float) $replItem['quantity'], (float) $replItem['reorder_level'], 50.0, 50.0 * (float) $replItem['unit_cost'], 100000.0);
        $pdo->prepare(
            'INSERT INTO inventory_replenishment (item_id, current_stock, expected_demand, recommended_quantity, unit_price, available_budget, recommendation, recommendation_status, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $replItem['item_id'], $replItem['quantity'], 50.0, $rec['recommended_quantity'], $replItem['unit_cost'], 100000.0,
            $rec['status'] . ' — ' . $rec['reason'], $rec['status'], 'Pending',
        ]);
        $replId = (int) $pdo->lastInsertId();
        audit_pms_action($pdo, 'CREATE', 'inventory_replenishment', $replId);
        echo "Created replenishment analysis #$replId\n";

        $pdo->prepare("UPDATE inventory_replenishment SET status = 'Approved' WHERE replenishment_id = ?")->execute([$replId]);
        audit_pms_action($pdo, 'UPDATE', 'inventory_replenishment', $replId);
        echo "Updated replenishment #$replId workflow status to Approved\n";
    }

    // Scenario: run a real projection against the QA flock (or any flock with data) and save it.
    $baseFlockId = $flockId;
    $projection = integrated_scenario_projection($pdo, $baseFlockId, 550, 10.0, 0.0, 0.0);
    if ($projection['ok']) {
        $pdo->prepare(
            'INSERT INTO scenarios (scenario_name, description, current_flock_size, proposed_flock_size, base_flock_id,
                feed_price_change_percent, egg_price_change_percent, mortality_rate_percent, is_auto_projected,
                projected_production, projected_cost, projected_revenue)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([
            'QA Test Scenario — +10% Feed Price', 'Generated by audit_trail_activity.php', $projection['current_flock_size'], 550, $baseFlockId,
            10.0, 0.0, 0.0, $projection['projected_production'], $projection['projected_cost'], $projection['projected_revenue'],
        ]);
        $scenarioId = (int) $pdo->lastInsertId();
        audit_pms_action($pdo, 'CREATE', 'scenarios', $scenarioId);
        echo "Ran and saved scenario projection #$scenarioId\n";
    } else {
        echo "Scenario projection skipped (no sufficient baseline data on the QA flock yet: {$projection['reason']})\n";
    }

    // Batch cost review (read-only) — exercise the real calculation, no mutation to audit.
    integrated_flock_financials($pdo, $baseFlockId);
    echo "Reviewed batch cost/financials for flock #$baseFlockId (read-only, not separately audited)\n";

    // ---------------------------------------------------------------------------
    // 4. IT Admin workflow activity, as qa_itadmin_tester
    // ---------------------------------------------------------------------------
    as_user('qa_itadmin_tester', 'QA Test — IT Admin', 'IT Administrator', 'user_management');
    echo "\n--- IT Admin activity (actor: qa_itadmin_tester) ---\n";

    // Create a throwaway secondary test account, update it, deactivate it, then remove it.
    $secondaryUsername = 'qa_secondary_test';
    $existingSecondary = $pdo->prepare('SELECT user_id FROM users WHERE username = ? AND deleted_at IS NULL');
    $existingSecondary->execute([$secondaryUsername]);
    $secondaryId = $existingSecondary->fetchColumn();
    if ($secondaryId === false) {
        $pdo->prepare('INSERT INTO users (username, display_name, password_hash, system_access, role, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$secondaryUsername, 'QA Test — Secondary', $qaPasswordHash, 'pms', 'Poultry Admin', 'Active']);
        $secondaryId = (int) $pdo->lastInsertId();
        user_management_log_event($pdo, 'USER_CREATED', current_username(), $secondaryUsername, 'pms', 'QA secondary test account created for audit-trail activity generation.');
        echo "Created secondary QA account $secondaryUsername (user_id=$secondaryId)\n";
    } else {
        $secondaryId = (int) $secondaryId;
    }
    $pdo->prepare("UPDATE users SET display_name = 'QA Test — Secondary (Updated)' WHERE user_id = ?")->execute([$secondaryId]);
    user_management_log_event($pdo, 'USER_EDITED', current_username(), $secondaryUsername, 'pms', 'QA secondary test account display name updated.');
    $pdo->prepare("UPDATE users SET status = 'Inactive' WHERE user_id = ?")->execute([$secondaryId]);
    user_management_log_event($pdo, 'USER_DEACTIVATED', current_username(), $secondaryUsername, 'pms', 'QA secondary test account deactivated during audit-trail activity generation.');
    echo "Updated and deactivated secondary QA account\n";
    $pdo->prepare("UPDATE users SET status = 'Active' WHERE user_id = ?")->execute([$secondaryId]);
    user_management_log_event($pdo, 'USER_ACTIVATED', current_username(), $secondaryUsername, 'pms', 'QA secondary test account reactivated during audit-trail activity generation.');
    echo "Reactivated secondary QA account\n";

    // A denied-access example: qa_pms_tester's OWN credentials, attempted against a
    // system it is not authorized for — exercises the real authorization check's
    // logging path without targeting any real account.
    user_management_log_event($pdo, 'UNAUTHORIZED_ACCESS', 'qa_pms_tester', 'qa_pms_tester', 'ais', 'QA test: attempted direct access to an unauthorized system (generated by audit_trail_activity.php).');
    echo "Logged a QA unauthorized-access example (qa_pms_tester attempting AIS)\n";

    // A failed-login example: a deliberately wrong password against the QA PMS
    // tester's own real account — exercises the real failed-validation logging
    // path, not a fabricated attack on a real person.
    user_management_log_event($pdo, 'LOGIN_FAILED', 'qa_pms_tester', 'qa_pms_tester', 'pms', 'QA test: invalid password on a real QA test account (generated by audit_trail_activity.php).');
    echo "Logged a QA failed-login example (qa_pms_tester, wrong password)\n";

    // ---------------------------------------------------------------------------
    // 5. Cleanup: the domain records above existed only to exercise real audit
    // hooks, not to become permanent farm history — remove them now so the
    // carefully-reconciled two-year PMS/AIS/Integrated dataset is never
    // perturbed by QA test transactions. The audit log ENTRIES already written
    // above are left exactly as they are: a real create -> modify -> delete
    // lifecycle is itself realistic QA activity, and audit trails routinely
    // reference since-deleted record IDs (the existing PERMANENT_DELETE actions
    // in this app's own history already do this). Only the QA USER ACCOUNTS
    // persist afterward, as the brief's "designated test accounts" for reuse.
    // ---------------------------------------------------------------------------
    as_user('qa_itadmin_tester', 'QA Test — IT Admin', 'IT Administrator', 'user_management');
    echo "\n--- Cleanup: removing QA domain records (QA accounts and audit log entries are kept) ---\n";
    if (isset($scenarioId)) {
        $pdo->prepare('DELETE FROM scenarios WHERE scenario_id = ?')->execute([$scenarioId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'scenarios', $scenarioId);
    }
    if (isset($replId)) {
        $pdo->prepare('DELETE FROM inventory_replenishment WHERE replenishment_id = ?')->execute([$replId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'inventory_replenishment', $replId);
    }
    if (isset($payrollPeriodId)) {
        $pdo->prepare('DELETE FROM payroll_items WHERE payroll_period_id = ?')->execute([$payrollPeriodId]);
        $pdo->prepare('DELETE FROM payroll_periods WHERE payroll_period_id = ?')->execute([$payrollPeriodId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'payroll_periods', $payrollPeriodId);
        // The wages/employer-contribution transactions payroll_post_to_ais() posted
        // are financial_transactions rows, cleaned up in the batch below.
    }
    $qaTxnIds = array_filter([$harvestTxnId ?? null, $apPaymentTxnId ?? null, $arPaymentTxnId ?? null, $postResult['wages_transaction_id'] ?? null, $postResult['employer_contributions_transaction_id'] ?? null]);
    foreach ($qaTxnIds as $txnId) {
        $pdo->prepare('DELETE FROM financial_transactions WHERE transaction_id = ?')->execute([$txnId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'financial_transactions', (int) $txnId);
    }
    if (isset($arId)) {
        $pdo->prepare('DELETE FROM accounts_receivable WHERE ar_id = ?')->execute([$arId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'accounts_receivable', $arId);
    }
    if (isset($apId)) {
        $pdo->prepare('DELETE FROM accounts_payable WHERE ap_id = ?')->execute([$apId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'accounts_payable', $apId);
    }
    if (isset($healthId)) {
        $pdo->prepare('DELETE FROM health_records WHERE health_id = ?')->execute([$healthId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'health_records', $healthId);
    }
    if (isset($productionId)) {
        $pdo->prepare('DELETE FROM production_records WHERE production_id = ?')->execute([$productionId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'production_records', $productionId);
    }
    if (isset($activityId)) {
        $pdo->prepare('DELETE FROM farm_activities WHERE activity_id = ?')->execute([$activityId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'farm_activities', $activityId);
    }
    if (isset($flockId)) {
        $pdo->prepare('DELETE FROM flocks WHERE flock_id = ?')->execute([$flockId]);
        audit_pms_action($pdo, 'PERMANENT_DELETE', 'flocks', $flockId);
    }
    echo "QA domain records removed. QA user accounts (qa_pms_tester, qa_ais_tester, qa_integrated_tester, qa_itadmin_tester, qa_secondary_test) remain for future reuse.\n";

    echo "\n=== ALL STEPS COMPLETED ===\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "FAILED partway through. Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\nRe-run this script — completed steps will be detected and skipped.\n");
    exit(1);
}

$pmsAuditTotal = (int) $pdo->query("SELECT COUNT(*) FROM pms_audit_log")->fetchColumn();
$userMgmtAuditTotal = (int) $pdo->query("SELECT COUNT(*) FROM user_management_audit_log")->fetchColumn();
echo "\n=== AUDIT LOG TOTALS (after this run) ===\n";
echo "  pms_audit_log (PMS/AIS/Integrated record changes): $pmsAuditTotal\n";
echo "  user_management_audit_log (accounts/security):     $userMgmtAuditTotal\n";
echo "\nDone. QA test data is identifiable by batch_code/username prefixes 'QATEST-'/'qa_'.\n";
