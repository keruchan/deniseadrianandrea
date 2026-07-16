<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/admin_management.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('administrator');

$adminUserId = (int) ($_SESSION['id'] ?? 0);
$errors = [];
$openCreate = false;
$editErrorUserId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['user_action'] ?? '');
    $targetId = (int) ($_POST['user_id'] ?? 0);

    if (!csrf_is_valid('csrf_admin_users_token', (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        // Guard: protect self + the last active administrator.
        $target = $targetId > 0 ? admin_get_user($pdo, $targetId) : null;
        $isSelf = $targetId === $adminUserId;
        $wouldStrandAdmin = static function () use ($pdo, $target): bool {
            return $target && $target['role_key'] === 'administrator' && admin_active_admin_count($pdo) <= 1;
        };

        if ($action === 'create_user') {
            $res = admin_create_user($pdo, $_POST);
            if ($res['ok']) {
                rotate_csrf_token('csrf_admin_users_token');
                $_SESSION['admin_success'] = 'Account created for ' . trim((string) $_POST['first_name'] . ' ' . (string) $_POST['last_name']) . '.';
                redirect_to('pages/administrator/users.php');
            }
            $errors = $res['errors'];
            $openCreate = true;
        } elseif ($action === 'update_user' && $target) {
            $res = admin_update_user($pdo, $targetId, $_POST);
            if ($res['ok']) {
                $newPw = (string) ($_POST['new_password'] ?? '');
                if ($newPw !== '') {
                    $pwRes = admin_reset_password($pdo, $targetId, $newPw);
                    if (!$pwRes['ok']) {
                        $errors = $pwRes['errors'];
                        $editErrorUserId = $targetId;
                    }
                }
                if (empty($errors)) {
                    rotate_csrf_token('csrf_admin_users_token');
                    $_SESSION['admin_success'] = 'Account updated.';
                    redirect_to('pages/administrator/users.php');
                }
            } else {
                $errors = $res['errors'];
                $editErrorUserId = $targetId;
            }
        } elseif ($action === 'set_status' && $target) {
            $status = (string) ($_POST['status'] ?? '');
            if (($status === 'disabled') && ($isSelf || $wouldStrandAdmin())) {
                $errors[] = 'You cannot disable your own account or the last active administrator.';
            } else {
                admin_set_user_status($pdo, $targetId, $status);
                rotate_csrf_token('csrf_admin_users_token');
                $_SESSION['admin_success'] = 'Account status updated.';
                redirect_to('pages/administrator/users.php');
            }
        } elseif ($action === 'delete_user' && $target) {
            if ($isSelf || $wouldStrandAdmin()) {
                $errors[] = 'You cannot delete your own account or the last active administrator.';
            } else {
                admin_delete_user($pdo, $targetId);
                rotate_csrf_token('csrf_admin_users_token');
                $_SESSION['admin_success'] = 'Account deleted.';
                redirect_to('pages/administrator/users.php');
            }
        }
    }
}

$fRole = isset(admin_role_options()[$_GET['role'] ?? '']) ? (string) $_GET['role'] : '';
$fStatus = in_array(($_GET['status'] ?? ''), ['active', 'pending', 'disabled'], true) ? (string) $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$userList = get_admin_users($pdo, $fRole, $fStatus, $q);

$successFlash = $_SESSION['admin_success'] ?? null;
unset($_SESSION['admin_success']);
$csrf = csrf_token('csrf_admin_users_token');
$roleOptions = admin_role_options();
$statusMeta = admin_status_meta();

$titleByRole = ['instructor' => 'Instructors', 'student' => 'Students', 'administrator' => 'Administrators'];
$pageTitle = $titleByRole[$fRole] ?? 'User Management';

function admin_user_form_fields(array $u = [], bool $isNew = false): void
{
    $roleOptions = admin_role_options();
    ?>
    <?php if ($isNew): ?>
        <div class="form-grid">
            <div class="field">
                <label class="form-label">Role</label>
                <select class="form-select" name="role" required>
                    <?php foreach ($roleOptions as $key => $label): ?>
                        <option value="<?php echo e($key); ?>" <?php echo ($u['role_key'] ?? 'student') === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" maxlength="50" value="<?php echo e($u['username'] ?? ''); ?>" required>
            </div>
        </div>
    <?php endif; ?>
    <div class="form-grid">
        <div class="field">
            <label class="form-label">First name</label>
            <input type="text" class="form-control" name="first_name" maxlength="100" value="<?php echo e($u['first_name'] ?? ''); ?>" required>
        </div>
        <div class="field">
            <label class="form-label">Middle name <span class="text-secondary">(optional)</span></label>
            <input type="text" class="form-control" name="middle_name" maxlength="100" value="<?php echo e($u['middle_name'] ?? ''); ?>">
        </div>
        <div class="field">
            <label class="form-label">Last name</label>
            <input type="text" class="form-control" name="last_name" maxlength="100" value="<?php echo e($u['last_name'] ?? ''); ?>" required>
        </div>
        <div class="field">
            <label class="form-label">ID / Employee / Student no.</label>
            <input type="text" class="form-control" name="ident_no" maxlength="50" value="<?php echo e($u['ident_no'] ?? ''); ?>">
        </div>
        <div class="field">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="email" maxlength="150" value="<?php echo e($u['email'] ?? ''); ?>" required>
        </div>
        <div class="field">
            <label class="form-label">Contact <span class="text-secondary">(optional)</span></label>
            <input type="text" class="form-control" name="contact" maxlength="20" value="<?php echo e($u['contact'] ?? ''); ?>">
        </div>
        <div class="field field-wide">
            <label class="form-label">Department <span class="text-secondary">(instructors only)</span></label>
            <input type="text" class="form-control" name="department" maxlength="120" value="<?php echo e($u['department'] ?? ''); ?>">
        </div>
    </div>
    <?php
}

render_dashboard_page([
    'role_label' => 'Administrator',
    'fallback_name' => 'Administrator',
    'title' => $pageTitle,
    'eyebrow' => 'Management',
    'description' => 'Create, edit, approve, disable, and reset accounts across the institution.',
    'active_route' => $fRole === 'instructor' ? 'admin.instructors' : ($fRole === 'student' ? 'admin.students' : 'admin.users'),
    'menu' => admin_sidebar_menu(),
    'content' => function () use ($userList, $fRole, $fStatus, $q, $errors, $successFlash, $csrf, $roleOptions, $statusMeta, $openCreate, $editErrorUserId, $adminUserId) {
        ?>
        <?php if ($successFlash): ?>
            <div class="alert alert-success mt-4" role="alert"><?php echo e($successFlash); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mt-4" role="alert"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($openCreate): ?><span data-auto-open-modal="#createUserModal" hidden></span><?php endif; ?>
        <?php if ($editErrorUserId > 0): ?><span data-auto-open-modal="#editUserModal-<?php echo (int) $editErrorUserId; ?>" hidden></span><?php endif; ?>

        <div class="section-heading mt-4">
            <h2><?php echo e($fRole !== '' ? ($roleOptions[$fRole] . 's') : 'All Users'); ?> <span class="text-secondary">(<?php echo (int) count($userList); ?>)</span></h2>
            <button class="btn btn-edupredict" type="button" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="bi bi-person-plus"></i> Create account</button>
        </div>

        <form method="get" action="<?php echo e(url_for('pages/administrator/users.php')); ?>" class="insights-filter-bar">
            <div class="insights-filter-fields">
                <div class="field">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role" onchange="this.form.submit()">
                        <option value="">All roles</option>
                        <?php foreach ($roleOptions as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $fRole === $key ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <option value="active" <?php echo $fStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="pending" <?php echo $fStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="disabled" <?php echo $fStatus === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                </div>
                <div class="field field-wide">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Name, username, email, or ID">
                </div>
            </div>
            <div class="insights-filter-actions">
                <button class="btn btn-edupredict" type="submit"><i class="bi bi-search"></i> Search</button>
                <a class="btn btn-copy" href="<?php echo e(url_for('pages/administrator/users.php')); ?>"><i class="bi bi-x-circle"></i> Reset</a>
            </div>
        </form>

        <article class="widget-panel mt-4">
            <?php if (empty($userList)): ?>
                <div class="empty-state"><i class="bi bi-people"></i><span>No users match these filters.</span></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table insights-table">
                        <thead><tr><th>Name</th><th>Role</th><th>ID / No.</th><th>Email</th><th>Classes</th><th>Status</th><th>Last login</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($userList as $u): ?>
                            <?php [$sl, $st, $si] = $statusMeta[$u['status']] ?? ['Active', 'tone-emerald', 'bi-check-circle']; ?>
                            <tr>
                                <td><strong><?php echo e($u['name'] ?: $u['username']); ?></strong><span class="insights-sub">@<?php echo e($u['username']); ?><?php echo (int) $u['id'] === $adminUserId ? ' &bull; you' : ''; ?></span></td>
                                <td><span class="status-pill small tone-slate"><?php echo e($u['role_name']); ?></span></td>
                                <td><?php echo e($u['ident_no'] ?: '—'); ?></td>
                                <td><span class="insights-sub"><?php echo e($u['email']); ?></span></td>
                                <td><?php echo $u['class_count'] === null ? '—' : (int) $u['class_count']; ?></td>
                                <td><span class="status-pill small <?php echo $st; ?>"><i class="bi <?php echo $si; ?>"></i> <?php echo e($sl); ?></span></td>
                                <td><span class="insights-sub"><?php echo $u['last_login_at'] ? e(date('M j, Y', strtotime((string) $u['last_login_at']))) : 'Never'; ?></span></td>
                                <td class="admin-row-actions">
                                    <button class="btn btn-copy btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#editUserModal-<?php echo (int) $u['id']; ?>" aria-label="Edit"><i class="bi bi-pencil-square"></i></button>
                                    <?php if ($u['status'] === 'pending'): ?>
                                        <form method="post" action="<?php echo e(url_for('pages/administrator/users.php')); ?>" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>"><input type="hidden" name="user_action" value="set_status"><input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>"><input type="hidden" name="status" value="active">
                                            <button class="btn btn-copy btn-sm text-success" type="submit" aria-label="Approve" title="Approve"><i class="bi bi-check-circle"></i></button>
                                        </form>
                                    <?php elseif ($u['status'] === 'active'): ?>
                                        <?php if ((int) $u['id'] !== $adminUserId): ?>
                                        <form method="post" action="<?php echo e(url_for('pages/administrator/users.php')); ?>" class="d-inline" data-confirm-action="Disable <?php echo e($u['name'] ?: $u['username']); ?>'s account? They will not be able to sign in.">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>"><input type="hidden" name="user_action" value="set_status"><input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>"><input type="hidden" name="status" value="disabled">
                                            <button class="btn btn-copy btn-sm text-amber" type="submit" aria-label="Disable" title="Disable"><i class="bi bi-slash-circle"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="post" action="<?php echo e(url_for('pages/administrator/users.php')); ?>" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>"><input type="hidden" name="user_action" value="set_status"><input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>"><input type="hidden" name="status" value="active">
                                            <button class="btn btn-copy btn-sm text-success" type="submit" aria-label="Enable" title="Enable"><i class="bi bi-arrow-clockwise"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int) $u['id'] !== $adminUserId): ?>
                                        <form method="post" action="<?php echo e(url_for('pages/administrator/users.php')); ?>" class="d-inline" data-confirm-action="Permanently delete <?php echo e($u['name'] ?: $u['username']); ?>'s account and all their data? This cannot be undone.">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>"><input type="hidden" name="user_action" value="delete_user"><input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                            <button class="btn btn-copy btn-sm btn-danger-soft" type="submit" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <!-- Create modal -->
        <div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" action="<?php echo e(url_for('pages/administrator/users.php')); ?>" novalidate>
                        <div class="modal-header"><h2 class="modal-title h5">Create account</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                            <input type="hidden" name="user_action" value="create_user">
                            <?php admin_user_form_fields(['role_key' => $fRole ?: 'student'], true); ?>
                            <div class="form-grid">
                                <div class="field">
                                    <label class="form-label">Temporary password</label>
                                    <input type="text" class="form-control" name="password" minlength="8" maxlength="128" required placeholder="At least 8 characters">
                                </div>
                                <div class="field">
                                    <label class="form-label">Initial status</label>
                                    <select class="form-select" name="status">
                                        <option value="active" selected>Active</option>
                                        <option value="pending">Pending</option>
                                        <option value="disabled">Disabled</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-edupredict"><i class="bi bi-person-plus"></i> Create account</button></div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit modals -->
        <?php foreach ($userList as $u): ?>
            <div class="modal fade" id="editUserModal-<?php echo (int) $u['id']; ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <form method="post" action="<?php echo e(url_for('pages/administrator/users.php')); ?>" novalidate>
                            <div class="modal-header"><h2 class="modal-title h5">Edit <?php echo e($u['name'] ?: $u['username']); ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                                <input type="hidden" name="user_action" value="update_user">
                                <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                <p class="insights-sub mb-3"><?php echo e($u['role_name']); ?> &bull; @<?php echo e($u['username']); ?></p>
                                <?php admin_user_form_fields($u, false); ?>
                                <div class="form-grid">
                                    <div class="field">
                                        <label class="form-label">Status</label>
                                        <select class="form-select" name="status" <?php echo (int) $u['id'] === $adminUserId ? 'disabled' : ''; ?>>
                                            <option value="active" <?php echo $u['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="pending" <?php echo $u['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="disabled" <?php echo $u['status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                        <?php if ((int) $u['id'] === $adminUserId): ?><input type="hidden" name="status" value="active"><small class="text-secondary">You can't change your own status.</small><?php endif; ?>
                                    </div>
                                    <div class="field">
                                        <label class="form-label">Reset password <span class="text-secondary">(optional)</span></label>
                                        <input type="text" class="form-control" name="new_password" minlength="8" maxlength="128" placeholder="Leave blank to keep current">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-copy" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-edupredict"><i class="bi bi-check2-circle"></i> Save changes</button></div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php
    },
]);
