-- Platform User Management: a new administration module, separate from PMS/AIS/
-- Integrated. Two new tables only; nothing existing is altered.
--
-- Inspection findings (see the implementation report for detail):
--   - The current single "admin" account lives in shared/config/admin_account.php
--     as a plain file (username + bcrypt hash), NOT in any database table. There is
--     no existing "users" table anywhere in `sia2` — `accounts` is AIS's unrelated
--     chart-of-accounts, and `pms_audit_log` records CRUD on PMS/AIS/Integrated data
--     rows (table_name + record_id), a different shape than a security/auth event
--     log. Neither can safely be reused for this module, so two new tables are
--     genuinely required.
--
-- Safe to run repeatedly (CREATE TABLE IF NOT EXISTS + INSERT IGNORE). Does not
-- touch flocks, financial_transactions, accounts, pms_audit_log, or any other
-- existing table.

SET NAMES utf8mb4;

USE sia2;

CREATE TABLE IF NOT EXISTS platform_users (
    user_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'Administrator',
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_by VARCHAR(100) NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uniq_platform_username (username),
    KEY idx_platform_users_status (status),
    KEY idx_platform_users_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_audit_log (
    log_id INT NOT NULL AUTO_INCREMENT,
    event_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_username VARCHAR(100) NULL,
    action VARCHAR(50) NOT NULL,
    target_username VARCHAR(100) NULL,
    description VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    PRIMARY KEY (log_id),
    KEY idx_platform_audit_event_time (event_time),
    KEY idx_platform_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the one existing administrator into the new table, using the EXACT same
-- bcrypt hash already in shared/config/admin_account.php — the password does not
-- change. INSERT IGNORE + the UNIQUE key on username make this idempotent.
INSERT IGNORE INTO platform_users (username, full_name, password_hash, role, status)
VALUES ('admin', 'Platform Administrator', '$2y$10$rbb7VT5hJ4H2JFzE8Xi5wes1hL8oO4ECpEN1xf8y3rKYq6jew4ezW', 'Administrator', 'Active');

