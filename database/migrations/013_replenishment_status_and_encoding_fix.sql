-- Integrated: Inventory Replenishment urgency colour-coding fix.
--
-- (1) Repairs mojibake in inventory_replenishment.recommendation: the text
--     was UTF-8 (containing an em dash, U+2014) that got stored/retrieved
--     through a latin1 connection at some point, turning "—" into the
--     3-byte sequence that prints as "â€”". Fixed only for rows that
--     actually contain that byte sequence (verified against a temporary
--     copy before writing this), using the standard MySQL double-convert
--     repair. Safe to run repeatedly — already-fixed rows no longer match
--     the WHERE clause.
--
-- (2) Adds a dedicated recommendation_status column instead of relying on
--     fragile string-matching against free text (which silently broke once
--     demo data added a "[DEMO] " prefix — see
--     shared/includes/integrated_records.php's replenishment_status_badge()
--     and integrated/inventory-replenishment/index.php). Backfilled from
--     existing rows via substring match against each of the four known,
--     mutually-exclusive status phrases (robust to the "[DEMO] " prefix and
--     to the mojibake above, since it runs after the repair). Written
--     directly on every new row going forward — see
--     integrated/inventory-replenishment/index.php's action=create handler.

USE sia2;

UPDATE inventory_replenishment
SET recommendation = CONVERT(CAST(CONVERT(recommendation USING latin1) AS BINARY) USING utf8mb4)
WHERE recommendation LIKE CONCAT('%', 0xC3A2E282AC, '%');

SET @db = DATABASE();
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='inventory_replenishment' AND COLUMN_NAME='recommendation_status') = 0,
    "ALTER TABLE inventory_replenishment ADD COLUMN recommendation_status ENUM('Sufficient Stock','Replenishment Recommended','Insufficient Budget','High Priority Replenishment') NULL AFTER recommendation",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE inventory_replenishment SET recommendation_status = 'High Priority Replenishment'
WHERE recommendation_status IS NULL AND recommendation LIKE '%High Priority Replenishment%';
UPDATE inventory_replenishment SET recommendation_status = 'Insufficient Budget'
WHERE recommendation_status IS NULL AND recommendation LIKE '%Insufficient Budget%';
UPDATE inventory_replenishment SET recommendation_status = 'Replenishment Recommended'
WHERE recommendation_status IS NULL AND recommendation LIKE '%Replenishment Recommended%';
UPDATE inventory_replenishment SET recommendation_status = 'Sufficient Stock'
WHERE recommendation_status IS NULL AND recommendation LIKE '%Sufficient Stock%';
