<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_authentication();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required.');
}
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    set_flash('danger', 'Your form session expired. Please try again.');
    redirect_to('index.php');
}

$pdo = database_connection();

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
if ($userId === false || $userId === null || $userId < 1) {
    set_flash('danger', 'The selected user could not be found.');
    redirect_to('index.php');
}

$currentUserId = (int) ($_SESSION['user']['user_id'] ?? 0);
if ($userId === $currentUserId) {
    set_flash('danger', 'You cannot deactivate the account you are currently signed in with.');
    redirect_to('index.php');
}

$statement = $pdo->prepare('SELECT username, status, system_access FROM users WHERE user_id = ? AND deleted_at IS NULL');
$statement->execute([$userId]);
$target = $statement->fetch();
if (!$target) {
    set_flash('danger', 'The selected user could not be found.');
    redirect_to('index.php');
}

if ($target['status'] === 'Active' && $target['system_access'] === 'user_management') {
    $guardStatement = $pdo->prepare(
        "SELECT COUNT(*) FROM users WHERE system_access = 'user_management' AND status = 'Active' AND deleted_at IS NULL AND user_id <> ?"
    );
    $guardStatement->execute([$userId]);
    if ((int) $guardStatement->fetchColumn() === 0) {
        set_flash('danger', 'At least one active IT Administrator must remain — cannot deactivate the last one.');
        redirect_to('index.php');
    }
}

$newStatus = $target['status'] === 'Active' ? 'Inactive' : 'Active';
$pdo->prepare('UPDATE users SET status = ? WHERE user_id = ?')->execute([$newStatus, $userId]);
user_management_log_event(
    $pdo,
    $newStatus === 'Active' ? 'USER_ACTIVATED' : 'USER_DEACTIVATED',
    current_username(),
    $target['username'],
    $target['system_access'],
    'Account status changed to ' . $newStatus . '.'
);
set_flash('success', 'User ' . strtolower($newStatus) . 'd.');
redirect_to('index.php');
