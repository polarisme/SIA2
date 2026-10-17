<?php
declare(strict_types=1);

// Disease early-warning thresholds (3.4) — computed live on every call, never
// stored, so changing these takes effect immediately with no migration.
const PMS_DISEASE_CLUSTER_MIN_RECORDS = 2;      // (a) this many Disease records...
const PMS_DISEASE_CLUSTER_WINDOW_DAYS = 7;      // ...for the same flock, within this many days
const PMS_HIGH_MORTALITY_RATE_PERCENT = 1.0;    // (b) 7-day mortality above this % of current_birds
const PMS_HIGH_MORTALITY_WINDOW_DAYS = 7;
const PMS_STALE_HEALTH_CASE_DAYS = 7;           // (c) still Observed/Under Treatment longer than this

/**
 * Computed live from flocks/health_records/production_records — no alert is
 * ever stored. Returns ['disease_clusters' => [...], 'high_mortality' => [...],
 * 'stale_cases' => [...]], each possibly empty.
 */
function pms_health_alerts(PDO $pdo): array
{
    $diseaseClusters = $pdo->prepare(
        "SELECT h.flock_id, f.batch_code, COUNT(*) AS disease_count
         FROM health_records h JOIN flocks f ON f.flock_id = h.flock_id
         WHERE h.deleted_at IS NULL AND f.deleted_at IS NULL AND h.record_type = 'Disease'
           AND h.record_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY h.flock_id, f.batch_code
         HAVING disease_count >= ?
         ORDER BY disease_count DESC"
    );
    $diseaseClusters->execute([PMS_DISEASE_CLUSTER_WINDOW_DAYS, PMS_DISEASE_CLUSTER_MIN_RECORDS]);

    $highMortality = $pdo->prepare(
        "SELECT p.flock_id, f.batch_code, f.current_birds, SUM(p.mortality_count) AS mortality_7d,
                (SUM(p.mortality_count) / f.current_birds * 100) AS mortality_rate
         FROM production_records p JOIN flocks f ON f.flock_id = p.flock_id
         WHERE p.deleted_at IS NULL AND f.deleted_at IS NULL AND f.current_birds > 0
           AND p.record_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY p.flock_id, f.batch_code, f.current_birds
         HAVING mortality_rate > ?
         ORDER BY mortality_rate DESC"
    );
    $highMortality->execute([PMS_HIGH_MORTALITY_WINDOW_DAYS, PMS_HIGH_MORTALITY_RATE_PERCENT]);

    $staleCases = $pdo->prepare(
        "SELECT h.health_id, h.flock_id, f.batch_code, h.record_type, h.status, h.record_date,
                DATEDIFF(CURDATE(), h.record_date) AS days_open
         FROM health_records h JOIN flocks f ON f.flock_id = h.flock_id
         WHERE h.deleted_at IS NULL AND f.deleted_at IS NULL
           AND h.status IN ('Observed', 'Under Treatment')
           AND DATEDIFF(CURDATE(), h.record_date) > ?
         ORDER BY days_open DESC"
    );
    $staleCases->execute([PMS_STALE_HEALTH_CASE_DAYS]);

    return [
        'disease_clusters' => $diseaseClusters->fetchAll(),
        'high_mortality' => $highMortality->fetchAll(),
        'stale_cases' => $staleCases->fetchAll(),
    ];
}

/**
 * A flock's age is always computed from start_date (plus age_at_arrival_days,
 * for birds that weren't day-old chicks when the batch started) — never
 * stored. Returns ['days' => int, 'weeks' => float].
 */
function pms_flock_age(string $startDate, int $ageAtArrivalDays = 0): array
{
    $start = new DateTimeImmutable($startDate);
    $today = new DateTimeImmutable('today');
    $interval = $start->diff($today);
    $daysSinceStart = $interval->invert === 1 ? 0 : $interval->days;
    $days = max(0, $daysSinceStart + $ageAtArrivalDays);

    return ['days' => $days, 'weeks' => round($days / 7, 1)];
}

/**
 * Adjusts a flock's current_birds by $delta (positive adds birds back,
 * negative removes them — e.g. a mortality count being recorded, edited, or
 * reversed). Locks the row first so concurrent adjustments can't race past
 * each other. Throws if the result would go below zero rather than letting
 * it happen — the caller is expected to be inside a transaction and to roll
 * back on this exception.
 */
function pms_adjust_flock_birds(PDO $pdo, int $flockId, int $delta): void
{
    if ($delta === 0) {
        return;
    }
    $statement = $pdo->prepare('SELECT current_birds FROM flocks WHERE flock_id = ? AND deleted_at IS NULL FOR UPDATE');
    $statement->execute([$flockId]);
    $current = $statement->fetchColumn();
    if ($current === false) {
        return;
    }
    $newCount = (int) $current + $delta;
    if ($newCount < 0) {
        throw new RuntimeException('This would reduce the flock\'s current birds below zero.');
    }
    $pdo->prepare('UPDATE flocks SET current_birds = ? WHERE flock_id = ?')->execute([$newCount, $flockId]);
}

/**
 * The one place quantity actually changes on poultry_inventory, and the one
 * place a row is written to inventory_adjustments — used by the manual
 * "Adjust stock" action and by the usage-based deductions from Health
 * Records / Farm Activities (3.5), so the status recalculation and history
 * logging can't drift between those call sites. Caller is expected to be
 * inside a transaction if this write needs to be atomic with something else
 * (e.g. saving the health/activity record it was triggered from); throws
 * RuntimeException — which the caller should let roll that transaction back
 * — if a "Reduce" would take quantity below zero.
 */
function pms_apply_inventory_adjustment(
    PDO $pdo,
    int $itemId,
    string $direction,
    string $quantity,
    string $reason,
    string $source,
    ?int $sourceId,
    ?string $adjustedBy = null
): int {
    if (!in_array($direction, ['Add', 'Reduce'], true)) {
        throw new InvalidArgumentException('Invalid adjustment direction.');
    }

    $lock = $pdo->prepare('SELECT quantity FROM poultry_inventory WHERE item_id = ? AND deleted_at IS NULL FOR UPDATE');
    $lock->execute([$itemId]);
    $quantityBefore = $lock->fetchColumn();
    if ($quantityBefore === false) {
        throw new RuntimeException('The inventory item could not be found.');
    }

    $delta = $direction === 'Add' ? $quantity : '-' . $quantity;
    $statement = $pdo->prepare(
        "UPDATE poultry_inventory
         SET status = CASE
                 WHEN quantity + :status_delta <= 0 THEN 'Out of Stock'
                 WHEN quantity + :status_delta_low <= reorder_level THEN 'Low Stock'
                 ELSE 'Available'
             END
             , quantity = quantity + :delta
         WHERE item_id = :item_id AND quantity + :guard_delta >= 0"
    );
    $statement->execute([
        'delta' => $delta, 'status_delta' => $delta, 'status_delta_low' => $delta,
        'item_id' => $itemId, 'guard_delta' => $delta,
    ]);
    if ($statement->rowCount() === 0) {
        throw new RuntimeException('This would reduce inventory below zero.');
    }

    $quantityAfter = number_format((float) $quantityBefore + ($direction === 'Add' ? (float) $quantity : -(float) $quantity), 2, '.', '');
    $historyStatement = $pdo->prepare(
        'INSERT INTO inventory_adjustments
            (item_id, direction, quantity, quantity_before, quantity_after, reason, source, source_id, adjusted_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $historyStatement->execute([
        $itemId, $direction, $quantity, $quantityBefore, $quantityAfter, $reason, $source, $sourceId,
        $adjustedBy ?? current_username(),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Finds the active inventory item whose name matches $itemName exactly
 * (case-insensitive) — used to offer "deduct from inventory" when a Health
 * Record's medicine_or_vaccine, or a Farm Activity's item field, names a
 * real stock item. Returns null if there's no match.
 */
function pms_find_inventory_item_by_name(PDO $pdo, string $itemName): ?array
{
    if ($itemName === '') {
        return null;
    }
    $statement = $pdo->prepare('SELECT * FROM poultry_inventory WHERE deleted_at IS NULL AND LOWER(item_name) = LOWER(?)');
    $statement->execute([$itemName]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function pms_record_types(): array
{
    return [
        'flocks' => ['table' => 'flocks', 'id' => 'flock_id', 'label' => 'Flock', 'identity' => 'batch_code'],
        'poultry_inventory' => ['table' => 'poultry_inventory', 'id' => 'item_id', 'label' => 'Inventory item', 'identity' => 'item_code'],
        'farm_activities' => ['table' => 'farm_activities', 'id' => 'activity_id', 'label' => 'Farm activity', 'identity' => 'activity_type'],
        'production_records' => ['table' => 'production_records', 'id' => 'production_id', 'label' => 'Production record', 'identity' => 'record_date'],
        'health_records' => ['table' => 'health_records', 'id' => 'health_id', 'label' => 'Health record', 'identity' => 'record_type'],
    ];
}

function pms_record_identity_column(string $type): string
{
    $types = pms_record_types();
    if (!isset($types[$type])) {
        throw new InvalidArgumentException('Unsupported PMS record type.');
    }

    return $types[$type]['identity'];
}

function pms_audit_action(PDO $pdo, string $action, string $type, int $recordId): void
{
    $types = pms_record_types();
    if (!isset($types[$type]) || $recordId < 1
        || !in_array($action, ['CREATE', 'UPDATE', 'SOFT_DELETE', 'RESTORE', 'PERMANENT_DELETE'], true)) {
        throw new InvalidArgumentException('Invalid audit entry.');
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO pms_audit_log (action, table_name, record_id, username) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$action, $types[$type]['table'], $recordId, current_username()]);
    } catch (PDOException $exception) {
        error_log('PMS audit log write failed: ' . $exception->getMessage());
    }
}

function pms_soft_delete(PDO $pdo, string $type, int $recordId): bool
{
    $types = pms_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return false;
    }
    if (in_array($type, ['flocks', 'poultry_inventory'], true)
        && pms_permanent_delete_blockers($pdo, $type, $recordId) !== []) {
        throw new RuntimeException('This record has related history and cannot be moved to the Recycle Bin.');
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];

    // A deleted production record no longer counts, so its mortality's effect
    // on the flock's current_birds must be reversed (birds added back) —
    // done in the same transaction as the soft delete itself.
    $reverseMortality = null;
    if ($type === 'production_records') {
        $lookup = $pdo->prepare('SELECT flock_id, mortality_count FROM production_records WHERE production_id = ? AND deleted_at IS NULL');
        $lookup->execute([$recordId]);
        $reverseMortality = $lookup->fetch();
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE `$idColumn` = ? AND deleted_at IS NULL");
        $statement->execute([current_username(), $recordId]);
        if ($statement->rowCount() !== 1) {
            $pdo->rollBack();

            return false;
        }
        if ($reverseMortality !== null && $reverseMortality !== false) {
            pms_adjust_flock_birds($pdo, (int) $reverseMortality['flock_id'], (int) $reverseMortality['mortality_count']);
        }
        $pdo->commit();
    } catch (RuntimeException $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    pms_audit_action($pdo, 'SOFT_DELETE', $type, $recordId);

    return true;
}

function pms_restore_record(PDO $pdo, string $type, int $recordId): bool
{
    $types = pms_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return false;
    }
    if (in_array($type, ['farm_activities', 'production_records', 'health_records'], true)) {
        $statement = $pdo->prepare("SELECT flock_id FROM `{$types[$type]['table']}` WHERE `{$types[$type]['id']}` = ? AND deleted_at IS NOT NULL");
        $statement->execute([$recordId]);
        $flockId = $statement->fetchColumn();
        if ($flockId === false) {
            return false;
        }
        if ($flockId !== null) {
            $parent = $pdo->prepare('SELECT 1 FROM flocks WHERE flock_id = ? AND deleted_at IS NULL');
            $parent->execute([$flockId]);
            if ($parent->fetchColumn() === false) {
                throw new RuntimeException('Restore the associated flock first, then restore this record.');
            }
        }
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    if ($type === 'flocks') {
        $statement = $pdo->prepare("SELECT batch_code FROM `$table` WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
        $statement->execute([$recordId]);
        $batchCode = $statement->fetchColumn();
        if ($batchCode === false) {
            return false;
        }
        $duplicate = $pdo->prepare('SELECT 1 FROM flocks WHERE batch_code = ? AND flock_id <> ? AND deleted_at IS NULL');
        $duplicate->execute([$batchCode, $recordId]);
        if ($duplicate->fetchColumn() !== false) {
            throw new RuntimeException('Cannot restore this flock because an active flock already uses its batch code.');
        }
    } elseif ($type === 'poultry_inventory') {
        $statement = $pdo->prepare("SELECT item_code FROM `$table` WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
        $statement->execute([$recordId]);
        $itemCode = $statement->fetchColumn();
        if ($itemCode === false) {
            return false;
        }
        $duplicate = $pdo->prepare('SELECT 1 FROM poultry_inventory WHERE item_code = ? AND item_id <> ? AND deleted_at IS NULL');
        $duplicate->execute([$itemCode, $recordId]);
        if ($duplicate->fetchColumn() !== false) {
            throw new RuntimeException('Cannot restore this inventory item because an active item already uses its item code.');
        }
    }

    // Restoring a production record re-applies its mortality to the flock,
    // which can legitimately be refused if other changes since it was
    // deleted mean the flock no longer has that many birds to lose — done in
    // the same transaction as the restore itself.
    $reapplyMortality = null;
    if ($type === 'production_records') {
        $lookup = $pdo->prepare('SELECT flock_id, mortality_count FROM production_records WHERE production_id = ? AND deleted_at IS NOT NULL');
        $lookup->execute([$recordId]);
        $reapplyMortality = $lookup->fetch();
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = NULL, deleted_by = NULL WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
        $statement->execute([$recordId]);
        if ($statement->rowCount() !== 1) {
            $pdo->rollBack();

            return false;
        }
        if ($reapplyMortality !== null && $reapplyMortality !== false) {
            pms_adjust_flock_birds($pdo, (int) $reapplyMortality['flock_id'], -(int) $reapplyMortality['mortality_count']);
        }
        $pdo->commit();
    } catch (RuntimeException $exception) {
        $pdo->rollBack();
        throw new RuntimeException('Cannot restore: the flock no longer has enough birds to account for this record\'s mortality count.');
    }
    pms_audit_action($pdo, 'RESTORE', $type, $recordId);

    return true;
}

function pms_permanent_delete_blockers(PDO $pdo, string $type, int $recordId): array
{
    $references = match ($type) {
        'flocks' => [
            ['farm_activities', 'flock_id'],
            ['production_records', 'flock_id'],
            ['health_records', 'flock_id'],
            ['batch_costs', 'flock_id'],
            ['batch_profitability', 'flock_id'],
            ['financial_transactions', 'flock_id'],
        ],
        'poultry_inventory' => [['inventory_replenishment', 'item_id']],
        default => [],
    };

    $blockers = [];
    foreach ($references as [$table, $column]) {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
        $statement->execute([$recordId]);
        $count = (int) $statement->fetchColumn();
        if ($count > 0) {
            $blockers[] = $count . ' related record(s) in ' . str_replace('_', ' ', $table);
        }
    }

    return $blockers;
}

function pms_permanent_delete(PDO $pdo, string $type, int $recordId): array
{
    $types = pms_record_types();
    if (!isset($types[$type]) || $recordId < 1) {
        return ['deleted' => false, 'blockers' => ['Invalid record type or ID.']];
    }
    $table = $types[$type]['table'];
    $idColumn = $types[$type]['id'];
    $exists = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
    $exists->execute([$recordId]);
    if ($exists->fetchColumn() === false) {
        return ['deleted' => false, 'blockers' => ['The record is not in the Recycle Bin.']];
    }

    $blockers = pms_permanent_delete_blockers($pdo, $type, $recordId);
    if ($blockers !== []) {
        return ['deleted' => false, 'blockers' => $blockers];
    }

    $statement = $pdo->prepare("DELETE FROM `$table` WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
    $statement->execute([$recordId]);
    if ($statement->rowCount() !== 1) {
        return ['deleted' => false, 'blockers' => ['The record could not be deleted.']];
    }
    pms_audit_action($pdo, 'PERMANENT_DELETE', $type, $recordId);

    return ['deleted' => true, 'blockers' => []];
}