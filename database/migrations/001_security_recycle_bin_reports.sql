-- Poultry Farm Management System: soft deletion and lightweight audit log.
-- Safe to run repeatedly in MySQL 8.4/phpMyAdmin. Existing records are preserved.

SET NAMES utf8mb4;

SET @pms_schema = DATABASE();

SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='flocks' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE flocks ADD COLUMN deleted_at DATETIME NULL',
	'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='flocks' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE flocks ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='flocks' AND INDEX_NAME='idx_flocks_deleted_at') = 0,
	'ALTER TABLE flocks ADD INDEX idx_flocks_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;

SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='poultry_inventory' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE poultry_inventory ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='poultry_inventory' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE poultry_inventory ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='poultry_inventory' AND INDEX_NAME='idx_inventory_deleted_at') = 0,
	'ALTER TABLE poultry_inventory ADD INDEX idx_inventory_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;

SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='farm_activities' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE farm_activities ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='farm_activities' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE farm_activities ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='farm_activities' AND INDEX_NAME='idx_activities_deleted_at') = 0,
	'ALTER TABLE farm_activities ADD INDEX idx_activities_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;

SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='production_records' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE production_records ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='production_records' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE production_records ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='production_records' AND INDEX_NAME='idx_production_deleted_at') = 0,
	'ALTER TABLE production_records ADD INDEX idx_production_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;

SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='health_records' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE health_records ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='health_records' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE health_records ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;
SET @pms_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@pms_schema AND TABLE_NAME='health_records' AND INDEX_NAME='idx_health_deleted_at') = 0,
	'ALTER TABLE health_records ADD INDEX idx_health_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE pms_stmt FROM @pms_sql; EXECUTE pms_stmt; DEALLOCATE PREPARE pms_stmt;

CREATE TABLE IF NOT EXISTS pms_audit_log (
	audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	action VARCHAR(32) NOT NULL,
	table_name VARCHAR(64) NOT NULL,
	record_id BIGINT UNSIGNED NOT NULL,
	username VARCHAR(100) NOT NULL,
	action_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_pms_audit_record (table_name, record_id),
	INDEX idx_pms_audit_action_at (action_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
