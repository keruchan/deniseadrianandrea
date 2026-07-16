<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/assessment_management.php';
require_once __DIR__ . '/../../includes/insights_management.php';
require_once __DIR__ . '/../../includes/insights_render.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

$sidebarClasses = instructor_sidebar_classes($pdo, $instructorId);
$overview = get_instructor_assessments_overview($pdo, $instructorId);
$rows = $overview['rows'];

$fClass = (int) ($_GET['class'] ?? 0);
$fType = in_array(($_GET['type'] ?? ''), ['activity', 'quiz', 'midterm', 'finals'], true) ? (string) $_GET['type'] : '';
$fStatus = in_array(($_GET['status'] ?? ''), ['pending', 'upcoming', 'graded', 'setup'], true) ? (string) $_GET['status'] : '';

$filtered = array_values(array_filter($rows, static function ($r) use ($fClass, $fType, $fStatus) {
    if ($fClass > 0 && (int) $r['class_id'] !== $fClass) {
        return false;
    }
    if ($fType !== '' && $r['type'] !== $fType) {
        return false;
    }
    if ($fStatus === 'pending' && !$r['needs_grading']) {
        return false;
    }
    if ($fStatus === 'upcoming' && !$r['is_upcoming']) {
        return false;
    }
    if ($fStatus === 'graded' && !$r['fully_graded']) {
        return false;
    }
    if ($fStatus === 'setup' && $r['gradeable']) {
        return false;
    }
    return true;
}));

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Assessments',
    'eyebrow' => 'Across all classes',
    'description' => 'Every activity, quiz, midterm, and final across your classes — grading progress, upcoming deadlines, and quick links to grade.',
    'active_route' => 'assessments',
    'menu' => instructor_sidebar_menu($sidebarClasses),
    'content' => function () use ($overview, $filtered, $fClass, $fType, $fStatus) {
        $counts = $overview['counts'];
        ?>
        <div class="metric-grid insights-summary-grid mt-4">
            <article class="metric-card tone-amber"><div class="metric-icon"><i class="bi bi-hourglass-split"></i></div><div><div class="metric-label">Pending grading</div><div class="metric-value"><?php echo (int) $counts['pending']; ?></div><div class="metric-note">Ready but not fully graded</div></div></article>
            <article class="metric-card tone-indigo"><div class="metric-icon"><i class="bi bi-calendar-plus"></i></div><div><div class="metric-label">Upcoming</div><div class="metric-value"><?php echo (int) $counts['upcoming']; ?></div><div class="metric-note">Scheduled from today</div></div></article>
            <article class="metric-card tone-emerald"><div class="metric-icon"><i class="bi bi-check2-circle"></i></div><div><div class="metric-label">Fully graded</div><div class="metric-value"><?php echo (int) $counts['graded']; ?></div><div class="metric-note">All students scored</div></div></article>
            <article class="metric-card"><div class="metric-icon"><i class="bi bi-tools"></i></div><div><div class="metric-label">Needs setup</div><div class="metric-value"><?php echo (int) $counts['setup']; ?></div><div class="metric-note">Missing title/date/max</div></div></article>
        </div>

        <?php if (empty($overview['rows'])): ?>
            <div class="empty-state large mt-4">
                <i class="bi bi-journal-text"></i>
                <span>No assessments yet. Open a class to configure activities, quizzes, and exams.</span>
                <a class="btn btn-edupredict mt-3" href="<?php echo e(url_for('classes')); ?>"><i class="bi bi-easel2"></i> Go to Classes</a>
            </div>
        <?php else: ?>
            <form method="get" action="<?php echo e(url_for('pages/instructor/assessments.php')); ?>" class="insights-filter-bar mt-4">
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
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" onchange="this.form.submit()">
                            <option value="">All types</option>
                            <?php foreach (assessment_types() as $key => $meta): ?>
                                <option value="<?php echo e($key); ?>" <?php echo $fType === $key ? 'selected' : ''; ?>><?php echo e($meta['label'] ?? ucfirst($key)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" onchange="this.form.submit()">
                            <option value="">All statuses</option>
                            <option value="pending" <?php echo $fStatus === 'pending' ? 'selected' : ''; ?>>Pending grading</option>
                            <option value="upcoming" <?php echo $fStatus === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                            <option value="graded" <?php echo $fStatus === 'graded' ? 'selected' : ''; ?>>Fully graded</option>
                            <option value="setup" <?php echo $fStatus === 'setup' ? 'selected' : ''; ?>>Needs setup</option>
                        </select>
                    </div>
                </div>
                <div class="insights-filter-actions">
                    <a class="btn btn-copy" href="<?php echo e(url_for('pages/instructor/assessments.php')); ?>"><i class="bi bi-x-circle"></i> Reset</a>
                </div>
            </form>

            <article class="widget-panel mt-4">
                <div class="section-heading">
                    <h2>Assessment List</h2>
                    <span><?php echo (int) count($filtered); ?> shown</span>
                </div>
                <div data-student-search-scope>
                    <div class="student-search-bar">
                        <div class="search-input-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" data-student-search placeholder="Search by title or class" aria-label="Search assessments" autocomplete="off">
                        </div>
                    </div>

                    <?php if (empty($filtered)): ?>
                        <div class="empty-state mt-3"><i class="bi bi-funnel"></i><span>No assessments match these filters.</span></div>
                    <?php else: ?>
                        <div class="table-responsive" data-student-search-list>
                            <table class="table insights-table">
                                <thead>
                                    <tr>
                                        <th>Assessment</th>
                                        <th>Class</th>
                                        <th>Type</th>
                                        <th>Date</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filtered as $r): ?>
                                        <?php
                                        $terms = mb_strtolower($r['title'] . ' ' . $r['class_label'] . ' ' . $r['type_label']);
                                        if (!$r['gradeable']) {
                                            $statusPill = '<span class="status-pill small tone-slate"><i class="bi bi-tools"></i> Needs setup</span>';
                                        } elseif ($r['fully_graded']) {
                                            $statusPill = '<span class="status-pill small tone-emerald"><i class="bi bi-check-circle"></i> Graded</span>';
                                        } elseif ($r['graded_count'] > 0) {
                                            $statusPill = '<span class="status-pill small tone-amber"><i class="bi bi-hourglass-split"></i> Partial</span>';
                                        } else {
                                            $statusPill = '<span class="status-pill small tone-amber"><i class="bi bi-pencil"></i> To grade</span>';
                                        }
                                        $gradeUrl = instructor_class_route((int) $r['class_id'], (string) $r['type_view']) . '?item=' . (int) $r['id'];
                                        ?>
                                        <tr data-search-terms="<?php echo e($terms); ?>">
                                            <td><strong><?php echo e($r['title']); ?></strong><span class="insights-sub">Max <?php echo e(format_score($r['max_score'])); ?></span></td>
                                            <td><span class="insights-sub"><?php echo e($r['class_label']); ?></span></td>
                                            <td><span class="status-pill small tone-slate"><?php echo e($r['type_label']); ?></span></td>
                                            <td><?php echo $r['scheduled_date'] !== '' ? e(date('M j, Y', strtotime($r['scheduled_date']))) : '<span class="insights-sub">No date</span>'; ?></td>
                                            <td><?php echo $r['gradeable'] ? (int) $r['graded_count'] . ' / ' . (int) $r['enrolled'] : '<span class="insights-sub">&mdash;</span>'; ?></td>
                                            <td><?php echo $statusPill; ?></td>
                                            <td>
                                                <?php if ($r['gradeable']): ?>
                                                    <a class="btn btn-copy btn-sm" href="<?php echo e($gradeUrl); ?>"><i class="bi bi-card-checklist"></i> Grade</a>
                                                <?php else: ?>
                                                    <a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route((int) $r['class_id'], (string) $r['type_view'])); ?>"><i class="bi bi-tools"></i> Set up</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="student-search-empty" data-student-search-empty hidden>
                            <i class="bi bi-search"></i> No assessments match your search.
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endif; ?>
        <?php
    },
]);
