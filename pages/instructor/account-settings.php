<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('instructor');

$instructorId = current_instructor_id($pdo);
if ($instructorId === null) {
    redirect_to('pages/auth/logout.php');
}

$sidebarClasses = instructor_sidebar_classes($pdo, $instructorId);

render_dashboard_page([
    'role_label' => 'Instructor',
    'fallback_name' => 'Instructor',
    'title' => 'Account Settings',
    'eyebrow' => 'Settings',
    'description' => 'Manage account preferences and profile settings from this workspace.',
    'active_route' => 'settings.account',
    'menu' => instructor_sidebar_menu($sidebarClasses),
    'content' => function () {
        ?>
        <section class="class-section">
            <div class="section-heading">
                <h2>Account Settings</h2>
                <span>Planned</span>
            </div>
            <div class="empty-state large">
                <i class="bi bi-person-gear"></i>
                <span>Account settings controls will be connected here.</span>
            </div>
        </section>
        <?php
    },
]);
