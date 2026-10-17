-- Centralized, per-system User Management.
--
-- Supersedes the previous Platform User Management module (platform_users /
-- platform_audit_log), which was a *separate* credential store layered on top
-- of the original single shared admin_account.php account. This migration
-- consolidates everything into ONE users table that is now the single source
-- of truth for all four account types (PMS, AIS, Integrated, User Management),
-- each restricted to exactly one system via system_access. Nothing outside
-- these two tables is touched; flocks/financial_transactions/accounts/etc. are
-- untouched.
--
-- Safe to run repeatedly (RENAME TABLE / ADD COLUMN are all information_schema
-- guarded below).

USE sia2;

-- 1. Rename platform_users -> users (only if platform_users exists and users doesn't yet).
SET @db = DATABASE();
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='platform_users') = 1
    AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users') = 0,
    'RENAME TABLE platform_users TO users',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Rename platform_audit_log -> user_management_audit_log (same guard pattern).
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='platform_audit_log') = 1
    AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME='user_management_audit_log') = 0,
    'RENAME TABLE platform_audit_log TO user_management_audit_log',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- In case this is a fresh database where neither 004 nor this migration has ever run,
-- create both tables outright with their final shape.
CREATE TABLE IF NOT EXISTS users (
    user_id INT NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    system_access ENUM('pms','ais','integrated','user_management') NOT NULL,
    role VARCHAR(30) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    deleted_at DATETIME NULL,
    deleted_by VARCHAR(100) NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uniq_users_username (username),
    KEY idx_users_system_access (system_access),
    KEY idx_users_status (status),
    KEY idx_users_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_management_audit_log (
    log_id INT NOT NULL AUTO_INCREMENT,
    event_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_username VARCHAR(100) NULL,
    action VARCHAR(50) NOT NULL,
    target_username VARCHAR(100) NULL,
    system_context VARCHAR(30) NULL,
    description VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    PRIMARY KEY (log_id),
    KEY idx_um_audit_event_time (event_time),
    KEY idx_um_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Migrate the renamed `users` table (formerly platform_users) to its final shape,
--    each guarded independently so this is safe whether it ran fresh or via rename.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='full_name') = 1
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='display_name') = 0,
    'ALTER TABLE users CHANGE COLUMN full_name display_name VARCHAR(150) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='system_access') = 0,
    "ALTER TABLE users ADD COLUMN system_access ENUM('pms','ais','integrated','user_management') NOT NULL DEFAULT 'user_management' AFTER password_hash",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND INDEX_NAME='idx_users_system_access') = 0,
    'ALTER TABLE users ADD INDEX idx_users_system_access (system_access)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='users' AND COLUMN_NAME='updated_at') = 0,
    'ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Migrate user_management_audit_log to its final shape.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='user_management_audit_log' AND COLUMN_NAME='system_context') = 0,
    'ALTER TABLE user_management_audit_log ADD COLUMN system_context VARCHAR(30) NULL AFTER target_username',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Re-point the pre-existing seeded account (username 'admin') at PMS. This was
--    seeded in 004 as a "Platform Administrator" before this module's design was
--    finalized to be per-system; it must now represent the PMS account instead,
--    keeping its original password hash unchanged.
UPDATE users
SET display_name = 'PMS Administrator', system_access = 'pms', role = 'PMS User'
WHERE username = 'admin' AND (system_access <> 'pms' OR role <> 'PMS User');
