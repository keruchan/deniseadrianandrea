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

$counts = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($dash['warnings'] as $w) {
    $counts[$w['severity']] = ($counts[$w['severity']] ?? 0) + 1;
}

render_dashboard_page([
    'role_label' => 'Student',
    'fallback_name' => 'Student',
    'title' => 'Warnings',
    'eyebrow' => 'Stay on track',
    'description' => 'Early-warning signals across your classes — risk of not passing, low attendance, missing work, and declining grades.',
    'active_route' => 'student.warnings',
    'menu' => student_sidebar_menu(),
    'content' => function () use ($dash, $counts) {
        if ($dash['class_count'] === 0) {
            ?>
            <div class="empty-state large mt-4">
                <i class="bi bi-easel2"></i>
                <span>Join a class first — warnings appear here once you have grades and attendance.</span>
                <a class="btn btn-edupredict mt-3" href="<?php echo e(url_for('pages/student/classes.php')); ?>"><i class="bi bi-door-open"></i> Join a class</a>
            </div>
            <?php
            return;
        }

        if (empty($dash['warnings'])) {
            ?>
            <div class="empty-state large mt-4 student-warning-clear">
                <i class="bi bi-shield-check"></i>
                <span>You're all clear — no warnings across your classes. Keep it up! 🎉</span>
                <a class="btn btn-copy mt-3" href="<?php echo e(url_for('pages/student/dashboard.php')); ?>"><i class="bi bi-arrow-left"></i> Back to dashboard</a>
            </div>
            <?php
            return;
        }
        ?>
        <div class="metric-grid insights-summary-grid mt-4">
            <article class="metric-card tone-rose"><div class="metric-icon"><i class="bi bi-exclamation-octagon"></i></div><div><div class="metric-label">High priority</div><div class="metric-value"><?php echo (int) $counts['high']; ?></div><div class="metric-note">Act soon</div></div></article>
            <article class="metric-card tone-amber"><div class="metric-icon"><i class="bi bi-exclamation-triangle"></i></div><div><div class="metric-label">Medium</div><div class="metric-value"><?php echo (int) $counts['medium']; ?></div><div class="metric-note">Worth attention</div></div></article>
            <article class="metric-card"><div class="metric-icon"><i class="bi bi-info-circle"></i></div><div><div class="metric-label">Low</div><div class="metric-value"><?php echo (int) $counts['low']; ?></div><div class="metric-note">Keep an eye on</div></div></article>
            <article class="metric-card"><div class="metric-icon"><i class="bi bi-easel2"></i></div><div><div class="metric-label">Classes flagged</div><div class="metric-value"><?php echo (int) $dash['at_risk_classes']; ?></div><div class="metric-note">Of <?php echo (int) $dash['class_count']; ?> classes</div></div></article>
        </div>

        <article class="widget-panel mt-4">
            <div class="section-heading"><h2>Your Warnings</h2><span><?php echo (int) $dash['warning_count']; ?> total</span></div>
            <div class="insights-rec-list">
                <?php foreach ($dash['warnings'] as $w): ?>
                    <?php
                    $tone = ['high' => 'tone-rose', 'medium' => 'tone-amber', 'low' => 'tone-slate'][$w['severity']] ?? 'tone-slate';
                    $sevLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'][$w['severity']] ?? 'Info';
                    ?>
                    <div class="insights-rec-item student-warning-row">
                        <span class="insights-rec-icon <?php echo $tone; ?>"><i class="bi <?php echo e($w['icon']); ?>"></i></span>
                        <div class="student-warning-body">
                            <div class="student-warning-titlerow">
                                <strong><?php echo e($w['title']); ?></strong>
                                <span class="status-pill small <?php echo $tone; ?>"><?php echo e($sevLabel); ?></span>
                            </div>
                            <p><?php echo e($w['text']); ?></p>
                            <a class="btn btn-copy btn-sm" href="<?php echo e($w['link']); ?>"><i class="bi bi-arrow-right-circle"></i> Take a look</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
        <?php
    },
]);
