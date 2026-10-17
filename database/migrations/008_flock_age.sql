-- PMS: age in days/weeks is computed from start_date and is never stored.
-- This one optional column lets a flock's age account for birds that were
-- already a few days old on arrival (rather than day-old chicks), instead
-- of assuming day zero == start_date. Defaults to 0, so existing flocks'
-- age calculation is unaffected until someone edits it.

SET NAMES utf8mb4;

USE sia2;

SET @db = DATABASE();
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='flocks' AND COLUMN_NAME='age_at_arrival_days') = 0,
    'ALTER TABLE flocks ADD COLUMN age_at_arrival_days INT NOT NULL DEFAULT 0 AFTER start_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

