<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('A valid logout form is required.');
}

if (is_authenticated()) {
    try {
        $pdo = database_connection();
        $username = current_username();
        $system = $_SESSION['user']['system_access'] ?? null;
        user_management_log_event($pdo, 'LOGOUT', $username, $username, $system, 'Signed out.');
    } catch (PDOException $exception) {
        error_log('Logout audit log write failed: ' . $exception->getMessage());
    }
}

unset($_SESSION['user'], $_SESSION['csrf_token']);
session_regenerate_id(true);
set_flash('info', 'You have been signed out.');
// Always the public YIELD landing page — never a specific system's URL, which
// would otherwise bounce back through that system's (PMS-themed, by default)
// login page regardless of which system the user actually signed out of.
redirect_to(app_url('/'));
