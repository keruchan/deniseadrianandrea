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
$dash = get_instructor_dashboard($pdo, $instructorId);
$hasClasses = $dash['class_count'] > 0;

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Instructor Dashboard',
    'eyebrow' => 'Classroom performance workspace',
    'description' => 'A live rollup of your classes: performance, attendance, grading workload, and students who need attention.',
    'active_route' => 'dashboard',
    'menu' => instructor_sidebar_menu($sidebarClasses),
    'content' => function () use ($dash, $hasClasses) {
        if (!$hasClasses) {
            ?>
            <div class="empty-state large mt-4">
                <i class="bi bi-easel2"></i>
                <span>You don't have any active classes yet. Create a class to start tracking performance, attendance, and predictions.</span>
                <a class="btn btn-edupredict mt-3" href="<?php echo e(url_for('classes')); ?>"><i class="bi bi-plus-circle"></i> Go to Classes</a>
            </div>
            <?php
            return;
        }
        ?>
        <?php
        $students = url_for('pages/instructor/students.php');
        $assessments = url_for('pages/instructor/assessments.php');
        ?>
        <div class="metric-grid insights-summary-grid mt-4">
            <a class="metric-card metric-card-link tone-blue" href="<?php echo e(url_for('classes')); ?>"><div class="metric-icon"><i class="bi bi-easel2"></i></div><div><div class="metric-label">Total classes</div><div class="metric-value"><?php echo (int) $dash['class_count']; ?></div><div class="metric-note">Active classes <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-emerald" href="<?php echo e($students); ?>"><div class="metric-icon"><i class="bi bi-mortarboard"></i></div><div><div class="metric-label">Total students</div><div class="metric-value"><?php echo (int) $dash['student_count']; ?></div><div class="metric-note">View all students <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-indigo" href="<?php echo e($students . '?sort=grade'); ?>"><div class="metric-icon"><i class="bi bi-bar-chart-line"></i></div><div><div class="metric-label">Overall class average</div><div class="metric-value"><?php echo insights_num($dash['class_average'], 1); ?></div><div class="metric-note">Rank by grade <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-emerald" href="<?php echo e($students . '?sort=attendance'); ?>"><div class="metric-icon"><i class="bi bi-calendar-check"></i></div><div><div class="metric-label">Attendance rate</div><div class="metric-value"><?php echo insights_num($dash['attendance_rate'], 1); ?></div><div class="metric-note">Rank by attendance <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-rose metric-card-attention" href="<?php echo e($students . '?risk=absence'); ?>"><div class="metric-icon"><i class="bi bi-exclamation-octagon"></i></div><div><div class="metric-label">Needs attention</div><div class="metric-value"><?php echo (int) $dash['absence_warnings']; ?></div><div class="metric-note">2+ unexcused absences <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link" href="<?php echo e($assessments . '?status=pending'); ?>"><div class="metric-icon"><i class="bi bi-hourglass-split"></i></div><div><div class="metric-label">Pending grading</div><div class="metric-value"><?php echo (int) $dash['pending_grading']; ?></div><div class="metric-note">Grade now <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link" href="<?php echo e($assessments . '?status=upcoming'); ?>"><div class="metric-icon"><i class="bi bi-calendar-plus"></i></div><div><div class="metric-label">Upcoming assessments</div><div class="metric-value"><?php echo (int) $dash['upcoming_assessments']; ?></div><div class="metric-note">View schedule <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-rose metric-card-at-risk" href="<?php echo e($students . '?risk=atrisk'); ?>"><div class="metric-icon"><i class="bi bi-shield-exclamation"></i></div><div><div class="metric-label">Forecast at-risk</div><div class="metric-value"><?php echo (int) $dash['at_risk_count']; ?></div><div class="metric-note">Review students <i class="bi bi-arrow-right-short"></i></div></div></a>
        </div>

        <section class="mt-4">
            <div class="section-heading">
                <h2>Your Classes</h2>
                <a class="btn btn-copy btn-sm" href="<?php echo e(url_for('classes')); ?>"><i class="bi bi-grid"></i> All classes</a>
            </div>
            <div class="dash-class-grid">
                <?php foreach ($dash['classes'] as $c): ?>
                    <?php
                    $avg = $c['class_average'];
                    $avgTone = $avg === null ? 'tone-slate' : ($avg >= 75 ? 'tone-emerald' : ($avg >= 60 ? 'tone-amber' : 'tone-rose'));
                    ?>
                    <article class="dash-class-card">
                        <div class="dash-class-head">
                            <div>
                                <h3><?php echo e($c['label']); ?></h3>
                                <span class="insights-sub"><i class="bi bi-people"></i> <?php echo (int) $c['student_count']; ?> students</span>
                            </div>
                            <span class="dash-class-avg <?php echo $avgTone; ?>"><?php echo insights_num($avg, 1); ?></span>
                        </div>

                        <div class="dash-class-stats">
                            <div><span>Attendance</span><strong><?php echo insights_num($c['attendance_rate'], 0); ?></strong></div>
                            <div><span>At-risk</span><strong class="<?php echo $c['at_risk'] > 0 ? 'text-rose' : ''; ?>"><?php echo (int) $c['at_risk']; ?></strong></div>
                            <div><span>Pending</span><strong class="<?php echo $c['pending'] > 0 ? 'text-amber' : ''; ?>"><?php echo (int) $c['pending']; ?></strong></div>
                        </div>

                        <?php if ($c['at_risk'] > 0 || $c['pending'] > 0 || $c['missing_students'] > 0 || $c['absence_warnings'] > 0): ?>
                            <div class="dash-class-flags">
                                <?php if ($c['absence_warnings'] > 0): ?><span class="status-pill small <?php echo $c['drop_warnings'] > 0 ? 'tone-rose' : 'tone-amber'; ?>"><i class="bi bi-exclamation-octagon"></i> <?php echo (int) $c['absence_warnings']; ?> attendance</span><?php endif; ?>
                                <?php if ($c['at_risk'] > 0): ?><span class="status-pill small tone-rose"><i class="bi bi-exclamation-octagon"></i> <?php echo (int) $c['at_risk']; ?> at-risk</span><?php endif; ?>
                                <?php if ($c['pending'] > 0): ?><span class="status-pill small tone-amber"><i class="bi bi-hourglass-split"></i> <?php echo (int) $c['pending']; ?> to grade</span><?php endif; ?>
                                <?php if ($c['missing_students'] > 0): ?><span class="status-pill small tone-slate"><i class="bi bi-clipboard-x"></i> <?php echo (int) $c['missing_students']; ?> missing</span><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="dash-class-flags"><span class="status-pill small tone-emerald"><i class="bi bi-check-circle"></i> On track</span></div>
                        <?php endif; ?>

                        <div class="dash-class-actions">
                            <a class="btn btn-edupredict btn-sm" href="<?php echo e(instructor_class_route((int) $c['id'], 'dashboard')); ?>"><i class="bi bi-speedometer2"></i> Insights</a>
                            <a class="btn btn-copy btn-sm" href="<?php echo e(instructor_class_route((int) $c['id'], 'predictions')); ?>"><i class="bi bi-stars"></i> Predictions</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="content-grid two-columns mt-4">
            <article class="widget-panel">
                <div class="section-heading"><h2>Attendance Trend</h2><span>All classes by week</span></div>
                <?php if (!empty($dash['attendance_trend']['values'])): ?>
                    <?php insights_chart_tag(['type' => 'line', 'labels' => $dash['attendance_trend']['labels'], 'series' => [['label' => 'Attendance %', 'data' => $dash['attendance_trend']['values'], 'color' => 'indigo']], 'yMax' => 100], 'Attendance trend across classes'); ?>
                <?php else: ?>
                    <?php insights_empty('bi-graph-up', 'No attendance recorded yet.'); ?>
                <?php endif; ?>
            </article>
            <article class="widget-panel">
                <div class="section-heading"><h2>Grade Distribution</h2><span>All students</span></div>
                <?php if (array_sum($dash['grade_distribution']['values']) > 0): ?>
                    <?php insights_chart_tag(['type' => 'bar', 'labels' => $dash['grade_distribution']['labels'], 'series' => [['label' => 'Students', 'data' => $dash['grade_distribution']['values'], 'color' => 'indigo']]], 'Grade distribution across classes'); ?>
                <?php else: ?>
                    <?php insights_empty('bi-bar-chart', 'No graded components yet.'); ?>
                <?php endif; ?>
            </article>
        </section>

        <section class="content-grid two-columns mt-4">
            <article class="widget-panel">
                <div class="section-heading"><h2>Assessment Completion</h2><span>Average across classes</span></div>
                <?php if (!empty($dash['completion_progress']['values'])): ?>
                    <?php insights_chart_tag(['type' => 'hbar', 'labels' => $dash['completion_progress']['labels'], 'series' => [['label' => 'Complete %', 'data' => $dash['completion_progress']['values'], 'color' => 'emerald']], 'yMax' => 100], 'Assessment completion by component', 220); ?>
                <?php else: ?>
                    <?php insights_empty('bi-list-check', 'No gradeable assessments yet.'); ?>
                <?php endif; ?>
            </article>
            <article class="widget-panel">
                <div class="section-heading"><h2>Recently Graded</h2><span>Latest activity</span></div>
                <?php if (!empty($dash['recently_graded'])): ?>
                    <div class="insights-quicklist">
                        <?php foreach ($dash['recently_graded'] as $r): ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon tone-emerald"><i class="bi bi-check2-square"></i></span>
                                <div>
                                    <strong><?php echo e($r['title']); ?> <span class="insights-sub"><?php echo e(ucfirst((string) $r['type'])); ?> &bull; <?php echo e($r['class_name'] . ' ' . (string) ($r['section'] ?? '')); ?></span></strong>
                                    <p><?php echo (int) $r['n']; ?> grade(s)<?php echo !empty($r['graded_at']) ? ' &bull; ' . e(date('M j, Y', strtotime((string) $r['graded_at']))) : ''; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-clipboard-check', 'No grades recorded yet.'); ?>
                <?php endif; ?>
            </article>
        </section>

        <section class="content-grid two-columns mt-4">
            <article class="widget-panel">
                <div class="section-heading"><h2>Upcoming Schedule</h2><span>Next meetings</span></div>
                <?php if (!empty($dash['upcoming_meetings'])): ?>
                    <div class="insights-quicklist">
                        <?php foreach ($dash['upcoming_meetings'] as $m): ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon tone-indigo"><i class="bi bi-calendar-event"></i></span>
                                <div>
                                    <strong><?php echo e(date('M j, Y', strtotime((string) $m['meeting_date']))); ?> <span class="insights-sub"><?php echo e($m['class_name'] . ' ' . (string) ($m['section'] ?? '')); ?></span></strong>
                                    <p>Week <?php echo (int) $m['week_number']; ?> &bull; <?php echo e(ucfirst((string) $m['status'])); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-calendar2-week', 'No upcoming meetings scheduled.'); ?>
                <?php endif; ?>
            </article>
            <article class="widget-panel">
                <div class="section-heading"><h2>Upcoming Deadlines</h2><span>Assessments due</span></div>
                <?php if (!empty($dash['upcoming_deadlines'])): ?>
                    <div class="insights-quicklist">
                        <?php foreach ($dash['upcoming_deadlines'] as $d): ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon tone-amber"><i class="bi bi-alarm"></i></span>
                                <div>
                                    <strong><?php echo e($d['title']); ?> <span class="insights-sub"><?php echo e(ucfirst((string) $d['type'])); ?> &bull; <?php echo e($d['class_name'] . ' ' . (string) ($d['section'] ?? '')); ?></span></strong>
                                    <p><?php echo e(date('M j, Y', strtotime((string) $d['scheduled_date']))); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-calendar-check', 'No upcoming assessment deadlines.'); ?>
                <?php endif; ?>
            </article>
        </section>
        <?php
    },
]);
