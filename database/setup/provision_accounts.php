<?php

declare(strict_types=1);

// One-time CLI setup script: creates the PMS, AIS, Integrated, and IT
// Administrator accounts with freshly generated random passwords. Run manually from the CLI
// (`php database/setup/provision_accounts.php`) whenever those accounts do not
// yet exist; it is idempotent and skips any username that is already present.
// Generated passwords are printed to the console ONCE and are never written to
// any file, so no plaintext credential ever lives in the source tree.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require __DIR__ . '/../../shared/config/database.php';

function random_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $password;
}

$pdo = database_connection();

$accounts = [
    ['username' => 'pms_admin', 'display_name' => 'PMS Administrator', 'system_access' => 'pms', 'role' => 'PMS User'],
    ['username' => 'ais_admin', 'display_name' => 'AIS Administrator', 'system_access' => 'ais', 'role' => 'AIS User'],
    ['username' => 'integrated_admin', 'display_name' => 'Integrated Administrator', 'system_access' => 'integrated', 'role' => 'Integrated User'],
    ['username' => 'it_admin', 'display_name' => 'IT Administrator', 'system_access' => 'user_management', 'role' => 'IT Administrator'],
];

$created = [];
foreach ($accounts as $account) {
    $existsStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $existsStatement->execute([$account['username']]);
    if ((int) $existsStatement->fetchColumn() > 0) {
        echo "SKIPPED (already exists): {$account['username']}\n";
        continue;
    }

    $password = random_password();
    $statement = $pdo->prepare(
        'INSERT INTO users (username, display_name, password_hash, system_access, role, status) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $account['username'],
        $account['display_name'],
        password_hash($password, PASSWORD_BCRYPT),
        $account['system_access'],
        $account['role'],
        'Active',
    ]);
    $created[] = ['username' => $account['username'], 'password' => $password];
}

if ($created === []) {
    echo "\nNo new accounts created; all target usernames already existed.\n";
    exit(0);
}

echo "\n=== Initial account credentials (shown once, not stored anywhere) ===\n";
foreach ($created as $account) {
    echo str_pad($account['username'], 20) . " : " . $account['password'] . "\n";
}
echo "======================================================================\n";
echo "Record these now. Change them after the first login if this were a real deployment.\n";
