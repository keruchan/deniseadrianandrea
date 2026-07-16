<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/admin_management.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('administrator');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['class_action'] ?? '');
    $classId = (int) ($_POST['class_id'] ?? 0);

    if (!csrf_is_valid('csrf_admin_classes_token', (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($classId > 0) {
        if ($action === 'set_status') {
            admin_set_class_status($pdo, $classId, (string) ($_POST['status'] ?? ''));
            rotate_csrf_token('csrf_admin_classes_token');
            $_SESSION['admin_success'] = 'Class status updated.';
            redirect_to('pages/administrator/classes.php');
        } elseif ($action === 'delete_class') {
            admin_delete_class($pdo, $classId);
            rotate_csrf_token('csrf_admin_classes_token');
            $_SESSION['admin_success'] = 'Class deleted.';
            redirect_to('pages/administrator/classes.php');
        }
    }
}

$fStatus = in_array(($_GET['status'] ?? ''), ['draft', 'active', 'archived'], true) ? (string) $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$classes = get_admin_classes($pdo, $fStatus, $q);

$successFlash = $_SESSION['admin_success'] ?? null;
unset($_SESSION['admin_success']);
$csrf = csrf_token('csrf_admin_classes_token');

$statusTone = ['active' => 'tone-emerald', 'archived' => 'tone-slate', 'draft' => 'tone-amber'];

render_dashboard_page([
    'role_label' => 'Administrator',
    'fallback_name' => 'Administrator',
    'title' => 'Classes',
    'eyebrow' => 'Oversight',
    'description' => 'Every class across the institution — instructor, enrollment, status, and controls.',
    'active_route' => 'admin.classes',
    'menu' => admin_sidebar_menu(),
    'content' => function () use ($classes, $fStatus, $q, $errors, $successFlash, $csrf, $statusTone) {
        ?>
        <?php if ($successFlash): ?><div class="alert alert-success mt-4" role="alert"><?php echo e($successFlash); ?></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-danger mt-4" role="alert"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <form method="get" action="<?php echo e(url_for('pages/administrator/classes.php')); ?>" class="insights-filter-bar mt-4">
            <div class="insights-filter-fields">
                <div class="field">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <option value="active" <?php echo $fStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="draft" <?php echo $fStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="archived" <?php echo $fStatus === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    </select>
                </div>
                <div class="field field-wide">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Class, subject, code, or instructor">
                </div>
            </div>
            <div class="insights-filter-actions">
                <button class="btn btn-edupredict" type="submit"><i class="bi bi-search"></i> Search</button>
                <a class="btn btn-copy" href="<?php echo e(url_for('pages/administrator/classes.php')); ?>"><i class="bi bi-x-circle"></i> Reset</a>
            </div>
        </form>

        <article class="widget-panel mt-4">
            <div class="section-heading"><h2>All Classes</h2><span><?php echo (int) count($classes); ?> shown</span></div>
            <?php if (empty($classes)): ?>
                <div class="empty-state"><i class="bi bi-easel2"></i><span>No classes match these filters.</span></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table insights-table">
                        <thead><tr><th>Class</th><th>Subject</th><th>Instructor</th><th>Code</th><th>Students</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td><strong><?php echo e(trim($c['class_name'] . ' ' . (string) ($c['section'] ?? ''))); ?></strong></td>
                                <td><span class="insights-sub"><?php echo e($c['subject_name']); ?></span></td>
                                <td><?php echo e($c['instructor'] ?: 'Unassigned'); ?></td>
                                <td><span class="insights-sub"><?php echo e($c['class_code']); ?></span></td>
                                <td><?php echo (int) $c['students']; ?></td>
                                <td><span class="status-pill small <?php echo $statusTone[$c['status']] ?? 'tone-slate'; ?>"><?php echo e(ucfirst((string) $c['status'])); ?></span></td>
                                <td class="admin-row-actions">
                                    <?php if ($c['status'] !== 'active'): ?>
                                        <form method="post" action="<?php echo e(url_for('pages/administrator/classes.php')); ?>" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>"><input type="hidden" name="class_action" value="set_status"><input type="hidden" name="class_id" value="<?php echo (int) $c['id']; ?>"><input type="hidden" name="status" value="active">
                                            <button class="btn btn-copy btn-sm text-success" type="submit" title="Activate"><i class="bi bi-play-circle"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($c['status'] !== 'archived'): ?>
                                        <form method="post" action="<?php echo e(url_for('pages/administrator/classes.php')); ?>" class="d-inline" data-confirm-action="Archive this class? Students keep their records but it becomes read-only.">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>"><input type="hidden" name="class_action" value="set_status"><input type="hidden" name="class_id" value="<?php echo (int) $c['id']; ?>"><input type="hidden" name="status" value="archived">
                                            <button class="btn btn-copy btn-sm text-amber" type="submit" title="Archive"><i class="bi bi-archive"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?php echo e(url_for('pages/administrator/classes.php')); ?>" class="d-inline" data-confirm-action="Permanently delete this class and ALL its data (attendance, grades, groupings)? This cannot be undone.">
                                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>"><input type="hidden" name="class_action" value="delete_class"><input type="hidden" name="class_id" value="<?php echo (int) $c['id']; ?>">
                                        <button class="btn btn-copy btn-sm btn-danger-soft" type="submit" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
        <?php
    },
]);
