<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/admin_management.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('administrator');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['settings_action'] ?? '') === 'save') {
    if (!csrf_is_valid('csrf_admin_settings_token', (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $values = [];
        foreach (get_admin_settings($pdo) as $composite => $meta) {
            if (array_key_exists('setting_' . $meta['group'] . '_' . $meta['key'], $_POST)) {
                $values[$composite] = trim((string) $_POST['setting_' . $meta['group'] . '_' . $meta['key']]);
            }
        }
        // Minimal validation: app + institution names required.
        foreach (['general.app_name' => 'Application name', 'general.institution_name' => 'Institution name'] as $k => $label) {
            if (isset($values[$k]) && $values[$k] === '') {
                $errors[] = $label . ' is required.';
            }
        }
        if (empty($errors)) {
            save_admin_settings($pdo, $values);
            rotate_csrf_token('csrf_admin_settings_token');
            $_SESSION['admin_success'] = 'System settings saved.';
            redirect_to('pages/administrator/settings.php');
        }
    }
}

$settings = get_admin_settings($pdo);
$successFlash = $_SESSION['admin_success'] ?? null;
unset($_SESSION['admin_success']);
$csrf = csrf_token('csrf_admin_settings_token');

// Group settings for display.
$grouped = [];
foreach ($settings as $composite => $meta) {
    $grouped[$meta['group']][] = $meta;
}

render_dashboard_page([
    'role_label' => 'Administrator',
    'fallback_name' => 'Administrator',
    'title' => 'System Settings',
    'eyebrow' => 'System',
    'description' => 'Institution-wide configuration used across the application.',
    'active_route' => 'admin.settings',
    'menu' => admin_sidebar_menu(),
    'content' => function () use ($grouped, $errors, $successFlash, $csrf) {
        ?>
        <?php if ($successFlash): ?><div class="alert alert-success mt-4" role="alert"><?php echo e($successFlash); ?></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-danger mt-4" role="alert"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <section class="content-grid two-columns mt-4">
            <article class="form-panel">
                <div class="section-heading"><h2>Configuration</h2><span>Applied system-wide</span></div>
                <?php if (empty($grouped)): ?>
                    <div class="empty-state"><i class="bi bi-sliders"></i><span>No configurable settings found.</span></div>
                <?php else: ?>
                    <form method="post" action="<?php echo e(url_for('pages/administrator/settings.php')); ?>" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                        <input type="hidden" name="settings_action" value="save">
                        <?php foreach ($grouped as $group => $items): ?>
                            <h3 class="insights-subhead mt-2"><?php echo e(ucfirst($group)); ?></h3>
                            <?php foreach ($items as $s): ?>
                                <div class="field mb-3">
                                    <label class="form-label" for="s-<?php echo e($s['group'] . '-' . $s['key']); ?>"><?php echo e($s['label']); ?></label>
                                    <input type="text" class="form-control" id="s-<?php echo e($s['group'] . '-' . $s['key']); ?>" name="setting_<?php echo e($s['group'] . '_' . $s['key']); ?>" value="<?php echo e($s['value']); ?>" maxlength="2000">
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <button class="btn btn-edupredict mt-2" type="submit"><i class="bi bi-check2-circle"></i> Save settings</button>
                    </form>
                <?php endif; ?>
            </article>
            <aside class="info-panel">
                <div class="section-heading"><h2>About these settings</h2><span>Reference</span></div>
                <div class="steps-list">
                    <div><strong>1</strong><span>Application &amp; institution names identify the platform in headings and reports.</span></div>
                    <div><strong>2</strong><span>The default academic term seeds new class terms.</span></div>
                    <div><strong>3</strong><span>Changes take effect immediately across all roles.</span></div>
                </div>
            </aside>
        </section>
        <?php
    },
]);
