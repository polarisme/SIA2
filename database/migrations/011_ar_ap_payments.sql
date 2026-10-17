-- AIS: Automated Income/Expense Recording (4.1). Recording a payment on an
-- AR/AP record now automatically posts a financial_transactions row and
-- keeps a payment history — previously a payment only updated amount_paid/
-- status on the AR/AP row itself, with no ledger entry and no history.

SET NAMES utf8mb4;

USE sia2;

SET @db = DATABASE();
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='accounts_receivable' AND COLUMN_NAME='flock_id') = 0,
    'ALTER TABLE accounts_receivable ADD COLUMN flock_id INT NULL AFTER transaction_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='accounts_payable' AND COLUMN_NAME='flock_id') = 0,
    'ALTER TABLE accounts_payable ADD COLUMN flock_id INT NULL AFTER transaction_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ar_ap_payments (
    payment_id INT NOT NULL AUTO_INCREMENT,
    ledger ENUM('AR','AP') NOT NULL,
    record_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('Cash','Bank Transfer','E-Wallet','Credit','Other') NOT NULL DEFAULT 'Cash',
    transaction_id INT NULL,
    recorded_by VARCHAR(100) NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    KEY idx_ar_ap_payments_ledger_record (ledger, record_id),
    KEY idx_ar_ap_payments_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

