<?php
/**
 * Administrator domain — institution-wide user/class/settings management and
 * system oversight. Reads and mutations behind the admin portal pages.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/insights_management.php';
require_once __DIR__ . '/notification_management.php';

/** role_key => label, in display order. */
function admin_role_options(): array
{
    return [
        'administrator' => 'Administrator',
        'instructor' => 'Instructor',
        'student' => 'Student',
    ];
}

/** user status => [label, tone, icon]. */
function admin_status_meta(): array
{
    return [
        'active' => ['Active', 'tone-emerald', 'bi-check-circle'],
        'pending' => ['Pending', 'tone-amber', 'bi-hourglass-split'],
        'disabled' => ['Disabled', 'tone-rose', 'bi-slash-circle'],
    ];
}

/* ------------------------------------------------------------------ *
 * Dashboard / overview
 * ------------------------------------------------------------------ */

function get_admin_overview(PDO $pdo): array
{
    $roleCounts = ['administrator' => 0, 'instructor' => 0, 'student' => 0];
    $stmt = $pdo->query(
        'SELECT r.role_key, COUNT(*) AS n FROM users u INNER JOIN user_roles r ON r.id = u.role_id GROUP BY r.role_key'
    );
    foreach ($stmt->fetchAll() as $row) {
        $roleCounts[(string) $row['role_key']] = (int) $row['n'];
    }

    $statusCounts = ['active' => 0, 'pending' => 0, 'disabled' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM users GROUP BY status')->fetchAll() as $row) {
        $statusCounts[(string) $row['status']] = (int) $row['n'];
    }

    $classStatus = ['draft' => 0, 'active' => 0, 'archived' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM classes GROUP BY status')->fetchAll() as $row) {
        $classStatus[(string) $row['status']] = (int) $row['n'];
    }

    $totalUsers = array_sum($roleCounts);
    $totalClasses = array_sum($classStatus);
    $enrollments = (int) $pdo->query('SELECT COUNT(*) FROM class_enrollments WHERE status = "active"')->fetchColumn();

    // System-wide at-risk students (loop active classes; small class counts).
    $atRisk = 0;
    $gradeVals = [];
    $activeClassIds = $pdo->query('SELECT id FROM classes WHERE status = "active"')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($activeClassIds as $cid) {
        $metrics = get_all_student_metrics($pdo, (int) $cid);
        foreach ($metrics as $m) {
            if ($m['at_risk']) {
                $atRisk++;
            }
            if ($m['current_grade'] !== null) {
                $gradeVals[] = $m['current_grade'];
            }
        }
    }

    $recentUsers = $pdo->query(
        'SELECT u.username, u.status, u.created_at, r.role_key, r.role_name,
                COALESCE(a.first_name, i.first_name, s.first_name) AS first_name,
                COALESCE(a.last_name, i.last_name, s.last_name) AS last_name
         FROM users u
         INNER JOIN user_roles r ON r.id = u.role_id
         LEFT JOIN administrators a ON a.user_id = u.id
         LEFT JOIN instructors i ON i.user_id = u.id
         LEFT JOIN students s ON s.user_id = u.id
         ORDER BY u.created_at DESC LIMIT 6'
    )->fetchAll();

    $recentClasses = $pdo->query(
        'SELECT c.class_name, c.section, c.status, c.created_at,
                CONCAT(i.first_name, " ", i.last_name) AS instructor,
                (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = c.id AND ce.status = "active") AS students
         FROM classes c LEFT JOIN instructors i ON i.id = c.instructor_id
         ORDER BY c.created_at DESC LIMIT 6'
    )->fetchAll();

    return [
        'total_users' => $totalUsers,
        'role_counts' => $roleCounts,
        'status_counts' => $statusCounts,
        'class_status' => $classStatus,
        'total_classes' => $totalClasses,
        'enrollments' => $enrollments,
        'at_risk' => $atRisk,
        'grade_average' => $gradeVals ? array_sum($gradeVals) / count($gradeVals) : null,
        'pending_accounts' => $statusCounts['pending'],
        'recent_users' => $recentUsers,
        'recent_classes' => $recentClasses,
    ];
}

/* ------------------------------------------------------------------ *
 * User management
 * ------------------------------------------------------------------ */

/**
 * Users across the institution with role, name, identifier, status, last login,
 * and a role-appropriate class count. $role/$status/$q narrow the list.
 */
function get_admin_users(PDO $pdo, string $role = '', string $status = '', string $q = ''): array
{
    $sql =
        'SELECT u.id, u.username, u.email, u.status, u.last_login_at, u.created_at,
                r.role_key, r.role_name,
                COALESCE(a.first_name, i.first_name, s.first_name) AS first_name,
                COALESCE(a.middle_name, i.middle_name, s.middle_name) AS middle_name,
                COALESCE(a.last_name, i.last_name, s.last_name) AS last_name,
                COALESCE(a.employee_no, i.employee_no, s.student_no) AS ident_no,
                COALESCE(a.contact, i.contact, s.contact) AS contact,
                i.department AS department,
                i.id AS instructor_id, s.id AS student_id,
                (SELECT COUNT(*) FROM classes c WHERE c.instructor_id = i.id) AS teach_count,
                (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.student_id = s.id AND ce.status = "active") AS enroll_count
         FROM users u
         INNER JOIN user_roles r ON r.id = u.role_id
         LEFT JOIN administrators a ON a.user_id = u.id
         LEFT JOIN instructors i ON i.user_id = u.id
         LEFT JOIN students s ON s.user_id = u.id
         WHERE 1 = 1';
    $params = [];
    if ($role !== '' && isset(admin_role_options()[$role])) {
        $sql .= ' AND r.role_key = :role';
        $params[':role'] = $role;
    }
    if (in_array($status, ['active', 'pending', 'disabled'], true)) {
        $sql .= ' AND u.status = :status';
        $params[':status'] = $status;
    }
    if (trim($q) !== '') {
        $sql .= ' AND CONCAT_WS(" ", u.username, u.email,
                      COALESCE(a.first_name, i.first_name, s.first_name),
                      COALESCE(a.last_name, i.last_name, s.last_name),
                      COALESCE(a.employee_no, i.employee_no, s.student_no)) LIKE :q';
        $params[':q'] = '%' . trim($q) . '%';
    }
    $sql .= ' ORDER BY u.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['name'] = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
        if ($r['role_key'] === 'instructor') {
            $r['class_count'] = (int) $r['teach_count'];
        } elseif ($r['role_key'] === 'student') {
            $r['class_count'] = (int) $r['enroll_count'];
        } else {
            $r['class_count'] = null;
        }
    }
    unset($r);

    return $rows;
}

/** Single user (for the edit form), with the same shape as get_admin_users rows. */
function admin_get_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.email, u.status, r.role_key,
                COALESCE(a.first_name, i.first_name, s.first_name) AS first_name,
                COALESCE(a.middle_name, i.middle_name, s.middle_name) AS middle_name,
                COALESCE(a.last_name, i.last_name, s.last_name) AS last_name,
                COALESCE(a.employee_no, i.employee_no, s.student_no) AS ident_no,
                COALESCE(a.contact, i.contact, s.contact) AS contact,
                i.department AS department
         FROM users u
         INNER JOIN user_roles r ON r.id = u.role_id
         LEFT JOIN administrators a ON a.user_id = u.id
         LEFT JOIN instructors i ON i.user_id = u.id
         LEFT JOIN students s ON s.user_id = u.id
         WHERE u.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Shared field validation. Returns a flat errors array. */
function admin_validate_user(PDO $pdo, array $d, bool $isNew, int $userId = 0): array
{
    $errors = [];
    $role = (string) ($d['role'] ?? '');
    $username = trim((string) ($d['username'] ?? ''));
    $email = trim((string) ($d['email'] ?? ''));
    $first = trim((string) ($d['first_name'] ?? ''));
    $last = trim((string) ($d['last_name'] ?? ''));
    $contact = trim((string) ($d['contact'] ?? ''));
    $ident = trim((string) ($d['ident_no'] ?? ''));

    if ($isNew && !isset(admin_role_options()[$role])) {
        $errors[] = 'Choose a valid role.';
    }
    if ($first === '' || mb_strlen($first) > 100) {
        $errors[] = 'First name is required (max 100 characters).';
    }
    if ($last === '' || mb_strlen($last) > 100) {
        $errors[] = 'Last name is required (max 100 characters).';
    }
    if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required (max 150 characters).';
    }
    if ($contact !== '' && !preg_match('/^[0-9+\-\s().]{7,20}$/', $contact)) {
        $errors[] = 'Contact number may contain only numbers, spaces, +, -, parentheses, and periods.';
    }
    if ($ident !== '' && mb_strlen($ident) > 50) {
        $errors[] = 'ID / employee / student number must not exceed 50 characters.';
    }

    if ($isNew) {
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3-50 characters (letters, numbers, _ . -).';
        }
        $pw = (string) ($d['password'] ?? '');
        if (strlen($pw) < 8 || strlen($pw) > 128) {
            $errors[] = 'Password must be between 8 and 128 characters.';
        }
    }

    // Uniqueness (username on create; email always, excluding self).
    if ($isNew && $username !== '') {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
        $chk->execute([':u' => $username]);
        if ((int) $chk->fetchColumn() > 0) {
            $errors[] = 'That username is already taken.';
        }
    }
    if ($email !== '') {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e AND id <> :id');
        $chk->execute([':e' => $email, ':id' => $userId]);
        if ((int) $chk->fetchColumn() > 0) {
            $errors[] = 'That email address is already in use.';
        }
    }

    return $errors;
}

/** Creates a user + its role profile row in a transaction. Returns [ok, errors]. */
function admin_create_user(PDO $pdo, array $d): array
{
    $errors = admin_validate_user($pdo, $d, true);
    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $role = (string) $d['role'];
    $roleId = role_id_for_key($pdo, $role);
    if ($roleId === null) {
        return ['ok' => false, 'errors' => ['Role is not configured.']];
    }

    $middle = trim((string) ($d['middle_name'] ?? ''));
    $contact = trim((string) ($d['contact'] ?? ''));
    $ident = trim((string) ($d['ident_no'] ?? ''));
    $status = in_array(($d['status'] ?? 'active'), ['active', 'pending', 'disabled'], true) ? (string) $d['status'] : 'active';

    try {
        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO users (role_id, username, email, password, status) VALUES (:r, :u, :e, :p, :s)');
        $insU->execute([
            ':r' => $roleId,
            ':u' => trim((string) $d['username']),
            ':e' => trim((string) $d['email']),
            ':p' => password_hash((string) $d['password'], PASSWORD_DEFAULT),
            ':s' => $status,
        ]);
        $userId = (int) $pdo->lastInsertId();

        if ($role === 'administrator') {
            $pdo->prepare('INSERT INTO administrators (user_id, employee_no, first_name, middle_name, last_name, contact) VALUES (:u,:no,:f,:m,:l,:c)')
                ->execute([':u' => $userId, ':no' => $ident ?: null, ':f' => trim((string) $d['first_name']), ':m' => $middle ?: null, ':l' => trim((string) $d['last_name']), ':c' => $contact ?: null]);
        } elseif ($role === 'instructor') {
            $pdo->prepare('INSERT INTO instructors (user_id, employee_no, first_name, middle_name, last_name, department, contact) VALUES (:u,:no,:f,:m,:l,:d,:c)')
                ->execute([':u' => $userId, ':no' => $ident ?: null, ':f' => trim((string) $d['first_name']), ':m' => $middle ?: null, ':l' => trim((string) $d['last_name']), ':d' => trim((string) ($d['department'] ?? '')) ?: null, ':c' => $contact ?: null]);
        } else {
            $pdo->prepare('INSERT INTO students (user_id, student_no, first_name, middle_name, last_name, contact) VALUES (:u,:no,:f,:m,:l,:c)')
                ->execute([':u' => $userId, ':no' => $ident ?: null, ':f' => trim((string) $d['first_name']), ':m' => $middle ?: null, ':l' => trim((string) $d['last_name']), ':c' => $contact ?: null]);
        }

        $pdo->commit();
        return ['ok' => true, 'errors' => [], 'user_id' => $userId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EDUPREDICT ADMIN CREATE USER] ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not create the account. Please try again.']];
    }
}

/** Updates a user's email, status, and role profile fields. Returns [ok, errors]. */
function admin_update_user(PDO $pdo, int $userId, array $d): array
{
    $existing = admin_get_user($pdo, $userId);
    if ($existing === null) {
        return ['ok' => false, 'errors' => ['User not found.']];
    }
    $d['role'] = $existing['role_key'];
    $errors = admin_validate_user($pdo, $d, false, $userId);
    if (!empty($errors)) {
        return ['ok' => false, 'errors' => $errors];
    }

    $role = (string) $existing['role_key'];
    $middle = trim((string) ($d['middle_name'] ?? ''));
    $contact = trim((string) ($d['contact'] ?? ''));
    $ident = trim((string) ($d['ident_no'] ?? ''));
    $status = in_array(($d['status'] ?? $existing['status']), ['active', 'pending', 'disabled'], true) ? (string) $d['status'] : (string) $existing['status'];

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET email = :e, status = :s WHERE id = :id')
            ->execute([':e' => trim((string) $d['email']), ':s' => $status, ':id' => $userId]);

        if ($role === 'administrator') {
            $pdo->prepare('UPDATE administrators SET employee_no=:no, first_name=:f, middle_name=:m, last_name=:l, contact=:c WHERE user_id=:u')
                ->execute([':no' => $ident ?: null, ':f' => trim((string) $d['first_name']), ':m' => $middle ?: null, ':l' => trim((string) $d['last_name']), ':c' => $contact ?: null, ':u' => $userId]);
        } elseif ($role === 'instructor') {
            $pdo->prepare('UPDATE instructors SET employee_no=:no, first_name=:f, middle_name=:m, last_name=:l, department=:d, contact=:c WHERE user_id=:u')
                ->execute([':no' => $ident ?: null, ':f' => trim((string) $d['first_name']), ':m' => $middle ?: null, ':l' => trim((string) $d['last_name']), ':d' => trim((string) ($d['department'] ?? '')) ?: null, ':c' => $contact ?: null, ':u' => $userId]);
        } else {
            $pdo->prepare('UPDATE students SET student_no=:no, first_name=:f, middle_name=:m, last_name=:l, contact=:c WHERE user_id=:u')
                ->execute([':no' => $ident ?: null, ':f' => trim((string) $d['first_name']), ':m' => $middle ?: null, ':l' => trim((string) $d['last_name']), ':c' => $contact ?: null, ':u' => $userId]);
        }

        $pdo->commit();
        return ['ok' => true, 'errors' => []];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EDUPREDICT ADMIN UPDATE USER] ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['Could not update the account.']];
    }
}

function admin_set_user_status(PDO $pdo, int $userId, string $status): bool
{
    if (!in_array($status, ['active', 'pending', 'disabled'], true)) {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE users SET status = :s WHERE id = :id');
    $stmt->execute([':s' => $status, ':id' => $userId]);
    return $stmt->rowCount() >= 0;
}

function admin_reset_password(PDO $pdo, int $userId, string $newPassword): array
{
    if (strlen($newPassword) < 8 || strlen($newPassword) > 128) {
        return ['ok' => false, 'errors' => ['Password must be between 8 and 128 characters.']];
    }
    $stmt = $pdo->prepare('UPDATE users SET password = :p WHERE id = :id');
    $stmt->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
    return ['ok' => true, 'errors' => []];
}

/** Hard-deletes a user (cascades to profile + owned data). Guarded by the caller. */
function admin_delete_user(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    return $stmt->rowCount() > 0;
}

/** Count of active administrators (used to prevent removing the last admin). */
function admin_active_admin_count(PDO $pdo): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM users u INNER JOIN user_roles r ON r.id = u.role_id
         WHERE r.role_key = "administrator" AND u.status = "active"'
    )->fetchColumn();
}

/* ------------------------------------------------------------------ *
 * Class oversight
 * ------------------------------------------------------------------ */

function get_admin_classes(PDO $pdo, string $status = '', string $q = ''): array
{
    $sql =
        'SELECT c.id, c.class_name, c.section, c.subject_name, c.class_code, c.status, c.created_at,
                CONCAT(i.first_name, " ", i.last_name) AS instructor, i.id AS instructor_id,
                (SELECT COUNT(*) FROM class_enrollments ce WHERE ce.class_id = c.id AND ce.status = "active") AS students
         FROM classes c LEFT JOIN instructors i ON i.id = c.instructor_id
         WHERE 1 = 1';
    $params = [];
    if (in_array($status, ['draft', 'active', 'archived'], true)) {
        $sql .= ' AND c.status = :status';
        $params[':status'] = $status;
    }
    if (trim($q) !== '') {
        $sql .= ' AND CONCAT_WS(" ", c.class_name, c.subject_name, c.class_code, i.first_name, i.last_name) LIKE :q';
        $params[':q'] = '%' . trim($q) . '%';
    }
    $sql .= ' ORDER BY c.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_set_class_status(PDO $pdo, int $classId, string $status): bool
{
    if (!in_array($status, ['draft', 'active', 'archived'], true)) {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE classes SET status = :s WHERE id = :id');
    $stmt->execute([':s' => $status, ':id' => $classId]);
    return $stmt->rowCount() >= 0;
}

function admin_delete_class(PDO $pdo, int $classId): bool
{
    $stmt = $pdo->prepare('DELETE FROM classes WHERE id = :id');
    $stmt->execute([':id' => $classId]);
    return $stmt->rowCount() > 0;
}

/* ------------------------------------------------------------------ *
 * System settings
 * ------------------------------------------------------------------ */

/** All settings as [group.key => ['value','label','group','key']], plus known defaults. */
function get_admin_settings(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT setting_group, setting_key, setting_value, label FROM settings ORDER BY setting_group, id')->fetchAll() as $r) {
        $out[$r['setting_group'] . '.' . $r['setting_key']] = [
            'group' => (string) $r['setting_group'],
            'key' => (string) $r['setting_key'],
            'value' => (string) ($r['setting_value'] ?? ''),
            'label' => (string) ($r['label'] ?? $r['setting_key']),
        ];
    }
    return $out;
}

/** Upserts a set of settings ($values keyed "group.key" => value). */
function save_admin_settings(PDO $pdo, array $values): void
{
    $upd = $pdo->prepare('UPDATE settings SET setting_value = :v WHERE setting_group = :g AND setting_key = :k');
    foreach ($values as $composite => $value) {
        if (strpos($composite, '.') === false) {
            continue;
        }
        [$group, $key] = explode('.', $composite, 2);
        $upd->execute([':v' => mb_substr((string) $value, 0, 2000), ':g' => $group, ':k' => $key]);
    }
}

/* ------------------------------------------------------------------ *
 * Announcements (broadcast into the notification bell)
 * ------------------------------------------------------------------ */

/**
 * Broadcasts an announcement as a notification to every non-disabled user in the
 * chosen audience ('all' or a role_key). Returns the number of recipients.
 */
function admin_broadcast_announcement(PDO $pdo, string $title, string $body, string $audience): int
{
    ensure_notifications_schema($pdo);
    $sql = 'SELECT u.id FROM users u INNER JOIN user_roles r ON r.id = u.role_id WHERE u.status <> "disabled"';
    $params = [];
    if ($audience !== 'all' && isset(admin_role_options()[$audience])) {
        $sql .= ' AND r.role_key = :role';
        $params[':role'] = $audience;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $count = 0;
    foreach ($ids as $uid) {
        if (create_notification($pdo, $uid, 'announcement', $title, $body) > 0) {
            $count++;
        }
    }
    return $count;
}
