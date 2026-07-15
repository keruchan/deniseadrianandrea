<?php
/**
 * ============================================================
 * File     : config/seed_students.php
 * Project  : EDUPREDICT
 * Purpose  : Development utility to seed sample Filipino
 *            student accounts and enroll them in a target class
 *            (default: "BSIS 3A") for testing attendance,
 *            participation, assessments, analytics and predictions.
 *
 * Usage:
 *   Browser : http://localhost/EDUPREDICT/config/seed_students.php
 *   CLI     : php config/seed_students.php [class_name_fragment] [count]
 *
 * IMPORTANT:
 * - Run only in development.
 * - Idempotent: existing usernames/emails/student numbers are skipped.
 * - Delete this file after seeding.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$isCli = (PHP_SAPI === 'cli');

$classFragment = 'BSIS 3A';
$targetCount = 30;

if ($isCli) {
    $classFragment = isset($argv[1]) && trim((string) $argv[1]) !== '' ? trim((string) $argv[1]) : $classFragment;
    $targetCount = isset($argv[2]) ? max(1, (int) $argv[2]) : $targetCount;
} else {
    $classFragment = trim((string) ($_GET['class'] ?? $classFragment));
    $targetCount = isset($_GET['count']) ? max(1, (int) $_GET['count']) : $targetCount;
}

$firstNamesMale = [
    'Juan', 'Jose', 'Mark', 'John Paul', 'Angelo', 'Miguel', 'Carlo', 'Paolo', 'Rafael', 'Emmanuel',
    'Christian', 'Kevin', 'Daniel', 'Vincent', 'Joshua', 'Rommel', 'Enrique', 'Gabriel', 'Aldrin', 'Neil',
    'Francis', 'Reymark', 'Jayson', 'Renz', 'Lorenzo', 'Diego', 'Elmer', 'Nathaniel', 'Patrick', 'Julius',
];

$firstNamesFemale = [
    'Maria', 'Andrea', 'Angel', 'Kristine', 'Nicole', 'Jasmine', 'Camille', 'Danica', 'Ericka', 'Trisha',
    'Mary Grace', 'Aira', 'Bianca', 'Cristina', 'Divine', 'Ella', 'Faith', 'Grace', 'Hazel', 'Ivy',
    'Jenny', 'Kaye', 'Leah', 'Michelle', 'Nadine', 'Pauline', 'Rhea', 'Shaira', 'Cassandra', 'Yza',
];

$middleNames = [
    'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Villanueva',
    'Ramos', 'Aquino', 'Delos Reyes', 'Castillo', 'Navarro', 'Salvador', 'Domingo', 'Del Rosario', 'Rivera', 'Gonzales',
];

$lastNames = [
    'Dela Cruz', 'Reyes', 'Santos', 'Bautista', 'Aquino', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Villanueva',
    'Ramos', 'Castro', 'Bacani', 'Manalo', 'Gonzales', 'Rivera', 'Pascual', 'Domingo', 'Salazar', 'Fernandez',
    'Marquez', 'Aguilar', 'Rosales', 'Velasco', 'Estrada', 'Magno', 'Tolentino', 'Panganiban', 'Alonzo', 'Cabrera',
    'Lacsamana', 'Miranda', 'Espiritu', 'Feliciano', 'Guevarra', 'Herrera', 'Ignacio', 'Javier', 'Lumbao', 'Zabala',
];

$log = [];
$log[] = ['type' => 'info', 'text' => "Target class fragment: \"{$classFragment}\", requested new students: {$targetCount}"];

try {
    $studentRoleId = role_id_for_key($pdo, 'student');

    if ($studentRoleId === null) {
        throw new RuntimeException('Missing "student" role in user_roles. Run schema.sql first.');
    }

    // Resolve the target class.
    $classStmt = $pdo->prepare(
        'SELECT id, class_name, class_code FROM classes WHERE class_name LIKE :fragment ORDER BY id ASC LIMIT 1'
    );
    $classStmt->execute([':fragment' => '%' . $classFragment . '%']);
    $class = $classStmt->fetch();

    if (!$class) {
        throw new RuntimeException('No class matched "' . $classFragment . '". Create the class first.');
    }

    $classId = (int) $class['id'];
    $log[] = ['type' => 'ok', 'text' => 'Target class: ' . $class['class_name'] . ' (ID ' . $classId . ', code ' . $class['class_code'] . ')'];

    // Preload existing identifiers to guarantee uniqueness.
    $existingUsernames = array_flip($pdo->query('SELECT username FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $existingEmails = array_flip($pdo->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $existingStudentNos = array_flip(array_filter($pdo->query('SELECT student_no FROM students')->fetchAll(PDO::FETCH_COLUMN)));
    $existingNames = [];
    foreach ($pdo->query('SELECT first_name, last_name FROM students')->fetchAll() as $row) {
        $existingNames[mb_strtolower(trim($row['first_name'] . ' ' . $row['last_name']))] = true;
    }

    $enrolledStmt = $pdo->prepare('SELECT student_id FROM class_enrollments WHERE class_id = :class_id');
    $enrolledStmt->execute([':class_id' => $classId]);
    $alreadyEnrolled = array_flip(array_map('intval', $enrolledStmt->fetchAll(PDO::FETCH_COLUMN)));

    $insertUserStmt = $pdo->prepare(
        'INSERT INTO users (role_id, username, email, password, status)
         VALUES (:role_id, :username, :email, :password, :status)'
    );
    $insertStudentStmt = $pdo->prepare(
        'INSERT INTO students (user_id, student_no, first_name, middle_name, last_name, contact)
         VALUES (:user_id, :student_no, :first_name, :middle_name, :last_name, :contact)'
    );
    $enrollStmt = $pdo->prepare(
        'INSERT INTO class_enrollments (class_id, student_id, status)
         VALUES (:class_id, :student_id, "active")'
    );

    $schoolYear = (int) date('Y');
    $created = 0;
    $enrolled = 0;
    $skipped = 0;
    $attempts = 0;
    $maxAttempts = $targetCount * 40;
    $sequence = 1;

    while ($created < $targetCount && $attempts < $maxAttempts) {
        $attempts++;

        $isFemale = random_int(0, 1) === 1;
        $firstName = $isFemale
            ? $firstNamesFemale[array_rand($firstNamesFemale)]
            : $firstNamesMale[array_rand($firstNamesMale)];
        $middleName = $middleNames[array_rand($middleNames)];
        $lastName = $lastNames[array_rand($lastNames)];

        $fullKey = mb_strtolower(trim($firstName . ' ' . $lastName));
        if (isset($existingNames[$fullKey])) {
            continue; // avoid duplicate first+last name pairs
        }

        // Unique student number: YYYY-#### sequential, checked against DB.
        do {
            $studentNo = $schoolYear . '-' . str_pad((string) (1000 + $sequence), 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while (isset($existingStudentNos[$studentNo]));

        // Unique username + email derived from name, deduped with a numeric suffix.
        $slugBase = preg_replace('/[^a-z0-9]+/', '.', mb_strtolower($firstName . '.' . $lastName));
        $slugBase = trim($slugBase, '.');
        $username = $slugBase;
        $suffix = 1;
        while (isset($existingUsernames[$username])) {
            $username = $slugBase . $suffix;
            $suffix++;
        }

        $emailBase = $slugBase;
        $email = $emailBase . '@student.edupredict.local';
        $suffix = 1;
        while (isset($existingEmails[$email])) {
            $email = $emailBase . $suffix . '@student.edupredict.local';
            $suffix++;
        }

        $contact = '09' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        $pdo->beginTransaction();
        try {
            $insertUserStmt->execute([
                ':role_id'  => $studentRoleId,
                ':username' => $username,
                ':email'    => $email,
                ':password' => password_hash('Student@123', PASSWORD_DEFAULT),
                ':status'   => 'active',
            ]);
            $userId = (int) $pdo->lastInsertId();

            $insertStudentStmt->execute([
                ':user_id'     => $userId,
                ':student_no'  => $studentNo,
                ':first_name'  => $firstName,
                ':middle_name' => $middleName,
                ':last_name'   => $lastName,
                ':contact'     => $contact,
            ]);
            $studentId = (int) $pdo->lastInsertId();

            $enrollStmt->execute([
                ':class_id'   => $classId,
                ':student_id' => $studentId,
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Record the newly-used identifiers so later iterations stay unique.
        $existingUsernames[$username] = true;
        $existingEmails[$email] = true;
        $existingStudentNos[$studentNo] = true;
        $existingNames[$fullKey] = true;
        $alreadyEnrolled[$studentId] = true;

        $created++;
        $enrolled++;
        $log[] = ['type' => 'ok', 'text' => sprintf('Created %s %s %s (%s) → %s', $firstName, $middleName, $lastName, $studentNo, $username)];
    }

    if ($created < $targetCount) {
        $log[] = ['type' => 'warn', 'text' => "Only created {$created} of {$targetCount} (ran out of unique name combinations)."];
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM class_enrollments WHERE class_id = :class_id AND status = "active"');
    $countStmt->execute([':class_id' => $classId]);
    $totalEnrolled = (int) $countStmt->fetchColumn();

    $log[] = ['type' => 'info', 'text' => "Created {$created} new students; enrolled {$enrolled}. Total active enrollment in class: {$totalEnrolled}."];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[EDUPREDICT SEED STUDENTS ERROR] ' . $e->getMessage());
    $log[] = ['type' => 'danger', 'text' => 'Error: ' . $e->getMessage()];
}

if ($isCli) {
    foreach ($log as $entry) {
        $prefix = strtoupper($entry['type']);
        echo '[' . $prefix . '] ' . $entry['text'] . PHP_EOL;
    }
    exit;
}

echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>EDUPREDICT Student Seeder</title>';
echo '<style>body{font-family:Arial,sans-serif;max-width:900px;margin:32px auto;color:#172033}';
echo '.ok{color:#047857}.warn{color:#b45309}.danger{color:#b91c1c}.info{color:#1e3a8a}';
echo 'p{margin:4px 0;font-size:14px}code{background:#f1f5f9;padding:1px 5px;border-radius:4px}</style>';
echo '</head><body>';
echo '<h1>EDUPREDICT - Sample Student Seeder</h1>';
foreach ($log as $entry) {
    echo '<p class="' . e($entry['type']) . '">' . e($entry['text']) . '</p>';
}
echo '<p class="info">Default student password: <code>Student@123</code></p>';
echo '<p class="danger"><strong>Important:</strong> Delete <code>config/seed_students.php</code> after seeding.</p>';
echo '</body></html>';
