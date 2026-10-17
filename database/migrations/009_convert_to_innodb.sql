-- All of the original application tables were created as ENGINE=MyISAM,
-- which does not support transactions at all: PDO::beginTransaction() /
-- commit() / rollBack() silently no-op against it, so a multi-statement
-- "transaction" (e.g. inserting a production record and adjusting a
-- flock's current_birds, or posting an AR/AP payment to the ledger) is not
-- actually atomic — a failure partway through leaves a half-applied,
-- inconsistent write instead of rolling back cleanly. This was caught
-- directly: a mortality count rejected for taking a flock's birds below
-- zero still left its production_records row inserted.
--
-- Converting to InnoDB preserves all data/indexes/engine-level behavior
-- otherwise identical to MyISAM for this app's queries, and is required for
-- PMS mortality adjustments (3.2) and AIS payment-to-ledger posting (4.1)
-- to be genuinely transactional. Safe to run repeatedly — converting a
-- table already on InnoDB is a no-op.

SET NAMES utf8mb4;

USE sia2;

ALTER TABLE accounts ENGINE=InnoDB;
ALTER TABLE accounts_payable ENGINE=InnoDB;
ALTER TABLE accounts_receivable ENGINE=InnoDB;
ALTER TABLE batch_costs ENGINE=InnoDB;
ALTER TABLE batch_profitability ENGINE=InnoDB;
ALTER TABLE farm_activities ENGINE=InnoDB;
ALTER TABLE financial_transactions ENGINE=InnoDB;
ALTER TABLE flocks ENGINE=InnoDB;
ALTER TABLE health_records ENGINE=InnoDB;
ALTER TABLE inventory_replenishment ENGINE=InnoDB;
ALTER TABLE poultry_inventory ENGINE=InnoDB;
ALTER TABLE production_records ENGINE=InnoDB;
ALTER TABLE scenarios ENGINE=InnoDB;
ALTER TABLE transaction_documents ENGINE=InnoDB;

