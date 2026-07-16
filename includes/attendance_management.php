<?php
/**
 * Attendance scheduling and record helpers.
 */

function attendance_statuses(): array
{
    return [
        'regular' => 'Regular',
        'holiday' => 'Holiday',
        'cancelled' => 'Cancelled',
        'review' => 'Review',
        'exam' => 'Exam',
    ];
}

function attendance_record_statuses(): array
{
    return [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excused',
    ];
}

function attendance_weekdays(): array
{
    return [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
}

function ensure_attendance_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_teaching_schedules (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            course_start_date DATE NOT NULL,
            semester_weeks TINYINT UNSIGNED NOT NULL DEFAULT 18,
            meetings_per_week TINYINT UNSIGNED NOT NULL DEFAULT 2,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_teaching_schedules_class_id (class_id),
            CONSTRAINT fk_class_teaching_schedules_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_teaching_schedule_slots (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            slot_order TINYINT UNSIGNED NOT NULL,
            day_of_week TINYINT UNSIGNED NOT NULL,
            meeting_type VARCHAR(80) NOT NULL DEFAULT "Lecture",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_schedule_slots_order (class_id, slot_order),
            KEY idx_class_schedule_slots_class_id (class_id),
            CONSTRAINT fk_class_schedule_slots_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_meetings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            meeting_date DATE NOT NULL,
            week_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            slot_order TINYINT UNSIGNED DEFAULT NULL,
            meeting_type VARCHAR(80) NOT NULL DEFAULT "Lecture",
            topic VARCHAR(255) DEFAULT NULL,
            status ENUM("regular","holiday","cancelled","review","exam") NOT NULL DEFAULT "regular",
            source ENUM("generated","manual") NOT NULL DEFAULT "generated",
            is_customized TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_class_meetings_class_date (class_id, meeting_date),
            KEY idx_class_meetings_status (status),
            CONSTRAINT fk_class_meetings_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS attendance_records (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            status ENUM("present","absent","late","excused") NOT NULL DEFAULT "present",
            saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_attendance_records_meeting_student (meeting_id, student_id),
            KEY idx_attendance_records_student_id (student_id),
            CONSTRAINT fk_attendance_records_meeting_id FOREIGN KEY (meeting_id) REFERENCES class_meetings (id) ON DELETE CASCADE,
            CONSTRAINT fk_attendance_records_student_id FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function attendance_schedule_defaults(): array
{
    return [
        'course_start_date' => date('Y-m-d'),
        'semester_weeks' => 18,
        'meetings_per_week' => 2,
        'slots' => [
            ['day_of_week' => 2, 'meeting_type' => 'Lecture'],
            ['day_of_week' => 4, 'meeting_type' => 'Laboratory'],
        ],
    ];
}

function attendance_schedule_data_from_array(array $source): array
{
    $days = $source['meeting_day'] ?? [];
    $types = $source['meeting_type'] ?? [];

    if (!is_array($days)) {
        $days = [$days];
    }

    if (!is_array($types)) {
        $types = [$types];
    }

    $slots = [];
    $count = max(count($days), count($types));

    for ($i = 0; $i < $count; $i++) {
        $day = (int) ($days[$i] ?? 0);
        $type = trim((string) ($types[$i] ?? ''));

        if ($day >= 1 && $day <= 7 && $type !== '') {
            $slots[] = [
                'day_of_week' => $day,
                'meeting_type' => $type,
            ];
        }
    }

    return [
        'course_start_date' => trim((string) ($source['course_start_date'] ?? '')),
        'semester_weeks' => (int) ($source['semester_weeks'] ?? 18),
        'meetings_per_week' => (int) ($source['meetings_per_week'] ?? max(1, count($slots))),
        'slots' => $slots,
    ];
}

function validate_attendance_schedule_data(array $scheduleData): array
{
    $errors = [];

    if ($scheduleData['course_start_date'] === '' || strtotime((string) $scheduleData['course_start_date']) === false) {
        $errors[] = 'Course start date is required.';
    }

    if ($scheduleData['semester_weeks'] < 1 || $scheduleData['semester_weeks'] > 30) {
        $errors[] = 'Semester length must be between 1 and 30 weeks.';
    }

    if ($scheduleData['meetings_per_week'] < 1 || $scheduleData['meetings_per_week'] > 7) {
        $errors[] = 'Meetings per week must be between 1 and 7.';
    }

    if (count($scheduleData['slots']) < 1) {
        $errors[] = 'Add at least one weekly meeting.';
    }

    if (count($scheduleData['slots']) !== $scheduleData['meetings_per_week']) {
        $errors[] = 'Weekly meeting rows must match meetings per week.';
    }

    return $errors;
}

function get_class_teaching_schedule(PDO $pdo, int $classId): array
{
    $defaults = attendance_schedule_defaults();
    $stmt = $pdo->prepare(
        'SELECT course_start_date, semester_weeks, meetings_per_week
         FROM class_teaching_schedules
         WHERE class_id = :class_id
         LIMIT 1'
    );
    $stmt->execute([':class_id' => $classId]);
    $schedule = $stmt->fetch();

    if (!$schedule) {
        return $defaults;
    }

    $slotsStmt = $pdo->prepare(
        'SELECT day_of_week, meeting_type
         FROM class_teaching_schedule_slots
         WHERE class_id = :class_id
         ORDER BY slot_order ASC'
    );
    $slotsStmt->execute([':class_id' => $classId]);
    $slots = $slotsStmt->fetchAll();

    return [
        'course_start_date' => (string) $schedule['course_start_date'],
        'semester_weeks' => (int) $schedule['semester_weeks'],
        'meetings_per_week' => (int) $schedule['meetings_per_week'],
        'slots' => !empty($slots) ? $slots : $defaults['slots'],
    ];
}

function save_class_teaching_schedule(PDO $pdo, int $classId, array $scheduleData): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO class_teaching_schedules (class_id, course_start_date, semester_weeks, meetings_per_week)
         VALUES (:class_id, :course_start_date, :semester_weeks, :meetings_per_week)
         ON DUPLICATE KEY UPDATE
            course_start_date = VALUES(course_start_date),
            semester_weeks = VALUES(semester_weeks),
            meetings_per_week = VALUES(meetings_per_week)'
    );
    $stmt->execute([
        ':class_id' => $classId,
        ':course_start_date' => $scheduleData['course_start_date'],
        ':semester_weeks' => $scheduleData['semester_weeks'],
        ':meetings_per_week' => $scheduleData['meetings_per_week'],
    ]);

    $pdo->prepare('DELETE FROM class_teaching_schedule_slots WHERE class_id = :class_id')->execute([':class_id' => $classId]);
    $slotStmt = $pdo->prepare(
        'INSERT INTO class_teaching_schedule_slots (class_id, slot_order, day_of_week, meeting_type)
         VALUES (:class_id, :slot_order, :day_of_week, :meeting_type)'
    );

    foreach ($scheduleData['slots'] as $index => $slot) {
        $slotStmt->execute([
            ':class_id' => $classId,
            ':slot_order' => $index + 1,
            ':day_of_week' => (int) $slot['day_of_week'],
            ':meeting_type' => (string) $slot['meeting_type'],
        ]);
    }
}

function date_for_weekday(DateTimeImmutable $weekStart, int $dayOfWeek): string
{
    $currentDay = (int) $weekStart->format('N');
    $offset = ($dayOfWeek - $currentDay + 7) % 7;

    return $weekStart->modify('+' . $offset . ' days')->format('Y-m-d');
}

function regenerate_class_meetings(PDO $pdo, int $classId): void
{
    $schedule = get_class_teaching_schedule($pdo, $classId);
    $start = new DateTimeImmutable((string) $schedule['course_start_date']);

    $deleteStmt = $pdo->prepare(
        'DELETE cm
         FROM class_meetings cm
         LEFT JOIN attendance_records ar ON ar.meeting_id = cm.id
         WHERE cm.class_id = :class_id
           AND cm.source = "generated"
           AND cm.is_customized = 0
           AND ar.id IS NULL'
    );
    $deleteStmt->execute([':class_id' => $classId]);

    $existsStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM class_meetings
         WHERE class_id = :class_id
           AND source = "generated"
           AND week_number = :week_number
           AND slot_order = :slot_order'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO class_meetings (class_id, meeting_date, week_number, slot_order, meeting_type, status, source)
         VALUES (:class_id, :meeting_date, :week_number, :slot_order, :meeting_type, "regular", "generated")'
    );

    for ($week = 1; $week <= (int) $schedule['semester_weeks']; $week++) {
        $weekStart = $start->modify('+' . (($week - 1) * 7) . ' days');

        foreach ($schedule['slots'] as $index => $slot) {
            $slotOrder = $index + 1;
            $existsStmt->execute([
                ':class_id' => $classId,
                ':week_number' => $week,
                ':slot_order' => $slotOrder,
            ]);

            if ((int) $existsStmt->fetchColumn() > 0) {
                continue;
            }

            $insertStmt->execute([
                ':class_id' => $classId,
                ':meeting_date' => date_for_weekday($weekStart, (int) $slot['day_of_week']),
                ':week_number' => $week,
                ':slot_order' => $slotOrder,
                ':meeting_type' => (string) $slot['meeting_type'],
            ]);
        }
    }
}

function get_class_meetings(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT cm.*,
            (SELECT COUNT(*) FROM attendance_records ar WHERE ar.meeting_id = cm.id) AS record_count
         FROM class_meetings cm
         WHERE cm.class_id = :class_id
         ORDER BY cm.meeting_date ASC, cm.id ASC'
    );
    $stmt->execute([':class_id' => $classId]);

    return $stmt->fetchAll();
}

function get_attendance_summary(PDO $pdo, int $classId): array
{
    $meetings = get_class_meetings($pdo, $classId);
    $totalPlanned = count($meetings);
    $counted = 0;
    $completed = 0;

    foreach ($meetings as $meeting) {
        if ($meeting['status'] === 'regular') {
            $counted++;
            if ((int) $meeting['record_count'] > 0) {
                $completed++;
            }
        }
    }

    $rateStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN ar.status IN ("present","late","excused") THEN 1 ELSE 0 END) AS attended_count,
            COUNT(*) AS total_count
         FROM attendance_records ar
         INNER JOIN class_meetings cm ON cm.id = ar.meeting_id
         WHERE cm.class_id = :class_id AND cm.status = "regular"'
    );
    $rateStmt->execute([':class_id' => $classId]);
    $rate = $rateStmt->fetch();
    $attended = (int) ($rate['attended_count'] ?? 0);
    $totalRecords = (int) ($rate['total_count'] ?? 0);

    return [
        'attendance_rate' => $totalRecords > 0 ? round(($attended / $totalRecords) * 100) : 0,
        'total_planned' => $totalPlanned,
        'counted' => $counted,
        'completed' => $completed,
        'remaining' => max(0, $counted - $completed),
    ];
}

function meeting_belongs_to_instructor(PDO $pdo, int $meetingId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cm.*
         FROM class_meetings cm
         INNER JOIN classes c ON c.id = cm.class_id
         WHERE cm.id = :meeting_id AND c.instructor_id = :instructor_id
         LIMIT 1'
    );
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':instructor_id' => $instructorId,
    ]);
    $meeting = $stmt->fetch();

    return $meeting ?: null;
}

function save_class_meeting(PDO $pdo, int $classId, array $data, ?int $meetingId = null): int
{
    $date = (string) $data['meeting_date'];
    $type = trim((string) $data['meeting_type']);
    $status = (string) $data['status'];
    $topic = trim((string) ($data['topic'] ?? ''));
    $schedule = get_class_teaching_schedule($pdo, $classId);
    $start = new DateTimeImmutable((string) $schedule['course_start_date']);
    $meetingDate = new DateTimeImmutable($date);
    $weekNumber = max(1, (int) floor(((int) $start->diff($meetingDate)->format('%r%a')) / 7) + 1);

    if ($meetingId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE class_meetings
             SET meeting_date = :meeting_date,
                 week_number = :week_number,
                 meeting_type = :meeting_type,
                 status = :status,
                 topic = :topic,
                 is_customized = 1
             WHERE id = :meeting_id AND class_id = :class_id'
        );
        $stmt->execute([
            ':meeting_date' => $date,
            ':week_number' => $weekNumber,
            ':meeting_type' => $type,
            ':status' => $status,
            ':topic' => $topic !== '' ? $topic : null,
            ':meeting_id' => $meetingId,
            ':class_id' => $classId,
        ]);

        return $meetingId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO class_meetings (class_id, meeting_date, week_number, meeting_type, status, topic, source, is_customized)
         VALUES (:class_id, :meeting_date, :week_number, :meeting_type, :status, :topic, "manual", 1)'
    );
    $stmt->execute([
        ':class_id' => $classId,
        ':meeting_date' => $date,
        ':week_number' => $weekNumber,
        ':meeting_type' => $type,
        ':status' => $status,
        ':topic' => $topic !== '' ? $topic : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function get_enrolled_students_with_attendance(PDO $pdo, int $classId, int $meetingId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            s.id,
            s.student_no,
            CONCAT(s.first_name, " ", s.last_name) AS student_name,
            COALESCE(ar.status, "present") AS attendance_status
         FROM class_enrollments ce
         INNER JOIN students s ON s.id = ce.student_id
         LEFT JOIN attendance_records ar ON ar.student_id = s.id AND ar.meeting_id = :meeting_id
         WHERE ce.class_id = :class_id AND ce.status = "active"
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':class_id' => $classId,
    ]);

    return $stmt->fetchAll();
}

function active_student_ids_for_class(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT student_id
         FROM class_enrollments
         WHERE class_id = :class_id AND status = "active"'
    );
    $stmt->execute([':class_id' => $classId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function attendance_record_status_meta(): array
{
    return [
        'present' => ['label' => 'Present', 'icon' => 'bi-check-circle-fill'],
        'absent' => ['label' => 'Absent', 'icon' => 'bi-x-circle-fill'],
        'late' => ['label' => 'Late', 'icon' => 'bi-clock-fill'],
        'excused' => ['label' => 'Excused', 'icon' => 'bi-shield-fill-check'],
    ];
}

function meeting_visual_state(array $meeting): string
{
    if (in_array($meeting['status'], ['holiday', 'cancelled'], true)) {
        return (string) $meeting['status'];
    }

    if ((int) $meeting['record_count'] > 0) {
        return 'completed';
    }

    if ((string) $meeting['meeting_date'] > date('Y-m-d')) {
        return 'upcoming';
    }

    return 'pending';
}

function meeting_visual_state_meta(): array
{
    return [
        'completed' => ['label' => 'Completed', 'icon' => 'bi-check-circle-fill'],
        'pending' => ['label' => 'Pending', 'icon' => 'bi-exclamation-circle-fill'],
        'upcoming' => ['label' => 'Upcoming', 'icon' => 'bi-calendar2-week'],
        'holiday' => ['label' => 'Holiday', 'icon' => 'bi-sun-fill'],
        'cancelled' => ['label' => 'Cancelled', 'icon' => 'bi-slash-circle'],
    ];
}

function attendance_current_week_number(array $meetings): int
{
    if (empty($meetings)) {
        return 1;
    }

    $today = strtotime(date('Y-m-d'));
    $closestWeek = (int) $meetings[0]['week_number'];
    $closestDiff = null;

    foreach ($meetings as $meeting) {
        $diff = abs(strtotime((string) $meeting['meeting_date']) - $today);

        if ($closestDiff === null || $diff < $closestDiff) {
            $closestDiff = $diff;
            $closestWeek = (int) $meeting['week_number'];
        }
    }

    return $closestWeek;
}

function get_meetings_needing_attention(array $meetings): array
{
    $today = date('Y-m-d');

    return array_values(array_filter($meetings, static function (array $meeting) use ($today): bool {
        return $meeting['status'] === 'regular'
            && (string) $meeting['meeting_date'] <= $today
            && (int) $meeting['record_count'] === 0;
    }));
}

function get_class_enrolled_students(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.student_no, CONCAT(s.first_name, " ", s.last_name) AS student_name
         FROM class_enrollments ce
         INNER JOIN students s ON s.id = ce.student_id
         WHERE ce.class_id = :class_id AND ce.status = "active"
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $stmt->execute([':class_id' => $classId]);

    return $stmt->fetchAll();
}

function get_class_attendance_matrix(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT ar.student_id, ar.meeting_id, ar.status
         FROM attendance_records ar
         INNER JOIN class_meetings cm ON cm.id = ar.meeting_id
         WHERE cm.class_id = :class_id'
    );
    $stmt->execute([':class_id' => $classId]);

    $matrix = [];
    foreach ($stmt->fetchAll() as $row) {
        $matrix[(int) $row['student_id']][(int) $row['meeting_id']] = (string) $row['status'];
    }

    return $matrix;
}

/**
 * Attendance status per student for a given calendar date, resolved from the
 * class meeting scheduled on that date. Used by assessment grading to show each
 * student's Present/Absent/Late/Excused for the assessment date.
 *
 * Returns ['has_meeting' => bool, 'meeting' => ?array, 'statuses' => [student_id => status]].
 * When no meeting exists on that date, has_meeting is false and statuses is empty.
 */
function get_attendance_status_for_date(PDO $pdo, int $classId, ?string $date): array
{
    $empty = ['has_meeting' => false, 'meeting' => null, 'statuses' => []];
    if ($date === null || trim($date) === '') {
        return $empty;
    }

    // Prefer a regular meeting on that date; fall back to any meeting on the date.
    $stmt = $pdo->prepare(
        'SELECT id, meeting_date, week_number, status, meeting_type
         FROM class_meetings
         WHERE class_id = :class_id AND meeting_date = :date
         ORDER BY (status = "regular") DESC, id ASC
         LIMIT 1'
    );
    $stmt->execute([':class_id' => $classId, ':date' => $date]);
    $meeting = $stmt->fetch();
    if (!$meeting) {
        return $empty;
    }

    $records = $pdo->prepare(
        'SELECT student_id, status FROM attendance_records WHERE meeting_id = :meeting_id'
    );
    $records->execute([':meeting_id' => (int) $meeting['id']]);

    $statuses = [];
    foreach ($records->fetchAll() as $row) {
        $statuses[(int) $row['student_id']] = (string) $row['status'];
    }

    return ['has_meeting' => true, 'meeting' => $meeting, 'statuses' => $statuses];
}

function build_student_attendance_overview(array $students, array $meetings, array $matrix): array
{
    $overview = [];

    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $studentMatrix = $matrix[$studentId] ?? [];
        $attended = 0;
        $total = 0;

        foreach ($meetings as $meeting) {
            if ($meeting['status'] !== 'regular') {
                continue;
            }

            $status = $studentMatrix[(int) $meeting['id']] ?? null;

            if ($status === null) {
                continue;
            }

            $total++;
            if (in_array($status, ['present', 'late', 'excused'], true)) {
                $attended++;
            }
        }

        $overview[] = [
            'id' => $studentId,
            'student_no' => (string) $student['student_no'],
            'student_name' => (string) $student['student_name'],
            'attended' => $attended,
            'total' => $total,
            'rate' => $total > 0 ? (int) round(($attended / $total) * 100) : null,
        ];
    }

    return $overview;
}

function build_student_meeting_history(array $meetings, array $matrix, int $studentId): array
{
    $studentMatrix = $matrix[$studentId] ?? [];
    $history = [];

    foreach ($meetings as $meeting) {
        $history[] = [
            'meeting' => $meeting,
            'status' => $studentMatrix[(int) $meeting['id']] ?? null,
        ];
    }

    return $history;
}

function save_attendance_records(PDO $pdo, int $meetingId, array $attendance, ?int $classId = null): void
{
    $statuses = attendance_record_statuses();
    $allowedStudentIds = $classId !== null ? active_student_ids_for_class($pdo, $classId) : [];
    $allowedMap = array_fill_keys($allowedStudentIds, true);
    $stmt = $pdo->prepare(
        'INSERT INTO attendance_records (meeting_id, student_id, status)
         VALUES (:meeting_id, :student_id, :status)
         ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($attendance as $studentId => $status) {
        $studentId = (int) $studentId;
        $status = (string) $status;
        if (!isset($statuses[$status])) {
            continue;
        }

        if ($classId !== null && !isset($allowedMap[$studentId])) {
            continue;
        }

        $stmt->execute([
            ':meeting_id' => $meetingId,
            ':student_id' => $studentId,
            ':status' => $status,
        ]);
    }
}
