<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/admin_management.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('administrator');

$errors = [];
$form = ['title' => '', 'body' => '', 'audience' => 'all'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['announce_action'] ?? '') === 'broadcast') {
    $form['title'] = trim((string) ($_POST['title'] ?? ''));
    $form['body'] = trim((string) ($_POST['body'] ?? ''));
    $form['audience'] = in_array(($_POST['audience'] ?? ''), array_merge(['all'], array_keys(admin_role_options())), true) ? (string) $_POST['audience'] : 'all';

    if (!csrf_is_valid('csrf_admin_announce_token', (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if ($form['title'] === '' || mb_strlen($form['title']) > 180) {
        $errors[] = 'A title is required (max 180 characters).';
    }
    if (mb_strlen($form['body']) > 500) {
        $errors[] = 'Message must not exceed 500 characters.';
    }

    if (empty($errors)) {
        $count = admin_broadcast_announcement($pdo, $form['title'], $form['body'], $form['audience']);
        rotate_csrf_token('csrf_admin_announce_token');
        $_SESSION['admin_success'] = 'Announcement sent to ' . $count . ' recipient' . ($count === 1 ? '' : 's') . '.';
        redirect_to('pages/administrator/announcements.php');
    }
}

// Recently sent announcements (grouped by title + minute), for reference.
$recent = $pdo->query(
    'SELECT title, body, COUNT(*) AS recipients, MAX(created_at) AS sent_at
     FROM notifications WHERE type = "announcement"
     GROUP BY title, body, DATE_FORMAT(created_at, "%Y-%m-%d %H:%i")
     ORDER BY sent_at DESC LIMIT 8'
)->fetchAll();

$successFlash = $_SESSION['admin_success'] ?? null;
unset($_SESSION['admin_success']);
$csrf = csrf_token('csrf_admin_announce_token');

render_dashboard_page([
    'role_label' => 'Administrator',
    'fallback_name' => 'Administrator',
    'title' => 'Announcements',
    'eyebrow' => 'System',
    'description' => 'Broadcast a message to the notification bell of every user, or a specific role.',
    'active_route' => 'admin.announcements',
    'menu' => admin_sidebar_menu(),
    'content' => function () use ($errors, $successFlash, $csrf, $form, $recent) {
        ?>
        <?php if ($successFlash): ?><div class="alert alert-success mt-4" role="alert"><?php echo e($successFlash); ?></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-danger mt-4" role="alert"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <section class="content-grid two-columns mt-4">
            <article class="form-panel">
                <div class="section-heading"><h2>Compose</h2><span>Delivered to the bell</span></div>
                <form method="post" action="<?php echo e(url_for('pages/administrator/announcements.php')); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="announce_action" value="broadcast">
                    <div class="field">
                        <label class="form-label" for="title">Title</label>
                        <input type="text" class="form-control" id="title" name="title" maxlength="180" value="<?php echo e($form['title']); ?>" required placeholder="e.g. Midterm week schedule">
                    </div>
                    <div class="field mt-2">
                        <label class="form-label" for="body">Message <span class="text-secondary">(optional)</span></label>
                        <textarea class="form-control" id="body" name="body" rows="4" maxlength="500" placeholder="Add details students and instructors should know."><?php echo e($form['body']); ?></textarea>
                    </div>
                    <div class="field mt-2">
                        <label class="form-label" for="audience">Send to</label>
                        <select class="form-select" id="audience" name="audience">
                            <option value="all" <?php echo $form['audience'] === 'all' ? 'selected' : ''; ?>>Everyone</option>
                            <?php foreach (admin_role_options() as $key => $label): ?>
                                <option value="<?php echo e($key); ?>" <?php echo $form['audience'] === $key ? 'selected' : ''; ?>><?php echo e($label); ?>s only</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-secondary">Disabled accounts are skipped automatically.</small>
                    </div>
                    <button class="btn btn-edupredict mt-3" type="submit"><i class="bi bi-megaphone"></i> Send announcement</button>
                </form>
            </article>

            <article class="widget-panel">
                <div class="section-heading"><h2>Recently Sent</h2><span>Last 8</span></div>
                <?php if (empty($recent)): ?>
                    <div class="empty-state"><i class="bi bi-megaphone"></i><span>No announcements sent yet.</span></div>
                <?php else: ?>
                    <div class="insights-quicklist">
                        <?php foreach ($recent as $a): ?>
                            <div class="insights-quick-row">
                                <span class="insights-quick-icon tone-indigo"><i class="bi bi-megaphone"></i></span>
                                <div>
                                    <strong><?php echo e($a['title']); ?></strong>
                                    <p><?php echo (int) $a['recipients']; ?> recipient(s) &bull; <?php echo e(date('M j, Y g:i A', strtotime((string) $a['sent_at']))); ?></p>
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
