-- ============================================================================
-- YIELD: starter chart of accounts for AIS (ais/accounts/).
--
-- These are the baseline account codes AIS is built around (used by the
-- Income Statement / Cash Flow / Statement of Financial Position reports).
-- No transactions, AR/AP, or any other sample data is seeded here — only
-- these starter account rows, which the app expects to exist before any
-- financial activity can be categorized.
--
-- Safe to run repeatedly: INSERT IGNORE + the UNIQUE KEY on account_code
-- make this idempotent. Run after database/schema.sql.
-- ============================================================================

USE sia2;

INSERT IGNORE INTO accounts (account_code, account_name, account_type, description, status) VALUES
('1000', 'Cash', 'Asset', 'Cash on hand', 'Active'),
('1100', 'Accounts Receivable', 'Asset', 'Money owed by customers', 'Active'),
('1200', 'Inventory', 'Asset', 'Farm inventory', 'Active'),
('2000', 'Accounts Payable', 'Liability', 'Money owed to suppliers', 'Active'),
('3000', 'Owner Equity', 'Equity', 'Owner investment', 'Active'),
('4000', 'Poultry Sales', 'Revenue', 'Revenue from poultry sales', 'Active'),
('4100', 'Egg Sales', 'Revenue', 'Revenue from egg sales', 'Active'),
('5000', 'Feed Expense', 'Expense', 'Cost of poultry feed', 'Active'),
('5100', 'Medicine Expense', 'Expense', 'Cost of medicine', 'Active'),
('5200', 'Vaccination Expense', 'Expense', 'Cost of vaccines', 'Active'),
('5300', 'Labor Expense', 'Expense', 'Farm labor costs', 'Active'),
('5400', 'Utilities Expense', 'Expense', 'Electricity and water', 'Active'),
('5500', 'Farm Supplies Expense', 'Expense', 'Other farm supplies', 'Active');
