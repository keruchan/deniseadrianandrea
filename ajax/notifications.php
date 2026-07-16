<?php
/**
 * AJAX endpoint for the topbar notification bell (all roles).
 * Actions: list (paginated), unread_count, mark_read, mark_all_read, delete.
 * Reads require a logged-in session; mutations also require the CSRF token.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notification_management.php';

header('Content-Type: application/json; charset=utf-8');

function notif_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    notif_fail('Method not allowed.', 405);
}

$userId = (int) ($_SESSION['id'] ?? 0);
if ($userId <= 0) {
    notif_fail('Not authorized.', 401);
}

ensure_notifications_schema($pdo);

$action = (string) ($_POST['action'] ?? '');
$mutating = in_array($action, ['mark_read', 'mark_all_read', 'delete'], true);

if ($mutating && !csrf_is_valid('csrf_notifications_token', (string) ($_POST['csrf_token'] ?? ''))) {
    notif_fail('Security validation failed. Refresh the page and try again.', 403);
}

try {
    if ($action === 'list') {
        $limit = (int) ($_POST['limit'] ?? 10);
        $beforeId = (int) ($_POST['before_id'] ?? 0);
        $rows = get_notifications($pdo, $userId, $limit, $beforeId);

        $limit = max(1, min(50, $limit));
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        echo json_encode([
            'ok' => true,
            'items' => array_map('notification_payload', $rows),
            'has_more' => $hasMore,
            'unread_count' => count_unread_notifications($pdo, $userId),
        ]);
        exit;
    }

    if ($action === 'unread_count') {
        echo json_encode(['ok' => true, 'unread_count' => count_unread_notifications($pdo, $userId)]);
        exit;
    }

    if ($action === 'mark_read') {
        mark_notification_read($pdo, $userId, (int) ($_POST['id'] ?? 0));
        echo json_encode(['ok' => true, 'unread_count' => count_unread_notifications($pdo, $userId)]);
        exit;
    }

    if ($action === 'mark_all_read') {
        mark_all_notifications_read($pdo, $userId);
        echo json_encode(['ok' => true, 'unread_count' => 0]);
        exit;
    }

    if ($action === 'delete') {
        delete_notification($pdo, $userId, (int) ($_POST['id'] ?? 0));
        echo json_encode(['ok' => true, 'unread_count' => count_unread_notifications($pdo, $userId)]);
        exit;
    }

    notif_fail('Unknown action.', 400);
} catch (Throwable $e) {
    error_log('[EDUPREDICT NOTIFY AJAX ERROR] ' . $e->getMessage());
    notif_fail('Unable to process the request right now.', 500);
}
