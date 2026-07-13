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
$formData = class_form_defaults();
$autoOpenModal = '';
$editTargetClassId = 0;
$postedAction = '';

if (!empty($_SESSION['class_success'])) {
    $successMessage = (string) $_SESSION['class_success'];
    unset($_SESSION['class_success']);
}

$csrfToken = csrf_token('csrf_class_manage_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedAction = (string) ($_POST['class_action'] ?? 'create');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $classId = (int) ($_POST['class_id'] ?? 0);

    if (!csrf_is_valid('csrf_class_manage_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($postedAction === 'create' || $postedAction === 'edit') {
        $formData = class_form_data_from_array($_POST);
        $errors = array_merge($errors, validate_class_form_data($formData));
    }

    if (in_array($postedAction, ['edit', 'archive', 'delete'], true) && $classId <= 0) {
        $errors[] = 'Class selection is invalid.';
    }

    if (empty($errors)) {
        try {
            if ($postedAction === 'create') {
                $classCode = generate_class_code($pdo);
                $insertStmt = $pdo->prepare(
                    'INSERT INTO classes
                        (instructor_id, class_code, class_name, section, subject_code, subject_name, schedule, description, school_year, term, status)
                     VALUES
                        (:instructor_id, :class_code, :class_name, :section, :subject_code, :subject_name, :schedule, :description, :school_year, :term, :status)'
                );
                $insertStmt->execute([
                    ':instructor_id' => $instructorId,
                    ':class_code' => $classCode,
                    ':class_name' => $formData['class_name'],
                    ':section' => $formData['section'] !== '' ? $formData['section'] : null,
                    ':subject_code' => $formData['subject_code'] !== '' ? $formData['subject_code'] : null,
                    ':subject_name' => $formData['subject_name'],
                    ':schedule' => $formData['schedule'] !== '' ? $formData['schedule'] : null,
                    ':description' => $formData['description'] !== '' ? $formData['description'] : null,
                    ':school_year' => $formData['school_year'] !== '' ? $formData['school_year'] : null,
                    ':term' => $formData['term'] !== '' ? $formData['term'] : null,
                    ':status' => 'active',
                ]);

                rotate_csrf_token('csrf_class_manage_token');
                $_SESSION['class_success'] = 'Class created successfully. Students can now join using code ' . $classCode . '.';

                redirect_to('pages/instructor/classes.php');
            } elseif ($postedAction === 'edit') {
                $editTargetClassId = $classId;

                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE classes
                         SET class_name = :class_name,
                             section = :section,
                             subject_code = :subject_code,
                             subject_name = :subject_name,
                             schedule = :schedule,
                             description = :description,
                             school_year = :school_year,
                             term = :term
                         WHERE id = :class_id AND instructor_id = :instructor_id AND status = "active"'
                    );
                    $updateStmt->execute([
                        ':class_name' => $formData['class_name'],
                        ':section' => $formData['section'] !== '' ? $formData['section'] : null,
                        ':subject_code' => $formData['subject_code'] !== '' ? $formData['subject_code'] : null,
                        ':subject_name' => $formData['subject_name'],
                        ':schedule' => $formData['schedule'] !== '' ? $formData['schedule'] : null,
                        ':description' => $formData['description'] !== '' ? $formData['description'] : null,
                        ':school_year' => $formData['school_year'] !== '' ? $formData['school_year'] : null,
                        ':term' => $formData['term'] !== '' ? $formData['term'] : null,
                        ':class_id' => $classId,
                        ':instructor_id' => $instructorId,
                    ]);

                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Class updated successfully.';

                    redirect_to('pages/instructor/classes.php');
                }
            } elseif ($postedAction === 'archive') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    update_instructor_class_status($pdo, $instructorId, $classId, 'archived');
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Class archived successfully.';

                    redirect_to('pages/instructor/classes.php');
                }
            } elseif ($postedAction === 'delete') {
                if (!instructor_class_exists($pdo, $instructorId, $classId)) {
                    $errors[] = 'Class not found.';
                } else {
                    delete_instructor_class($pdo, $instructorId, $classId);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Class deleted successfully.';

                    redirect_to('pages/instructor/classes.php');
                }
            } else {
                $errors[] = 'Class action is invalid.';
            }
        } catch (PDOException $e) {
            error_log('[EDUPREDICT CLASS MANAGEMENT ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to update the class at this time. Please try again later.';
        }
    }

    if (!empty($errors)) {
        if ($postedAction === 'create') {
            $autoOpenModal = '#createClassModal';
        } elseif ($postedAction === 'edit' && $classId > 0 && instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
            $editTargetClassId = $classId;
            $autoOpenModal = '#editClassModal-' . $classId;
        }
    }

    $csrfToken = csrf_token('csrf_class_manage_token');
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$baseClassSelect = 'SELECT
        c.id,
        c.class_code,
        c.class_name,
        c.section,
        c.subject_code,
        c.subject_name,
        c.schedule,
        c.school_year,
        c.term,
        c.description,
        c.status,
        c.created_at,
        (
            SELECT COUNT(*)
            FROM class_enrollments ce
            WHERE ce.class_id = c.id AND ce.status = "active"
        ) AS student_count
     FROM classes c
     WHERE c.instructor_id = :instructor_id AND c.status = "active"';

$allClassesStmt = $pdo->prepare($baseClassSelect . ' ORDER BY c.created_at DESC');
$allClassesStmt->execute([':instructor_id' => $instructorId]);
$allClasses = $allClassesStmt->fetchAll();

$classes = $allClasses;

if ($searchTerm !== '') {
    $classesStmt = $pdo->prepare(
        $baseClassSelect . '
         AND (
            c.class_name LIKE :search
            OR c.section LIKE :search
            OR c.subject_name LIKE :search
            OR c.subject_code LIKE :search
            OR c.class_code LIKE :search
         )
         ORDER BY c.created_at DESC'
    );
    $classesStmt->execute([
        ':instructor_id' => $instructorId,
        ':search' => '%' . $searchTerm . '%',
    ]);
    $classes = $classesStmt->fetchAll();
}

$origin = 'http';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $origin = 'https';
}
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$baseJoinUrl = $origin . '://' . $host . url_for('pages/student/classes.php?code=');

$requestedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;
$classView = instructor_normalize_class_view((string) ($_GET['view'] ?? 'overview'));
$selectedClass = null;

if ($requestedClassId > 0) {
    foreach ($allClasses as $class) {
        if ((int) $class['id'] === $requestedClassId) {
            $selectedClass = $class;
            break;
        }
    }

    if ($selectedClass === null) {
        redirect_to('pages/instructor/classes.php');
    }
}

$selectedClassId = $selectedClass !== null ? (int) $selectedClass['id'] : null;
$classViewMeta = instructor_class_view_meta($classView);
$activeRoute = $selectedClass !== null ? (string) $classViewMeta['route'] : 'classes';
$pageTitle = 'Classes';
$pageEyebrow = 'Classroom setup';
$pageDescription = 'Create classroom spaces and share a join code or invite link with students.';

if ($selectedClass !== null) {
    $classLabel = instructor_class_label($selectedClass);
    $pageTitle = $classView === 'overview' ? $classLabel : (string) $classViewMeta['label'] . ' - ' . $classLabel;
    $pageEyebrow = 'Class workspace';
    $pageDescription = (string) $classViewMeta['description'];
}

$menu = instructor_sidebar_menu($allClasses, $selectedClassId);

function render_instructor_class_workspace(array $class, string $classView, array $viewMeta, string $baseJoinUrl): void
{
    $classId = (int) $class['id'];
    $joinLink = $baseJoinUrl . urlencode((string) $class['class_code']);
    $classLabel = instructor_class_label($class);
    $createdAt = !empty($class['created_at']) ? date('M j, Y', strtotime((string) $class['created_at'])) : 'Not available';
    ?>
    <section class="class-section">
        <div class="class-context-header">
            <div>
                <div class="eyebrow">Selected class</div>
                <h2><?php echo e($classLabel); ?></h2>
                <p><?php echo e($class['subject_name']); ?><?php echo !empty($class['subject_code']) ? ' (' . e($class['subject_code']) . ')' : ''; ?></p>
            </div>
            <a class="btn btn-copy" href="<?php echo e(url_for('classes')); ?>">
                <i class="bi bi-arrow-left"></i> All classes
            </a>
        </div>

        <div class="class-context-meta">
            <span><i class="bi bi-calendar2-week"></i><?php echo e($class['schedule'] ?: 'No schedule set'); ?></span>
            <span><i class="bi bi-people"></i><?php echo e((string) $class['student_count']); ?> students</span>
            <span><i class="bi bi-clock-history"></i>Created <?php echo e($createdAt); ?></span>
            <span><i class="bi bi-circle-fill"></i><?php echo e($class['status']); ?></span>
        </div>

        <?php if ($classView === 'overview'): ?>
            <div class="metric-grid class-overview-grid mt-4">
                <article class="metric-card tone-indigo">
                    <div class="metric-icon"><i class="bi bi-door-open"></i></div>
                    <div>
                        <div class="metric-label">Class code</div>
                        <div class="metric-value compact"><?php echo e($class['class_code']); ?></div>
                        <div class="metric-note">Students use this to join.</div>
                    </div>
                </article>
                <article class="metric-card tone-emerald">
                    <div class="metric-icon"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="metric-label">Students</div>
                        <div class="metric-value"><?php echo e((string) $class['student_count']); ?></div>
                        <div class="metric-note">Active enrollments.</div>
                    </div>
                </article>
                <article class="metric-card tone-amber">
                    <div class="metric-icon"><i class="bi bi-calendar-event"></i></div>
                    <div>
                        <div class="metric-label">Term</div>
                        <div class="metric-value compact"><?php echo e($class['term'] ?: 'Not set'); ?></div>
                        <div class="metric-note"><?php echo e($class['school_year'] ?: 'No school year set'); ?></div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-easel2"></i></div>
                    <div>
                        <div class="metric-label">Section</div>
                        <div class="metric-value compact"><?php echo e($class['section'] ?: 'Not set'); ?></div>
                        <div class="metric-note"><?php echo e($class['subject_name']); ?></div>
                    </div>
                </article>
            </div>

            <section class="content-grid two-columns mt-4">
                <article class="widget-panel">
                    <div class="section-heading">
                        <h2>Quick Actions</h2>
                        <span>Class</span>
                    </div>
                    <div class="class-action-grid">
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'attendance')); ?>"><i class="bi bi-calendar-check"></i> Attendance</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'participation')); ?>"><i class="bi bi-chat-square-text"></i> Participation</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'activities')); ?>"><i class="bi bi-journal-check"></i> Activities</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'quizzes')); ?>"><i class="bi bi-patch-question"></i> Quizzes</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'midterm')); ?>"><i class="bi bi-clipboard-data"></i> Midterm</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'finals')); ?>"><i class="bi bi-clipboard2-check"></i> Finals</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'analytics')); ?>"><i class="bi bi-graph-up"></i> Analytics</a>
                        <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'predictions')); ?>"><i class="bi bi-stars"></i> Predictions</a>
                    </div>
                </article>

                <article class="widget-panel">
                    <div class="section-heading">
                        <h2>Invite Link</h2>
                        <span>Share</span>
                    </div>
                    <p>Share this link with students who need to join the class.</p>
                    <div class="invite-row mt-auto">
                        <input type="text" class="form-control" value="<?php echo e($joinLink); ?>" readonly>
                        <button class="btn btn-copy" type="button" data-copy="<?php echo e($joinLink); ?>">
                            <i class="bi bi-link-45deg"></i> Copy link
                        </button>
                    </div>
                </article>
            </section>
        <?php else: ?>
            <article class="widget-panel class-workspace-panel mt-4">
                <div class="section-heading">
                    <h2><?php echo e((string) $viewMeta['label']); ?></h2>
                    <span>Class module</span>
                </div>
                <p><?php echo e((string) $viewMeta['description']); ?></p>
                <div class="empty-state large">
                    <i class="bi <?php echo e((string) $viewMeta['icon']); ?>"></i>
                    <span><?php echo e((string) $viewMeta['label']); ?> workspace for <?php echo e($classLabel); ?> is ready for its module content.</span>
                </div>
            </article>
        <?php endif; ?>
    </section>
    <?php
}

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => $pageTitle,
    'eyebrow' => $pageEyebrow,
    'description' => $pageDescription,
    'active_route' => $activeRoute,
    'menu' => $menu,
    'content' => function () use ($errors, $successMessage, $formData, $csrfToken, $classes, $allClasses, $baseJoinUrl, $selectedClass, $classView, $classViewMeta, $searchTerm, $autoOpenModal, $postedAction, $editTargetClassId) {
        ?>
        <?php if ($selectedClass !== null): ?>
            <?php render_instructor_class_workspace($selectedClass, $classView, $classViewMeta, $baseJoinUrl); ?>
            <?php return; ?>
        <?php endif; ?>

        <?php if ($autoOpenModal !== ''): ?>
            <span data-auto-open-modal="<?php echo e($autoOpenModal); ?>" hidden></span>
        <?php endif; ?>

        <section class="class-section">
            <div class="section-heading">
                <h2>My Classes</h2>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span><?php echo count($classes); ?> shown</span>
                    <a class="btn btn-copy" href="<?php echo e(url_for('classes/archived')); ?>">
                        <i class="bi bi-archive"></i> Archived
                    </a>
                    <button class="btn btn-edupredict" type="button" data-bs-toggle="modal" data-bs-target="#createClassModal">
                        <i class="bi bi-plus-circle"></i> Create class
                    </button>
                </div>
            </div>

            <div class="class-toolbar">
                <form class="class-search-form" method="get" action="<?php echo e(url_for('classes')); ?>">
                    <label class="visually-hidden" for="class_search">Search classes</label>
                    <div class="search-input-wrap">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" class="form-control" id="class_search" name="q" value="<?php echo e($searchTerm); ?>" placeholder="Search by class, section, subject, or code">
                    </div>
                    <button class="btn btn-copy" type="submit"><i class="bi bi-search"></i> Search</button>
                    <?php if ($searchTerm !== ''): ?>
                        <a class="btn btn-copy" href="<?php echo e(url_for('classes')); ?>"><i class="bi bi-x-circle"></i> Clear</a>
                    <?php endif; ?>
                </form>
                <span><?php echo count($allClasses); ?> active total</span>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors) && $autoOpenModal === ''): ?>
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($classes)): ?>
                <div class="empty-state large">
                    <i class="bi bi-easel2"></i>
                    <span><?php echo $searchTerm !== '' ? 'No active classes match your search.' : 'No classes yet. Use Create class to generate your first join code.'; ?></span>
                </div>
            <?php else: ?>
                <div class="class-grid">
                    <?php foreach ($classes as $class): ?>
                        <?php $joinLink = $baseJoinUrl . urlencode((string) $class['class_code']); ?>
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
                            </div>
                            <div class="join-box">
                                <div>
                                    <span class="join-label">Class code</span>
                                    <strong><?php echo e($class['class_code']); ?></strong>
                                </div>
                                <button class="btn btn-copy" type="button" data-copy="<?php echo e($class['class_code']); ?>">
                                    <i class="bi bi-copy"></i> Copy code
                                </button>
                            </div>
                            <div class="invite-row">
                                <input type="text" class="form-control" value="<?php echo e($joinLink); ?>" readonly>
                                <button class="btn btn-copy" type="button" data-copy="<?php echo e($joinLink); ?>">
                                    <i class="bi bi-link-45deg"></i> Copy link
                                </button>
                            </div>
                            <div class="class-card-actions">
                                <a class="btn btn-copy" href="<?php echo e(instructor_class_route((int) $class['id'])); ?>">
                                    <i class="bi bi-box-arrow-up-right"></i> Open class
                                </a>
                                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#editClassModal-<?php echo e((string) $class['id']); ?>">
                                    <i class="bi bi-pencil-square"></i> Edit
                                </button>
                                <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" data-confirm-action="Archive this class? You can restore it from Archived Classes.">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="class_action" value="archive">
                                    <input type="hidden" name="class_id" value="<?php echo e((string) $class['id']); ?>">
                                    <button class="btn btn-copy" type="submit"><i class="bi bi-archive"></i> Archive</button>
                                </form>
                                <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" data-confirm-action="Delete this class permanently? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                    <input type="hidden" name="class_action" value="delete">
                                    <input type="hidden" name="class_id" value="<?php echo e((string) $class['id']); ?>">
                                    <button class="btn btn-copy btn-danger-soft" type="submit"><i class="bi bi-trash"></i> Delete</button>
                                </form>
                            </div>
                        </article>

                        <?php
                        $classId = (int) $class['id'];
                        $editFormData = [
                            'class_name' => (string) $class['class_name'],
                            'section' => (string) ($class['section'] ?? ''),
                            'subject_name' => (string) $class['subject_name'],
                            'subject_code' => (string) ($class['subject_code'] ?? ''),
                            'schedule' => (string) ($class['schedule'] ?? ''),
                            'school_year' => (string) ($class['school_year'] ?? ''),
                            'term' => (string) ($class['term'] ?? ''),
                            'description' => (string) ($class['description'] ?? ''),
                        ];

                        if ($postedAction === 'edit' && $editTargetClassId === $classId && !empty($errors)) {
                            $editFormData = $formData;
                        }
                        ?>
                        <div class="modal fade" id="editClassModal-<?php echo e((string) $classId); ?>" tabindex="-1" aria-labelledby="editClassModalLabel-<?php echo e((string) $classId); ?>" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h2 class="modal-title h5" id="editClassModalLabel-<?php echo e((string) $classId); ?>">Edit Class</h2>
                                            <p class="mb-0 text-secondary small">Update class details without changing the join code.</p>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>

                                    <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" novalidate>
                                        <div class="modal-body">
                                            <?php if ($postedAction === 'edit' && $editTargetClassId === $classId && !empty($errors)): ?>
                                                <div class="alert alert-danger" role="alert">
                                                    <p class="fw-semibold mb-2">Please fix the following:</p>
                                                    <ul class="mb-0">
                                                        <?php foreach ($errors as $error): ?>
                                                            <li><?php echo e($error); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <input type="hidden" name="class_action" value="edit">
                                            <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">

                                            <div class="form-grid">
                                                <div class="field field-wide">
                                                    <label for="edit_class_name_<?php echo e((string) $classId); ?>" class="form-label">Class name</label>
                                                    <input type="text" class="form-control" id="edit_class_name_<?php echo e((string) $classId); ?>" name="class_name" value="<?php echo e($editFormData['class_name']); ?>" maxlength="150" required>
                                                </div>
                                                <div class="field">
                                                    <label for="edit_subject_name_<?php echo e((string) $classId); ?>" class="form-label">Subject name</label>
                                                    <input type="text" class="form-control" id="edit_subject_name_<?php echo e((string) $classId); ?>" name="subject_name" value="<?php echo e($editFormData['subject_name']); ?>" maxlength="150" required>
                                                </div>
                                                <div class="field">
                                                    <label for="edit_subject_code_<?php echo e((string) $classId); ?>" class="form-label">Subject code</label>
                                                    <input type="text" class="form-control" id="edit_subject_code_<?php echo e((string) $classId); ?>" name="subject_code" value="<?php echo e($editFormData['subject_code']); ?>" maxlength="50">
                                                </div>
                                                <div class="field">
                                                    <label for="edit_section_<?php echo e((string) $classId); ?>" class="form-label">Section</label>
                                                    <input type="text" class="form-control" id="edit_section_<?php echo e((string) $classId); ?>" name="section" value="<?php echo e($editFormData['section']); ?>" maxlength="100">
                                                </div>
                                                <div class="field">
                                                    <label for="edit_schedule_<?php echo e((string) $classId); ?>" class="form-label">Schedule</label>
                                                    <input type="text" class="form-control" id="edit_schedule_<?php echo e((string) $classId); ?>" name="schedule" value="<?php echo e($editFormData['schedule']); ?>" maxlength="150">
                                                </div>
                                                <div class="field">
                                                    <label for="edit_school_year_<?php echo e((string) $classId); ?>" class="form-label">School year</label>
                                                    <input type="text" class="form-control" id="edit_school_year_<?php echo e((string) $classId); ?>" name="school_year" value="<?php echo e($editFormData['school_year']); ?>" maxlength="20">
                                                </div>
                                                <div class="field">
                                                    <label for="edit_term_<?php echo e((string) $classId); ?>" class="form-label">Term</label>
                                                    <input type="text" class="form-control" id="edit_term_<?php echo e((string) $classId); ?>" name="term" value="<?php echo e($editFormData['term']); ?>" maxlength="50">
                                                </div>
                                                <div class="field field-wide">
                                                    <label for="edit_description_<?php echo e((string) $classId); ?>" class="form-label">Description</label>
                                                    <textarea class="form-control" id="edit_description_<?php echo e((string) $classId); ?>" name="description" rows="4" maxlength="1000"><?php echo e($editFormData['description']); ?></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-edupredict"><i class="bi bi-check2-circle"></i> Save changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="modal fade" id="createClassModal" tabindex="-1" aria-labelledby="createClassModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title h5" id="createClassModalLabel">Create Class</h2>
                            <p class="mb-0 text-secondary small">Set up the class details students will use when joining.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <form method="post" action="<?php echo e(url_for('pages/instructor/classes.php')); ?>" novalidate>
                        <div class="modal-body">
                            <?php if ($postedAction === 'create' && !empty($errors)): ?>
                                <div class="alert alert-danger" role="alert">
                                    <p class="fw-semibold mb-2">Please fix the following:</p>
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo e($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="class_action" value="create">

                            <div class="form-grid">
                                <div class="field field-wide">
                                    <label for="class_name" class="form-label">Class name</label>
                                    <input type="text" class="form-control" id="class_name" name="class_name" value="<?php echo e($formData['class_name']); ?>" maxlength="150" placeholder="Example: Grade 11 STEM - Mathematics" required>
                                </div>
                                <div class="field">
                                    <label for="subject_name" class="form-label">Subject name</label>
                                    <input type="text" class="form-control" id="subject_name" name="subject_name" value="<?php echo e($formData['subject_name']); ?>" maxlength="150" placeholder="General Mathematics" required>
                                </div>
                                <div class="field">
                                    <label for="subject_code" class="form-label">Subject code</label>
                                    <input type="text" class="form-control" id="subject_code" name="subject_code" value="<?php echo e($formData['subject_code']); ?>" maxlength="50" placeholder="MATH-101">
                                </div>
                                <div class="field">
                                    <label for="section" class="form-label">Section</label>
                                    <input type="text" class="form-control" id="section" name="section" value="<?php echo e($formData['section']); ?>" maxlength="100" placeholder="STEM 11-A">
                                </div>
                                <div class="field">
                                    <label for="schedule" class="form-label">Schedule</label>
                                    <input type="text" class="form-control" id="schedule" name="schedule" value="<?php echo e($formData['schedule']); ?>" maxlength="150" placeholder="MWF 9:00 AM - 10:00 AM">
                                </div>
                                <div class="field">
                                    <label for="school_year" class="form-label">School year</label>
                                    <input type="text" class="form-control" id="school_year" name="school_year" value="<?php echo e($formData['school_year']); ?>" maxlength="20" placeholder="2026-2027">
                                </div>
                                <div class="field">
                                    <label for="term" class="form-label">Term</label>
                                    <input type="text" class="form-control" id="term" name="term" value="<?php echo e($formData['term']); ?>" maxlength="50" placeholder="First Semester">
                                </div>
                                <div class="field field-wide">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="4" maxlength="1000" placeholder="Optional class notes for students"><?php echo e($formData['description']); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-edupredict"><i class="bi bi-plus-circle"></i> Create class</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    },
]);
