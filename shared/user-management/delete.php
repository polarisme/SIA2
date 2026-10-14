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

$currentUserId = (int) ($_SESSION['user']['user_id'] ?? 0);
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
$mode = (string) ($_POST['mode'] ?? 'soft');

if ($userId === false || $userId === null || $userId < 1 || !in_array($mode, ['soft', 'restore', 'permanent'], true)) {
    set_flash('danger', 'The selected user could not be found.');
    redirect_to('index.php');
}

if ($mode === 'soft') {
    if ($userId === $currentUserId) {
        set_flash('danger', 'You cannot delete the account you are currently signed in with.');
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
            set_flash('danger', 'At least one active IT Administrator must remain — cannot delete the last one.');
            redirect_to('index.php');
        }
    }

    $pdo->prepare('UPDATE users SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ? WHERE user_id = ? AND deleted_at IS NULL')
        ->execute([current_username(), $userId]);
    user_management_log_event($pdo, 'USER_DELETED', current_username(), $target['username'], $target['system_access'], 'Account moved to the Recycle Bin.');
    set_flash('success', 'User deleted. It can be restored from the Recycle Bin.');
    redirect_to('index.php?show_deleted=1');
}

if ($mode === 'restore') {
    $statement = $pdo->prepare('SELECT username, system_access FROM users WHERE user_id = ? AND deleted_at IS NOT NULL');
    $statement->execute([$userId]);
    $target = $statement->fetch();
    if (!$target) {
        set_flash('danger', 'The selected user was not found in the Recycle Bin.');
        redirect_to('index.php?show_deleted=1');
    }

    $pdo->prepare('UPDATE users SET deleted_at = NULL, deleted_by = NULL WHERE user_id = ?')->execute([$userId]);
    user_management_log_event($pdo, 'USER_RESTORED', current_username(), $target['username'], $target['system_access'], 'Account restored from the Recycle Bin.');
    set_flash('success', 'User restored.');
    redirect_to('index.php');
}

if ($mode === 'permanent') {
    $statement = $pdo->prepare('SELECT username, system_access FROM users WHERE user_id = ? AND deleted_at IS NOT NULL');
    $statement->execute([$userId]);
    $target = $statement->fetch();
    if (!$target) {
        set_flash('danger', 'The selected user was not found in the Recycle Bin.');
        redirect_to('index.php?show_deleted=1');
    }

    $pdo->prepare('DELETE FROM users WHERE user_id = ? AND deleted_at IS NOT NULL')->execute([$userId]);
    user_management_log_event($pdo, 'USER_PERMANENTLY_DELETED', current_username(), $target['username'], $target['system_access'], 'Account permanently deleted.');
    set_flash('success', 'User permanently deleted.');
    redirect_to('index.php?show_deleted=1');
}
