<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/pms_records.php';
require_once __DIR__ . '/ais_records.php';
require_once __DIR__ . '/integrated_records.php';
require_once __DIR__ . '/user_management_records.php';

/**
 * Same as app_url(), but appends a `?v=<file mtime>` cache-busting query string
 * so the browser is forced to refetch a static asset (CSS/JS) whenever the file
 * on disk actually changes, instead of silently reusing a stale cached copy —
 * Apache serves these files with no Cache-Control/Expires header, so without
 * this a browser's own heuristic caching can keep serving an old version.
 */
function versioned_asset_url(string $path): string
{
    $absolutePath = rtrim(realpath(__DIR__ . '/../..'), '/\\') . '/' . ltrim($path, '/');
    $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : '0';

    return app_url($path) . '?v=' . $version;
}

function app_url(string $path = '/'): string
{
    static $basePath = null;

    if ($basePath === null) {
        // URL prefix of the project root (e.g. "/SIA2"), found by removing the running
        // script's path relative to the project root from its URL path.
        $basePath = '';
        $projectRoot = realpath(dirname(__DIR__, 2));
        $scriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($projectRoot !== false && $scriptFile !== false && stripos($scriptFile, $projectRoot) === 0) {
            $relative = '/' . ltrim(str_replace('\\', '/', substr($scriptFile, strlen($projectRoot))), '/');
            if (strlen($scriptName) >= strlen($relative) && strcasecmp(substr($scriptName, -strlen($relative)), $relative) === 0) {
                $basePath = rtrim(substr($scriptName, 0, -strlen($relative)), '/');
            }
        }
    }

    return $basePath . '/' . ltrim($path, '/');
}

/**
 * Resolves the URL of a brand's logo file under shared/assets/logos/<brand>/.
 * Not called anywhere yet — the folders are prepared ahead of the actual logo
 * files being placed; wire this into a page's markup once a file exists.
 *
 * @param string $brand   'yield' | 'pms' | 'ais' | 'integrated'
 * @param string $variant 'logo' (default) | 'logo-mark' | 'logo-light' | 'logo-dark' | 'favicon'
 */
function logo_url(string $brand, string $variant = 'logo'): string
{
    $allowedBrands = ['yield', 'pms', 'ais', 'integrated'];
    $allowedVariants = ['logo', 'logo-mark', 'logo-light', 'logo-dark', 'favicon'];
    if (!in_array($brand, $allowedBrands, true) || !in_array($variant, $allowedVariants, true)) {
        throw new InvalidArgumentException('Unknown logo brand or variant.');
    }

    return app_url("/shared/assets/logos/{$brand}/{$brand}-{$variant}.png");
}

function is_authenticated(): bool
{
    return isset($_SESSION['user']['username']);
}

/**
 * Which of pms/ais/integrated/user_management the CURRENT request belongs to,
 * inferred from the running script's own path — not a redirect target. Returns
 * null for pages outside all four systems (e.g. the landing page, login.php
 * itself), which are not subject to the per-system authorization check below.
 */
function sia2_current_request_system(): ?string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['pms', 'ais', 'integrated'] as $system) {
        if (str_contains($scriptName, '/' . $system . '/')) {
            return $system;
        }
    }
    if (str_contains($scriptName, '/shared/user-management/')) {
        return 'user_management';
    }

    return null;
}

/**
 * Gate used at the top of every PMS/AIS/Integrated/User-Management page. Two
 * checks, in order: (1) is there a session at all, and (2) is that session's
 * account authorized for the system this specific page belongs to. Check (2)
 * is what stops a valid AIS account from reaching /pms/... etc. by typing the
 * URL directly — it is enforced here, once, for every call site, rather than
 * relying on any single page or the navigation UI to hide the wrong links.
 */
function require_authentication(): void
{
    if (!is_authenticated()) {
        $loginUrl = app_url('/shared/auth/login.php');
        $destination = sia2_safe_redirect_target($_SERVER['REQUEST_URI'] ?? '');
        if ($destination !== null) {
            $loginUrl .= '?redirect=' . rawurlencode($destination);
        }
        redirect_to($loginUrl);
    }

    $requiredSystem = sia2_current_request_system();
    $grantedSystem = $_SESSION['user']['system_access'] ?? null;
    if ($requiredSystem !== null && $grantedSystem !== $requiredSystem) {
        try {
            user_management_log_event(
                database_connection(),
                'UNAUTHORIZED_ACCESS',
                current_username(),
                current_username(),
                $requiredSystem,
                'Attempted direct access to an unauthorized system.'
            );
        } catch (Throwable $exception) {
            error_log('Unauthorized-access audit log write failed: ' . $exception->getMessage());
        }
        set_flash('danger', 'Your account is not authorized to access this system.');
        redirect_to(app_url('/'));
    }
}

/**
 * Validates a post-login redirect destination so login.php can send the user back to
 * wherever they came from (PMS, AIS, or Integrated) instead of always landing on PMS.
 * Returns null for anything that is not a safe, first-party, in-app path — an absolute
 * URL, a protocol-relative "//host/..." URL, or a path outside pms/ais/integrated/root
 * are all rejected, which is what keeps this from becoming an open redirect.
 */
function sia2_safe_redirect_target(?string $target): ?string
{
    if ($target === null || $target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
        return null;
    }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target) || str_contains($target, '\\')) {
        return null;
    }

    $basePath = rtrim(app_url('/'), '/');
    if ($basePath !== '' && $target !== $basePath && !str_starts_with($target, $basePath . '/')) {
        return null;
    }

    $relative = '/' . ltrim(substr($target, strlen($basePath)), '/');
    $isRoot = $relative === '/' || $relative === '/index.php' || str_starts_with($relative, '/index.php?');
    $isKnownSystem = false;
    foreach (['/pms/', '/ais/', '/integrated/', '/shared/user-management/'] as $systemPrefix) {
        if (str_starts_with($relative, $systemPrefix)) {
            $isKnownSystem = true;
            break;
        }
    }

    return ($isRoot || $isKnownSystem) ? $target : null;
}

/**
 * Infers which system (pms/ais/integrated) a post-login destination belongs to, so the
 * shared login page can present that system's branding/colors. Defaults to 'pms' for the
 * platform root or anything unrecognized — there is still only one login and one session
 * behind this; this only changes what the login page looks like.
 */
function sia2_infer_system_from_path(?string $target): string
{
    if ($target === null || $target === '') {
        return 'pms';
    }
    $basePath = rtrim(app_url('/'), '/');
    $relative = '/' . ltrim(substr($target, strlen($basePath)), '/');
    foreach (['ais', 'integrated'] as $system) {
        if (str_starts_with($relative, '/' . $system . '/')) {
            return $system;
        }
    }
    if (str_starts_with($relative, '/shared/user-management/')) {
        return 'user_management';
    }

    return 'pms';
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function can_manage_flocks(): bool
{
    return is_poultry_admin();
}

/**
 * True for any of the three operational-system accounts (PMS/AIS/Integrated).
 * Each of those systems has exactly one account authorized to use it, so that
 * account is inherently "the admin" of its own system — this preserves every
 * existing is_poultry_admin()-gated feature (Reports, Recycle Bin, manage
 * actions) exactly as before, now that a session also carries system_access.
 * An IT Administrator session is never authorized for pms/ais/integrated
 * pages in the first place (require_authentication() blocks it earlier), so
 * this never needs to consider that case.
 */
function is_poultry_admin(): bool
{
    return in_array($_SESSION['user']['system_access'] ?? null, ['pms', 'ais', 'integrated'], true);
}

function current_username(): string
{
    return (string) ($_SESSION['user']['username'] ?? 'guest');
}

function current_display_name(): string
{
    return (string) ($_SESSION['user']['display_name'] ?? current_username());
}

// Despite the name (kept for backward compatibility with existing PMS call sites),
// this is the one shared platform audit log used by PMS, AIS, and Integrated alike —
// table_name already disambiguates which system a row belongs to, so no separate
// per-system log is needed.
function audit_pms_action(PDO $pdo, string $action, string $table, int $recordId): void
{
    $allowedTables = [
        'flocks', 'poultry_inventory', 'farm_activities', 'production_records', 'health_records',
        'inventory_adjustments',
        'financial_transactions', 'accounts_receivable', 'accounts_payable', 'transaction_documents', 'accounts',
        'batch_costs', 'inventory_replenishment', 'scenarios',
    ];
    $allowedActions = ['CREATE', 'UPDATE', 'SOFT_DELETE', 'RESTORE', 'PERMANENT_DELETE'];
    if (!in_array($table, $allowedTables, true) || !in_array($action, $allowedActions, true) || $recordId < 1) {
        throw new InvalidArgumentException('Invalid audit entry.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO pms_audit_log (action, table_name, record_id, username) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$action, $table, $recordId, current_username()]);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}