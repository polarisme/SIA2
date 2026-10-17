-- Integrated System: soft deletion for batch_costs, inventory_replenishment, and
-- scenarios, mirroring the PMS/AIS pattern (001_security_recycle_bin_reports.sql,
-- 002_ais_recycle_bin.sql). Safe to run repeatedly. Existing records are preserved;
-- this only adds columns. batch_profitability is intentionally NOT touched here —
-- it is a system-managed cache with no user delete action, not a user record type.

SET NAMES utf8mb4;

SET @int_schema = DATABASE();

SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='batch_costs' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE batch_costs ADD COLUMN deleted_at DATETIME NULL',
	'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;
SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='batch_costs' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE batch_costs ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;
SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='batch_costs' AND INDEX_NAME='idx_batch_costs_deleted_at') = 0,
	'ALTER TABLE batch_costs ADD INDEX idx_batch_costs_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;

SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='inventory_replenishment' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE inventory_replenishment ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;
SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='inventory_replenishment' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE inventory_replenishment ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;
SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='inventory_replenishment' AND INDEX_NAME='idx_replenishment_deleted_at') = 0,
	'ALTER TABLE inventory_replenishment ADD INDEX idx_replenishment_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;

SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='scenarios' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE scenarios ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;
SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='scenarios' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE scenarios ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;
SET @int_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@int_schema AND TABLE_NAME='scenarios' AND INDEX_NAME='idx_scenarios_deleted_at') = 0,
	'ALTER TABLE scenarios ADD INDEX idx_scenarios_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE int_stmt FROM @int_sql; EXECUTE int_stmt; DEALLOCATE PREPARE int_stmt;

