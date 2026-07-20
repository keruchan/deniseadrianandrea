<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/insights_management.php';
require_once __DIR__ . '/../../includes/insights_render.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

$sidebarClasses = instructor_sidebar_classes($pdo, $instructorId);
$errors = [];
$successMessage = '';

if (!empty($_SESSION['student_class_success'])) {
    $successMessage = (string) $_SESSION['student_class_success'];
    unset($_SESSION['student_class_success']);
}

$csrfToken = csrf_token('csrf_instructor_student_class_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['class_action'] ?? '') === 'remove_student_class') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $classId = (int) ($_POST['class_id'] ?? 0);
    $studentId = (int) ($_POST['student_id'] ?? 0);

    if (!csrf_is_valid('csrf_instructor_student_class_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }
    if ($classId <= 0 || $studentId <= 0) {
        $errors[] = 'Student or class selection is invalid.';
    }

    if (empty($errors)) {
        try {
            $checkStmt = $pdo->prepare(
                'SELECT c.class_name, c.section, CONCAT(s.first_name, " ", s.last_name) AS student_name
                 FROM class_enrollments ce
                 INNER JOIN classes c ON c.id = ce.class_id
                 INNER JOIN students s ON s.id = ce.student_id
                 WHERE ce.class_id = :class_id
                    AND ce.student_id = :student_id
                    AND ce.status = "active"
                    AND c.instructor_id = :instructor_id
                 LIMIT 1'
            );
            $checkStmt->execute([
                ':class_id' => $classId,
                ':student_id' => $studentId,
                ':instructor_id' => $instructorId,
            ]);
            $target = $checkStmt->fetch();

            if (!$target) {
                $errors[] = 'Student enrollment was not found for this class.';
            } else {
                $pdo->beginTransaction();

                $removeStmt = $pdo->prepare(
                    'UPDATE class_enrollments
                     SET status = "removed", updated_at = CURRENT_TIMESTAMP
                     WHERE class_id = :class_id AND student_id = :student_id AND status = "active"'
                );
                $removeStmt->execute([
                    ':class_id' => $classId,
                    ':student_id' => $studentId,
                ]);

                $groupStmt = $pdo->prepare(
                    'SELECT g.id
                     FROM class_grouping_groups g
                     INNER JOIN class_groupings cg ON cg.id = g.grouping_id
                     WHERE cg.class_id = :class_id'
                );
                $groupStmt->execute([':class_id' => $classId]);
                $groupIds = array_map('intval', $groupStmt->fetchAll(PDO::FETCH_COLUMN));

                if (!empty($groupIds)) {
                    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
                    $clearLeader = $pdo->prepare("UPDATE class_grouping_groups SET leader_student_id = NULL WHERE leader_student_id = ? AND id IN ($placeholders)");
                    $clearLeader->execute(array_merge([$studentId], $groupIds));

                    $deleteMember = $pdo->prepare("DELETE FROM class_grouping_members WHERE student_id = ? AND group_id IN ($placeholders)");
                    $deleteMember->execute(array_merge([$studentId], $groupIds));
                }

                $pdo->commit();

                $classLabel = trim((string) $target['class_name'] . ' ' . (string) ($target['section'] ?? ''));
                $_SESSION['student_class_success'] = (string) $target['student_name'] . ' was removed from ' . ($classLabel !== '' ? $classLabel : 'this class') . '.';
                rotate_csrf_token('csrf_instructor_student_class_token');
                redirect_to('pages/instructor/students.php?class=' . $classId);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[EDUPREDICT STUDENT CLASS REMOVE ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to remove the student from this class right now. Please try again.';
        }
    }

    $csrfToken = csrf_token('csrf_instructor_student_class_token');
}

$overview = get_instructor_students_overview($pdo, $instructorId);
$rows = $overview['rows'];

// Filters (server-side GET) + sort. Search is client-side (below).
$fClass = (int) ($_GET['class'] ?? 0);
$fStatus = in_array(($_GET['status'] ?? ''), ['active', 'pending', 'disabled'], true) ? (string) $_GET['status'] : '';
$fRisk = in_array(($_GET['risk'] ?? ''), ['atrisk', 'absence', 'drop', 'high', 'medium', 'low'], true) ? (string) $_GET['risk'] : '';
$sort = in_array(($_GET['sort'] ?? ''), ['name', 'grade', 'attendance', 'participation', 'risk'], true) ? (string) $_GET['sort'] : 'name';

$filtered = array_values(array_filter($rows, static function ($r) use ($fClass, $fStatus, $fRisk) {
    if ($fClass > 0 && (int) $r['class_id'] !== $fClass) {
        return false;
    }
    if ($fStatus !== '' && $r['account_status'] !== $fStatus) {
        return false;
    }
    if ($fRisk === 'atrisk' && !$r['at_risk']) {
        return false;
    }
    if ($fRisk === 'drop' && empty($r['drop_warning'])) {
        return false;
    }
    if ($fRisk === 'absence' && empty($r['absence_warning'])) {
        return false;
    }
    if ($fRisk !== '' && !in_array($fRisk, ['atrisk', 'absence', 'drop'], true) && $r['risk_level'] !== $fRisk) {
        return false;
    }
    return true;
}));

$riskRank = ['high' => 3, 'medium' => 2, 'low' => 1];
usort($filtered, static function ($a, $b) use ($sort, $riskRank) {
    switch ($sort) {
        case 'grade':
            return ($b['current_grade'] ?? -1) <=> ($a['current_grade'] ?? -1);
        case 'attendance':
            return ($b['attendance_rate'] ?? -1) <=> ($a['attendance_rate'] ?? -1);
        case 'participation':
            return ($b['participation_rate'] ?? -1) <=> ($a['participation_rate'] ?? -1);
        case 'risk':
            return ($riskRank[$b['risk_level']] ?? 0) <=> ($riskRank[$a['risk_level']] ?? 0);
        default:
            return strcasecmp((string) $a['student_name'], (string) $b['student_name']);
    }
});

$statusMeta = [
    'active' => ['Active', 'tone-emerald', 'bi-check-circle'],
    'pending' => ['Pending', 'tone-amber', 'bi-hourglass-split'],
    'disabled' => ['Disabled', 'tone-rose', 'bi-slash-circle'],
];

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Students',
    'eyebrow' => 'Across all classes',
    'description' => 'Every student in your active classes with quick performance, risk, and account status. Search, filter, and drill into any student.',
    'active_route' => 'students',
    'menu' => instructor_sidebar_menu($sidebarClasses),
    'content' => function () use ($overview, $filtered, $fClass, $fStatus, $fRisk, $sort, $statusMeta, $errors, $successMessage, $csrfToken) {
        ?>
        <div class="metric-grid insights-summary-grid mt-4">
            <article class="metric-card tone-emerald"><div class="metric-icon"><i class="bi bi-mortarboard"></i></div><div><div class="metric-label">Distinct students</div><div class="metric-value"><?php echo (int) $overview['distinct_students']; ?></div><div class="metric-note">Across your classes</div></div></article>
            <article class="metric-card tone-blue"><div class="metric-icon"><i class="bi bi-easel2"></i></div><div><div class="metric-label">Classes</div><div class="metric-value"><?php echo (int) count($overview['classes']); ?></div><div class="metric-note">Active classes</div></div></article>
            <a class="metric-card metric-card-link tone-rose metric-card-attention" href="<?php echo e(url_for('pages/instructor/students.php?risk=absence')); ?>"><div class="metric-icon"><i class="bi bi-exclamation-octagon"></i></div><div><div class="metric-label">Needs attention</div><div class="metric-value"><?php echo (int) $overview['absence_warnings']; ?></div><div class="metric-note">2+ unexcused absences</div></div></a>
            <article class="metric-card tone-rose metric-card-at-risk"><div class="metric-icon"><i class="bi bi-shield-exclamation"></i></div><div><div class="metric-label">At-risk</div><div class="metric-value"><?php echo (int) $overview['at_risk']; ?></div><div class="metric-note">Forecast or strong warning</div></div></article>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success mt-4" role="alert"><?php echo e($successMessage); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mt-4" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($overview['rows'])): ?>
            <div class="empty-state large mt-4">
                <i class="bi bi-people"></i>
                <span>No students are enrolled in your active classes yet. Share a class code so students can join.</span>
                <a class="btn btn-edupredict mt-3" href="<?php echo e(url_for('classes')); ?>"><i class="bi bi-easel2"></i> Go to Classes</a>
            </div>
        <?php else: ?>
            <form method="get" action="<?php echo e(url_for('pages/instructor/students.php')); ?>" class="insights-filter-bar mt-4">
                <div class="insights-filter-fields">
                    <div class="field">
                        <label class="form-label">Class</label>
                        <select class="form-select" name="class" onchange="this.form.submit()">
                            <option value="0">All classes</option>
                            <?php foreach ($overview['classes'] as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo $fClass === (int) $c['id'] ? 'selected' : ''; ?>><?php echo e($c['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label">Account status</label>
                        <select class="form-select" name="status" onchange="this.form.submit()">
                            <option value="">All statuses</option>
                            <option value="active" <?php echo $fStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="pending" <?php echo $fStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="disabled" <?php echo $fStatus === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label">Risk</label>
                        <select class="form-select" name="risk" onchange="this.form.submit()">
                            <option value="">All risk levels</option>
                            <option value="atrisk" <?php echo $fRisk === 'atrisk' ? 'selected' : ''; ?>>At-risk (forecast)</option>
                            <option value="absence" <?php echo $fRisk === 'absence' ? 'selected' : ''; ?>>Needs attention (2+ absences)</option>
                            <option value="drop" <?php echo $fRisk === 'drop' ? 'selected' : ''; ?>>Needs attention (3 absences)</option>
                            <option value="high" <?php echo $fRisk === 'high' ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo $fRisk === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo $fRisk === 'low' ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label">Sort by</label>
                        <select class="form-select" name="sort" onchange="this.form.submit()">
                            <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name (A–Z)</option>
                            <option value="grade" <?php echo $sort === 'grade' ? 'selected' : ''; ?>>Current grade</option>
                            <option value="attendance" <?php echo $sort === 'attendance' ? 'selected' : ''; ?>>Attendance</option>
                            <option value="participation" <?php echo $sort === 'participation' ? 'selected' : ''; ?>>Participation</option>
                            <option value="risk" <?php echo $sort === 'risk' ? 'selected' : ''; ?>>Risk level</option>
                        </select>
                    </div>
                </div>
                <div class="insights-filter-actions">
                    <a class="btn btn-copy" href="<?php echo e(url_for('pages/instructor/students.php')); ?>"><i class="bi bi-x-circle"></i> Reset</a>
                </div>
            </form>

            <article class="widget-panel mt-4">
                <div class="section-heading">
                    <h2>Student List</h2>
                    <span><?php echo (int) count($filtered); ?> shown</span>
                </div>
                <div data-student-search-scope>
                    <div class="student-search-bar">
                        <div class="search-input-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" data-student-search placeholder="Search by name or ID" aria-label="Search students by name or ID" autocomplete="off">
                        </div>
                    </div>

                    <?php if (empty($filtered)): ?>
                        <div class="empty-state mt-3"><i class="bi bi-funnel"></i><span>No students match these filters.</span></div>
                    <?php else: ?>
                        <div class="table-responsive" data-student-search-list>
                            <table class="table insights-table students-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Class</th>
                                        <th>Account</th>
                                        <th>Grade</th>
                                        <th>Attendance</th>
                                        <th>Predicted</th>
                                        <th>Risk</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filtered as $r): ?>
                                        <?php
                                        [$stLabel, $stTone, $stIcon] = $statusMeta[$r['account_status']] ?? ['Active', 'tone-emerald', 'bi-check-circle'];
                                        $terms = mb_strtolower($r['student_name'] . ' ' . $r['student_no'] . ' ' . $r['class_label']);
                                        ?>
                                        <tr data-search-terms="<?php echo e($terms); ?>">
                                            <td>
                                                <strong><?php echo e($r['student_name']); ?></strong>
                                                <span class="insights-sub"><?php echo e($r['student_no'] ?: 'No ID'); ?></span>
                                            </td>
                                            <td><span class="insights-sub"><?php echo e($r['class_label']); ?></span></td>
                                            <td><span class="status-pill small <?php echo e($stTone); ?>"><i class="bi <?php echo e($stIcon); ?>"></i> <?php echo e($stLabel); ?></span></td>
                                            <td><strong><?php echo insights_num($r['current_grade'], 1); ?></strong></td>
                                            <td>
                                                <?php echo insights_num($r['attendance_rate'], 0); ?>
                                                <?php if (!empty($r['drop_warning'])): ?>
                                                    <span class="status-pill small tone-rose mt-1" title="Subject for dropping review, not automatic"><i class="bi bi-exclamation-octagon"></i> <?php echo (int) $r['unexcused_absences']; ?> absences</span>
                                                <?php elseif (!empty($r['absence_warning'])): ?>
                                                    <span class="status-pill small tone-amber mt-1" title="Early attendance warning"><i class="bi bi-exclamation-triangle"></i> <?php echo (int) $r['unexcused_absences']; ?> absences</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo insights_num($r['predicted_final'], 1); ?></td>
                                            <td><?php echo insights_risk_pill($r['risk_level']); ?></td>
                                            <td>
                                                <div class="d-flex flex-wrap justify-content-end gap-1">
                                                    <a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route((int) $r['class_id'], 'predictions') . '?student=' . (int) $r['student_id']); ?>" aria-label="View <?php echo e($r['student_name']); ?>"><i class="bi bi-arrow-right-circle"></i></a>
                                                    <form method="post" action="<?php echo e(url_for('pages/instructor/students.php')); ?>" data-confirm-action="Remove <?php echo e($r['student_name']); ?> from <?php echo e($r['class_label']); ?>? This only removes this class enrollment.">
                                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                        <input type="hidden" name="class_action" value="remove_student_class">
                                                        <input type="hidden" name="class_id" value="<?php echo (int) $r['class_id']; ?>">
                                                        <input type="hidden" name="student_id" value="<?php echo (int) $r['student_id']; ?>">
                                                        <button class="btn btn-copy btn-sm btn-danger-soft" type="submit" aria-label="Remove <?php echo e($r['student_name']); ?> from <?php echo e($r['class_label']); ?>"><i class="bi bi-person-dash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="student-search-empty" data-student-search-empty hidden>
                            <i class="bi bi-search"></i> No students match your search.
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endif; ?>
        <?php
    },
]);
