-- ============================================================================
-- YIELD: Poultry Farm Operations and Accounting Management System
-- Full database schema for `sia2`.
--
-- Generated from the live development database via
-- `mysqldump --no-data sia2`, then hand-cleaned: DROP TABLE statements were
-- removed and every CREATE TABLE converted to CREATE TABLE IF NOT EXISTS, so
-- this file is safe to import into either an empty database or one that
-- already has some of these tables. AUTO_INCREMENT start values from the dump
-- were stripped (a fresh install should start every table at 1).
--
-- Usage (fresh install):
--   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS sia2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root -p sia2 < database/schema.sql
--   mysql -u root -p sia2 < database/seed.sql
--   php database/setup/provision_accounts.php
--
-- This file reflects the schema's CURRENT, fully-migrated shape. The
-- individual numbered files under database/migrations/ are the historical/
-- incremental record of how it got here (ALTER TABLE steps); you do not need
-- to run those after importing this file on a fresh database ??? they exist
-- for reference and for upgrading an older, already-running copy of this
-- database in place.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS sia2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sia2;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- Platform: user accounts and the centralized audit trail
-- (shared/user-management/, shared/auth/login.php)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `system_access` enum('pms','ais','integrated','user_management') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user_management',
  `role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Administrator',
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uniq_platform_username` (`username`),
  KEY `idx_platform_users_status` (`status`),
  KEY `idx_platform_users_deleted_at` (`deleted_at`),
  KEY `idx_users_system_access` (`system_access`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_management_audit_log (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `event_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actor_username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `system_context` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_platform_audit_event_time` (`event_time`),
  KEY `idx_platform_audit_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pms_audit_log (
  `audit_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` bigint unsigned NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `idx_pms_audit_record` (`table_name`,`record_id`),
  KEY `idx_pms_audit_action_at` (`action_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- PMS: Poultry Management System (pms/)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS flocks (
  `flock_id` int NOT NULL AUTO_INCREMENT,
  `batch_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `breed` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `age_at_arrival_days` int NOT NULL DEFAULT '0',
  `initial_birds` int NOT NULL,
  `current_birds` int NOT NULL,
  `status` enum('Active','Completed','Sold','Terminated') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`flock_id`),
  UNIQUE KEY `batch_code` (`batch_code`),
  KEY `idx_flock_status` (`status`),
  KEY `idx_flocks_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poultry_inventory (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `item_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('Feed','Vaccine','Medicine','Farm Supply','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reorder_level` decimal(12,2) DEFAULT '0.00',
  `unit_cost` decimal(12,2) DEFAULT '0.00',
  `status` enum('Available','Low Stock','Out of Stock') COLLATE utf8mb4_unicode_ci DEFAULT 'Available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `item_code` (`item_code`),
  KEY `idx_inventory_category` (`category`),
  KEY `idx_inventory_status` (`status`),
  KEY `idx_inventory_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS farm_activities (
  `activity_id` int NOT NULL AUTO_INCREMENT,
  `flock_id` int DEFAULT NULL,
  `activity_type` enum('Feeding','Cleaning','Vaccination','Medication','Egg Collection','Weighing','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduled_date` date NOT NULL,
  `completed_date` date DEFAULT NULL,
  `assigned_to` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inventory_item_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inventory_quantity_used` decimal(12,2) DEFAULT NULL,
  `status` enum('Scheduled','In Progress','Completed','Missed','Cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'Scheduled',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`activity_id`),
  KEY `fk_activity_flock` (`flock_id`),
  KEY `idx_activity_date_status` (`scheduled_date`,`status`),
  KEY `idx_activities_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_records (
  `production_id` int NOT NULL AUTO_INCREMENT,
  `flock_id` int NOT NULL,
  `record_date` date NOT NULL,
  `eggs_produced` int DEFAULT '0',
  `good_eggs` int DEFAULT '0',
  `damaged_eggs` int DEFAULT '0',
  `sample_size` int DEFAULT '0',
  `average_weight` decimal(8,2) DEFAULT '0.00',
  `mortality_count` int DEFAULT '0',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`production_id`),
  KEY `idx_production_flock_date` (`flock_id`,`record_date`),
  KEY `idx_production_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS health_records (
  `health_id` int NOT NULL AUTO_INCREMENT,
  `flock_id` int NOT NULL,
  `record_date` date NOT NULL,
  `record_type` enum('Disease','Vaccination','Treatment','Health Check') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `affected_birds` int DEFAULT '0',
  `medicine_or_vaccine` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity_used` decimal(10,2) DEFAULT '0.00',
  `unit` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_taken` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Observed','Under Treatment','Resolved','Monitoring') COLLATE utf8mb4_unicode_ci DEFAULT 'Observed',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`health_id`),
  KEY `idx_health_flock_date` (`flock_id`,`record_date`),
  KEY `idx_health_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_adjustments (
  `adjustment_id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `direction` enum('Add','Reduce') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `quantity_before` decimal(12,2) NOT NULL,
  `quantity_after` decimal(12,2) NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` enum('Manual','Health Record','Farm Activity') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Manual',
  `source_id` int DEFAULT NULL,
  `adjusted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adjusted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`adjustment_id`),
  KEY `idx_inventory_adjustments_item` (`item_id`),
  KEY `idx_inventory_adjustments_adjusted_at` (`adjusted_at`),
  KEY `idx_inventory_adjustments_source` (`source`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- AIS: Accounting Information System (ais/)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS financial_transactions (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('Income','Expense') COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `flock_id` int DEFAULT NULL,
  `payment_method` enum('Cash','Bank Transfer','E-Wallet','Credit','Other') COLLATE utf8mb4_unicode_ci DEFAULT 'Cash',
  `reference_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Recorded','Pending','Cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'Recorded',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `fk_transaction_flock` (`flock_id`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_transactions_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_documents (
  `document_id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `document_type` enum('Receipt','Invoice','Official Receipt','Purchase Order','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `fk_document_transaction` (`transaction_id`),
  KEY `idx_documents_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_receivable (
  `ar_id` int NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `flock_id` int DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `amount_due` decimal(14,2) NOT NULL,
  `amount_paid` decimal(14,2) DEFAULT '0.00',
  `balance` decimal(14,2) GENERATED ALWAYS AS ((`amount_due` - `amount_paid`)) STORED,
  `status` enum('Unpaid','Partially Paid','Paid','Overdue') COLLATE utf8mb4_unicode_ci DEFAULT 'Unpaid',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`ar_id`),
  KEY `fk_ar_transaction` (`transaction_id`),
  KEY `idx_ar_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_payable (
  `ap_id` int NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `flock_id` int DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `amount_due` decimal(14,2) NOT NULL,
  `amount_paid` decimal(14,2) DEFAULT '0.00',
  `balance` decimal(14,2) GENERATED ALWAYS AS ((`amount_due` - `amount_paid`)) STORED,
  `status` enum('Unpaid','Partially Paid','Paid','Overdue') COLLATE utf8mb4_unicode_ci DEFAULT 'Unpaid',
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`ap_id`),
  KEY `fk_ap_transaction` (`transaction_id`),
  KEY `idx_ap_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ar_ap_payments (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `ledger` enum('AR','AP') COLLATE utf8mb4_unicode_ci NOT NULL,
  `record_id` int NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('Cash','Bank Transfer','E-Wallet','Credit','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Cash',
  `transaction_id` int DEFAULT NULL,
  `recorded_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `idx_ar_ap_payments_ledger_record` (`ledger`,`record_id`),
  KEY `idx_ar_ap_payments_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
  `account_id` int NOT NULL AUTO_INCREMENT,
  `account_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('Asset','Liability','Equity','Revenue','Expense') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('Active','Inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'Active',
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `account_code` (`account_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Integrated: Integrated Poultry Farm Operations & Accounting (integrated/)
-- Reads PMS/AIS data (flocks, financial_transactions, etc.) but never writes
-- to any PMS or AIS table ??? these four tables are the only ones it owns.
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS batch_costs (
  `cost_id` int NOT NULL AUTO_INCREMENT,
  `flock_id` int NOT NULL,
  `cost_date` date NOT NULL,
  `cost_category` enum('Feed','Medicine','Vaccination','Labor','Utilities','Farm Supplies','Other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `source_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`cost_id`),
  KEY `idx_batch_cost_flock` (`flock_id`),
  KEY `idx_batch_costs_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batch_profitability (
  `profitability_id` int NOT NULL AUTO_INCREMENT,
  `flock_id` int NOT NULL,
  `total_cost` decimal(14,2) DEFAULT '0.00',
  `total_revenue` decimal(14,2) DEFAULT '0.00',
  `gross_profit` decimal(14,2) GENERATED ALWAYS AS ((`total_revenue` - `total_cost`)) STORED,
  `profit_margin` decimal(8,2) GENERATED ALWAYS AS ((case when (`total_revenue` > 0) then (((`total_revenue` - `total_cost`) / `total_revenue`) * 100) else 0 end)) STORED,
  `calculation_date` date NOT NULL,
  PRIMARY KEY (`profitability_id`),
  UNIQUE KEY `flock_id` (`flock_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_replenishment (
  `replenishment_id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `current_stock` decimal(12,2) NOT NULL,
  `expected_demand` decimal(12,2) NOT NULL,
  `recommended_quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) DEFAULT '0.00',
  `estimated_cost` decimal(14,2) GENERATED ALWAYS AS ((`recommended_quantity` * `unit_price`)) STORED,
  `available_budget` decimal(14,2) DEFAULT '0.00',
  `recommendation` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Pending','Approved','Rejected','Completed') COLLATE utf8mb4_unicode_ci DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`replenishment_id`),
  KEY `fk_replenishment_item` (`item_id`),
  KEY `idx_replenishment_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scenarios (
  `scenario_id` int NOT NULL AUTO_INCREMENT,
  `scenario_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `current_flock_size` int DEFAULT '0',
  `proposed_flock_size` int DEFAULT '0',
  `projected_production` decimal(14,2) DEFAULT '0.00',
  `projected_cost` decimal(14,2) DEFAULT '0.00',
  `projected_revenue` decimal(14,2) DEFAULT '0.00',
  `projected_profit` decimal(14,2) GENERATED ALWAYS AS ((`projected_revenue` - `projected_cost`)) STORED,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`scenario_id`),
  KEY `idx_scenarios_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
