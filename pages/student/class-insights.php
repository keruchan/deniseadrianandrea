<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/insights_management.php';
require_once __DIR__ . '/../../includes/insights_render.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('student');

$studentId = current_student_id($pdo);
if ($studentId === null) {
    redirect_to('pages/auth/logout.php');
}

// The engine reads the same tables the instructor page materializes.
ensure_attendance_schema($pdo);
ensure_participation_schema($pdo);
ensure_assessment_schema($pdo);
ensure_grouping_schema($pdo);
ensure_insights_schema($pdo);

// Active enrollments = the only classes this student may view insights for.
$enrollStmt = $pdo->prepare(
    'SELECT c.id, c.class_name, c.section, c.subject_name, c.class_code,
            CONCAT(i.first_name, " ", i.last_name) AS instructor_name
     FROM class_enrollments ce
     INNER JOIN classes c ON c.id = ce.class_id
     LEFT JOIN instructors i ON i.id = c.instructor_id
     WHERE ce.student_id = :sid AND ce.status = "active"
     ORDER BY ce.joined_at DESC'
);
$enrollStmt->execute([':sid' => $studentId]);
$enrollments = $enrollStmt->fetchAll();
$enrollmentIds = array_map(static fn ($c) => (int) $c['id'], $enrollments);

// Save the student's own (permanent) target grade for Goal Analysis.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['insights_action'] ?? '') === 'save_goal') {
    $postClassId = (int) ($_POST['class_id'] ?? 0);
    $rawGoal = trim((string) ($_POST['goal'] ?? ''));
    if (csrf_is_valid('csrf_student_goal_token', (string) ($_POST['csrf_token'] ?? ''))
        && in_array($postClassId, $enrollmentIds, true)
        && is_numeric($rawGoal)
    ) {
        $goal = max(1.0, min(100.0, (float) $rawGoal));
        save_student_goal($pdo, $postClassId, $studentId, $goal);
        rotate_csrf_token('csrf_student_goal_token');
        $_SESSION['insights_goal_saved'] = 'Your target grade was saved.';
    }
    redirect_to('pages/student/class-insights.php?class_id=' . $postClassId . '&tab=goal');
}

$requestedClassId = (int) ($_GET['class_id'] ?? 0);
$tab = (string) ($_GET['tab'] ?? 'grades');

// Resolve which class to show (0 / 1 / 2+ enrollment paths + ownership check).
$selectedClass = null;
if ($requestedClassId > 0 && in_array($requestedClassId, $enrollmentIds, true)) {
    foreach ($enrollments as $c) {
        if ((int) $c['id'] === $requestedClassId) {
            $selectedClass = $c;
            break;
        }
    }
} elseif ($requestedClassId === 0 && count($enrollments) === 1) {
    $selectedClass = $enrollments[0];
}

$resolvedClassId = $selectedClass !== null ? (int) $selectedClass['id'] : 0;

// Sidebar: shared student menu, with this tab highlighted and class_id carried through.
$tabToRoute = [
    'grades' => 'student.grades', 'overview' => 'student.grades',
    'attendance' => 'student.attendance', 'participation' => 'student.progress',
    'goal' => 'student.goal', 'predictions' => 'student.predictions',
];
$activeRoute = $tabToRoute[$tab] ?? 'student.grades';
$menu = student_sidebar_menu($resolvedClassId > 0 ? $resolvedClassId : null);

$ownershipRejected = $requestedClassId > 0 && $selectedClass === null;
$goalSavedFlash = $_SESSION['insights_goal_saved'] ?? null;
unset($_SESSION['insights_goal_saved']);

render_dashboard_page([
    'role_label' => 'Student',
    'fallback_name' => 'Student',
    'title' => 'My Insights',
    'eyebrow' => 'Insights',
    'description' => 'Your grades, attendance, predictions, and goal analysis for a class.',
    'active_route' => $activeRoute,
    'menu' => $menu,
    'content' => function () use ($pdo, $enrollments, $selectedClass, $studentId, $tab, $ownershipRejected, $goalSavedFlash) {
        if ($goalSavedFlash) {
            echo '<div class="alert alert-success mt-4" role="alert">' . e($goalSavedFlash) . '</div>';
        }
        if ($ownershipRejected) {
            echo '<div class="alert alert-danger mt-4" role="alert">You are not enrolled in that class, or it is no longer available.</div>';
        }

        if ($selectedClass !== null) {
            ?>
            <div class="insights-student-header mt-4">
                <div>
                    <div class="eyebrow">Class</div>
                    <h2><?php echo e($selectedClass['class_name']); ?><?php echo !empty($selectedClass['section']) ? ' ' . e($selectedClass['section']) : ''; ?></h2>
                    <p class="insights-sub"><?php echo e($selectedClass['subject_name']); ?> &middot; <?php echo e($selectedClass['instructor_name'] ?: 'Instructor'); ?></p>
                </div>
                <?php if (count($enrollments) > 1): ?>
                    <a class="btn btn-copy" href="class-insights.php"><i class="bi bi-arrow-left-right"></i> Switch class</a>
                <?php endif; ?>
            </div>
            <?php
            render_student_class_insights_page($pdo, $selectedClass, $studentId, $tab, true, 'student');
            return;
        }

        // No class resolved: empty state (0 enrollments) or picker (2+).
        if (empty($enrollments)) {
            ?>
            <div class="empty-state large mt-4">
                <i class="bi bi-easel2"></i>
                <span>You haven't joined any classes yet. Join a class first, then your insights will appear here.</span>
                <a class="btn btn-edupredict mt-3" href="classes.php"><i class="bi bi-door-open"></i> Join a class</a>
            </div>
            <?php
            return;
        }
        ?>
        <section class="class-section mt-4">
            <div class="section-heading">
                <h2>Choose a class</h2>
                <span><?php echo (int) count($enrollments); ?> joined</span>
            </div>
            <div class="class-grid">
                <?php foreach ($enrollments as $c): ?>
                    <a class="class-card class-card-link" href="class-insights.php?class_id=<?php echo (int) $c['id']; ?>&tab=<?php echo e($tab); ?>">
                        <div class="class-card-top">
                            <div>
                                <h3><?php echo e($c['class_name']); ?></h3>
                                <p><?php echo e($c['subject_name']); ?><?php echo !empty($c['section']) ? ' &middot; ' . e($c['section']) : ''; ?></p>
                            </div>
                            <span class="status-pill small">View</span>
                        </div>
                        <div class="class-meta">
                            <span><i class="bi bi-person-workspace"></i><?php echo e($c['instructor_name'] ?: 'Instructor'); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    },
]);
