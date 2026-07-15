<?php
/**
 * Participation recording helpers.
 *
 * Reuses the meeting-based architecture defined in
 * includes/attendance_management.php (class_meetings, teaching schedule,
 * meeting visual state, current-week helpers). This module only adds the
 * participation_records store and its derived views.
 */

require_once __DIR__ . '/attendance_management.php';

function participation_max_score(): int
{
    return 100;
}

function ensure_participation_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS participation_records (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            meeting_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            score DECIMAL(6,2) NOT NULL DEFAULT 0,
            remarks VARCHAR(255) DEFAULT NULL,
            saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_participation_records_meeting_student (meeting_id, student_id),
            KEY idx_participation_records_student_id (student_id),
            CONSTRAINT fk_participation_records_meeting_id FOREIGN KEY (meeting_id) REFERENCES class_meetings (id) ON DELETE CASCADE,
            CONSTRAINT fk_participation_records_student_id FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Roster for a meeting with each student's saved participation score (if any)
 * and whether they were marked absent for that meeting.
 */
function get_students_with_participation(PDO $pdo, int $classId, int $meetingId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            s.id,
            s.student_no,
            CONCAT(s.first_name, " ", s.last_name) AS student_name,
            pr.score AS participation_score,
            pr.remarks AS participation_remarks,
            ar.status AS attendance_status
         FROM class_enrollments ce
         INNER JOIN students s ON s.id = ce.student_id
         LEFT JOIN participation_records pr ON pr.student_id = s.id AND pr.meeting_id = :meeting_id
         LEFT JOIN attendance_records ar ON ar.student_id = s.id AND ar.meeting_id = :meeting_id2
         WHERE ce.class_id = :class_id AND ce.status = "active"
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $stmt->execute([
        ':meeting_id' => $meetingId,
        ':meeting_id2' => $meetingId,
        ':class_id' => $classId,
    ]);

    return $stmt->fetchAll();
}

function save_participation_records(PDO $pdo, int $meetingId, array $scores, array $remarks, int $classId): void
{
    $allowedStudentIds = active_student_ids_for_class($pdo, $classId);
    $allowedMap = array_fill_keys($allowedStudentIds, true);
    $maxScore = participation_max_score();

    $upsert = $pdo->prepare(
        'INSERT INTO participation_records (meeting_id, student_id, score, remarks)
         VALUES (:meeting_id, :student_id, :score, :remarks)
         ON DUPLICATE KEY UPDATE score = VALUES(score), remarks = VALUES(remarks), updated_at = CURRENT_TIMESTAMP'
    );
    $delete = $pdo->prepare(
        'DELETE FROM participation_records WHERE meeting_id = :meeting_id AND student_id = :student_id'
    );

    // Union of student ids referenced by either scores or remarks.
    $studentIds = array_unique(array_map('intval', array_merge(array_keys($scores), array_keys($remarks))));

    foreach ($studentIds as $studentId) {
        if (!isset($allowedMap[$studentId])) {
            continue;
        }

        $rawScore = trim((string) ($scores[$studentId] ?? ''));
        $remark = trim((string) ($remarks[$studentId] ?? ''));

        // Empty score with no remark clears the participation record for this student.
        if ($rawScore === '' && $remark === '') {
            $delete->execute([':meeting_id' => $meetingId, ':student_id' => $studentId]);
            continue;
        }

        $score = $rawScore === '' ? 0.0 : (float) $rawScore;
        $score = max(0.0, min((float) $maxScore, $score));

        $upsert->execute([
            ':meeting_id' => $meetingId,
            ':student_id' => $studentId,
            ':score' => $score,
            ':remarks' => $remark !== '' ? $remark : null,
        ]);
    }
}

/**
 * Returns [meeting_id => participation_record_count] for a class.
 */
function get_participation_counts(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT pr.meeting_id, COUNT(*) AS c
         FROM participation_records pr
         INNER JOIN class_meetings cm ON cm.id = pr.meeting_id
         WHERE cm.class_id = :class_id
         GROUP BY pr.meeting_id'
    );
    $stmt->execute([':class_id' => $classId]);

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['meeting_id']] = (int) $row['c'];
    }

    return $counts;
}

function get_participation_summary(PDO $pdo, int $classId): array
{
    $meetings = get_class_meetings($pdo, $classId);
    $countedMeetings = 0;
    foreach ($meetings as $meeting) {
        if ($meeting['status'] === 'regular') {
            $countedMeetings++;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_records,
            AVG(pr.score) AS avg_score,
            COUNT(DISTINCT pr.student_id) AS active_participants,
            COUNT(DISTINCT pr.meeting_id) AS sessions_recorded
         FROM participation_records pr
         INNER JOIN class_meetings cm ON cm.id = pr.meeting_id
         WHERE cm.class_id = :class_id'
    );
    $stmt->execute([':class_id' => $classId]);
    $row = $stmt->fetch() ?: [];

    $enrolledStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM class_enrollments WHERE class_id = :class_id AND status = "active"'
    );
    $enrolledStmt->execute([':class_id' => $classId]);
    $enrolled = (int) $enrolledStmt->fetchColumn();

    $activeParticipants = (int) ($row['active_participants'] ?? 0);

    return [
        'total_records' => (int) ($row['total_records'] ?? 0),
        'avg_score' => $row['avg_score'] !== null ? round((float) $row['avg_score'], 1) : 0.0,
        'active_participants' => $activeParticipants,
        'enrolled' => $enrolled,
        'coverage' => $enrolled > 0 ? (int) round(($activeParticipants / $enrolled) * 100) : 0,
        'sessions_recorded' => (int) ($row['sessions_recorded'] ?? 0),
        'counted_meetings' => $countedMeetings,
    ];
}

/**
 * matrix[studentId][meetingId] = ['score' => float, 'remarks' => ?string]
 */
function get_class_participation_matrix(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT pr.student_id, pr.meeting_id, pr.score, pr.remarks
         FROM participation_records pr
         INNER JOIN class_meetings cm ON cm.id = pr.meeting_id
         WHERE cm.class_id = :class_id'
    );
    $stmt->execute([':class_id' => $classId]);

    $matrix = [];
    foreach ($stmt->fetchAll() as $row) {
        $matrix[(int) $row['student_id']][(int) $row['meeting_id']] = [
            'score' => (float) $row['score'],
            'remarks' => $row['remarks'] !== null ? (string) $row['remarks'] : null,
        ];
    }

    return $matrix;
}

function build_student_participation_overview(array $students, array $meetings, array $matrix): array
{
    $overview = [];

    foreach ($students as $student) {
        $studentId = (int) $student['id'];
        $studentMatrix = $matrix[$studentId] ?? [];
        $count = 0;
        $total = 0.0;

        foreach ($meetings as $meeting) {
            $entry = $studentMatrix[(int) $meeting['id']] ?? null;
            if ($entry === null) {
                continue;
            }
            $count++;
            $total += $entry['score'];
        }

        $overview[] = [
            'id' => $studentId,
            'student_no' => (string) $student['student_no'],
            'student_name' => (string) $student['student_name'],
            'times' => $count,
            'total' => $total,
            'average' => $count > 0 ? round($total / $count, 1) : null,
        ];
    }

    return $overview;
}

function build_student_participation_history(array $meetings, array $matrix, int $studentId): array
{
    $studentMatrix = $matrix[$studentId] ?? [];
    $history = [];

    foreach ($meetings as $meeting) {
        $history[] = [
            'meeting' => $meeting,
            'entry' => $studentMatrix[(int) $meeting['id']] ?? null,
        ];
    }

    return $history;
}
