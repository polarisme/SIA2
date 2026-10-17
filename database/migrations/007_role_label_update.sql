-- Renames the per-system role labels stored in users.role to match
-- SYSTEM_ROLE_MAP in shared/includes/user_management_records.php:
--   pms -> 'Poultry Admin', ais -> 'Accounting Admin',
--   integrated -> 'Manager', user_management stays 'IT Administrator'.
-- Only rewrites rows still holding the OLD label for that system, so this is
-- safe to run repeatedly and won't touch a role an IT Administrator has since
-- customized by hand.

USE sia2;

UPDATE users SET role = 'Poultry Admin' WHERE system_access = 'pms' AND role = 'PMS User';
UPDATE users SET role = 'Accounting Admin' WHERE system_access = 'ais' AND role = 'AIS User';
UPDATE users SET role = 'Manager' WHERE system_access = 'integrated' AND role = 'Integrated User';
