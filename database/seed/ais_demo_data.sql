-- =============================================================================
-- AIS Capstone Demonstration Dataset
-- =============================================================================
-- Target database : sia2 (do not point this at poultry_farm_system or any
--                    other database)
-- Purpose          : Fictional, internally-consistent sample data for
--                    demonstrating the Accounting Information System (AIS)
--                    during the capstone presentation.
--
-- IMPORTANT
--   * All customer names, supplier names, and reference numbers below are
--     entirely fictional. No real people or organizations are represented.
--   * This script is safe to re-run. All demo rows are tagged with a
--     distinctive prefix ('AISDEMO-...') in reference_number / invoice_number,
--     so re-running first deletes only rows carrying that prefix (and their
--     dependent documents) before reinserting the full set. It never touches
--     any other transaction, receivable, payable, or account.
--   * Nothing here alters the schema (no CREATE/ALTER/DROP TABLE).
--   * financial_transactions.flock_id references the three PMS demo flocks
--     seeded by database/seed/pms_demo_data.sql (FLK-2026-001, FLK-2026-002,
--     FLK-2025-011). If that seed has not been run, the flock lookups below
--     simply resolve to NULL and the transactions are still inserted (with
--     no related flock/batch).
-- =============================================================================

SET NAMES utf8mb4;

USE sia2;

-- -----------------------------------------------------------------------------
-- Make re-runs idempotent: remove only this seed's previous rows (by prefix),
-- in dependency order (documents and links before their parent transactions).
-- -----------------------------------------------------------------------------
DELETE FROM transaction_documents
WHERE transaction_id IN (SELECT transaction_id FROM financial_transactions WHERE reference_number LIKE 'AISDEMO-%');
DELETE FROM accounts_receivable WHERE invoice_number LIKE 'AISDEMO-AR-%';
DELETE FROM accounts_payable WHERE invoice_number LIKE 'AISDEMO-AP-%';
DELETE FROM financial_transactions WHERE reference_number LIKE 'AISDEMO-%';

-- -----------------------------------------------------------------------------
-- 1. FINANCIAL TRANSACTIONS (26 rows: 10 income, 16 expense)
--    Covers multiple categories, all 5 payment methods, a spread of dates
--    across the year, optional flock/batch links, and Recorded / Pending /
--    Cancelled statuses.
-- -----------------------------------------------------------------------------
INSERT INTO financial_transactions
    (transaction_date, transaction_type, category, description, amount, flock_id, payment_method, reference_number, status)
VALUES
    ('2026-02-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock A', 45000.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Cash', 'AISDEMO-INC-001', 'Recorded'),
    ('2026-03-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock A', 47500.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Bank Transfer', 'AISDEMO-INC-002', 'Recorded'),
    ('2026-04-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock A', 49250.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Bank Transfer', 'AISDEMO-INC-003', 'Recorded'),
    ('2026-05-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock B', 51000.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'E-Wallet', 'AISDEMO-INC-004', 'Recorded'),
    ('2026-06-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock B', 52250.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Bank Transfer', 'AISDEMO-INC-005', 'Recorded'),
    ('2026-07-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock B', 53500.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Bank Transfer', 'AISDEMO-INC-006', 'Recorded'),
    ('2026-08-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock A', 54750.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Cash', 'AISDEMO-INC-007', 'Recorded'),
    ('2026-09-05', 'Income', 'Egg Sales', 'Weekly egg sales - Flock A (awaiting clearance)', 56000.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Bank Transfer', 'AISDEMO-INC-008', 'Pending'),
    ('2026-05-20', 'Income', 'Poultry Sales', 'Batch closeout sale - Flock C', 82000.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2025-011'), 'Bank Transfer', 'AISDEMO-INC-009', 'Recorded'),
    ('2026-06-10', 'Income', 'Poultry Sales', 'Bulk order - buyer withdrew before pickup', 15000.00, NULL, 'Cash', 'AISDEMO-INC-010', 'Cancelled'),
    ('2026-01-18', 'Expense', 'Feed Expense', 'Layer feed delivery - Flock A', 22000.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Bank Transfer', 'AISDEMO-EXP-001', 'Recorded'),
    ('2026-02-18', 'Expense', 'Feed Expense', 'Layer feed delivery - Flock A', 23500.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Bank Transfer', 'AISDEMO-EXP-002', 'Recorded'),
    ('2026-03-18', 'Expense', 'Feed Expense', 'Layer feed delivery - Flock B', 24800.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Bank Transfer', 'AISDEMO-EXP-003', 'Recorded'),
    ('2026-04-18', 'Expense', 'Feed Expense', 'Layer feed delivery - Flock B', 25200.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Credit', 'AISDEMO-EXP-004', 'Recorded'),
    ('2026-01-20', 'Expense', 'Vaccination Expense', 'Newcastle Disease vaccine - Flock A', 4200.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Cash', 'AISDEMO-EXP-005', 'Recorded'),
    ('2026-03-25', 'Expense', 'Vaccination Expense', 'Newcastle Disease vaccine - Flock B', 3800.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-002'), 'Cash', 'AISDEMO-EXP-006', 'Recorded'),
    ('2026-05-05', 'Expense', 'Medicine Expense', 'Antibiotic treatment course - Flock A', 6500.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Cash', 'AISDEMO-EXP-007', 'Recorded'),
    ('2026-05-19', 'Expense', 'Medicine Expense', 'Follow-up medical supplies - Flock A', 2100.00, (SELECT flock_id FROM flocks WHERE batch_code = 'FLK-2026-001'), 'Cash', 'AISDEMO-EXP-008', 'Recorded'),
    ('2026-06-01', 'Expense', 'Labor Expense', 'Farm crew wages - June', 18000.00, NULL, 'Bank Transfer', 'AISDEMO-EXP-009', 'Recorded'),
    ('2026-07-01', 'Expense', 'Labor Expense', 'Farm crew wages - July', 18000.00, NULL, 'Bank Transfer', 'AISDEMO-EXP-010', 'Recorded'),
    ('2026-08-01', 'Expense', 'Labor Expense', 'Farm crew wages - August', 18500.00, NULL, 'Bank Transfer', 'AISDEMO-EXP-011', 'Recorded'),
    ('2026-06-15', 'Expense', 'Utilities Expense', 'Electricity and water - June', 5400.00, NULL, 'E-Wallet', 'AISDEMO-EXP-012', 'Recorded'),
    ('2026-07-15', 'Expense', 'Utilities Expense', 'Electricity and water - July', 5600.00, NULL, 'E-Wallet', 'AISDEMO-EXP-013', 'Recorded'),
    ('2026-08-15', 'Expense', 'Utilities Expense', 'Electricity and water - August', 5750.00, NULL, 'E-Wallet', 'AISDEMO-EXP-014', 'Recorded'),
    ('2026-02-10', 'Expense', 'Farm Supplies Expense', 'Bedding and general farm supplies', 3200.00, NULL, 'Other', 'AISDEMO-EXP-015', 'Recorded'),
    ('2026-06-25', 'Expense', 'Farm Supplies Expense', 'Bedding order - awaiting supplier confirmation', 2900.00, NULL, 'Other', 'AISDEMO-EXP-016', 'Pending');

-- -----------------------------------------------------------------------------
-- 2. TRANSACTION DOCUMENTS (metadata for 4 representative transactions)
--    Files referenced here are real placeholder PDFs generated for this demo
--    at ais/transactions/uploads/... so "View" and download work end to end.
-- -----------------------------------------------------------------------------
INSERT INTO transaction_documents (transaction_id, document_type, file_name, file_path)
VALUES
    ((SELECT transaction_id FROM financial_transactions WHERE reference_number = 'AISDEMO-INC-001'),
     'Official Receipt', 'egg-sales-official-receipt-feb.pdf', 'ais/transactions/uploads/2026/02/egg-sales-official-receipt-feb.pdf'),
    ((SELECT transaction_id FROM financial_transactions WHERE reference_number = 'AISDEMO-EXP-001'),
     'Purchase Order', 'feed-purchase-order-jan.pdf', 'ais/transactions/uploads/2026/01/feed-purchase-order-jan.pdf'),
    ((SELECT transaction_id FROM financial_transactions WHERE reference_number = 'AISDEMO-EXP-007'),
     'Receipt', 'medicine-receipt-may.pdf', 'ais/transactions/uploads/2026/05/medicine-receipt-may.pdf'),
    ((SELECT transaction_id FROM financial_transactions WHERE reference_number = 'AISDEMO-INC-009'),
     'Invoice', 'batch-sale-invoice-may.pdf', 'ais/transactions/uploads/2026/05/batch-sale-invoice-may.pdf');

-- -----------------------------------------------------------------------------
-- 3. ACCOUNTS RECEIVABLE (6 rows: paid, partially paid, overdue x2, unpaid)
--    Status values below are the initial stored status; the application
--    always displays/filters by the live-computed status instead, so these
--    stay correct even as real time passes the due dates.
-- -----------------------------------------------------------------------------
INSERT INTO accounts_receivable
    (customer_name, invoice_number, transaction_id, invoice_date, due_date, amount_due, amount_paid, status, remarks)
VALUES
    ('Golden Grain Poultry Traders', 'AISDEMO-AR-001', (SELECT transaction_id FROM financial_transactions WHERE reference_number = 'AISDEMO-INC-009'), '2026-05-15', '2026-06-14', 82000.00, 82000.00, 'Paid', 'Settled in full for the Flock C closeout sale.'),
    ('Metro Fresh Eggs Distributor', 'AISDEMO-AR-002', NULL, '2026-08-01', '2026-08-31', 15000.00, 15000.00, 'Paid', NULL),
    ('Valley Homestead Grocers', 'AISDEMO-AR-003', NULL, '2026-09-10', '2026-10-15', 47500.00, 20000.00, 'Partially Paid', 'Remaining balance due next delivery cycle.'),
    ('Sunridge Wet Market Co-op', 'AISDEMO-AR-004', NULL, '2026-07-01', '2026-08-01', 30000.00, 10000.00, 'Overdue', 'Follow up with buyer; partial payment received.'),
    ('Lakeside Poultry Wholesalers', 'AISDEMO-AR-005', NULL, '2026-06-01', '2026-07-01', 38000.00, 0.00, 'Overdue', 'No payment received; escalate to collections.'),
    ('Northgate Farm Supply Retail', 'AISDEMO-AR-006', NULL, '2026-09-20', '2026-10-20', 26000.00, 0.00, 'Unpaid', NULL);

-- -----------------------------------------------------------------------------
-- 4. ACCOUNTS PAYABLE (6 rows: paid x2, partially paid, overdue x2, unpaid)
-- -----------------------------------------------------------------------------
INSERT INTO accounts_payable
    (supplier_name, invoice_number, transaction_id, invoice_date, due_date, amount_due, amount_paid, status, remarks)
VALUES
    ('Sunrise Feed Mills Corp', 'AISDEMO-AP-001', (SELECT transaction_id FROM financial_transactions WHERE reference_number = 'AISDEMO-EXP-003'), '2026-03-10', '2026-04-09', 24800.00, 24800.00, 'Paid', NULL),
    ('BioShield Animal Health Inc.', 'AISDEMO-AP-002', NULL, '2026-01-15', '2026-02-14', 4200.00, 4200.00, 'Paid', NULL),
    ('AgroVet Supply Co.', 'AISDEMO-AP-003', NULL, '2026-09-08', '2026-10-08', 6500.00, 3000.00, 'Partially Paid', 'Balance scheduled for next payment run.'),
    ('FarmTech Equipment Traders', 'AISDEMO-AP-004', NULL, '2026-07-05', '2026-08-05', 12500.00, 5000.00, 'Overdue', 'Awaiting confirmation from supplier on remaining balance.'),
    ('GreenPasture Feed Distributors', 'AISDEMO-AP-005', NULL, '2026-06-05', '2026-07-05', 25200.00, 0.00, 'Overdue', 'Payment delayed pending cash flow review.'),
    ('Everline Utilities Cooperative', 'AISDEMO-AP-006', NULL, '2026-09-15', '2026-10-15', 5750.00, 0.00, 'Unpaid', NULL);

-- =============================================================================
-- End of seed data.
-- =============================================================================

