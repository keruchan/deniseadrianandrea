<?php
/**
 * ============================================================
 * File     : config/create_users.php
 * Project  : EDUPREDICT
 * Purpose  : One-time development utility to create initial
 *            Administrator and Instructor accounts.
 *
 * IMPORTANT:
 * - Run only in development.
 * - Delete this file after creating the accounts.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$accounts = [
    [
        'role'        => 'administrator',
        'username'    => 'admin',
        'email'       => 'admin@edupredict.local',
        'password'    => 'Admin@123',
        'employee_no' => 'ADM-0001',
        'first_name'  => 'System',
        'middle_name' => '',
        'last_name'   => 'Administrator',
        'contact'     => '09000000001',
    ],
    [
        'role'        => 'instructor',
        'username'    => 'instructor',
        'email'       => 'instructor@edupredict.local',
        'password'    => 'Instructor@123',
        'employee_no' => 'INS-0001',
        'first_name'  => 'Demo',
        'middle_name' => '',
        'last_name'   => 'Instructor',
        'department'  => 'Academic Department',
        'contact'     => '09000000002',
    ],
];

try {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>EDUPREDICT User Creation</title>';
    echo '<style>body{font-family:Arial,sans-serif;max-width:900px;margin:32px auto;color:#172033}table{border-collapse:collapse;width:100%;margin-top:16px}td,th{border:1px solid #d9deea;padding:10px;text-align:left}.ok{color:#047857}.warn{color:#b45309}.danger{color:#b91c1c}</style>';
    echo '</head><body>';
    echo '<h1>EDUPREDICT - Default User Creation</h1>';

    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
    $insertUserStmt = $pdo->prepare(
        'INSERT INTO users (role_id, username, email, password, status)
         VALUES (:role_id, :username, :email, :password, :status)'
    );
    $insertAdminStmt = $pdo->prepare(
        'INSERT INTO administrators (user_id, employee_no, first_name, middle_name, last_name, contact)
         VALUES (:user_id, :employee_no, :first_name, :middle_name, :last_name, :contact)'
    );
    $insertInstructorStmt = $pdo->prepare(
        'INSERT INTO instructors (user_id, employee_no, first_name, middle_name, last_name, department, contact)
         VALUES (:user_id, :employee_no, :first_name, :middle_name, :last_name, :department, :contact)'
    );

    foreach ($accounts as $account) {
        $roleId = role_id_for_key($pdo, $account['role']);

        if ($roleId === null) {
            echo '<p class="danger">Missing role: ' . e($account['role']) . '. Run schema.sql first.</p>';
            continue;
        }

        $checkStmt->execute([
            ':username' => $account['username'],
            ':email'    => $account['email'],
        ]);

        if ($checkStmt->fetchColumn() !== false) {
            echo '<p class="warn">Account already exists: <strong>' . e($account['username']) . '</strong></p>';
            continue;
        }

        $pdo->beginTransaction();

        $insertUserStmt->execute([
            ':role_id'  => $roleId,
            ':username' => $account['username'],
            ':email'    => $account['email'],
            ':password' => password_hash($account['password'], PASSWORD_DEFAULT),
            ':status'   => 'active',
        ]);

        $userId = (int) $pdo->lastInsertId();

        if ($account['role'] === 'administrator') {
            $insertAdminStmt->execute([
                ':user_id'     => $userId,
                ':employee_no' => $account['employee_no'],
                ':first_name'  => $account['first_name'],
                ':middle_name' => $account['middle_name'] !== '' ? $account['middle_name'] : null,
                ':last_name'   => $account['last_name'],
                ':contact'     => $account['contact'],
            ]);
        } else {
            $insertInstructorStmt->execute([
                ':user_id'     => $userId,
                ':employee_no' => $account['employee_no'],
                ':first_name'  => $account['first_name'],
                ':middle_name' => $account['middle_name'] !== '' ? $account['middle_name'] : null,
                ':last_name'   => $account['last_name'],
                ':department'  => $account['department'],
                ':contact'     => $account['contact'],
            ]);
        }

        $pdo->commit();
        echo '<p class="ok">Created account: <strong>' . e($account['username']) . '</strong></p>';
    }

    echo '<h2>Default Accounts</h2>';
    echo '<table><tr><th>Role</th><th>Username</th><th>Password</th></tr>';
    echo '<tr><td>Administrator</td><td>admin</td><td>Admin@123</td></tr>';
    echo '<tr><td>Instructor</td><td>instructor</td><td>Instructor@123</td></tr>';
    echo '</table>';
    echo '<p class="danger"><strong>Important:</strong> Delete <code>create_users.php</code> after successful account creation.</p>';
    echo '</body></html>';
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[EDUPREDICT CREATE USERS ERROR] ' . $e->getMessage());
    die('Unable to create development users. Check the server logs for details.');
}
