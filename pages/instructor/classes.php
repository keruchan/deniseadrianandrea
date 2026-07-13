<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

$errors = [];
$successMessage = '';
$formData = [
    'class_name'   => '',
    'section'      => '',
    'subject_name' => '',
    'subject_code' => '',
    'schedule'     => '',
    'school_year'  => '',
    'term'         => '',
    'description'  => '',
];

if (!empty($_SESSION['class_success'])) {
    $successMessage = (string) $_SESSION['class_success'];
    unset($_SESSION['class_success']);
}

$csrfToken = csrf_token('csrf_class_create_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formData as $key => $value) {
        $formData[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!csrf_is_valid('csrf_class_create_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($formData['class_name'] === '') {
        $errors[] = 'Class name is required.';
    } elseif (text_length($formData['class_name']) > 150) {
        $errors[] = 'Class name must not exceed 150 characters.';
    }

    if ($formData['subject_name'] === '') {
        $errors[] = 'Subject name is required.';
    } elseif (text_length($formData['subject_name']) > 150) {
        $errors[] = 'Subject name must not exceed 150 characters.';
    }

    if ($formData['section'] !== '' && text_length($formData['section']) > 100) {
        $errors[] = 'Section must not exceed 100 characters.';
    }

    if ($formData['subject_code'] !== '' && text_length($formData['subject_code']) > 50) {
        $errors[] = 'Subject code must not exceed 50 characters.';
    }

    if ($formData['schedule'] !== '' && text_length($formData['schedule']) > 150) {
        $errors[] = 'Schedule must not exceed 150 characters.';
    }

    if ($formData['school_year'] !== '' && text_length($formData['school_year']) > 20) {
        $errors[] = 'School year must not exceed 20 characters.';
    }

    if ($formData['term'] !== '' && text_length($formData['term']) > 50) {
        $errors[] = 'Term must not exceed 50 characters.';
    }

    if ($formData['description'] !== '' && text_length($formData['description']) > 1000) {
        $errors[] = 'Description must not exceed 1000 characters.';
    }

    if (empty($errors)) {
        try {
            $classCode = generate_class_code($pdo);
            $insertStmt = $pdo->prepare(
                'INSERT INTO classes
                    (instructor_id, class_code, class_name, section, subject_code, subject_name, schedule, description, school_year, term, status)
                 VALUES
                    (:instructor_id, :class_code, :class_name, :section, :subject_code, :subject_name, :schedule, :description, :school_year, :term, :status)'
            );
            $insertStmt->execute([
                ':instructor_id' => $instructorId,
                ':class_code'    => $classCode,
                ':class_name'    => $formData['class_name'],
                ':section'       => $formData['section'] !== '' ? $formData['section'] : null,
                ':subject_code'  => $formData['subject_code'] !== '' ? $formData['subject_code'] : null,
                ':subject_name'  => $formData['subject_name'],
                ':schedule'      => $formData['schedule'] !== '' ? $formData['schedule'] : null,
                ':description'   => $formData['description'] !== '' ? $formData['description'] : null,
                ':school_year'   => $formData['school_year'] !== '' ? $formData['school_year'] : null,
                ':term'          => $formData['term'] !== '' ? $formData['term'] : null,
                ':status'        => 'active',
            ]);

            rotate_csrf_token('csrf_class_create_token');
            $_SESSION['class_success'] = 'Class created successfully. Students can now join using code ' . $classCode . '.';

            redirect_to('pages/instructor/classes.php');
        } catch (PDOException $e) {
            error_log('[EDUPREDICT CLASS CREATE ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to create the class at this time. Please try again later.';
        }
    }

    $csrfToken = csrf_token('csrf_class_create_token');
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
        (
            SELECT COUNT(*)
            FROM class_enrollments ce
            WHERE ce.class_id = c.id AND ce.status = "active"
        ) AS student_count
     FROM classes c
     WHERE c.instructor_id = :instructor_id
     ORDER BY c.created_at DESC'
);
$classesStmt->execute([':instructor_id' => $instructorId]);
$classes = $classesStmt->fetchAll();

$origin = 'http';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $origin = 'https';
}
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$baseJoinUrl = $origin . '://' . $host . url_for('pages/student/classes.php?code=');

$menu = [
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
];

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Classes',
    'eyebrow' => 'Classroom setup',
    'description' => 'Create classroom spaces and share a join code or invite link with students.',
    'active' => 'Classes',
    'menu' => $menu,
    'content' => function () use ($errors, $successMessage, $formData, $csrfToken, $classes, $baseJoinUrl) {
        ?>
        <section class="content-grid two-columns">
            <article class="form-panel">
                <div class="section-heading">
                    <h2>Create Class</h2>
                    <span>Instructor</span>
                </div>

                <?php if ($successMessage !== ''): ?>
                    <div class="alert alert-success" role="alert"><?php echo e($successMessage); ?></div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <p class="fw-semibold mb-2">Please fix the following:</p>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="classes.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

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

                    <button type="submit" class="btn btn-edupredict mt-3"><i class="bi bi-plus-circle"></i> Create class</button>
                </form>
            </article>

            <article class="info-panel">
                <div class="section-heading">
                    <h2>Join Process</h2>
                    <span>Code or link</span>
                </div>
                <div class="steps-list">
                    <div><strong>1</strong><span>Create a class with subject, section, and schedule.</span></div>
                    <div><strong>2</strong><span>Copy the generated class code or invite link.</span></div>
                    <div><strong>3</strong><span>Students open My Classes and join using the code or shared link.</span></div>
                </div>
            </article>
        </section>

        <section class="class-section mt-4">
            <div class="section-heading">
                <h2>My Classes</h2>
                <span><?php echo count($classes); ?> created</span>
            </div>

            <?php if (empty($classes)): ?>
                <div class="empty-state large">
                    <i class="bi bi-easel2"></i>
                    <span>No classes yet. Create your first class to generate a join code.</span>
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
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    },
]);
