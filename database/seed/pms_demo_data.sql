-- =============================================================================
-- PMS Capstone Demonstration Dataset
-- =============================================================================
-- Target database : sia2 (the application's live database; do not point this
--                    at poultry_farm_system or any other database)
-- Purpose          : Fictional, internally-consistent sample data for
--                    demonstrating the Poultry Management System during the
--                    capstone presentation.
--
-- IMPORTANT
--   * All flock batch codes, staff labels, and item codes below are entirely
--     fictional. No real people, customers, or organizations are represented.
--   * This script is safe to re-run. Flocks and inventory items are matched
--     by their unique batch_code / item_code and inserted with INSERT IGNORE,
--     so re-running never creates duplicate flocks or inventory rows.
--   * Farm activities, production records, and health records have no natural
--     unique key, so this script first removes any existing rows tied to the
--     three demo flocks listed below, then reinserts the full demo set. This
--     only ever touches rows belonging to these specific demo batch codes; it
--     never deletes rows belonging to any other flock.
--   * Nothing here alters the schema (no CREATE/ALTER/DROP TABLE) and nothing
--     outside the five PMS tables below is touched.
-- =============================================================================

SET NAMES utf8mb4;

USE sia2;

-- -----------------------------------------------------------------------------
-- 1. FLOCKS
-- -----------------------------------------------------------------------------
-- FLK-2026-001 and FLK-2026-002 are the two active demonstration flocks.
-- FLK-2025-011 is the completed demonstration flock (cycle closed out, stock
-- sold/processed, hence current_birds = 0).
-- current_birds below already reflects the cumulative mortality recorded in
-- the production records inserted further down (500-16=484, 400-7=393).
INSERT IGNORE INTO flocks
    (batch_code, breed, start_date, initial_birds, current_birds, status, notes)
VALUES
    ('FLK-2026-001', 'Rhode Island Red', '2026-01-15', 500, 484, 'Active',
     'Primary layer flock, housing block A.'),
    ('FLK-2026-002', 'Leghorn', '2026-03-10', 400, 393, 'Active',
     'Secondary layer flock, housing block B.'),
    ('FLK-2025-011', 'Sasso', '2025-11-01', 350, 0, 'Completed',
     'Cycle completed and closed out on 2026-05-20; remaining stock sold/processed.');

-- -----------------------------------------------------------------------------
-- 2. POULTRY INVENTORY (10 items; status matches the app's own
--    quantity/reorder_level rule so the computed and stored status agree)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO poultry_inventory
    (item_code, item_name, category, quantity, unit, reorder_level, unit_cost, status)
VALUES
    ('FEED-CORN-001', 'Layer Corn-Soy Feed Mix', 'Feed', 850.00, 'kg', 300.00, 18.50, 'Available'),
    ('FEED-STARTER-002', 'Chick Starter Feed', 'Feed', 120.00, 'kg', 150.00, 22.00, 'Low Stock'),
    ('VAC-ND-001', 'Newcastle Disease Vaccine (Lasota)', 'Vaccine', 260.00, 'dose', 200.00, 3.25, 'Available'),
    ('VAC-IB-002', 'Infectious Bronchitis Vaccine', 'Vaccine', 90.00, 'dose', 100.00, 4.10, 'Low Stock'),
    ('MED-TYL-001', 'Tylosin Tartrate Water Soluble', 'Medicine', 4.50, 'kg', 5.00, 45.00, 'Low Stock'),
    ('MED-ALB-002', 'Albendazole Oral Suspension', 'Medicine', 0.00, 'liter', 2.00, 30.00, 'Out of Stock'),
    ('SUP-BED-001', 'Wood Shavings Bedding', 'Farm Supply', 60.00, 'bag', 20.00, 8.75, 'Available'),
    ('SUP-EGGTRAY-002', 'Egg Collection Trays (30-count)', 'Farm Supply', 400.00, 'piece', 100.00, 0.85, 'Available'),
    ('SUP-DISINF-003', 'Poultry House Disinfectant', 'Farm Supply', 15.00, 'liter', 25.00, 6.20, 'Low Stock'),
    ('OTH-BULB-001', 'Heat Lamp Bulbs (250W)', 'Other', 8.00, 'piece', 10.00, 5.50, 'Low Stock');

-- -----------------------------------------------------------------------------
-- Make re-runs idempotent for the tables with no natural unique key: clear out
-- only the demo rows tied to the three batch codes above, then reinsert them.
-- -----------------------------------------------------------------------------
DELETE FROM farm_activities
WHERE flock_id IN (SELECT flock_id FROM flocks WHERE batch_code IN ('FLK-2026-001', 'FLK-2026-002', 'FLK-2025-011'));
DELETE FROM production_records
WHERE flock_id IN (SELECT flock_id FROM flocks WHERE batch_code IN ('FLK-2026-001', 'FLK-2026-002', 'FLK-2025-011'));
DELETE FROM health_records
WHERE flock_id IN (SELECT flock_id FROM flocks WHERE batch_code IN ('FLK-2026-001', 'FLK-2026-002', 'FLK-2025-011'));

-- -----------------------------------------------------------------------------
-- 3. FARM ACTIVITIES (19 records across all 7 activity types and all 5
--    statuses, so Scheduled/Completed/Missed/Cancelled/In Progress all
--    have at least one example)
-- -----------------------------------------------------------------------------
INSERT INTO farm_activities
    (flock_id, activity_type, description, scheduled_date, completed_date, assigned_to, status, remarks)
VALUES
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Vaccination', 'Administer ND vaccine to Flock A', '2026-01-20', '2026-01-20', 'Team B - Health Crew', 'Completed', 'Completed without incident.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Feeding', 'Weekly feed formulation adjustment', '2026-01-22', '2026-01-22', 'Team A - Feed Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Cleaning', 'Deep clean of housing block A', '2026-02-05', '2026-02-05', 'Team A - Feed Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Weighing', 'Monthly bird weight sampling', '2026-03-01', '2026-03-01', 'Team B - Health Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Egg Collection', 'Standard egg collection round', '2026-03-15', '2026-03-15', 'Team A - Feed Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Medication', 'Isolate and treat birds showing respiratory symptoms', '2026-04-29', '2026-04-29', 'Team B - Health Crew', 'Completed', 'Linked to health record dated 2026-04-28.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Medication', 'Administer antibiotic course to affected birds', '2026-05-05', '2026-05-05', 'Team B - Health Crew', 'Completed', 'Linked to treatment health record dated 2026-05-05.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Cleaning', 'Routine housing block A sanitation', '2026-09-05', NULL, 'Team A - Feed Crew', 'Scheduled', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Weighing', 'Monthly bird weight sampling', '2026-09-10', NULL, 'Team B - Health Crew', 'Missed', 'Missed due to staff availability; to be rescheduled.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Vaccination', 'Administer ND vaccine to Flock B', '2026-03-25', '2026-03-25', 'Team B - Health Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Feeding', 'Initial feed schedule setup', '2026-04-01', '2026-04-01', 'Team A - Feed Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Egg Collection', 'Standard egg collection round', '2026-06-01', '2026-06-01', 'Team A - Feed Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Weighing', 'Quarterly health inspection weigh-in', '2026-06-30', '2026-06-30', 'Team B - Health Crew', 'Completed', 'Linked to health record dated 2026-06-30.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Other', 'Housing block B ventilation upgrade', '2026-09-15', NULL, 'Team A - Feed Crew', 'In Progress', 'Contractor scheduled mid-September.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Feeding', 'Weekly feed formulation adjustment', '2026-09-25', NULL, 'Team A - Feed Crew', 'Scheduled', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), 'Vaccination', 'Administer ND vaccine to Flock C', '2025-11-15', '2025-11-15', 'Team B - Health Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), 'Medication', 'Deworming treatment for Flock C', '2026-03-02', '2026-03-02', 'Team B - Health Crew', 'Completed', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), 'Other', 'Final flock closeout and sale processing', '2026-05-20', '2026-05-20', 'Site Supervisor', 'Completed', 'Batch cycle concluded.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), 'Cleaning', 'Post-cycle housing cleanout', '2026-05-25', NULL, 'Team A - Feed Crew', 'Cancelled', 'Cancelled - cleanout merged with block B prep work.');

-- -----------------------------------------------------------------------------
-- 4. PRODUCTION RECORDS (17 records; mortality_count sums to 16 for Flock A
--    and 7 for Flock B, matching the current_birds values inserted above)
-- -----------------------------------------------------------------------------
INSERT INTO production_records
    (flock_id, record_date, eggs_produced, good_eggs, damaged_eggs, sample_size, average_weight, mortality_count, remarks)
VALUES
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-02-01', 410, 395, 15, 50, 1.65, 2, 'Normal week, minor breakage from nesting box.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-03-01', 430, 418, 12, 50, 1.70, 1, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-04-05', 445, 430, 15, 50, 1.75, 3, 'Slight uptick in mortality, monitored.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-05-10', 460, 448, 12, 55, 1.78, 6, 'Mortality spike linked to respiratory illness; see health records.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-06-14', 452, 440, 12, 55, 1.80, 2, 'Recovering after treatment.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-07-19', 465, 452, 13, 55, 1.82, 1, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-08-23', 470, 458, 12, 55, 1.83, 0, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-09-20', 468, 455, 13, 55, 1.84, 1, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-04-12', 340, 330, 10, 45, 1.55, 1, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-05-17', 355, 344, 11, 45, 1.58, 2, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-06-21', 365, 352, 13, 48, 1.60, 1, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-07-26', 372, 360, 12, 48, 1.62, 0, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-08-30', 378, 366, 12, 48, 1.63, 2, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-09-21', 380, 368, 12, 50, 1.64, 1, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), '2025-12-05', 250, 240, 10, 40, 2.05, 3, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), '2026-02-10', 265, 255, 10, 40, 2.10, 2, NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), '2026-04-15', 270, 258, 12, 40, 2.15, 4, 'Final production cycle before batch closeout.');

-- -----------------------------------------------------------------------------
-- 5. HEALTH RECORDS (8 records; includes a full disease -> treatment ->
--    resolved arc for Flock A, plus routine vaccination/health-check history)
-- -----------------------------------------------------------------------------
INSERT INTO health_records
    (flock_id, record_date, record_type, description, affected_birds, medicine_or_vaccine, quantity_used, unit, action_taken, status, remarks)
VALUES
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-01-20', 'Vaccination', 'Newcastle Disease (ND) vaccine administered to entire flock', 500, 'Newcastle Disease Vaccine (Lasota strain)', 500.00, 'dose', 'Routine vaccination per flock health schedule.', 'Resolved', 'No adverse reactions observed.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-04-28', 'Disease', 'Signs of respiratory distress and reduced feed intake observed in housing block A', 35, NULL, 0.00, NULL, 'Isolated affected birds; submitted samples for lab testing.', 'Under Treatment', 'Suspected mild respiratory infection; awaiting lab confirmation.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-05-05', 'Treatment', 'Antibiotic treatment administered following lab confirmation of respiratory infection', 35, 'Tylosin Tartrate Water Soluble', 2.50, 'kg', '5-day antibiotic course administered via drinking water.', 'Under Treatment', 'Mortality monitored closely during treatment window.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), '2026-05-19', 'Health Check', 'Follow-up check after antibiotic treatment course', 35, NULL, 0.00, NULL, 'Visual inspection and weight check of previously affected birds.', 'Resolved', 'Flock condition normalized; no further mortality linked to the outbreak.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-03-25', 'Vaccination', 'Newcastle Disease (ND) vaccine administered to entire flock', 400, 'Newcastle Disease Vaccine (Lasota strain)', 400.00, 'dose', 'Routine vaccination per flock health schedule.', 'Resolved', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), '2026-06-30', 'Health Check', 'Routine quarterly health inspection', 400, NULL, 0.00, NULL, 'General flock inspection, weight sampling, and mortality review.', 'Resolved', 'Flock in good general condition.'),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), '2025-11-15', 'Vaccination', 'Newcastle Disease (ND) vaccine administered to entire flock', 350, 'Newcastle Disease Vaccine (Lasota strain)', 350.00, 'dose', 'Routine vaccination per flock health schedule.', 'Resolved', NULL),
    ((SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), '2026-03-02', 'Treatment', 'Deworming treatment administered as part of standard mid-cycle care', 350, 'Albendazole Oral Suspension', 3.00, 'liter', 'Administered via drinking water over 2 days.', 'Resolved', NULL);

-- =============================================================================
-- End of seed data.
-- =============================================================================

