-- PMS: optional inventory usage fields for Farm Activities of type Feeding/
-- Vaccination/Medication (3.5) — mirrors what health_records already had
-- (medicine_or_vaccine/quantity_used), which farm_activities never had.

SET NAMES utf8mb4;

USE sia2;

SET @db = DATABASE();
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='farm_activities' AND COLUMN_NAME='inventory_item_name') = 0,
    'ALTER TABLE farm_activities ADD COLUMN inventory_item_name VARCHAR(150) NULL AFTER assigned_to',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='farm_activities' AND COLUMN_NAME='inventory_quantity_used') = 0,
    'ALTER TABLE farm_activities ADD COLUMN inventory_quantity_used DECIMAL(12,2) NULL AFTER inventory_item_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

