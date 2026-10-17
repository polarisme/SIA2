-- =============================================================================
-- Integrated System (YIELD) Demonstration Data
-- =============================================================================
-- Target database : sia2
-- Purpose          : Illustrative data for the three Integrated-only tables that
--                    have no way to be populated from real farm activity alone
--                    (batch_costs' allocated-overhead rows, inventory_replenishment
--                    analyses, and scenarios). batch_profitability is NOT seeded
--                    here — it is a live cache the application recalculates itself
--                    every time a relevant page is viewed.
--
-- IMPORTANT
--   * Everything inserted here is clearly labelled as demo/illustrative, both in
--     SQL comments and in a visible marker in the data itself ("(Demo)" in each
--     scenario name, "[DEMO]" in each replenishment recommendation string, and an
--     'INTDEMO-' prefixed source_reference on each allocated batch cost) so it is
--     never mistaken for a real farm record in the UI.
--   * This script is safe to re-run: it first deletes only the rows carrying
--     those markers, then reinserts them. It never touches any other row.
--   * Nothing here alters the schema, and no PMS or AIS table is touched.
--   * Uses the flocks and inventory items already seeded by
--     database/seed/pms_demo_data.sql (FLK-2026-001, FLK-2026-002) and the
--     transactions already seeded by database/seed/ais_demo_data.sql. If those
--     have not been run, the flock/item lookups below simply match nothing and
--     these inserts are skipped harmlessly (nothing breaks).
-- =============================================================================

USE sia2;

-- -----------------------------------------------------------------------------
-- Make re-runs idempotent.
-- -----------------------------------------------------------------------------
DELETE FROM batch_costs WHERE source_reference LIKE 'INTDEMO-%';
DELETE FROM inventory_replenishment WHERE recommendation LIKE '[DEMO]%';
DELETE FROM scenarios WHERE scenario_name LIKE '%(Demo)';

-- -----------------------------------------------------------------------------
-- 1. BATCH_COSTS — illustrative allocation of farm-wide overhead (labor,
--    utilities) that AIS recorded WITHOUT a flock_id, apportioned here across
--    the two active batches roughly by their share of current birds. This is a
--    judgement call made for demonstration purposes, not a system-derived split.
-- -----------------------------------------------------------------------------
INSERT INTO batch_costs (flock_id, cost_date, cost_category, description, amount, source_reference)
SELECT flock_id, '2026-09-01', 'Labor', 'Demo: allocated share of farm-wide labor cost (illustrative split)', 12000.00, 'INTDEMO-ALLOC-001'
FROM flocks WHERE batch_code = 'FLK-2026-001'
UNION ALL
SELECT flock_id, '2026-09-01', 'Utilities', 'Demo: allocated share of farm-wide utilities cost (illustrative split)', 3600.00, 'INTDEMO-ALLOC-002'
FROM flocks WHERE batch_code = 'FLK-2026-001'
UNION ALL
SELECT flock_id, '2026-09-01', 'Labor', 'Demo: allocated share of farm-wide labor cost (illustrative split)', 9500.00, 'INTDEMO-ALLOC-003'
FROM flocks WHERE batch_code = 'FLK-2026-002'
UNION ALL
SELECT flock_id, '2026-09-01', 'Utilities', 'Demo: allocated share of farm-wide utilities cost (illustrative split)', 2900.00, 'INTDEMO-ALLOC-004'
FROM flocks WHERE batch_code = 'FLK-2026-002';

-- -----------------------------------------------------------------------------
-- 2. INVENTORY_REPLENISHMENT — three demo analyses showing the three different
--    outcomes the recommendation rule can produce, using each item's REAL current
--    stock/reorder level and a stated (demo) expected demand and budget.
--      MED-ALB-002 : Out of Stock -> High Priority Replenishment
--      VAC-IB-002  : Low Stock, affordable -> Replenishment Recommended
--      FEED-CORN-001: Well stocked -> Sufficient Stock
-- -----------------------------------------------------------------------------
INSERT INTO inventory_replenishment (item_id, current_stock, expected_demand, recommended_quantity, unit_price, available_budget, recommendation, status)
SELECT item_id, quantity, 5.00, GREATEST(0, 5.00 + reorder_level - quantity), unit_cost, 200.00,
       '[DEMO] High Priority Replenishment — stock is at zero; this item needs immediate restocking regardless of budget.', 'Pending'
FROM poultry_inventory WHERE item_code = 'MED-ALB-002'
UNION ALL
SELECT item_id, quantity, 150.00, GREATEST(0, 150.00 + reorder_level - quantity), unit_cost, 1000.00,
       '[DEMO] Replenishment Recommended — stock is below the reorder buffer for expected demand, and the estimated cost fits the stated budget.', 'Pending'
FROM poultry_inventory WHERE item_code = 'VAC-IB-002'
UNION ALL
SELECT item_id, quantity, 200.00, GREATEST(0, 200.00 + reorder_level - quantity), unit_cost, 5000.00,
       '[DEMO] Sufficient Stock — current stock already covers both the reorder buffer and expected demand.', 'Pending'
FROM poultry_inventory WHERE item_code = 'FEED-CORN-001';

-- -----------------------------------------------------------------------------
-- 3. SCENARIOS — two illustrative what-if simulations. projected_profit is a
--    generated column (projected_revenue - projected_cost); nothing here touches
--    any real flock or transaction.
-- -----------------------------------------------------------------------------
INSERT INTO scenarios (scenario_name, description, current_flock_size, proposed_flock_size, projected_production, projected_cost, projected_revenue)
VALUES
    ('Expand FLK-2026-001 by 20% (Demo)', 'Simulated expansion of the Rhode Island Red flock to test whether a larger batch remains profitable at current feed/vaccination cost levels.', 484, 581, 4320.00, 70000.00, 303000.00),
    ('Reduce FLK-2026-002 due to rising feed cost (Demo)', 'Simulated downsizing of the Leghorn flock in response to a projected feed price increase, to compare against maintaining current size.', 393, 300, 1670.00, 45000.00, 133000.00);

-- =============================================================================
-- End of seed data.
-- =============================================================================
