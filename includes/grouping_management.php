<?php
/**
 * Class Groupings: reusable, class-level student groupings.
 *
 * Groupings are created Randomly, as a Suggested (performance-balanced) split,
 * or Manually, then edited (names, leaders, membership). They are reusable
 * across group activities, projects, lab work, etc. Activity-specific groupings
 * live in the same tables with scope = "activity" and an item_id, so they never
 * overwrite the saved class groupings.
 */

require_once __DIR__ . '/attendance_management.php';
require_once __DIR__ . '/participation_management.php';

function grouping_methods(): array
{
    return [
        'random' => ['label' => 'Completely Random', 'icon' => 'bi-shuffle'],
        'suggested' => ['label' => 'Suggested (Balanced)', 'icon' => 'bi-bar-chart-steps'],
        'manual' => ['label' => 'Manual', 'icon' => 'bi-hand-index'],
    ];
}

function ensure_grouping_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_groupings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            class_id INT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            scope ENUM("class","activity") NOT NULL DEFAULT "class",
            item_id INT UNSIGNED DEFAULT NULL,
            method ENUM("random","suggested","manual") NOT NULL DEFAULT "manual",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_class_groupings_class_scope (class_id, scope),
            KEY idx_class_groupings_item (item_id),
            CONSTRAINT fk_class_groupings_class_id FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_grouping_groups (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            grouping_id INT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            leader_student_id INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_class_grouping_groups_grouping (grouping_id),
            CONSTRAINT fk_class_grouping_groups_grouping_id FOREIGN KEY (grouping_id) REFERENCES class_groupings (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS class_grouping_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_grouping_members (group_id, student_id),
            KEY idx_class_grouping_members_student (student_id),
            CONSTRAINT fk_class_grouping_members_group_id FOREIGN KEY (group_id) REFERENCES class_grouping_groups (id) ON DELETE CASCADE,
            CONSTRAINT fk_class_grouping_members_student_id FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Per-student performance signal in [0,1], used for Suggested (Balanced)
 * splits. Combines whatever data exists: assessment score percentage,
 * participation average, and attendance rate. Students with no data get 0.5
 * (neutral), so balanced mode degrades gracefully to a random-like split.
 */
function get_student_performance_map(PDO $pdo, int $classId): array
{
    $signals = [];

    $assess = $pdo->prepare(
        'SELECT cas.student_id, AVG(cas.score / NULLIF(cai.max_score, 0)) AS pct
         FROM class_assessment_scores cas
         INNER JOIN class_assessment_items cai ON cai.id = cas.item_id
         WHERE cai.class_id = :class_id
         GROUP BY cas.student_id'
    );
    $assess->execute([':class_id' => $classId]);
    foreach ($assess->fetchAll() as $row) {
        $signals[(int) $row['student_id']][] = max(0.0, min(1.0, (float) $row['pct']));
    }

    $part = $pdo->prepare(
        'SELECT pr.student_id, AVG(pr.score) AS avg_score
         FROM participation_records pr
         INNER JOIN class_meetings cm ON cm.id = pr.meeting_id
         WHERE cm.class_id = :class_id
         GROUP BY pr.student_id'
    );
    $part->execute([':class_id' => $classId]);
    $partMax = (float) participation_max_score();
    foreach ($part->fetchAll() as $row) {
        if ($partMax > 0) {
            $signals[(int) $row['student_id']][] = max(0.0, min(1.0, (float) $row['avg_score'] / $partMax));
        }
    }

    $att = $pdo->prepare(
        'SELECT ar.student_id,
            SUM(CASE WHEN ar.status IN ("present","late","excused") THEN 1 ELSE 0 END) / COUNT(*) AS rate
         FROM attendance_records ar
         INNER JOIN class_meetings cm ON cm.id = ar.meeting_id
         WHERE cm.class_id = :class_id AND cm.status = "regular"
         GROUP BY ar.student_id'
    );
    $att->execute([':class_id' => $classId]);
    foreach ($att->fetchAll() as $row) {
        $signals[(int) $row['student_id']][] = max(0.0, min(1.0, (float) $row['rate']));
    }

    $map = [];
    foreach ($signals as $studentId => $values) {
        $map[$studentId] = array_sum($values) / count($values);
    }

    return $map;
}

function grouping_group_count(int $studentCount, string $sizeBy, int $sizeValue): int
{
    $sizeValue = max(1, $sizeValue);

    if ($sizeBy === 'per_group') {
        $count = $studentCount > 0 ? (int) ceil($studentCount / $sizeValue) : 1;
    } else {
        $count = $sizeValue;
    }

    // At least one group; never more groups than students (unless there are none).
    if ($studentCount > 0) {
        $count = min($count, $studentCount);
    }

    return max(1, $count);
}

/**
 * Builds group assignments.
 * $students: list of ['id'=>int,'name'=>string]. Returns a list of groups:
 * ['name'=>string, 'leader'=>?int, 'members'=>int[]].
 */
function generate_grouping(array $students, string $method, string $sizeBy, int $sizeValue, bool $assignLeaders, array $performanceMap): array
{
    $numGroups = grouping_group_count(count($students), $sizeBy, $sizeValue);

    $groups = [];
    for ($i = 0; $i < $numGroups; $i++) {
        $groups[$i] = ['name' => 'Group ' . ($i + 1), 'leader' => null, 'members' => []];
    }

    if ($method === 'manual' || empty($students)) {
        return $groups; // Empty groups the instructor fills in manually.
    }

    if ($method === 'suggested') {
        // Sort by performance descending, then snake-draft across groups so the
        // strongest students are spread evenly.
        usort($students, static function (array $a, array $b) use ($performanceMap): int {
            $pa = $performanceMap[(int) $a['id']] ?? 0.5;
            $pb = $performanceMap[(int) $b['id']] ?? 0.5;
            return $pb <=> $pa;
        });

        foreach ($students as $index => $student) {
            $round = intdiv($index, $numGroups);
            $pos = $index % $numGroups;
            $groupIndex = ($round % 2 === 0) ? $pos : ($numGroups - 1 - $pos);
            $groups[$groupIndex]['members'][] = (int) $student['id'];
        }
    } else {
        // Completely random: shuffle then round-robin for even sizes.
        shuffle($students);
        foreach ($students as $index => $student) {
            $groups[$index % $numGroups]['members'][] = (int) $student['id'];
        }
    }

    if ($assignLeaders) {
        foreach ($groups as $i => $group) {
            if (empty($group['members'])) {
                continue;
            }
            // Highest-performance member leads; ties fall to the first member.
            $leader = $group['members'][0];
            $best = $performanceMap[$leader] ?? 0.5;
            foreach ($group['members'] as $memberId) {
                $score = $performanceMap[$memberId] ?? 0.5;
                if ($score > $best) {
                    $best = $score;
                    $leader = $memberId;
                }
            }
            $groups[$i]['leader'] = $leader;
        }
    }

    return $groups;
}

function create_grouping(PDO $pdo, int $classId, string $name, string $method, string $scope = 'class', ?int $itemId = null): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO class_groupings (class_id, name, scope, item_id, method)
         VALUES (:class_id, :name, :scope, :item_id, :method)'
    );
    $stmt->execute([
        ':class_id' => $classId,
        ':name' => $name,
        ':scope' => $scope === 'activity' ? 'activity' : 'class',
        ':item_id' => $itemId,
        ':method' => isset(grouping_methods()[$method]) ? $method : 'manual',
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Replaces a grouping's groups and members from a normalized structure:
 * $groups = list of ['name'=>string,'leader'=>?int,'members'=>int[]].
 * Only students actively enrolled in the class are accepted, and each student
 * lands in at most one group (first wins).
 */
function save_grouping_structure(PDO $pdo, int $groupingId, int $classId, array $groups): void
{
    $allowed = array_fill_keys(active_student_ids_for_class($pdo, $classId), true);

    $pdo->prepare('DELETE FROM class_grouping_groups WHERE grouping_id = :grouping_id')
        ->execute([':grouping_id' => $groupingId]);

    $insertGroup = $pdo->prepare(
        'INSERT INTO class_grouping_groups (grouping_id, position, name, leader_student_id)
         VALUES (:grouping_id, :position, :name, :leader)'
    );
    $insertMember = $pdo->prepare(
        'INSERT INTO class_grouping_members (group_id, student_id) VALUES (:group_id, :student_id)'
    );

    $seen = [];
    $position = 1;

    foreach ($groups as $group) {
        $name = trim((string) ($group['name'] ?? ''));
        if ($name === '') {
            $name = 'Group ' . $position;
        }

        $members = [];
        foreach ((array) ($group['members'] ?? []) as $studentId) {
            $studentId = (int) $studentId;
            if ($studentId > 0 && isset($allowed[$studentId]) && !isset($seen[$studentId])) {
                $members[] = $studentId;
                $seen[$studentId] = true;
            }
        }

        $leader = (int) ($group['leader'] ?? 0);
        if ($leader <= 0 || !in_array($leader, $members, true)) {
            $leader = null;
        }

        $insertGroup->execute([
            ':grouping_id' => $groupingId,
            ':position' => $position,
            ':name' => mb_substr($name, 0, 150),
            ':leader' => $leader,
        ]);
        $groupId = (int) $pdo->lastInsertId();

        foreach ($members as $memberId) {
            $insertMember->execute([':group_id' => $groupId, ':student_id' => $memberId]);
        }

        $position++;
    }

    $pdo->prepare('UPDATE class_groupings SET name = name WHERE id = :id')->execute([':id' => $groupingId]);
}

function rename_grouping(PDO $pdo, int $groupingId, string $name): void
{
    $pdo->prepare('UPDATE class_groupings SET name = :name WHERE id = :id')
        ->execute([':name' => mb_substr($name, 0, 150), ':id' => $groupingId]);
}

function get_class_groupings(PDO $pdo, int $classId, string $scope = 'class'): array
{
    $stmt = $pdo->prepare(
        'SELECT cg.*,
            (SELECT COUNT(*) FROM class_grouping_groups g WHERE g.grouping_id = cg.id) AS group_count,
            (SELECT COUNT(*) FROM class_grouping_members m
                INNER JOIN class_grouping_groups g ON g.id = m.group_id
                WHERE g.grouping_id = cg.id) AS member_count
         FROM class_groupings cg
         WHERE cg.class_id = :class_id AND cg.scope = :scope
         ORDER BY cg.created_at DESC, cg.id DESC'
    );
    $stmt->execute([':class_id' => $classId, ':scope' => $scope]);

    return $stmt->fetchAll();
}

function grouping_belongs_to_instructor(PDO $pdo, int $groupingId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT cg.*
         FROM class_groupings cg
         INNER JOIN classes c ON c.id = cg.class_id
         WHERE cg.id = :grouping_id AND c.instructor_id = :instructor_id
         LIMIT 1'
    );
    $stmt->execute([':grouping_id' => $groupingId, ':instructor_id' => $instructorId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function get_grouping_with_groups(PDO $pdo, int $groupingId): array
{
    $groupsStmt = $pdo->prepare(
        'SELECT id, position, name, leader_student_id
         FROM class_grouping_groups
         WHERE grouping_id = :grouping_id
         ORDER BY position ASC, id ASC'
    );
    $groupsStmt->execute([':grouping_id' => $groupingId]);
    $groups = $groupsStmt->fetchAll();

    $membersStmt = $pdo->prepare(
        'SELECT m.group_id, m.student_id, s.student_no,
            CONCAT(s.first_name, " ", s.last_name) AS student_name
         FROM class_grouping_members m
         INNER JOIN class_grouping_groups g ON g.id = m.group_id
         INNER JOIN students s ON s.id = m.student_id
         WHERE g.grouping_id = :grouping_id
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $membersStmt->execute([':grouping_id' => $groupingId]);

    $membersByGroup = [];
    foreach ($membersStmt->fetchAll() as $row) {
        $membersByGroup[(int) $row['group_id']][] = $row;
    }

    foreach ($groups as $index => $group) {
        $groups[$index]['members'] = $membersByGroup[(int) $group['id']] ?? [];
    }

    return $groups;
}

/**
 * Returns [student_id => group_id] for a grouping, for quick membership lookup.
 */
function get_grouping_member_map(PDO $pdo, int $groupingId): array
{
    $stmt = $pdo->prepare(
        'SELECT m.student_id, m.group_id
         FROM class_grouping_members m
         INNER JOIN class_grouping_groups g ON g.id = m.group_id
         WHERE g.grouping_id = :grouping_id'
    );
    $stmt->execute([':grouping_id' => $groupingId]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['student_id']] = (int) $row['group_id'];
    }

    return $map;
}

function delete_grouping(PDO $pdo, int $groupingId): void
{
    $pdo->prepare('DELETE FROM class_groupings WHERE id = :id')->execute([':id' => $groupingId]);
}

/**
 * Confirms a group row belongs to a grouping owned by the instructor.
 * Returns [group_id, grouping_id, class_id, scope, item_id] or null.
 */
function grouping_group_belongs_to_instructor(PDO $pdo, int $groupId, int $instructorId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT g.id AS group_id, g.grouping_id, cg.class_id, cg.scope, cg.item_id
         FROM class_grouping_groups g
         INNER JOIN class_groupings cg ON cg.id = g.grouping_id
         INNER JOIN classes c ON c.id = cg.class_id
         WHERE g.id = :group_id AND c.instructor_id = :instructor_id
         LIMIT 1'
    );
    $stmt->execute([':group_id' => $groupId, ':instructor_id' => $instructorId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Moves a student to a group within a grouping (groupId = 0 unassigns).
 * The student is first removed from every group in the grouping, and if they
 * were a group's leader that leadership is cleared. Idempotent.
 */
function assign_student_to_group(PDO $pdo, int $groupingId, int $studentId, int $groupId): void
{
    $groupsStmt = $pdo->prepare('SELECT id FROM class_grouping_groups WHERE grouping_id = :gid');
    $groupsStmt->execute([':gid' => $groupingId]);
    $groupIds = array_map('intval', $groupsStmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($groupIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $del = $pdo->prepare("DELETE FROM class_grouping_members WHERE student_id = ? AND group_id IN ($placeholders)");
    $del->execute(array_merge([$studentId], $groupIds));

    $clearLeader = $pdo->prepare('UPDATE class_grouping_groups SET leader_student_id = NULL WHERE grouping_id = :gid AND leader_student_id = :sid');
    $clearLeader->execute([':gid' => $groupingId, ':sid' => $studentId]);

    if ($groupId > 0 && in_array($groupId, $groupIds, true)) {
        $ins = $pdo->prepare('INSERT IGNORE INTO class_grouping_members (group_id, student_id) VALUES (:gid, :sid)');
        $ins->execute([':gid' => $groupId, ':sid' => $studentId]);
    }
}

function rename_grouping_group(PDO $pdo, int $groupId, string $name): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }
    $pdo->prepare('UPDATE class_grouping_groups SET name = :name WHERE id = :gid')
        ->execute([':name' => mb_substr($name, 0, 150), ':gid' => $groupId]);
}

/**
 * Sets a group's leader, but only if the student is a member of that group;
 * otherwise clears the leader.
 */
function set_group_leader(PDO $pdo, int $groupId, int $studentId): void
{
    if ($studentId > 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM class_grouping_members WHERE group_id = :gid AND student_id = :sid');
        $chk->execute([':gid' => $groupId, ':sid' => $studentId]);
        if ((int) $chk->fetchColumn() === 0) {
            $studentId = 0;
        }
    }
    $pdo->prepare('UPDATE class_grouping_groups SET leader_student_id = :leader WHERE id = :gid')
        ->execute([':leader' => $studentId > 0 ? $studentId : null, ':gid' => $groupId]);
}

/**
 * Lightweight JSON-friendly snapshot of a grouping's groups + members, used by
 * the live editor to re-render after an instant assignment.
 */
function grouping_groups_payload(PDO $pdo, int $groupingId): array
{
    $groups = get_grouping_with_groups($pdo, $groupingId);
    $out = [];

    foreach ($groups as $group) {
        $members = [];
        foreach ($group['members'] as $member) {
            $members[] = ['id' => (int) $member['student_id'], 'name' => (string) $member['student_name']];
        }
        $out[] = [
            'id' => (int) $group['id'],
            'name' => (string) $group['name'],
            'leader' => (int) ($group['leader_student_id'] ?? 0),
            'members' => $members,
        ];
    }

    return $out;
}

/**
 * Removes activity-specific groupings tied to an item except the one to keep.
 * Called after (re)assigning an item's grouping so replaced activity-specific
 * groupings don't accumulate. Never touches class-scoped groupings.
 */
function delete_orphan_activity_groupings(PDO $pdo, int $itemId, int $keepGroupingId = 0): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM class_groupings
         WHERE item_id = :item_id AND scope = "activity" AND id <> :keep_id'
    );
    $stmt->execute([':item_id' => $itemId, ':keep_id' => $keepGroupingId]);
}

/**
 * Parses grouping generation settings from POST, returning
 * [config array, errors]. Config keys: name, method, size_by, size_value,
 * assign_leaders.
 */
function grouping_config_from_input(array $source): array
{
    $errors = [];
    $name = trim((string) ($source['grouping_name'] ?? ''));
    $method = (string) ($source['grouping_method'] ?? 'random');
    $sizeBy = ($source['grouping_size_by'] ?? 'groups') === 'per_group' ? 'per_group' : 'groups';
    $sizeValue = (int) ($source['grouping_size_value'] ?? 0);
    $assignLeaders = !empty($source['grouping_assign_leaders']);

    if ($name === '' || text_length($name) > 150) {
        $errors[] = 'Grouping name is required and must not exceed 150 characters.';
    }

    if (!isset(grouping_methods()[$method])) {
        $errors[] = 'Grouping method is invalid.';
    }

    if ($sizeValue < 1 || $sizeValue > 100) {
        $errors[] = ($sizeBy === 'per_group' ? 'Students per group' : 'Number of groups') . ' must be between 1 and 100.';
    }

    return [
        [
            'name' => $name,
            'method' => $method,
            'size_by' => $sizeBy,
            'size_value' => $sizeValue,
            'assign_leaders' => $assignLeaders,
        ],
        $errors,
    ];
}
