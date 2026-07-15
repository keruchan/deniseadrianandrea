<?php
/**
 * Activities & Quizzes (assessment) configuration helpers.
 *
 * A class optionally configures a total number of activities and quizzes.
 * Once a total is set, numbered items ("Activity 1..N", "Quiz 1..N") are
 * generated as class_assessment_items rows that instructors can rename,
 * edit, and grade later. Recorded scores protect items from automatic
 * removal when totals are reduced.
 */

function assessment_types(): array
{
    return [
        'activity' => [
            'label' => 'Activity',
            'plural' => 'Activities',
            'view' => 'activities',
            'icon' => 'bi-journal-check',
            'default_max' => 100,
        ],
        'quiz' => [
            'label' => 'Quiz',
            'plural' => 'Quizzes',
            'view' => 'quizzes',
            'icon' => 'bi-patch-question',
            'default_max' => 10,
        ],
    ];
}

function assessment_default_max(string $type): int
{
    return (int) (assessment_types()[$type]['default_max'] ?? 100);
}

/**
 * Formats a numeric score without trailing zeros (90.00 -> "90", 8.50 -> "8.5").
 * number_format guarantees a decimal point so trimming is always safe, even for
 * integer-valued floats where a naive rtrim would eat a significant zero.
 */
function format_score($value): string
{
    return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
}

/**
 * An item can be graded only once its required setup is complete: a title, a
 * scheduled date, a positive maximum score, and -- for Group Activities -- a
 * grouping actually assigned. (Activity Type itself is always set on the row,
 * defaulting to "individual", so there's nothing further to check for it here.)
 */
function is_assessment_item_gradeable(array $item): bool
{
    $isGroupActivity = (string) ($item['type'] ?? '') === 'activity'
        && (string) ($item['activity_mode'] ?? 'individual') === 'group';

    return trim((string) ($item['title'] ?? '')) !== ''
        && !empty($item['scheduled_date'])
        && (float) ($item['max_score'] ?? 0) > 0
        && (!$isGroupActivity || !empty($item['grouping_id']));
}

function ensure_assessment_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_assessment_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            total_activities SMALLINT UNSIGNED DEFAULT NULL,
            total_quizzes SMALLINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_assessment_settings_class_id (class_id),
            CONSTRAINT fk_class_assessment_settings_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_assessment_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            type ENUM("activity","quiz") NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            max_score DECIMAL(6,2) NOT NULL DEFAULT 100,
            scheduled_date DATE DEFAULT NULL,
            activity_mode ENUM("individual","group") NOT NULL DEFAULT "individual",
            grouping_id INT UNSIGNED DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_assessment_items_position (class_id, type, position),
            KEY idx_class_assessment_items_class_type (class_id, type),
            CONSTRAINT fk_class_assessment_items_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Migrations for databases created before these columns existed (MariaDB supports IF NOT EXISTS).
    $pdo->exec('ALTER TABLE class_assessment_items ADD COLUMN IF NOT EXISTS scheduled_date DATE DEFAULT NULL AFTER max_score');
    $pdo->exec('ALTER TABLE class_assessment_items ADD COLUMN IF NOT EXISTS activity_mode ENUM("individual","group") NOT NULL DEFAULT "individual" AFTER scheduled_date');
    $pdo->exec('ALTER TABLE class_assessment_items ADD COLUMN IF NOT EXISTS grouping_id INT UNSIGNED DEFAULT NULL AFTER activity_mode');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_assessment_scores (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            score DECIMAL(6,2) NOT NULL DEFAULT 0,
            saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_assessment_scores_item_student (item_id, student_id),
            KEY idx_class_assessment_scores_student_id (student_id),
            CONSTRAINT fk_class_assessment_scores_item_id FOREIGN KEY (item_id) REFERENCES class_assessment_items (id) ON DELETE CASCADE,
            CONSTRAINT fk_class_assessment_scores_student_id FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function get_assessment_settings(PDO $pdo, int $classId): array
{
    $stmt = $pdo->prepare(
        'SELECT total_activities, total_quizzes
         FROM class_assessment_settings
         WHERE class_id = :class_id
         LIMIT 1'
    );
    $stmt->execute([':class_id' => $classId]);
    $row = $stmt->fetch();

    return [
        'activity' => ($row && $row['total_activities'] !== null) ? (int) $row['total_activities'] : null,
        'quiz' => ($row && $row['total_quizzes'] !== null) ? (int) $row['total_quizzes'] : null,
    ];
}

function save_assessment_total(PDO $pdo, int $classId, string $type, int $total): void
{
    $column = $type === 'quiz' ? 'total_quizzes' : 'total_activities';
    $stmt = $pdo->prepare(
        'INSERT INTO class_assessment_settings (class_id, ' . $column . ')
         VALUES (:class_id, :total)
         ON DUPLICATE KEY UPDATE ' . $column . ' = VALUES(' . $column . ')'
    );
    $stmt->execute([
        ':class_id' => $classId,
        ':total' => $total,
    ]);
}

/**
 * Parses an optional total from form input.
 * Returns [?int value, bool provided, ?string error].
 */
function assessment_total_from_input($raw): array
{
    $raw = trim((string) $raw);

    if ($raw === '') {
        return [null, false, null];
    }

    if (!ctype_digit($raw)) {
        return [null, true, 'must be a whole number.'];
    }

    $value = (int) $raw;

    if ($value < 1 || $value > 200) {
        return [null, true, 'must be between 1 and 200.'];
    }

    return [$value, true, null];
}

/**
 * Aligns generated items with the configured total.
 * Appends "Activity N"/"Quiz N" items when the total grows; when it shrinks,
 * removes only trailing items that have no recorded scores so graded work is
 * never deleted automatically. Returns a summary of what happened.
 */
function sync_assessment_items(PDO $pdo, int $classId, string $type, int $total): array
{
    $label = assessment_types()[$type]['label'];

    $itemsStmt = $pdo->prepare(
        'SELECT cai.id, cai.position,
            (SELECT COUNT(*) FROM class_assessment_scores cas WHERE cas.item_id = cai.id) AS score_count
         FROM class_assessment_items cai
         WHERE cai.class_id = :class_id AND cai.type = :type
         ORDER BY cai.position ASC'
    );
    $itemsStmt->execute([':class_id' => $classId, ':type' => $type]);
    $items = $itemsStmt->fetchAll();

    $added = 0;
    $removed = 0;
    $keptGraded = 0;

    if (count($items) < $total) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO class_assessment_items (class_id, type, position, title, max_score)
             VALUES (:class_id, :type, :position, :title, :max_score)'
        );
        $maxPosition = !empty($items) ? (int) end($items)['position'] : 0;
        $defaultMax = assessment_default_max($type);

        for ($position = $maxPosition + 1; count($items) + $added < $total; $position++) {
            $insertStmt->execute([
                ':class_id' => $classId,
                ':type' => $type,
                ':position' => $position,
                ':title' => $label . ' ' . $position,
                ':max_score' => $defaultMax,
            ]);
            $added++;
        }
    } elseif (count($items) > $total) {
        $deleteStmt = $pdo->prepare('DELETE FROM class_assessment_items WHERE id = :id');

        for ($i = count($items) - 1; $i >= $total; $i--) {
            if ((int) $items[$i]['score_count'] > 0) {
                $keptGraded++;
                continue;
            }

            $deleteStmt->execute([':id' => (int) $items[$i]['id']]);
            $removed++;
        }
    }

    return ['added' => $added, 'removed' => $removed, 'kept_graded' => $keptGraded];
}

function assessment_default_title(string $type, int $position): string
{
    return assessment_types()[$type]['label'] . ' ' . $position;
}

/**
 * Appends a new item after the last position with default title/max score, and
 * keeps the configured total in sync. Returns the new item id.
 */
function add_assessment_item(PDO $pdo, int $classId, string $type): int
{
    $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM class_assessment_items WHERE class_id = :c AND type = :t');
    $posStmt->execute([':c' => $classId, ':t' => $type]);
    $position = (int) $posStmt->fetchColumn() + 1;

    $ins = $pdo->prepare(
        'INSERT INTO class_assessment_items (class_id, type, position, title, max_score)
         VALUES (:c, :t, :p, :title, :max)'
    );
    $ins->execute([
        ':c' => $classId,
        ':t' => $type,
        ':p' => $position,
        ':title' => assessment_default_title($type, $position),
        ':max' => assessment_default_max($type),
    ]);
    $newId = (int) $pdo->lastInsertId();

    $count = (int) $pdo->query('SELECT COUNT(*) FROM class_assessment_items WHERE class_id = ' . (int) $classId . ' AND type = ' . $pdo->quote($type))->fetchColumn();
    save_assessment_total($pdo, $classId, $type, $count);

    return $newId;
}

function delete_assessment_item(PDO $pdo, int $itemId): void
{
    // Scores cascade via the class_assessment_scores foreign key.
    $pdo->prepare('DELETE FROM class_assessment_items WHERE id = :id')->execute([':id' => $itemId]);
}

/**
 * Compacts item positions to a contiguous 1..N and renumbers only items that
 * still carry the auto-generated default label ("Activity 3" -> "Activity 2"),
 * leaving instructor-renamed items untouched. Positions and titles change only;
 * item ids (and therefore recorded scores) are preserved. Keeps the configured
 * total in sync with the actual item count.
 */
function resequence_assessment_items(PDO $pdo, int $classId, string $type): void
{
    $items = get_assessment_items($pdo, $classId, $type); // ordered by position
    $label = assessment_types()[$type]['label'];
    $upd = $pdo->prepare('UPDATE class_assessment_items SET position = :p, title = :title WHERE id = :id');

    $newPos = 1;
    foreach ($items as $item) {
        $oldPos = (int) $item['position'];
        $title = (string) $item['title'];

        // Only rewrite the label if it's still the default one for the old position.
        if ($title === $label . ' ' . $oldPos) {
            $title = $label . ' ' . $newPos;
        }

        $upd->execute([':p' => $newPos, ':title' => $title, ':id' => (int) $item['id']]);
        $newPos++;
    }

    save_assessment_total($pdo, $classId, $type, count($items));
}

function get_assessment_items(PDO $pdo, int $classId, string $type): array
{
    $stmt = $pdo->prepare(
        'SELECT cai.*,
            (SELECT COUNT(*) FROM class_assessment_scores cas WHERE cas.item_id = cai.id) AS score_count
         FROM class_assessment_items cai
         WHERE cai.class_id = :class_id AND cai.type = :type
         ORDER BY cai.position ASC, cai.id ASC'
    );
    $stmt->execute([':class_id' => $classId, ':type' => $type]);

    return $stmt->fetchAll();
}

function assessment_item_belongs_to_instructor(PDO $pdo, int $itemId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cai.*
         FROM class_assessment_items cai
         INNER JOIN classes c ON c.id = cai.class_id
         WHERE cai.id = :item_id AND c.instructor_id = :instructor_id
         LIMIT 1'
    );
    $stmt->execute([
        ':item_id' => $itemId,
        ':instructor_id' => $instructorId,
    ]);
    $item = $stmt->fetch();

    return $item ?: null;
}

function save_assessment_item(PDO $pdo, int $itemId, array $data): void
{
    $stmt = $pdo->prepare(
        'UPDATE class_assessment_items
         SET title = :title,
             max_score = :max_score,
             scheduled_date = :scheduled_date,
             description = :description,
             activity_mode = :activity_mode,
             grouping_id = :grouping_id
         WHERE id = :item_id'
    );
    $stmt->execute([
        ':title' => (string) $data['title'],
        ':max_score' => (float) $data['max_score'],
        ':scheduled_date' => !empty($data['scheduled_date']) ? (string) $data['scheduled_date'] : null,
        ':description' => trim((string) ($data['description'] ?? '')) !== '' ? (string) $data['description'] : null,
        ':activity_mode' => ($data['activity_mode'] ?? 'individual') === 'group' ? 'group' : 'individual',
        ':grouping_id' => !empty($data['grouping_id']) ? (int) $data['grouping_id'] : null,
        ':item_id' => $itemId,
    ]);
}

function set_assessment_item_grouping(PDO $pdo, int $itemId, ?int $groupingId): void
{
    $stmt = $pdo->prepare('UPDATE class_assessment_items SET grouping_id = :grouping_id WHERE id = :item_id');
    $stmt->execute([
        ':grouping_id' => $groupingId && $groupingId > 0 ? $groupingId : null,
        ':item_id' => $itemId,
    ]);
}

function get_assessment_item(PDO $pdo, int $classId, int $itemId, string $type): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cai.*,
            (SELECT COUNT(*) FROM class_assessment_scores cas WHERE cas.item_id = cai.id) AS score_count
         FROM class_assessment_items cai
         WHERE cai.id = :item_id AND cai.class_id = :class_id AND cai.type = :type
         LIMIT 1'
    );
    $stmt->execute([':item_id' => $itemId, ':class_id' => $classId, ':type' => $type]);
    $item = $stmt->fetch();

    return $item ?: null;
}

/**
 * Roster for grading an item: each active student with their saved score (or null).
 */
function get_students_with_scores(PDO $pdo, int $classId, int $itemId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.student_no,
            CONCAT(s.first_name, " ", s.last_name) AS student_name,
            cas.score AS item_score
         FROM class_enrollments ce
         INNER JOIN students s ON s.id = ce.student_id
         LEFT JOIN class_assessment_scores cas ON cas.student_id = s.id AND cas.item_id = :item_id
         WHERE ce.class_id = :class_id AND ce.status = "active"
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $stmt->execute([':item_id' => $itemId, ':class_id' => $classId]);

    return $stmt->fetchAll();
}

function get_item_scores_map(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare('SELECT student_id, score FROM class_assessment_scores WHERE item_id = :item_id');
    $stmt->execute([':item_id' => $itemId]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['student_id']] = (float) $row['score'];
    }

    return $map;
}

/**
 * Upsert per-student scores for an item. A blank value clears that student's
 * score; out-of-range values (outside 0..max) are skipped. Restricted to
 * actively-enrolled students. Returns the number of validation errors skipped.
 */
function save_assessment_scores(PDO $pdo, int $itemId, int $classId, float $maxScore, array $scores): int
{
    $allowed = array_fill_keys(active_student_ids_for_class($pdo, $classId), true);
    $upsert = $pdo->prepare(
        'INSERT INTO class_assessment_scores (item_id, student_id, score)
         VALUES (:item_id, :student_id, :score)
         ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = CURRENT_TIMESTAMP'
    );
    $delete = $pdo->prepare('DELETE FROM class_assessment_scores WHERE item_id = :item_id AND student_id = :student_id');
    $skipped = 0;

    foreach ($scores as $studentId => $raw) {
        $studentId = (int) $studentId;
        if (!isset($allowed[$studentId])) {
            continue;
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            $delete->execute([':item_id' => $itemId, ':student_id' => $studentId]);
            continue;
        }

        if (!is_numeric($raw)) {
            $skipped++;
            continue;
        }

        $value = (float) $raw;
        if ($value < 0 || $value > $maxScore) {
            $skipped++;
            continue;
        }

        $upsert->execute([
            ':item_id' => $itemId,
            ':student_id' => $studentId,
            ':score' => $value,
        ]);
    }

    return $skipped;
}

function get_assessment_summary(PDO $pdo, int $classId, string $type): array
{
    $items = get_assessment_items($pdo, $classId, $type);
    $configured = 0;
    $graded = 0;

    foreach ($items as $item) {
        if (is_assessment_item_gradeable($item)) {
            $configured++;
        }
        if ((int) $item['score_count'] > 0) {
            $graded++;
        }
    }

    return [
        'total' => count($items),
        'configured' => $configured,
        'needs_setup' => count($items) - $configured,
        'graded' => $graded,
    ];
}
