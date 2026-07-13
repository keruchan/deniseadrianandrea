<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/class_management.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

$errors = [];
$successMessage = '';

if (!empty($_SESSION['archived_class_success'])) {
    $successMessage = (string) $_SESSION['archived_class_success'];
    unset($_SESSION['archived_class_success']);
}

$csrfToken = csrf_token('csrf_archived_class_manage_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['class_action'] ?? '');
    $classId = (int) ($_POST['class_id'] ?? 0);
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!csrf_is_valid('csrf_archived_class_manage_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($classId <= 0) {
        $errors[] = 'Class selection is invalid.';
    }

    if (empty($errors)) {
        try {
            if ($action === 'restore') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'archived')) {
                    $errors[] = 'Archived class not found.';
                } else {
                    update_instructor_class_status($pdo, $instructorId, $classId, 'active');
                    rotate_csrf_token('csrf_archived_class_manage_token');
                    $_SESSION['archived_class_success'] = 'Class restored successfully.';

                    redirect_to('pages/instructor/archived-classes.php');
                }
            } elseif ($action === 'delete') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'archived')) {
                    $errors[] = 'Archived class not found.';
                } else {
                    delete_instructor_class($pdo, $instructorId, $classId);
                    rotate_csrf_token('csrf_archived_class_manage_token');
                    $_SESSION['archived_class_success'] = 'Class deleted permanently.';

                    redirect_to('pages/instructor/archived-classes.php');
                }
            } else {
                $errors[] = 'Class action is invalid.';
            }
        } catch (PDOException $e) {
            error_log('[EDUPREDICT ARCHIVED CLASS ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to update the archived class at this time. Please try again later.';
        }
    }

    $csrfToken = csrf_token('csrf_archived_class_manage_token');
}

$classesStmt = $pdo->prepare(
    'SELECT
        c.id,
        c.class_code,
        c.class_name,
        c.section,
        c.subject_code,
        c.subject_name,
        c.schedule,
        c.school_year,
        c.term,
        c.status,
        c.created_at,
        c.updated_at,
        (
            SELECT COUNT(*)
            FROM class_enrollments ce
            WHERE ce.class_id = c.id AND ce.status = "active"
        ) AS student_count
     FROM classes c
     WHERE c.instructor_id = :instructor_id AND c.status = "archived"
     ORDER BY COALESCE(c.updated_at, c.created_at) DESC'
);
$classesStmt->execute([':instructor_id' => $instructorId]);
$archivedClasses = $classesStmt->fetchAll();

$sidebarClasses = instructor_sidebar_classes($pdo, $instructorId);

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Archived Classes',
    'eyebrow' => 'Class archive',
    'description' => 'Review archived classes, restore them to active use, or permanently delete records you no longer need.',
    'active_route' => 'classes.archived',
    'menu' => instructor_sidebar_menu($sidebarClasses),
    'content' => function () use ($archivedClasses, $errors, $successMessage, $csrfToken) {
        ?>
        <section class="class-section">
            <div class="section-heading">
                <h2>Archived Classes</h2>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span><?php echo count($archivedClasses); ?> archived</span>
                    <a class="btn btn-copy" href="<?php echo e(url_for('classes')); ?>">
                        <i class="bi bi-arrow-left"></i> Active classes
                    </a>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($archivedClasses)): ?>
                <div class="empty-state large">
                    <i class="bi bi-archive"></i>
                    <span>No archived classes yet.</span>
                </div>
            <?php else: ?>
                <div class="class-grid">
                    <?php foreach ($archivedClasses as $class): ?>
                        <?php
                        $archivedDate = !empty($class['updated_at'])
                            ? date('M j, Y', strtotime((string) $class['updated_at']))
                            : date('M j, Y', strtotime((string) $class['created_at']));
                        ?>
                        <article class="class-card">
                            <div class="class-card-top">
                                <div>
                                    <h3><?php echo e($class['class_name']); ?></h3>
                                    <p><?php echo e($class['subject_name']); ?><?php echo !empty($class['section']) ? ' &middot; ' . e($class['section']) : ''; ?></p>
                                </div>
                                <span class="status-pill small"><?php echo e($class['status']); ?></span>
                            </div>
                            <div class="class-meta">
                                <span><i class="bi bi-calendar2-week"></i><?php echo e($class['schedule'] ?: 'No schedule set'); ?></span>
                                <span><i class="bi bi-people"></i><?php echo e((string) $class['student_count']); ?> students</span>
                                <span><i class="bi bi-archive"></i>Archived <?php echo e($archivedDate); ?></span>
                            </div>
                            <div class="join-box">
                                <div>
                                    <span class="join-label">Class code</span>
                                    <strong><?php echo e($class['class_code']); ?></strong>
                                </div>
                            </div>
                            <div class="class-card-actions">
                                <form method="post" action="<?php echo e(url_for('pages/instructor/archived-classes.php')); ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="class_action" value="restore">
                                    <input type="hidden" name="class_id" value="<?php echo e((string) $class['id']); ?>">
                                    <button class="btn btn-edupredict" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                </form>
                                <form method="post" action="<?php echo e(url_for('pages/instructor/archived-classes.php')); ?>" data-confirm-action="Delete this archived class permanently? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="class_action" value="delete">
                                    <input type="hidden" name="class_id" value="<?php echo e((string) $class['id']); ?>">
                                    <button class="btn btn-copy btn-danger-soft" type="submit"><i class="bi bi-trash"></i> Delete</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    },
]);
