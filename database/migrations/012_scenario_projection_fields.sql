-- Integrated: Scenario Analysis auto-projection (5.1). Lets a scenario be
-- based on a real flock's actual per-bird figures, scaled to a proposed
-- size and adjusted by optional feed/egg price and mortality-rate inputs.
-- All nullable — existing scenarios (always manually typed in) are
-- unaffected; base_flock_id is informational only and is never written
-- back to, so this still never modifies PMS or AIS data.

USE sia2;

SET @db = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='scenarios' AND COLUMN_NAME='base_flock_id') = 0,
    'ALTER TABLE scenarios ADD COLUMN base_flock_id INT NULL AFTER proposed_flock_size',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='scenarios' AND COLUMN_NAME='feed_price_change_percent') = 0,
    'ALTER TABLE scenarios ADD COLUMN feed_price_change_percent DECIMAL(6,2) NULL AFTER base_flock_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='scenarios' AND COLUMN_NAME='egg_price_change_percent') = 0,
    'ALTER TABLE scenarios ADD COLUMN egg_price_change_percent DECIMAL(6,2) NULL AFTER feed_price_change_percent',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='scenarios' AND COLUMN_NAME='mortality_rate_percent') = 0,
    'ALTER TABLE scenarios ADD COLUMN mortality_rate_percent DECIMAL(6,2) NULL AFTER egg_price_change_percent',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='scenarios' AND COLUMN_NAME='is_auto_projected') = 0,
    "ALTER TABLE scenarios ADD COLUMN is_auto_projected TINYINT(1) NOT NULL DEFAULT 0 AFTER mortality_rate_percent",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
