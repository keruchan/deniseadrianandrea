<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/admin_management.php';
require_once __DIR__ . '/../../includes/insights_render.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('administrator');

$overview = get_admin_overview($pdo);
$users = url_for('pages/administrator/users.php');
$classesUrl = url_for('pages/administrator/classes.php');

render_dashboard_page([
    'role_label' => 'Administrator',
    'fallback_name' => 'Administrator',
    'title' => 'Administrator Dashboard',
    'eyebrow' => 'Institution command center',
    'description' => 'A live overview of users, classes, enrollment, and institution-wide academic risk.',
    'active_route' => 'admin.dashboard',
    'menu' => admin_sidebar_menu(),
    'content' => function () use ($overview, $users, $classesUrl) {
        $statusMeta = admin_status_meta();
        ?>
        <div class="metric-grid insights-summary-grid mt-4">
            <a class="metric-card metric-card-link tone-blue" href="<?php echo e($users); ?>"><div class="metric-icon"><i class="bi bi-people"></i></div><div><div class="metric-label">Total users</div><div class="metric-value"><?php echo (int) $overview['total_users']; ?></div><div class="metric-note">Manage users <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-indigo" href="<?php echo e($users . '?role=instructor'); ?>"><div class="metric-icon"><i class="bi bi-person-workspace"></i></div><div><div class="metric-label">Instructors</div><div class="metric-value"><?php echo (int) $overview['role_counts']['instructor']; ?></div><div class="metric-note">View instructors <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link tone-emerald" href="<?php echo e($users . '?role=student'); ?>"><div class="metric-icon"><i class="bi bi-mortarboard"></i></div><div><div class="metric-label">Students</div><div class="metric-value"><?php echo (int) $overview['role_counts']['student']; ?></div><div class="metric-note">View students <i class="bi bi-arrow-right-short"></i></div></div></a>
            <a class="metric-card metric-card-link" href="<?php echo e($classesUrl . '?status=active'); ?>"><div class="metric-icon"><i class="bi bi-easel2"></i></div><div><div class="metric-label">Active classes</div><div class="metric-value"><?php echo (int) $overview['class_status']['active']; ?></div><div class="metric-note">Oversee classes <i class="bi bi-arrow-right-short"></i></div></div></a>
            <article class="metric-card"><div class="metric-icon"><i class="bi bi-diagram-3"></i></div><div><div class="metric-label">Active enrollments</div><div class="metric-value"><?php echo (int) $overview['enrollments']; ?></div><div class="metric-note">Student × class</div></div></article>
            <a class="metric-card metric-card-link <?php echo $overview['pending_accounts'] > 0 ? 'tone-amber' : ''; ?>" href="<?php echo e($users . '?status=pending'); ?>"><div class="metric-icon"><i class="bi bi-hourglass-split"></i></div><div><div class="metric-label">Pending accounts</div><div class="metric-value"><?php echo (int) $overview['pending_accounts']; ?></div><div class="metric-note">Review approvals <i class="bi bi-arrow-right-short"></i></div></div></a>
            <article class="metric-card tone-rose"><div class="metric-icon"><i class="bi bi-exclamation-octagon"></i></div><div><div class="metric-label">At-risk students</div><div class="metric-value"><?php echo (int) $overview['at_risk']; ?></div><div class="metric-note">Across active classes</div></div></article>
            <article class="metric-card"><div class="metric-icon"><i class="bi bi-bar-chart-line"></i></div><div><div class="metric-label">Overall average</div><div class="metric-value"><?php echo insights_num($overview['grade_average'], 1); ?></div><div class="metric-note">All graded students</div></div></article>
        </div>

        <section class="content-grid two-columns mt-4">
            <article class="widget-panel">
                <div class="section-heading"><h2>Users by Role</h2><span>Distribution</span></div>
                <?php insights_chart_tag([
                    'type' => 'doughnut',
                    'labels' => ['Administrators', 'Instructors', 'Students'],
                    'series' => [['label' => 'Users', 'data' => [
                        (int) $overview['role_counts']['administrator'],
                        (int) $overview['role_counts']['instructor'],
                        (int) $overview['role_counts']['student'],
                    ]]],
                ], 'Users by role'); ?>
            </article>
            <article class="widget-panel">
                <div class="section-heading"><h2>Classes by Status</h2><span>All classes</span></div>
                <?php if ($overview['total_classes'] > 0): ?>
                    <?php insights_chart_tag([
                        'type' => 'bar',
                        'labels' => ['Draft', 'Active', 'Archived'],
                        'series' => [['label' => 'Classes', 'data' => [
                            (int) $overview['class_status']['draft'],
                            (int) $overview['class_status']['active'],
                            (int) $overview['class_status']['archived'],
                        ], 'color' => 'indigo']],
                    ], 'Classes by status'); ?>
                <?php else: ?>
                    <?php insights_empty('bi-easel2', 'No classes created yet.'); ?>
                <?php endif; ?>
            </article>
        </section>

        <section class="content-grid two-columns mt-4">
            <article class="widget-panel">
                <div class="section-heading"><h2>Recent Users</h2><a class="btn btn-copy btn-sm" href="<?php echo e($users); ?>">Manage</a></div>
                <?php if (!empty($overview['recent_users'])): ?>
                    <div class="insights-quicklist">
                        <?php foreach ($overview['recent_users'] as $u): ?>
                            <?php [$sl, $st, $si] = $statusMeta[$u['status']] ?? ['Active', 'tone-emerald', 'bi-check-circle']; ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon tone-indigo"><i class="bi bi-person"></i></span>
                                <div>
                                    <strong><?php echo e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['username']); ?> <span class="insights-sub"><?php echo e($u['role_name']); ?> &bull; @<?php echo e($u['username']); ?></span></strong>
                                    <p><span class="status-pill small <?php echo $st; ?>"><i class="bi <?php echo $si; ?>"></i> <?php echo e($sl); ?></span> &bull; joined <?php echo e(date('M j, Y', strtotime((string) $u['created_at']))); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-people', 'No users yet.'); ?>
                <?php endif; ?>
            </article>
            <article class="widget-panel">
                <div class="section-heading"><h2>Recent Classes</h2><a class="btn btn-copy btn-sm" href="<?php echo e($classesUrl); ?>">Oversee</a></div>
                <?php if (!empty($overview['recent_classes'])): ?>
                    <div class="insights-quicklist">
                        <?php foreach ($overview['recent_classes'] as $c): ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon tone-emerald"><i class="bi bi-easel2"></i></span>
                                <div>
                                    <strong><?php echo e(trim($c['class_name'] . ' ' . (string) ($c['section'] ?? ''))); ?> <span class="insights-sub"><?php echo e($c['instructor'] ?: 'Unassigned'); ?></span></strong>
                                    <p><?php echo (int) $c['students']; ?> students &bull; <?php echo e(ucfirst((string) $c['status'])); ?> &bull; <?php echo e(date('M j, Y', strtotime((string) $c['created_at']))); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?php insights_empty('bi-easel2', 'No classes yet.'); ?>
                <?php endif; ?>
            </article>
        </section>
        <?php
    },
]);
