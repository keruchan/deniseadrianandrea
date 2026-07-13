<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('student');

$studentId = current_student_id($pdo);
if ($studentId === null) {
    redirect_to('pages/auth/logout.php');
}

$errors = [];
$successMessage = '';
$joinCode = normalize_class_code((string) ($_GET['code'] ?? ''));

if (!empty($_SESSION['join_success'])) {
    $successMessage = (string) $_SESSION['join_success'];
    unset($_SESSION['join_success']);
}

$csrfToken = csrf_token('csrf_class_join_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $joinCode = normalize_class_code((string) ($_POST['class_code'] ?? ''));
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!csrf_is_valid('csrf_class_join_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($joinCode === '') {
        $errors[] = 'Class code is required.';
    } elseif (!preg_match('/^[A-Z0-9]{5,30}$/', $joinCode)) {
        $errors[] = 'Class code must contain 5-30 letters or numbers.';
    }

    if (empty($errors)) {
        try {
            $classStmt = $pdo->prepare(
                'SELECT id, class_name, subject_name, status
                 FROM classes
                 WHERE class_code = :class_code
                 LIMIT 1'
            );
            $classStmt->execute([':class_code' => $joinCode]);
            $class = $classStmt->fetch();

            if (!$class) {
                $errors[] = 'No class found with that code.';
            } elseif ($class['status'] !== 'active') {
                $errors[] = 'This class is not currently open for joining.';
            } else {
                $existingStmt = $pdo->prepare(
                    'SELECT id, status
                     FROM class_enrollments
                     WHERE class_id = :class_id AND student_id = :student_id
                     LIMIT 1'
                );
                $existingStmt->execute([
                    ':class_id'   => (int) $class['id'],
                    ':student_id' => $studentId,
                ]);
                $existingEnrollment = $existingStmt->fetch();

                if ($existingEnrollment && $existingEnrollment['status'] === 'active') {
                    $errors[] = 'You have already joined this class.';
                } elseif ($existingEnrollment) {
                    $updateStmt = $pdo->prepare(
                        'UPDATE class_enrollments
                         SET status = "active", joined_at = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $updateStmt->execute([':id' => (int) $existingEnrollment['id']]);

                    rotate_csrf_token('csrf_class_join_token');
                    $_SESSION['join_success'] = 'You rejoined ' . $class['class_name'] . '.';
                    redirect_to('pages/student/classes.php');
                } else {
                    $insertStmt = $pdo->prepare(
                        'INSERT INTO class_enrollments (class_id, student_id, status)
                         VALUES (:class_id, :student_id, :status)'
                    );
                    $insertStmt->execute([
                        ':class_id'   => (int) $class['id'],
                        ':student_id' => $studentId,
                        ':status'     => 'active',
                    ]);

                    rotate_csrf_token('csrf_class_join_token');
                    $_SESSION['join_success'] = 'You joined ' . $class['class_name'] . '.';
                    redirect_to('pages/student/classes.php');
                }
            }
        } catch (PDOException $e) {
            error_log('[EDUPREDICT CLASS JOIN ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to join the class at this time. Please try again later.';
        }
    }

    $csrfToken = csrf_token('csrf_class_join_token');
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
        ce.joined_at,
        CONCAT(i.first_name, " ", i.last_name) AS instructor_name
     FROM class_enrollments ce
     INNER JOIN classes c ON c.id = ce.class_id
     LEFT JOIN instructors i ON i.id = c.instructor_id
     WHERE ce.student_id = :student_id AND ce.status = "active"
     ORDER BY ce.joined_at DESC'
);
$classesStmt->execute([':student_id' => $studentId]);
$classes = $classesStmt->fetchAll();

$menu = [
    ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2-fill', 'href' => 'dashboard.php'],
    ['label' => 'My Classes', 'icon' => 'bi-easel2', 'href' => 'classes.php'],
    ['label' => 'Grades', 'icon' => 'bi-clipboard-data', 'href' => '#'],
    ['label' => 'Attendance', 'icon' => 'bi-calendar-check', 'href' => '#'],
    ['label' => 'Progress', 'icon' => 'bi-activity', 'href' => '#'],
    ['label' => 'Target Grade', 'icon' => 'bi-bullseye', 'href' => '#'],
    ['label' => 'Predictions', 'icon' => 'bi-stars', 'href' => '#'],
    ['label' => 'Warnings', 'icon' => 'bi-exclamation-triangle', 'href' => '#'],
    ['label' => 'Settings', 'icon' => 'bi-gear', 'href' => '#'],
];

render_dashboard_page([
    'role_label' => 'Student',
    'fallback_name' => 'Student',
    'title' => 'My Classes',
    'eyebrow' => 'Join classroom',
    'description' => 'Join an instructor class using the shared class code or invite link.',
    'active' => 'My Classes',
    'menu' => $menu,
    'content' => function () use ($errors, $successMessage, $joinCode, $csrfToken, $classes) {
        ?>
        <section class="content-grid two-columns">
            <article class="form-panel">
                <div class="section-heading">
                    <h2>Join a Class</h2>
                    <span>Code or link</span>
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

                <form method="post" action="classes.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                    <label for="class_code" class="form-label">Class code</label>
                    <div class="join-form-row">
                        <input type="text" class="form-control code-input" id="class_code" name="class_code" value="<?php echo e($joinCode); ?>" maxlength="30" placeholder="Enter class code" autocomplete="off" required>
                        <button type="submit" class="btn btn-edupredict"><i class="bi bi-door-open"></i> Join</button>
                    </div>
                    <p class="field-note">Your instructor can share either the code or an invite link. Invite links open this page with the code already filled in.</p>
                </form>
            </article>

            <article class="info-panel">
                <div class="section-heading">
                    <h2>How Joining Works</h2>
                    <span>Student</span>
                </div>
                <div class="steps-list">
                    <div><strong>1</strong><span>Get the class code or invite link from your instructor.</span></div>
                    <div><strong>2</strong><span>Enter the code here, or open the invite link while signed in.</span></div>
                    <div><strong>3</strong><span>The class appears in your My Classes list after joining.</span></div>
                </div>
            </article>
        </section>

        <section class="class-section mt-4">
            <div class="section-heading">
                <h2>Joined Classes</h2>
                <span><?php echo count($classes); ?> active</span>
            </div>

            <?php if (empty($classes)): ?>
                <div class="empty-state large">
                    <i class="bi bi-easel2"></i>
                    <span>No joined classes yet. Enter a class code to join your first class.</span>
                </div>
            <?php else: ?>
                <div class="class-grid">
                    <?php foreach ($classes as $class): ?>
                        <article class="class-card">
                            <div class="class-card-top">
                                <div>
                                    <h3><?php echo e($class['class_name']); ?></h3>
                                    <p><?php echo e($class['subject_name']); ?><?php echo !empty($class['section']) ? ' &middot; ' . e($class['section']) : ''; ?></p>
                                </div>
                                <span class="status-pill small">Joined</span>
                            </div>
                            <div class="class-meta">
                                <span><i class="bi bi-person-workspace"></i><?php echo e($class['instructor_name'] ?: 'Instructor'); ?></span>
                                <span><i class="bi bi-calendar2-week"></i><?php echo e($class['schedule'] ?: 'No schedule set'); ?></span>
                            </div>
                            <div class="join-box compact">
                                <div>
                                    <span class="join-label">Class code</span>
                                    <strong><?php echo e($class['class_code']); ?></strong>
                                </div>
                                <span class="joined-date">Joined <?php echo e(date('M j, Y', strtotime((string) $class['joined_at']))); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    },
]);
