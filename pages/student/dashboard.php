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

$dash = get_student_dashboard($pdo, $studentId);
$insights = url_for('pages/student/class-insights.php');

render_dashboard_page([
    'role_label' => 'Student',
    'fallback_name' => 'Student',
    'title' => 'My Dashboard',
    'eyebrow' => 'Learner progress hub',
    'description' => 'Your grades, attendance, predictions, and warnings across every class — at a glance.',
    'active_route' => 'student.dashboard',
    'menu' => student_sidebar_menu(),
    'content' => function () use ($dash, $insights) {
        if ($dash['class_count'] === 0) {
            ?>
            <div class="empty-state large mt-4">
                <i class="bi bi-easel2"></i>
                <span>You haven't joined any classes yet. Enter a class code to get started, then your grades, attendance, and predictions will appear here.</span>
                <a class="btn btn-edupredict mt-3" href="<?php echo e(url_for('pages/student/classes.php')); ?>"><i class="bi bi-door-open"></i> Join a class</a>
            </div>
            <?php
            return;
        }
        ?>
        <div class="metric-grid insights-summary-grid mt-4">
            <a class="metric-card metric-card-link tone-blue" href="<?php echo e(url_for('pages/student/classes.php')); ?>"><div class="metric-icon"><i class="bi bi-easel2"></i></div><div><div class="metric-label">My classes</div><div class="metric-value"><?php echo (int) $dash['class_count']; ?></div><div class="metric-note">View classes <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-indigo" href="<?php echo e($insights . '?tab=grades'); ?>"><div class="metric-icon"><i class="bi bi-mortarboard"></i></div><div><div class="metric-label">Overall average</div><div class="metric-value"><?php echo insights_num($dash['overall_average'], 1); ?></div><div class="metric-note">See grades <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-emerald" href="<?php echo e($insights . '?tab=attendance'); ?>"><div class="metric-icon"><i class="bi bi-calendar-check"></i></div><div><div class="metric-label">Attendance</div><div class="metric-value"><?php echo insights_num($dash['overall_attendance'], 1); ?></div><div class="metric-note">See attendance <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link <?php echo $dash['warning_count'] > 0 ? 'tone-rose' : 'tone-emerald'; ?>" href="<?php echo e(url_for('pages/student/warnings.php')); ?>"><div class="metric-icon"><i class="bi bi-exclamation-triangle"></i></div><div><div class="metric-label">Warnings</div><div class="metric-value"><?php echo (int) $dash['warning_count']; ?></div><div class="metric-note">Review warnings <i class="bi bi-arrow-right-short"></i></div></div></a>
        </div>

        <?php if (!empty($dash['warnings'])): ?>
            <section class="widget-panel mt-4 student-warning-preview">
                <div class="section-heading">
                    <h2><i class="bi bi-exclamation-triangle text-amber"></i> Needs your attention</h2>
                    <a class="btn btn-copy btn-sm" href="<?php echo e(url_for('pages/student/warnings.php')); ?>">View all (<?php echo (int) $dash['warning_count']; ?>)</a>
                </div>
                <div class="insights-rec-list">
                    <?php foreach (array_slice($dash['warnings'], 0, 3) as $w): ?>
                        <?php $tone = ['high' => 'tone-rose', 'medium' => 'tone-amber', 'low' => 'tone-slate'][$w['severity']] ?? 'tone-slate'; ?>
                        <a class="insights-rec-item student-warning-link" href="<?php echo e($w['link']); ?>">
                            <span class="insights-rec-icon <?php echo $tone; ?>"><i class="bi <?php echo e($w['icon']); ?>"></i></span>
                            <div><strong><?php echo e($w['title']); ?></strong><p><?php echo e($w['text']); ?></p></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="mt-4">
            <div class="section-heading">
                <h2>My Classes</h2>
                <a class="btn btn-copy btn-sm" href="<?php echo e(url_for('pages/student/classes.php')); ?>"><i class="bi bi-grid"></i> All classes</a>
            </div>
            <div class="dash-class-grid">
                <?php foreach ($dash['classes'] as $c): ?>
                    <?php
                    $avg = $c['current_grade'];
                    $avgTone = $avg === null ? 'tone-slate' : ($avg >= 75 ? 'tone-emerald' : ($avg >= 60 ? 'tone-amber' : 'tone-rose'));
                    $classInsights = $insights . '?class_id=' . (int) $c['id'] . '&tab=grades';
                    ?>
                    <article class="dash-class-card">
                        <div class="dash-class-head">
                            <div>
                                <h3><?php echo e($c['label']); ?></h3>
                                <span class="insights-sub"><i class="bi bi-person-workspace"></i> <?php echo e($c['instructor']); ?></span>
                                <span class="insights-sub"><i class="bi bi-calendar3"></i> <?php echo e($c['school_year'] !== '' ? $c['school_year'] : 'No school year'); ?> &middot; <i class="bi bi-journal-bookmark"></i> <?php echo e($c['term'] !== '' ? $c['term'] : 'No semester'); ?></span>
                            </div>
                            <span class="dash-class-avg <?php echo $avgTone; ?>"><?php echo insights_num($avg, 1); ?></span>
                        </div>
                        <div class="dash-class-stats">
                            <div><span>Attendance</span><strong><?php echo insights_num($c['attendance_rate'], 0); ?></strong></div>
                            <div><span>Predicted</span><strong><?php echo insights_num($c['predicted_final'], 0); ?></strong></div>
                            <div><span>Trend</span><strong><?php echo ucfirst($c['trend'] === 'none' ? '—' : $c['trend']); ?></strong></div>
                        </div>
                        <div class="dash-class-flags">
                            <?php echo insights_risk_pill($c['risk_level']); ?>
                            <?php if ($c['missing_count'] > 0): ?><span class="status-pill small tone-slate"><i class="bi bi-clipboard-x"></i> <?php echo (int) $c['missing_count']; ?> missing</span><?php endif; ?>
                        </div>
                        <div class="dash-class-actions">
                            <a class="btn btn-edupredict btn-sm" href="<?php echo e($classInsights); ?>"><i class="bi bi-graph-up"></i> My insights</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="content-grid two-columns mt-4">
            <article class="widget-panel">
                <div class="section-heading"><h2>Recent Grades</h2><span>Latest posted</span></div>
                <?php if (!empty($dash['recent_grades'])): ?>
                    <div class="insights-quicklist">
                        <?php foreach ($dash['recent_grades'] as $g): ?>
                            <?php $pct = (float) $g['max_score'] > 0 ? ($g['score'] / $g['max_score']) * 100 : null; ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon <?php echo $pct === null ? 'tone-slate' : ($pct >= 75 ? 'tone-emerald' : ($pct >= 50 ? 'tone-amber' : 'tone-rose')); ?>"><i class="bi bi-card-checklist"></i></span>
                                <div>
                                    <strong><?php echo e($g['title']); ?> <span class="insights-sub"><?php echo e(ucfirst((string) $g['type'])); ?> &bull; <?php echo e($g['class_name'] . ' ' . (string) ($g['section'] ?? '')); ?></span></strong>
                                    <p><?php echo e(format_score($g['score'])); ?> / <?php echo e(format_score($g['max_score'])); ?><?php echo $pct !== null ? ' &bull; ' . round($pct) . '%' : ''; ?><?php echo !empty($g['graded_at']) ? ' &bull; ' . e(date('M j', strtotime((string) $g['graded_at']))) : ''; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-clipboard-check', 'No grades have been posted yet.'); ?>
                <?php endif; ?>
            </article>
            <article class="widget-panel">
                <div class="section-heading"><h2>Upcoming</h2><span>Meetings &amp; deadlines</span></div>
                <?php if (empty($dash['upcoming_meetings']) && empty($dash['upcoming_deadlines'])): ?>
                    <?php insights_empty('bi-calendar2-week', 'Nothing scheduled ahead right now.'); ?>
                <?php else: ?>
                    <div class="insights-quicklist">
                        <?php foreach ($dash['upcoming_deadlines'] as $d): ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon tone-amber"><i class="bi bi-alarm"></i></span>
                                <div>
                                    <strong><?php echo e($d['title']); ?> <span class="insights-sub"><?php echo e(ucfirst((string) $d['type'])); ?> &bull; <?php echo e($d['class_name'] . ' ' . (string) ($d['section'] ?? '')); ?></span></strong>
                                    <p>Due <?php echo e(date('M j, Y', strtotime((string) $d['scheduled_date']))); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
                <?php endif; ?>
            </article>
        </section>
        <?php
    },
]);
