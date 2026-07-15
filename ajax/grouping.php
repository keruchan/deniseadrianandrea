<?php
/**
 * AJAX endpoint for the live grouping editor.
 * Persists a single grouping change immediately (no form submit): moving a
 * student between groups, renaming a group/grouping, or setting a leader.
 * Used by the class-level Groupings module and activity-specific groupings.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/attendance_management.php';      // active_student_ids_for_class
require_once __DIR__ . '/../includes/participation_management.php';
require_once __DIR__ . '/../includes/grouping_management.php';

header('Content-Type: application/json; charset=utf-8');

function grouping_ajax_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    grouping_ajax_fail('Method not allowed.', 405);
}

if (empty($_SESSION['id']) || (string) ($_SESSION['role'] ?? '') !== 'instructor') {
    grouping_ajax_fail('Not authorized.', 401);
}

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    grouping_ajax_fail('Not authorized.', 401);
}

// Same session token the class workspace uses; not rotated here so successive
// live edits keep working without a page reload.
if (!csrf_is_valid('csrf_class_manage_token', (string) ($_POST['csrf_token'] ?? ''))) {
    grouping_ajax_fail('Security validation failed. Refresh the page and try again.', 403);
}

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'assign_member') {
        $groupingId = (int) ($_POST['grouping_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $groupId = (int) ($_POST['group_id'] ?? 0); // 0 = unassigned

        $grouping = grouping_belongs_to_instructor($pdo, $groupingId, $instructorId);
        if (!$grouping) {
            grouping_ajax_fail('Grouping not found.', 404);
        }

        $allowed = array_fill_keys(active_student_ids_for_class($pdo, (int) $grouping['class_id']), true);
        if (!isset($allowed[$studentId])) {
            grouping_ajax_fail('Student is not enrolled in this class.', 400);
        }

        if ($groupId > 0) {
            $group = grouping_group_belongs_to_instructor($pdo, $groupId, $instructorId);
            if (!$group || (int) $group['grouping_id'] !== $groupingId) {
                grouping_ajax_fail('Group not found.', 404);
            }
        }

        assign_student_to_group($pdo, $groupingId, $studentId, $groupId);
        echo json_encode(['ok' => true, 'groups' => grouping_groups_payload($pdo, $groupingId)]);
        exit;
    }

    if ($action === 'rename_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if (!grouping_group_belongs_to_instructor($pdo, $groupId, $instructorId)) {
            grouping_ajax_fail('Group not found.', 404);
        }
        rename_grouping_group($pdo, $groupId, (string) ($_POST['name'] ?? ''));
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'set_leader') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if (!grouping_group_belongs_to_instructor($pdo, $groupId, $instructorId)) {
            grouping_ajax_fail('Group not found.', 404);
        }
        set_group_leader($pdo, $groupId, $studentId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'rename_grouping') {
        $groupingId = (int) ($_POST['grouping_id'] ?? 0);
        if (!grouping_belongs_to_instructor($pdo, $groupingId, $instructorId)) {
            grouping_ajax_fail('Grouping not found.', 404);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            grouping_ajax_fail('Grouping name is required.', 400);
        }
        rename_grouping($pdo, $groupingId, $name);
        echo json_encode(['ok' => true]);
        exit;
    }

    grouping_ajax_fail('Unknown action.', 400);
} catch (Throwable $e) {
    error_log('[EDUPREDICT GROUPING AJAX ERROR] ' . $e->getMessage());
    grouping_ajax_fail('Unable to save the change right now.', 500);
}
