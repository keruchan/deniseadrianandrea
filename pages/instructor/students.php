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
$overview = get_instructor_students_overview($pdo, $instructorId);
$rows = $overview['rows'];

// Filters (server-side GET) + sort. Search is client-side (below).
$fClass = (int) ($_GET['class'] ?? 0);
$fStatus = in_array(($_GET['status'] ?? ''), ['active', 'pending', 'disabled'], true) ? (string) $_GET['status'] : '';
$fRisk = in_array(($_GET['risk'] ?? ''), ['atrisk', 'high', 'medium', 'low'], true) ? (string) $_GET['risk'] : '';
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
    if ($fRisk !== '' && $fRisk !== 'atrisk' && $r['risk_level'] !== $fRisk) {
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
    'content' => function () use ($overview, $filtered, $fClass, $fStatus, $fRisk, $sort, $statusMeta) {
        ?>
        <div class="metric-grid insights-summary-grid mt-4">
            <article class="metric-card tone-emerald"><div class="metric-icon"><i class="bi bi-mortarboard"></i></div><div><div class="metric-label">Distinct students</div><div class="metric-value"><?php echo (int) $overview['distinct_students']; ?></div><div class="metric-note">Across your classes</div></div></article>
            <article class="metric-card tone-blue"><div class="metric-icon"><i class="bi bi-people"></i></div><div><div class="metric-label">Enrollments</div><div class="metric-value"><?php echo (int) $overview['total_rows']; ?></div><div class="metric-note">Student × class rows</div></div></article>
            <article class="metric-card tone-rose"><div class="metric-icon"><i class="bi bi-exclamation-octagon"></i></div><div><div class="metric-label">At-risk</div><div class="metric-value"><?php echo (int) $overview['at_risk']; ?></div><div class="metric-note">Forecast below passing</div></div></article>
            <article class="metric-card"><div class="metric-icon"><i class="bi bi-easel2"></i></div><div><div class="metric-label">Classes</div><div class="metric-value"><?php echo (int) count($overview['classes']); ?></div><div class="metric-note">Active classes</div></div></article>
        </div>

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
                                            <td><?php echo insights_num($r['attendance_rate'], 0); ?></td>
                                            <td><?php echo insights_num($r['predicted_final'], 1); ?></td>
                                            <td><?php echo insights_risk_pill($r['risk_level']); ?></td>
                                            <td><a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route((int) $r['class_id'], 'predictions') . '?student=' . (int) $r['student_id']); ?>" aria-label="View <?php echo e($r['student_name']); ?>"><i class="bi bi-arrow-right-circle"></i></a></td>
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
