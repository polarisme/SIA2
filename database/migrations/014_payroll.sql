-- Payroll (Part 8): employee records, payroll periods, and per-employee payroll
-- line items, integrated with AIS (Labor Expense postings) and the Integrated
-- System (per-batch labor cost allocation via batch_costs). Idempotent — safe
-- to run more than once.
--
-- Statutory contribution rates below (SSS 15% total / 5% employee + 10%
-- employer, salary credit ceiling PHP 35,000; PhilHealth 5% total / 2.5% +
-- 2.5%, ceiling PHP 100,000; Pag-IBIG 2% + 2%, capped at PHP 200 each side)
-- reflect published 2025 rates at the time this migration was written and are
-- applied uniformly across the whole dataset for simplicity — this is a
-- modeling approximation for a fictional farm's demo data, not a certified
-- payroll computation, and must be re-verified against current official
-- circulars before any real payroll use.

SET NAMES utf8mb4;

USE sia2;

CREATE TABLE IF NOT EXISTS employees (
    employee_id INT NOT NULL AUTO_INCREMENT,
    employee_code VARCHAR(20) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    job_title VARCHAR(100) NOT NULL,
    department ENUM(
        'Farm Management', 'Broiler Operations', 'Layer Operations', 'Feed & Inventory',
        'Maintenance', 'Egg Collection & Packing', 'Harvest & Loading', 'Administration & Accounting'
    ) NOT NULL,
    employment_status ENUM('Active', 'Inactive', 'Terminated') NOT NULL DEFAULT 'Active',
    pay_type ENUM('Daily', 'Monthly') NOT NULL DEFAULT 'Daily',
    basic_rate DECIMAL(10,2) NOT NULL,
    date_hired DATE NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by VARCHAR(100) NULL,
    PRIMARY KEY (employee_id),
    UNIQUE KEY uniq_employee_code (employee_code),
    KEY idx_employees_department (department),
    KEY idx_employees_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_periods (
    payroll_period_id INT NOT NULL AUTO_INCREMENT,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    pay_date DATE NOT NULL,
    status ENUM('Draft', 'Reviewed', 'Approved', 'Paid') NOT NULL DEFAULT 'Draft',
    created_by VARCHAR(100) NULL,
    approved_by VARCHAR(100) NULL,
    approved_at DATETIME NULL,
    paid_by VARCHAR(100) NULL,
    paid_at DATETIME NULL,
    -- Set only once the period is actually paid (see shared/includes/payroll_records.php),
    -- so a recalculation or re-approval can never post the Labor Expense transactions twice.
    wages_transaction_id INT NULL,
    employer_contributions_transaction_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    deleted_by VARCHAR(100) NULL,
    PRIMARY KEY (payroll_period_id),
    UNIQUE KEY uniq_payroll_period_dates (period_start, period_end),
    KEY idx_payroll_periods_status (status),
    KEY idx_payroll_periods_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_items (
    payroll_item_id INT NOT NULL AUTO_INCREMENT,
    payroll_period_id INT NOT NULL,
    employee_id INT NOT NULL,
    days_worked DECIMAL(5,2) NOT NULL DEFAULT 0,
    basic_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
    gross_pay DECIMAL(12,2) GENERATED ALWAYS AS (basic_pay + overtime_pay + allowances) STORED,
    sss_employee DECIMAL(10,2) NOT NULL DEFAULT 0,
    philhealth_employee DECIMAL(10,2) NOT NULL DEFAULT 0,
    pagibig_employee DECIMAL(10,2) NOT NULL DEFAULT 0,
    other_deductions DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_deductions DECIMAL(12,2) GENERATED ALWAYS AS (sss_employee + philhealth_employee + pagibig_employee + other_deductions) STORED,
    net_pay DECIMAL(12,2) GENERATED ALWAYS AS (basic_pay + overtime_pay + allowances - sss_employee - philhealth_employee - pagibig_employee - other_deductions) STORED,
    sss_employer DECIMAL(10,2) NOT NULL DEFAULT 0,
    philhealth_employer DECIMAL(10,2) NOT NULL DEFAULT 0,
    pagibig_employer DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- The farm's full cost for this employee this period: gross pay actually
    -- disbursed plus the employer's own statutory share. Employee-side
    -- deductions are NOT part of this — they are withheld from gross_pay
    -- (already reducing net_pay) and are a pass-through to government
    -- agencies, never an additional farm expense.
    employer_cost DECIMAL(12,2) GENERATED ALWAYS AS (basic_pay + overtime_pay + allowances + sss_employer + philhealth_employer + pagibig_employer) STORED,
    -- How this employee's employer_cost is allocated into batch_costs: a flock-
    -- specific worker allocates entirely to that flock; an overhead/shared role
    -- (e.g. farm management, maintenance, feed & inventory, administration) is
    -- spread across that period's active flocks in proportion to bird-days,
    -- the same convention already used for other shared overhead.
    allocation_type ENUM('Flock', 'Overhead') NOT NULL DEFAULT 'Overhead',
    allocation_flock_id INT NULL,
    remarks VARCHAR(255) NULL,
    deleted_at TIMESTAMP NULL,
    deleted_by VARCHAR(100) NULL,
    PRIMARY KEY (payroll_item_id),
    UNIQUE KEY uniq_payroll_item_period_employee (payroll_period_id, employee_id),
    KEY idx_payroll_items_period (payroll_period_id),
    KEY idx_payroll_items_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
