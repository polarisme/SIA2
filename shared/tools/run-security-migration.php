<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = database_connection();
$tables = [
    'flocks' => ['idx_flocks_deleted_at'],
    'poultry_inventory' => ['idx_inventory_deleted_at'],
    'farm_activities' => ['idx_activities_deleted_at'],
    'production_records' => ['idx_production_deleted_at'],
    'health_records' => ['idx_health_deleted_at'],
];

foreach ($tables as $table => $indexes) {
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );

    foreach (['deleted_at' => 'DATETIME NULL', 'deleted_by' => 'VARCHAR(100) NULL'] as $column => $definition) {
        $columnCheck->execute([$table, $column]);
        if ((int) $columnCheck->fetchColumn() === 0) {
            $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        }
    }

    $indexCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    foreach ($indexes as $indexName) {
        $indexCheck->execute([$table, $indexName]);
        if ((int) $indexCheck->fetchColumn() === 0) {
            $pdo->exec(sprintf('ALTER TABLE `%s` ADD INDEX `%s` (`deleted_at`)', $table, $indexName));
        }
    }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS pms_audit_log (
        audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(32) NOT NULL,
        table_name VARCHAR(64) NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        username VARCHAR(100) NOT NULL,
        action_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pms_audit_record (table_name, record_id),
        INDEX idx_pms_audit_action_at (action_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "PMS security/recycle-bin migration applied (or already current).\n";