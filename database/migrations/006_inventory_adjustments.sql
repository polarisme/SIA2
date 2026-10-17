-- PMS: records every manual stock adjustment made from pms/inventory/, with
-- the reason the user entered (previously discarded — see
-- pms/inventory/index.php's action=adjust handler). Also used as the
-- source for Health Records / Farm Activities inventory deductions
-- (source = 'Health Record' / 'Farm Activity'). Safe to run repeatedly.

SET NAMES utf8mb4;

USE sia2;

CREATE TABLE IF NOT EXISTS inventory_adjustments (
    adjustment_id INT NOT NULL AUTO_INCREMENT,
    item_id INT NOT NULL,
    direction ENUM('Add','Reduce') NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    quantity_before DECIMAL(12,2) NOT NULL,
    quantity_after DECIMAL(12,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    source ENUM('Manual','Health Record','Farm Activity') NOT NULL DEFAULT 'Manual',
    source_id INT NULL,
    adjusted_by VARCHAR(100) NULL,
    adjusted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (adjustment_id),
    KEY idx_inventory_adjustments_item (item_id),
    KEY idx_inventory_adjustments_adjusted_at (adjusted_at),
    KEY idx_inventory_adjustments_source (source, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

