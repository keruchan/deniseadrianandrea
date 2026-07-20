<?php
/**
 * Notifications — a per-user notification feed shared by every role, powering the
 * topbar bell (unread badge, read/unread, delete, paginated "load more"). Rows are
 * created at real event points (a student joins a class, grades are posted, …) via
 * create_notification(); the bell reads them through ajax/notifications.php.
 *
 * Self-migrating like the rest of the app: ensure_notifications_schema() runs on
 * every dashboard render (CREATE TABLE IF NOT EXISTS).
 */

function ensure_notifications_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT "general",
            title VARCHAR(180) NOT NULL,
            body VARCHAR(500) NOT NULL DEFAULT "",
            link VARCHAR(300) NULL DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_notifications_user (user_id, is_read),
            KEY idx_notifications_user_created (user_id, created_at),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Creates a notification for one user. `type` drives the bell icon (see
 * notification_icon()); `link` is an app path (already url_for()-ed or relative)
 * the bell navigates to when the item is clicked. Returns the new id (0 on failure).
 */
function create_notification(PDO $pdo, int $userId, string $type, string $title, string $body = '', ?string $link = null): int
{
    if ($userId <= 0 || trim($title) === '') {
        return 0;
    }
    try {
        ensure_notifications_schema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, body, link)
             VALUES (:u, :t, :title, :body, :link)'
        );
        $stmt->execute([
            ':u' => $userId,
            ':t' => mb_substr($type, 0, 40),
            ':title' => mb_substr(trim($title), 0, 180),
            ':body' => mb_substr(trim($body), 0, 500),
            ':link' => $link !== null ? mb_substr($link, 0, 300) : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[EDUPREDICT NOTIFY ERROR] ' . $e->getMessage());
        return 0;
    }
}

/**
 * A page of a user's notifications, newest first. Keyset pagination: pass the last
 * seen id as $beforeId to get the next page. Returns up to $limit rows.
 */
function get_notifications(PDO $pdo, int $userId, int $limit = 10, int $beforeId = 0): array
{
    $limit = max(1, min(50, $limit));
    $sql = 'SELECT id, type, title, body, link, is_read, created_at
            FROM notifications
            WHERE user_id = :u';
    $params = [':u' => $userId];
    if ($beforeId > 0) {
        $sql .= ' AND id < :before';
        $params[':before'] = $beforeId;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . ($limit + 1); // +1 to detect has_more
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function count_unread_notifications(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0');
    $stmt->execute([':u' => $userId]);
    return (int) $stmt->fetchColumn();
}

/** Marks one notification read (scoped to the owner). Returns true if it changed. */
function mark_notification_read(PDO $pdo, int $userId, int $id): bool
{
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :u AND is_read = 0');
    $stmt->execute([':id' => $id, ':u' => $userId]);
    return $stmt->rowCount() > 0;
}

function mark_all_notifications_read(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = CURRENT_TIMESTAMP WHERE user_id = :u AND is_read = 0');
    $stmt->execute([':u' => $userId]);
    return $stmt->rowCount();
}

function delete_notification(PDO $pdo, int $userId, int $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :u');
    $stmt->execute([':id' => $id, ':u' => $userId]);
    return $stmt->rowCount() > 0;
}

/** Bootstrap-icon class for a notification type. */
function notification_icon(string $type): string
{
    return [
        'grade' => 'bi-card-checklist',
        'enrollment' => 'bi-person-plus',
        'attendance' => 'bi-calendar-check',
        'risk' => 'bi-exclamation-octagon',
        'assessment' => 'bi-journal-text',
        'goal' => 'bi-bullseye',
        'welcome' => 'bi-stars',
        'announcement' => 'bi-megaphone',
        'general' => 'bi-bell',
    ][$type] ?? 'bi-bell';
}

/** Facebook-style relative time ("Just now", "5m", "3h", "2d", "1w", or a date). */
function notification_time_ago(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h';
    }
    if ($diff < 604800) {
        return floor($diff / 86400) . 'd';
    }
    if ($diff < 2592000) {
        return floor($diff / 604800) . 'w';
    }
    return date('M j, Y', $ts);
}

/** Shapes a DB row into the JSON payload the bell UI consumes. */
function notification_payload(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'type' => (string) $row['type'],
        'icon' => notification_icon((string) $row['type']),
        'title' => (string) $row['title'],
        'body' => (string) $row['body'],
        'link' => $row['link'] !== null ? (string) $row['link'] : '',
        'is_read' => (int) $row['is_read'] === 1,
        'time_ago' => notification_time_ago((string) $row['created_at']),
        'created_at' => (string) $row['created_at'],
    ];
}

/** Resolves an instructor profile id (instructors.id) to its users.id. */
function notification_instructor_user_id(PDO $pdo, int $instructorId): int
{
    $stmt = $pdo->prepare('SELECT user_id FROM instructors WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $instructorId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int) $val;
}

/** Resolves a student profile id (students.id) to its users.id. */
function notification_student_user_id(PDO $pdo, int $studentId): int
{
    $stmt = $pdo->prepare('SELECT user_id FROM students WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $studentId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int) $val;
}

/* ------------------------------------------------------------------ *
 * Event helpers (called from the flows that should raise a notification)
 * ------------------------------------------------------------------ */

/** A student joined (or rejoined) a class → notify that class's instructor. */
function notify_class_instructor_of_join(PDO $pdo, array $class, string $studentName, bool $rejoined): void
{
    $instructorId = (int) ($class['instructor_id'] ?? 0);
    if ($instructorId <= 0) {
        return;
    }
    $userId = notification_instructor_user_id($pdo, $instructorId);
    if ($userId <= 0) {
        return;
    }
    $className = (string) ($class['class_name'] ?? 'your class');
    $verb = $rejoined ? 'rejoined' : 'joined';
    create_notification(
        $pdo,
        $userId,
        'enrollment',
        'New student ' . $verb . ' ' . $className,
        $studentName . ' ' . $verb . ' ' . $className . '.',
        url_for('classes/' . (int) $class['id'])
    );
}

/**
 * Grades were posted for an assessment item → notify each student whose score was
 * newly added or changed on this save. $changedStudentIds are students.id values.
 */
function notify_students_of_grade(PDO $pdo, array $changedStudentIds, string $itemTitle, string $className, string $link): void
{
    foreach (array_unique(array_map('intval', $changedStudentIds)) as $studentId) {
        if ($studentId <= 0) {
            continue;
        }
        $userId = notification_student_user_id($pdo, $studentId);
        if ($userId <= 0) {
            continue;
        }
        create_notification(
            $pdo,
            $userId,
            'grade',
            'Grade posted: ' . $itemTitle,
            'Your ' . $itemTitle . ' grade in ' . $className . ' was posted.',
            $link
        );
    }
}

function notify_students_of_attendance_absence_warning(PDO $pdo, array $studentAbsenceCounts, string $className, string $link): void
{
    foreach ($studentAbsenceCounts as $studentId => $absenceCount) {
        $studentId = (int) $studentId;
        $absenceCount = (int) $absenceCount;
        if ($studentId <= 0 || !in_array($absenceCount, [2, 3], true)) {
            continue;
        }

        $userId = notification_student_user_id($pdo, $studentId);
        if ($userId <= 0) {
            continue;
        }

        $subject = $absenceCount >= 3;
        create_notification(
            $pdo,
            $userId,
            'risk',
            $subject ? 'Subject for dropping review' : 'Attendance warning',
            $subject
                ? 'You now have 3 unexcused absences in ' . $className . '. Dropping is not automatic, but this needs immediate attention.'
                : 'You now have 2 unexcused absences in ' . $className . '. Three unexcused absences are subject for dropping review.',
            $link
        );
    }
}
