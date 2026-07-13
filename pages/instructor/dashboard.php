<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

$classCountStmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE instructor_id = :instructor_id AND status = "active"');
$classCountStmt->execute([':instructor_id' => $instructorId]);
$classCount = (int) $classCountStmt->fetchColumn();

$studentCountStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT ce.student_id)
     FROM class_enrollments ce
     INNER JOIN classes c ON c.id = ce.class_id
     WHERE c.instructor_id = :instructor_id AND ce.status = "active"'
);
$studentCountStmt->execute([':instructor_id' => $instructorId]);
$studentCount = (int) $studentCountStmt->fetchColumn();

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Instructor Dashboard',
    'eyebrow' => 'Classroom performance workspace',
    'description' => 'Prepare classes, monitor learner progress, and reserve space for grading, attendance, analytics, and prediction workflows.',
    'active' => 'Dashboard',
    'menu' => [
        ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2-fill', 'href' => 'dashboard.php'],
        ['label' => 'Classes', 'icon' => 'bi-easel2', 'href' => 'classes.php'],
        ['label' => 'Attendance', 'icon' => 'bi-calendar-check', 'href' => '#'],
        ['label' => 'Activities', 'icon' => 'bi-journal-check', 'href' => '#'],
        ['label' => 'Quizzes', 'icon' => 'bi-patch-question', 'href' => '#'],
        ['label' => 'Midterm', 'icon' => 'bi-clipboard-data', 'href' => '#'],
        ['label' => 'Finals', 'icon' => 'bi-clipboard2-check', 'href' => '#'],
        ['label' => 'Participation', 'icon' => 'bi-chat-square-text', 'href' => '#'],
        ['label' => 'Group Activities', 'icon' => 'bi-diagram-3', 'href' => '#'],
        ['label' => 'Analytics', 'icon' => 'bi-graph-up', 'href' => '#'],
        ['label' => 'Predictions', 'icon' => 'bi-stars', 'href' => '#'],
        ['label' => 'Settings', 'icon' => 'bi-gear', 'href' => '#'],
    ],
    'cards' => [
        ['label' => 'My classes', 'value' => (string) $classCount, 'note' => 'Active classes created', 'icon' => 'bi-easel2', 'tone' => 'tone-blue'],
        ['label' => 'Students', 'value' => (string) $studentCount, 'note' => 'Learners enrolled across classes', 'icon' => 'bi-mortarboard', 'tone' => 'tone-emerald'],
        ['label' => 'Grade items', 'value' => '0', 'note' => 'Activities and quizzes planned', 'icon' => 'bi-journal-text', 'tone' => 'tone-indigo'],
        ['label' => 'Prediction watchlist', 'value' => '0', 'note' => 'Future academic risk signals', 'icon' => 'bi-stars', 'tone' => 'tone-amber'],
    ],
    'widgets' => [
        ['title' => 'Class Progress', 'tag' => 'Future', 'body' => 'Reserved for progress charts, grade completion, and class-level learning signals.', 'icon' => 'bi-speedometer2', 'empty' => 'Progress charts will be added later.'],
        ['title' => 'Grade Computation', 'tag' => 'Future', 'body' => 'A prepared area for activity, quiz, midterm, finals, participation, and group activity grade components.', 'icon' => 'bi-calculator', 'empty' => 'Grade computation is not implemented yet.'],
        ['title' => 'Prediction Insights', 'tag' => 'Future', 'body' => 'A placeholder for academic prediction outputs once performance data exists.', 'icon' => 'bi-lightning-charge', 'empty' => 'Prediction engine placeholder.'],
    ],
]);
