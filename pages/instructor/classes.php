<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/class_management.php';
require_once __DIR__ . '/../../includes/attendance_management.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

ensure_attendance_schema($pdo);

$errors = [];
$successMessage = '';
$formData = class_form_defaults();
$scheduleData = attendance_schedule_defaults();
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
    $meetingId = (int) ($_POST['meeting_id'] ?? 0);
    $meetingData = [];

    if (!csrf_is_valid('csrf_class_manage_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($postedAction === 'create' || $postedAction === 'edit') {
        $formData = class_form_data_from_array($_POST);
        $errors = array_merge($errors, validate_class_form_data($formData));
        $scheduleData = attendance_schedule_data_from_array($_POST);
        $errors = array_merge($errors, validate_attendance_schedule_data($scheduleData));
    }

    if (in_array($postedAction, ['edit', 'archive', 'delete', 'meeting_add', 'meeting_save'], true) && $classId <= 0) {
        $errors[] = 'Class selection is invalid.';
    }

    if (in_array($postedAction, ['meeting_add', 'meeting_save'], true)) {
        $meetingData = [
            'meeting_date' => trim((string) ($_POST['meeting_date'] ?? '')),
            'meeting_type' => trim((string) ($_POST['meeting_type'] ?? '')),
            'status' => (string) ($_POST['meeting_status'] ?? 'regular'),
            'topic' => trim((string) ($_POST['topic'] ?? '')),
        ];

        if ($meetingData['meeting_date'] === '' || strtotime($meetingData['meeting_date']) === false) {
            $errors[] = 'Meeting date is required.';
        }

        if ($meetingData['meeting_type'] === '') {
            $errors[] = 'Meeting type is required.';
        }

        if (!isset(attendance_statuses()[$meetingData['status']])) {
            $errors[] = 'Meeting status is invalid.';
        }
    }

    if ($postedAction === 'meeting_save' && $meetingId <= 0) {
        $errors[] = 'Meeting selection is invalid.';
    }

    if ($postedAction === 'attendance_save' && $meetingId <= 0) {
        $errors[] = 'Meeting selection is invalid.';
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
                $newClassId = (int) $pdo->lastInsertId();
                save_class_teaching_schedule($pdo, $newClassId, $scheduleData);
                regenerate_class_meetings($pdo, $newClassId);

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
                    save_class_teaching_schedule($pdo, $classId, $scheduleData);
                    regenerate_class_meetings($pdo, $classId);

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
            } elseif ($postedAction === 'meeting_add') {
                if (!instructor_class_exists($pdo, $instructorId, $classId, 'active')) {
                    $errors[] = 'Class not found or no longer active.';
                } else {
                    $newMeetingId = save_class_meeting($pdo, $classId, $meetingData);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Meeting added successfully.';

                    redirect_to('classes/' . $classId . '/attendance?meeting_id=' . $newMeetingId);
                }
            } elseif ($postedAction === 'meeting_save') {
                $meeting = meeting_belongs_to_instructor($pdo, $meetingId, $instructorId);

                if (!$meeting || (int) $meeting['class_id'] !== $classId) {
                    $errors[] = 'Meeting not found.';
                } else {
                    save_class_meeting($pdo, $classId, $meetingData, $meetingId);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Meeting updated successfully.';

                    redirect_to('classes/' . $classId . '/attendance?meeting_id=' . $meetingId);
                }
            } elseif ($postedAction === 'attendance_save') {
                $meeting = meeting_belongs_to_instructor($pdo, $meetingId, $instructorId);

                if (!$meeting) {
                    $errors[] = 'Meeting not found.';
                } else {
                    save_attendance_records($pdo, $meetingId, $_POST['attendance'] ?? [], (int) $meeting['class_id']);
                    rotate_csrf_token('csrf_class_manage_token');
                    $_SESSION['class_success'] = 'Attendance saved successfully.';

                    redirect_to('classes/' . (int) $meeting['class_id'] . '/attendance?meeting_id=' . $meetingId);
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

function render_teaching_schedule_fields(array $scheduleData, string $idPrefix): void
{
    $weekdays = attendance_weekdays();
    $slots = $scheduleData['slots'];

    if (empty($slots)) {
        $slots = attendance_schedule_defaults()['slots'];
    }
    ?>
    <div class="teaching-schedule-block">
        <div class="section-heading compact">
            <h2>Teaching Schedule</h2>
            <span>Auto-generates meetings</span>
        </div>

        <div class="form-grid">
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>course_start_date" class="form-label">Course start date</label>
                <input type="date" class="form-control" id="<?php echo e($idPrefix); ?>course_start_date" name="course_start_date" value="<?php echo e((string) $scheduleData['course_start_date']); ?>" required>
            </div>
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>semester_weeks" class="form-label">Semester length</label>
                <input type="number" class="form-control" id="<?php echo e($idPrefix); ?>semester_weeks" name="semester_weeks" value="<?php echo e((string) $scheduleData['semester_weeks']); ?>" min="1" max="30" step="1" required>
            </div>
            <div class="field">
                <label for="<?php echo e($idPrefix); ?>meetings_per_week" class="form-label">Meetings per week</label>
                <input type="number" class="form-control" id="<?php echo e($idPrefix); ?>meetings_per_week" name="meetings_per_week" value="<?php echo e((string) $scheduleData['meetings_per_week']); ?>" min="1" max="7" step="1" data-meetings-per-week required>
            </div>
        </div>

        <div class="schedule-slot-list" data-schedule-slot-list>
            <?php foreach ($slots as $slot): ?>
                <div class="schedule-slot-row" data-schedule-slot>
                    <div class="field">
                        <label class="form-label">Day of week</label>
                        <select class="form-control" name="meeting_day[]">
                            <?php foreach ($weekdays as $dayValue => $dayLabel): ?>
                                <option value="<?php echo e((string) $dayValue); ?>" <?php echo (int) $slot['day_of_week'] === $dayValue ? 'selected' : ''; ?>><?php echo e($dayLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label">Meeting type</label>
                        <input type="text" class="form-control" name="meeting_type[]" value="<?php echo e((string) $slot['meeting_type']); ?>" maxlength="80" placeholder="Lecture">
                    </div>
                    <button class="btn btn-copy btn-danger-soft" type="button" data-remove-schedule-slot aria-label="Remove meeting"><i class="bi bi-trash"></i></button>
                </div>
            <?php endforeach; ?>
        </div>

        <button class="btn btn-copy" type="button" data-add-schedule-slot><i class="bi bi-plus-circle"></i> Add weekly meeting</button>
    </div>
    <?php
}

function render_meeting_form_fields(array $meeting = []): void
{
    $statuses = attendance_statuses();
    $meetingDate = (string) ($meeting['meeting_date'] ?? date('Y-m-d'));
    $meetingType = (string) ($meeting['meeting_type'] ?? 'Lecture');
    $meetingStatus = (string) ($meeting['status'] ?? 'regular');
    $topic = (string) ($meeting['topic'] ?? '');
    ?>
    <div class="form-grid">
        <div class="field">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" name="meeting_date" value="<?php echo e($meetingDate); ?>" required>
        </div>
        <div class="field">
            <label class="form-label">Meeting type</label>
            <input type="text" class="form-control" name="meeting_type" value="<?php echo e($meetingType); ?>" maxlength="80" required>
        </div>
        <div class="field">
            <label class="form-label">Status</label>
            <select class="form-control" name="meeting_status">
                <?php foreach ($statuses as $statusKey => $statusLabel): ?>
                    <option value="<?php echo e($statusKey); ?>" <?php echo $meetingStatus === $statusKey ? 'selected' : ''; ?>><?php echo e($statusLabel); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field field-wide">
            <label class="form-label">Topic</label>
            <input type="text" class="form-control" name="topic" value="<?php echo e($topic); ?>" maxlength="255" placeholder="Optional topic">
        </div>
    </div>
    <?php
}

function render_attendance_workspace(PDO $pdo, int $instructorId, array $class, string $csrfToken, array $errors, string $successMessage): void
{
    $classId = (int) $class['id'];
    $schedule = get_class_teaching_schedule($pdo, $classId);
    $meetings = get_class_meetings($pdo, $classId);

    if (empty($meetings)) {
        regenerate_class_meetings($pdo, $classId);
        $meetings = get_class_meetings($pdo, $classId);
    }

    $summary = get_attendance_summary($pdo, $classId);
    $selectedMeetingId = (int) ($_GET['meeting_id'] ?? 0);
    $selectedMeeting = null;
    $meetingSelectionNotice = '';

    foreach ($meetings as $meeting) {
        if ((int) $meeting['id'] === $selectedMeetingId) {
            $selectedMeeting = $meeting;
            break;
        }
    }

    if ($selectedMeeting === null && $selectedMeetingId > 0 && !empty($meetings)) {
        $selectedMeeting = $meetings[0];
        $meetingSelectionNotice = 'The selected meeting was refreshed. Showing the first available meeting instead.';
    }

    $students = $selectedMeeting ? get_enrolled_students_with_attendance($pdo, $classId, (int) $selectedMeeting['id']) : [];
    $meetingsByWeek = [];
    foreach ($meetings as $meeting) {
        $meetingsByWeek[(int) $meeting['week_number']][] = $meeting;
    }
    ?>
    <section class="attendance-layout mt-4">
        <div class="attendance-main">
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

            <?php if ($meetingSelectionNotice !== ''): ?>
                <div class="alert alert-warning" role="alert"><?php echo e($meetingSelectionNotice); ?></div>
            <?php endif; ?>

            <div class="metric-grid attendance-metrics">
                <article class="metric-card tone-emerald">
                    <div class="metric-icon"><i class="bi bi-percent"></i></div>
                    <div>
                        <div class="metric-label">Attendance rate</div>
                        <div class="metric-value"><?php echo e((string) $summary['attendance_rate']); ?>%</div>
                        <div class="metric-note">Present, late, and excused over saved records.</div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-calendar2-week"></i></div>
                    <div>
                        <div class="metric-label">Total planned meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['total_planned']); ?></div>
                        <div class="metric-note"><?php echo e((string) $schedule['semester_weeks']); ?> weeks planned.</div>
                    </div>
                </article>
                <article class="metric-card tone-indigo">
                    <div class="metric-icon"><i class="bi bi-check2-square"></i></div>
                    <div>
                        <div class="metric-label">Counted meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['counted']); ?></div>
                        <div class="metric-note">Regular meetings only.</div>
                    </div>
                </article>
                <article class="metric-card tone-amber">
                    <div class="metric-icon"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <div class="metric-label">Completed meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['completed']); ?></div>
                        <div class="metric-note">Regular meetings with saved sheets.</div>
                    </div>
                </article>
                <article class="metric-card">
                    <div class="metric-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <div class="metric-label">Remaining meetings</div>
                        <div class="metric-value"><?php echo e((string) $summary['remaining']); ?></div>
                        <div class="metric-note">Counted meetings still unsaved.</div>
                    </div>
                </article>
            </div>

            <section class="class-section mt-4">
                <div class="section-heading">
                    <h2>Generated Meeting Schedule</h2>
                    <button class="btn btn-edupredict" type="button" data-bs-toggle="modal" data-bs-target="#addMeetingModal">
                        <i class="bi bi-plus-circle"></i> Add meeting
                    </button>
                </div>

                <?php if (empty($meetingsByWeek)): ?>
                    <div class="empty-state large">
                        <i class="bi bi-calendar2-week"></i>
                        <span>No meetings generated yet. Update the teaching schedule to generate planned meetings.</span>
                    </div>
                <?php else: ?>
                    <div class="meeting-week-list">
                        <?php foreach ($meetingsByWeek as $weekNumber => $weekMeetings): ?>
                            <div class="meeting-week">
                                <h3>Week <?php echo e((string) $weekNumber); ?></h3>
                                <div class="meeting-list">
                                    <?php foreach ($weekMeetings as $meeting): ?>
                                        <?php
                                        $isSelected = $selectedMeeting && (int) $selectedMeeting['id'] === (int) $meeting['id'];
                                        $dateLabel = date('M j', strtotime((string) $meeting['meeting_date']));
                                        $statusLabel = attendance_statuses()[$meeting['status']] ?? 'Regular';
                                        $sheetSaved = (int) $meeting['record_count'] > 0;
                                        ?>
                                        <article class="meeting-item <?php echo $isSelected ? 'active' : ''; ?>">
                                            <div>
                                                <strong><?php echo e($dateLabel); ?> &bull; <?php echo e($meeting['meeting_type']); ?></strong>
                                                <span>
                                                    <?php echo e($statusLabel); ?><?php echo !empty($meeting['topic']) ? ' &middot; ' . e($meeting['topic']) : ''; ?>
                                                    <em><?php echo $sheetSaved ? 'Sheet saved' : 'No sheet yet'; ?></em>
                                                </span>
                                            </div>
                                            <div class="meeting-actions">
                                                <a class="btn btn-copy" href="<?php echo e(instructor_class_route($classId, 'attendance') . '?meeting_id=' . (int) $meeting['id']); ?>"><i class="bi bi-card-checklist"></i> Sheet</a>
                                                <button class="btn btn-copy" type="button" data-bs-toggle="modal" data-bs-target="#editMeetingModal-<?php echo e((string) $meeting['id']); ?>"><i class="bi bi-pencil-square"></i> Edit</button>
                                            </div>
                                        </article>

                                        <div class="modal fade" id="editMeetingModal-<?php echo e((string) $meeting['id']); ?>" tabindex="-1" aria-labelledby="editMeetingModalLabel-<?php echo e((string) $meeting['id']); ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <div>
                                                            <h2 class="modal-title h5" id="editMeetingModalLabel-<?php echo e((string) $meeting['id']); ?>">Edit Meeting</h2>
                                                            <p class="mb-0 text-secondary small">Changes affect this meeting only and keep existing attendance records attached.</p>
                                                        </div>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <form method="post" action="<?php echo e(instructor_class_route($classId, 'attendance') . '?meeting_id=' . (int) $meeting['id']); ?>" novalidate>
                                                        <div class="modal-body">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                            <input type="hidden" name="class_action" value="meeting_save">
                                                            <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                                                            <input type="hidden" name="meeting_id" value="<?php echo e((string) $meeting['id']); ?>">
                                                            <?php render_meeting_form_fields($meeting); ?>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-edupredict"><i class="bi bi-check2-circle"></i> Save meeting</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="attendance-sheet-panel">
            <?php if (!$selectedMeeting): ?>
                <div class="section-heading">
                    <h2>Attendance Sheet</h2>
                    <span>Select meeting</span>
                </div>
                <div class="empty-state large">
                    <i class="bi bi-card-checklist"></i>
                    <span>Select a meeting to take attendance.</span>
                </div>
            <?php else: ?>
                <div class="section-heading">
                    <h2><?php echo e(date('M j, Y', strtotime((string) $selectedMeeting['meeting_date']))); ?></h2>
                    <span><?php echo e($selectedMeeting['meeting_type']); ?></span>
                </div>
                <div class="attendance-sheet-meta">
                    <span><i class="bi bi-tag"></i><?php echo e(attendance_statuses()[$selectedMeeting['status']] ?? 'Regular'); ?></span>
                    <span><i class="bi bi-journal-text"></i><?php echo e($selectedMeeting['topic'] ?: 'No topic'); ?></span>
                </div>
                <form method="post" action="<?php echo e(instructor_class_route($classId, 'attendance') . '?meeting_id=' . (int) $selectedMeeting['id']); ?>" data-attendance-sheet>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="class_action" value="attendance_save">
                    <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                    <input type="hidden" name="meeting_id" value="<?php echo e((string) $selectedMeeting['id']); ?>">

                    <button class="btn btn-copy w-100 mb-3" type="button" data-mark-all-present><i class="bi bi-check2-all"></i> Mark all present</button>

                    <?php if (empty($students)): ?>
                        <div class="empty-state large">
                            <i class="bi bi-people"></i>
                            <span>No enrolled students yet.</span>
                        </div>
                    <?php else: ?>
                        <div class="attendance-student-list">
                            <?php foreach ($students as $student): ?>
                                <label class="attendance-student-row">
                                    <span>
                                        <strong><?php echo e($student['student_name']); ?></strong>
                                        <small><?php echo e($student['student_no'] ?: 'No student number'); ?></small>
                                    </span>
                                    <select class="form-control" name="attendance[<?php echo e((string) $student['id']); ?>]" data-attendance-status>
                                        <?php foreach (attendance_record_statuses() as $statusKey => $statusLabel): ?>
                                            <option value="<?php echo e($statusKey); ?>" <?php echo $student['attendance_status'] === $statusKey ? 'selected' : ''; ?>><?php echo e($statusLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-edupredict w-100 mt-3" type="submit"><i class="bi bi-save"></i> Save attendance</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </aside>
    </section>

    <div class="modal fade" id="addMeetingModal" tabindex="-1" aria-labelledby="addMeetingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title h5" id="addMeetingModalLabel">Add Meeting</h2>
                        <p class="mb-0 text-secondary small">Extra meetings are added without changing the generated teaching schedule.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="<?php echo e(instructor_class_route($classId, 'attendance')); ?>" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="class_action" value="meeting_add">
                        <input type="hidden" name="class_id" value="<?php echo e((string) $classId); ?>">
                        <?php render_meeting_form_fields(); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-edupredict"><i class="bi bi-plus-circle"></i> Add meeting</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function render_instructor_class_workspace(array $class, string $classView, array $viewMeta, string $baseJoinUrl, PDO $pdo, int $instructorId, string $csrfToken, array $errors, string $successMessage): void
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

        <?php if ($classView === 'attendance'): ?>
            <?php render_attendance_workspace($pdo, $instructorId, $class, $csrfToken, $errors, $successMessage); ?>
        <?php elseif ($classView === 'overview'): ?>
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
    'content' => function () use ($pdo, $instructorId, $errors, $successMessage, $formData, $scheduleData, $csrfToken, $classes, $allClasses, $baseJoinUrl, $selectedClass, $classView, $classViewMeta, $searchTerm, $autoOpenModal, $postedAction, $editTargetClassId) {
        ?>
        <?php if ($selectedClass !== null): ?>
            <?php render_instructor_class_workspace($selectedClass, $classView, $classViewMeta, $baseJoinUrl, $pdo, $instructorId, $csrfToken, $errors, $successMessage); ?>
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

                        $editScheduleData = get_class_teaching_schedule($pdo, $classId);
                        if ($postedAction === 'edit' && $editTargetClassId === $classId && !empty($errors)) {
                            $editScheduleData = $scheduleData;
                        }
                        ?>
                        <div class="modal fade" id="editClassModal-<?php echo e((string) $classId); ?>" tabindex="-1" aria-labelledby="editClassModalLabel-<?php echo e((string) $classId); ?>" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable class-management-modal">
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

                                            <?php render_teaching_schedule_fields($editScheduleData, 'edit_' . $classId . '_'); ?>
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
            <div class="modal-dialog modal-xl modal-dialog-scrollable class-management-modal">
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

                            <?php render_teaching_schedule_fields($scheduleData, 'create_'); ?>
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
