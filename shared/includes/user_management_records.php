<?php
declare(strict_types=1);

// Shared helpers for the centralized User Management module
// (shared/user-management/). Authentication and session state for that module
// are NOT separate from PMS/AIS/Integrated — it is the exact same $_SESSION['user']
// / is_authenticated() / require_authentication() mechanism, just for accounts
// whose system_access is 'user_management'. Only the record-keeping helpers
// below (roles, audit log) are specific to this module.

const USER_STATUSES = ['Active', 'Inactive'];

const SYSTEM_ROLE_MAP = [
    'pms' => 'Poultry Admin',
    'ais' => 'Accounting Admin',
    'integrated' => 'Manager',
    'user_management' => 'IT Administrator',
];

const SYSTEM_LABELS = [
    'pms' => 'PMS',
    'ais' => 'AIS',
    'integrated' => 'Integrated',
    'user_management' => 'User Management',
];

const USER_MANAGEMENT_AUDIT_ACTIONS = [
    'USER_CREATED', 'USER_EDITED', 'USER_ACTIVATED', 'USER_DEACTIVATED',
    'USER_DELETED', 'USER_RESTORED', 'USER_PERMANENTLY_DELETED', 'PASSWORD_RESET',
    'LOGIN', 'LOGOUT', 'LOGIN_FAILED', 'UNAUTHORIZED_ACCESS',
];

// Record-change audit (pms_audit_log) — one shared log for PMS/AIS/Integrated
// data rows; table_name is how shared/user-management/audit-trail.php's
// "Record Changes" tab labels which system a row belongs to.
const RECORD_AUDIT_ACTIONS = ['CREATE', 'UPDATE', 'SOFT_DELETE', 'RESTORE', 'PERMANENT_DELETE'];

const TABLE_SYSTEM_MAP = [
    'flocks' => 'pms', 'poultry_inventory' => 'pms', 'farm_activities' => 'pms',
    'production_records' => 'pms', 'health_records' => 'pms', 'inventory_adjustments' => 'pms',
    'financial_transactions' => 'ais', 'accounts_receivable' => 'ais', 'accounts_payable' => 'ais',
    'transaction_documents' => 'ais', 'accounts' => 'ais',
    'batch_costs' => 'integrated', 'inventory_replenishment' => 'integrated', 'scenarios' => 'integrated',
];

/** Best-effort client IP — safe to be wrong/blank; never relied on as a real security control. */
function user_management_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);

        return trim($parts[0]);
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Records one account/security event to the centralized audit trail. Covers
 * every system, not just User Management itself — login/logout/unauthorized
 * access on PMS/AIS/Integrated land here too, via $systemContext. Never pass
 * a password or hash in $description.
 */
function user_management_log_event(
    PDO $pdo,
    string $action,
    ?string $actorUsername,
    ?string $targetUsername,
    ?string $systemContext,
    string $description
): void {
    if (!in_array($action, USER_MANAGEMENT_AUDIT_ACTIONS, true)) {
        throw new InvalidArgumentException('Invalid user management audit action.');
    }
    try {
        $statement = $pdo->prepare(
            'INSERT INTO user_management_audit_log (actor_username, action, target_username, system_context, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $actorUsername,
            $action,
            $targetUsername,
            $systemContext,
            mb_strimwidth($description, 0, 255, ''),
            user_management_client_ip(),
        ]);
    } catch (PDOException $exception) {
        error_log('User management audit log write failed: ' . $exception->getMessage());
    }
}

// Both badge helpers are used only within the User Management interface
// (the Users table and its filters), which is styled as a strict
// black/white/gray interface — so these intentionally return neutral
// Bootstrap variants only (dark/secondary), never a hue-based one
// (success/primary/warning), regardless of which system a row belongs to.
function user_status_badge(string $status): string
{
    return $status === 'Active' ? 'dark' : 'secondary';
}

function system_badge(string $system): string
{
    return 'secondary';
}
