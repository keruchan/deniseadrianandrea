<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

require_role('student');

$studentId = current_student_id($pdo);
$userId = (int) ($_SESSION['id'] ?? 0);
if ($studentId === null || $userId <= 0) {
    redirect_to('pages/auth/logout.php');
}

$profileErrors = [];
$passwordErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['settings_action'] ?? '');

    if ($action === 'update_profile') {
        if (!csrf_is_valid('csrf_student_profile_token', (string) ($_POST['csrf_token'] ?? ''))) {
            $profileErrors[] = 'Your session expired. Please try again.';
        } else {
            $first = trim((string) ($_POST['first_name'] ?? ''));
            $middle = trim((string) ($_POST['middle_name'] ?? ''));
            $last = trim((string) ($_POST['last_name'] ?? ''));
            $contact = trim((string) ($_POST['contact'] ?? ''));

            if ($first === '' || mb_strlen($first) > 100) {
                $profileErrors[] = 'First name is required and must not exceed 100 characters.';
            }
            if ($last === '' || mb_strlen($last) > 100) {
                $profileErrors[] = 'Last name is required and must not exceed 100 characters.';
            }
            if (mb_strlen($middle) > 100) {
                $profileErrors[] = 'Middle name must not exceed 100 characters.';
            }
            if ($contact !== '' && !preg_match('/^[0-9+()\-\s]{6,20}$/', $contact)) {
                $profileErrors[] = 'Contact number must be 6-20 digits and may include + ( ) - and spaces.';
            }

            if (empty($profileErrors)) {
                $stmt = $pdo->prepare(
                    'UPDATE students SET first_name = :f, middle_name = :m, last_name = :l, contact = :c WHERE id = :id'
                );
                $stmt->execute([
                    ':f' => $first,
                    ':m' => $middle !== '' ? $middle : null,
                    ':l' => $last,
                    ':c' => $contact !== '' ? $contact : null,
                    ':id' => $studentId,
                ]);
                rotate_csrf_token('csrf_student_profile_token');
                $_SESSION['settings_success'] = 'Your profile was updated.';
                redirect_to('pages/student/settings.php');
            }
        }
    } elseif ($action === 'change_password') {
        if (!csrf_is_valid('csrf_student_password_token', (string) ($_POST['csrf_token'] ?? ''))) {
            $passwordErrors[] = 'Your session expired. Please try again.';
        } else {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            $hashStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id LIMIT 1');
            $hashStmt->execute([':id' => $userId]);
            $currentHash = (string) $hashStmt->fetchColumn();

            if ($currentHash === '' || !password_verify($current, $currentHash)) {
                $passwordErrors[] = 'Your current password is incorrect.';
            } elseif (strlen($new) < 8 || strlen($new) > 128) {
                $passwordErrors[] = 'New password must be between 8 and 128 characters.';
            } elseif ($new !== $confirm) {
                $passwordErrors[] = 'New password and confirmation do not match.';
            } elseif ($new === $current) {
                $passwordErrors[] = 'New password must be different from your current password.';
            }

            if (empty($passwordErrors)) {
                $upd = $pdo->prepare('UPDATE users SET password = :p WHERE id = :id');
                $upd->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $userId]);
                rotate_csrf_token('csrf_student_password_token');
                $_SESSION['settings_success'] = 'Your password was changed.';
                redirect_to('pages/student/settings.php');
            }
        }
    }
}

// Current profile for display.
$profileStmt = $pdo->prepare(
    'SELECT s.student_no, s.first_name, s.middle_name, s.last_name, s.contact, u.username, u.email
     FROM students s INNER JOIN users u ON u.id = s.user_id
     WHERE s.id = :id LIMIT 1'
);
$profileStmt->execute([':id' => $studentId]);
$profile = $profileStmt->fetch() ?: [];

$successFlash = $_SESSION['settings_success'] ?? null;
unset($_SESSION['settings_success']);
$profileCsrf = csrf_token('csrf_student_profile_token');
$passwordCsrf = csrf_token('csrf_student_password_token');

render_dashboard_page([
    'role_label' => 'Student',
    'fallback_name' => 'Student',
    'title' => 'Settings',
    'eyebrow' => 'Account',
    'description' => 'Manage your profile details and password.',
    'active_route' => 'student.settings',
    'menu' => student_sidebar_menu(),
    'content' => function () use ($profile, $profileErrors, $passwordErrors, $successFlash, $profileCsrf, $passwordCsrf) {
        ?>
        <?php if ($successFlash): ?>
            <div class="alert alert-success mt-4" role="alert"><?php echo e($successFlash); ?></div>
        <?php endif; ?>

        <section class="content-grid two-columns mt-4">
            <article class="form-panel">
                <div class="section-heading"><h2>Profile</h2><span>Your details</span></div>

                <?php if (!empty($profileErrors)): ?>
                    <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($profileErrors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <div class="settings-readonly">
                    <div><span>Student number</span><strong><?php echo e($profile['student_no'] ?? '—'); ?></strong></div>
                    <div><span>Username</span><strong><?php echo e($profile['username'] ?? '—'); ?></strong></div>
                    <div><span>Email</span><strong><?php echo e($profile['email'] ?? '—'); ?></strong></div>
                </div>

                <form method="post" action="<?php echo e(url_for('pages/student/settings.php')); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($profileCsrf); ?>">
                    <input type="hidden" name="settings_action" value="update_profile">
                    <div class="form-grid">
                        <div class="field">
                            <label class="form-label" for="first_name">First name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" maxlength="100" value="<?php echo e($profile['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="field">
                            <label class="form-label" for="middle_name">Middle name <span class="text-secondary">(optional)</span></label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name" maxlength="100" value="<?php echo e($profile['middle_name'] ?? ''); ?>">
                        </div>
                        <div class="field">
                            <label class="form-label" for="last_name">Last name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" maxlength="100" value="<?php echo e($profile['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="field">
                            <label class="form-label" for="contact">Contact number <span class="text-secondary">(optional)</span></label>
                            <input type="text" class="form-control" id="contact" name="contact" maxlength="20" value="<?php echo e($profile['contact'] ?? ''); ?>" placeholder="e.g. 0917 123 4567">
                        </div>
                    </div>
                    <button class="btn btn-edupredict mt-2" type="submit"><i class="bi bi-check2-circle"></i> Save profile</button>
                </form>
            </article>

            <article class="form-panel">
                <div class="section-heading"><h2>Change Password</h2><span>Security</span></div>

                <?php if (!empty($passwordErrors)): ?>
                    <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($passwordErrors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="post" action="<?php echo e(url_for('pages/student/settings.php')); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($passwordCsrf); ?>">
                    <input type="hidden" name="settings_action" value="change_password">
                    <div class="field">
                        <label class="form-label" for="current_password">Current password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="field mt-2">
                        <label class="form-label" for="new_password">New password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password" required>
                        <small class="text-secondary">At least 8 characters.</small>
                    </div>
                    <div class="field mt-2">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-edupredict mt-3" type="submit"><i class="bi bi-shield-lock"></i> Update password</button>
                </form>
            </article>
        </section>
        <?php
    },
]);
