-- AIS: soft deletion for the Accounting Information System, mirroring the PMS
-- pattern from 001_security_recycle_bin_reports.sql. Safe to run repeatedly in
-- MySQL 8.4/phpMyAdmin. Existing records are preserved; this only adds columns.

SET NAMES utf8mb4;

SET @ais_schema = DATABASE();

SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='financial_transactions' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE financial_transactions ADD COLUMN deleted_at DATETIME NULL',
	'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='financial_transactions' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE financial_transactions ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='financial_transactions' AND INDEX_NAME='idx_transactions_deleted_at') = 0,
	'ALTER TABLE financial_transactions ADD INDEX idx_transactions_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;

SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='accounts_receivable' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE accounts_receivable ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='accounts_receivable' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE accounts_receivable ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='accounts_receivable' AND INDEX_NAME='idx_ar_deleted_at') = 0,
	'ALTER TABLE accounts_receivable ADD INDEX idx_ar_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;

SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='accounts_payable' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE accounts_payable ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='accounts_payable' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE accounts_payable ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='accounts_payable' AND INDEX_NAME='idx_ap_deleted_at') = 0,
	'ALTER TABLE accounts_payable ADD INDEX idx_ap_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;

SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='transaction_documents' AND COLUMN_NAME='deleted_at') = 0,
	'ALTER TABLE transaction_documents ADD COLUMN deleted_at DATETIME NULL', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='transaction_documents' AND COLUMN_NAME='deleted_by') = 0,
	'ALTER TABLE transaction_documents ADD COLUMN deleted_by VARCHAR(100) NULL', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;
SET @ais_sql = IF(
	(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@ais_schema AND TABLE_NAME='transaction_documents' AND INDEX_NAME='idx_documents_deleted_at') = 0,
	'ALTER TABLE transaction_documents ADD INDEX idx_documents_deleted_at (deleted_at)', 'SELECT 1'
);
PREPARE ais_stmt FROM @ais_sql; EXECUTE ais_stmt; DEALLOCATE PREPARE ais_stmt;

