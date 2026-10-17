<?php
declare(strict_types=1);

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
    $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE `$idColumn` = ? AND deleted_at IS NULL");
    $statement->execute([current_username(), $recordId]);
    if ($statement->rowCount() !== 1) {
        return false;
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

    $statement = $pdo->prepare("UPDATE `$table` SET deleted_at = NULL, deleted_by = NULL WHERE `$idColumn` = ? AND deleted_at IS NOT NULL");
    $statement->execute([$recordId]);
    if ($statement->rowCount() !== 1) {
        return false;
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