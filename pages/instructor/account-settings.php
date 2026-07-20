<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/sidebar_config.php';
require_once __DIR__ . '/../../includes/dashboard_layout.php';

if (empty($_SESSION['id']) || empty($_SESSION['role'])) {
    redirect_to('pages/auth/login.php');
}

$userId = (int) $_SESSION['id'];
$role = (string) $_SESSION['role'];
$profileMap = [
    'administrator' => ['table' => 'administrators', 'id_label' => 'Employee number', 'id_column' => 'employee_no'],
    'instructor' => ['table' => 'instructors', 'id_label' => 'Employee number', 'id_column' => 'employee_no'],
    'student' => ['table' => 'students', 'id_label' => 'Student number', 'id_column' => 'student_no'],
];

if (!isset($profileMap[$role])) {
    redirect_to('pages/auth/logout.php');
}

$profileMeta = $profileMap[$role];
$profileErrors = [];
$passwordErrors = [];
$successMessage = '';

if (!empty($_SESSION['account_settings_success'])) {
    $successMessage = (string) $_SESSION['account_settings_success'];
    unset($_SESSION['account_settings_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['settings_action'] ?? '');

    if ($action === 'update_profile') {
        if (!csrf_is_valid('csrf_account_profile_token', (string) ($_POST['csrf_token'] ?? ''))) {
            $profileErrors[] = 'Your session expired. Please try again.';
        } else {
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $first = trim((string) ($_POST['first_name'] ?? ''));
            $middle = trim((string) ($_POST['middle_name'] ?? ''));
            $last = trim((string) ($_POST['last_name'] ?? ''));
            $contact = trim((string) ($_POST['contact'] ?? ''));

            if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                $profileErrors[] = 'Username must be 3-50 characters and may contain letters, numbers, dots, underscores, or hyphens.';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || text_length($email) > 255) {
                $profileErrors[] = 'Enter a valid email address.';
            }
            if ($first === '' || text_length($first) > 100) {
                $profileErrors[] = 'First name is required and must not exceed 100 characters.';
            }
            if ($last === '' || text_length($last) > 100) {
                $profileErrors[] = 'Last name is required and must not exceed 100 characters.';
            }
            if (text_length($middle) > 100) {
                $profileErrors[] = 'Middle name must not exceed 100 characters.';
            }
            if ($contact !== '' && !preg_match('/^[0-9+()\-\s]{6,20}$/', $contact)) {
                $profileErrors[] = 'Contact number must be 6-20 digits and may include + ( ) - and spaces.';
            }

            if (empty($profileErrors)) {
                $uniqueStmt = $pdo->prepare(
                    'SELECT username, email
                     FROM users
                     WHERE id <> :id AND (username = :username OR email = :email)
                     LIMIT 1'
                );
                $uniqueStmt->execute([
                    ':id' => $userId,
                    ':username' => $username,
                    ':email' => $email,
                ]);
                $existing = $uniqueStmt->fetch();

                if ($existing && strcasecmp((string) $existing['username'], $username) === 0) {
                    $profileErrors[] = 'That username is already taken.';
                }
                if ($existing && strcasecmp((string) $existing['email'], $email) === 0) {
                    $profileErrors[] = 'That email address is already used.';
                }
            }

            if (empty($profileErrors)) {
                try {
                    $pdo->beginTransaction();

                    $userStmt = $pdo->prepare('UPDATE users SET username = :username, email = :email WHERE id = :id');
                    $userStmt->execute([
                        ':username' => $username,
                        ':email' => $email,
                        ':id' => $userId,
                    ]);

                    $profileStmt = $pdo->prepare(
                        'UPDATE ' . $profileMeta['table'] . '
                         SET first_name = :first_name,
                             middle_name = :middle_name,
                             last_name = :last_name,
                             contact = :contact
                         WHERE user_id = :user_id'
                    );
                    $profileStmt->execute([
                        ':first_name' => $first,
                        ':middle_name' => $middle !== '' ? $middle : null,
                        ':last_name' => $last,
                        ':contact' => $contact !== '' ? $contact : null,
                        ':user_id' => $userId,
                    ]);

                    $pdo->commit();

                    $_SESSION['username'] = $username;
                    $_SESSION['name'] = trim($first . ' ' . $last);
                    rotate_csrf_token('csrf_account_profile_token');
                    $_SESSION['account_settings_success'] = 'Your account details were updated.';
                    redirect_to('settings/account');
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[EDUPREDICT ACCOUNT SETTINGS ERROR] ' . $e->getMessage());
                    $profileErrors[] = 'Unable to update your account right now. Please try again.';
                }
            }
        }
    } elseif ($action === 'change_password') {
        if (!csrf_is_valid('csrf_account_password_token', (string) ($_POST['csrf_token'] ?? ''))) {
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
                $upd = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $upd->execute([
                    ':password' => password_hash($new, PASSWORD_DEFAULT),
                    ':id' => $userId,
                ]);
                rotate_csrf_token('csrf_account_password_token');
                $_SESSION['account_settings_success'] = 'Your password was changed.';
                redirect_to('settings/account');
            }
        }
    }
}

$profileStmt = $pdo->prepare(
    'SELECT u.username, u.email, p.' . $profileMeta['id_column'] . ' AS profile_no,
            p.first_name, p.middle_name, p.last_name, p.contact
     FROM users u
     INNER JOIN ' . $profileMeta['table'] . ' p ON p.user_id = u.id
     WHERE u.id = :id
     LIMIT 1'
);
$profileStmt->execute([':id' => $userId]);
$profile = $profileStmt->fetch() ?: [];

$profileCsrf = csrf_token('csrf_account_profile_token');
$passwordCsrf = csrf_token('csrf_account_password_token');

if ($role === 'administrator') {
    $menu = admin_sidebar_menu();
    $roleLabel = 'Administrator';
    $fallbackName = 'Administrator';
} elseif ($role === 'instructor') {
    $instructorId = current_instructor_id($pdo);
    $menu = instructor_sidebar_menu($instructorId !== null ? instructor_sidebar_classes($pdo, $instructorId) : []);
    $roleLabel = 'Instructor';
    $fallbackName = 'Instructor';
} else {
    $menu = student_sidebar_menu();
    $roleLabel = 'Student';
    $fallbackName = 'Student';
}

render_dashboard_page([
    'role_label' => $roleLabel,
    'fallback_name' => $fallbackName,
    'title' => 'Account Settings',
    'eyebrow' => 'Account',
    'description' => 'Update your username, personal information, and password.',
    'active_route' => 'settings.account',
    'menu' => $menu,
    'content' => function () use ($profile, $profileMeta, $profileErrors, $passwordErrors, $successMessage, $profileCsrf, $passwordCsrf) {
        ?>
        <?php if ($successMessage !== ''): ?>
            <div class="alert alert-success mt-4" role="alert"><?php echo e($successMessage); ?></div>
        <?php endif; ?>

        <section class="content-grid two-columns mt-4">
            <article class="form-panel">
                <div class="section-heading"><h2>Personal Information</h2><span>Profile</span></div>

                <?php if (!empty($profileErrors)): ?>
                    <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($profileErrors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <div class="settings-readonly">
                    <div><span><?php echo e($profileMeta['id_label']); ?></span><strong><?php echo e($profile['profile_no'] ?? '—'); ?></strong></div>
                </div>

                <form method="post" action="<?php echo e(url_for('settings/account')); ?>" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($profileCsrf); ?>">
                    <input type="hidden" name="settings_action" value="update_profile">
                    <div class="form-grid">
                        <div class="field">
                            <label class="form-label" for="username">Username</label>
                            <input type="text" class="form-control" id="username" name="username" maxlength="50" value="<?php echo e($profile['username'] ?? ''); ?>" autocomplete="username" required>
                        </div>
                        <div class="field">
                            <label class="form-label" for="email">Email</label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="255" value="<?php echo e($profile['email'] ?? ''); ?>" autocomplete="email" required>
                        </div>
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
                    <button class="btn btn-edupredict mt-2" type="submit"><i class="bi bi-check2-circle"></i> Save account</button>
                </form>
            </article>

            <article class="form-panel">
                <div class="section-heading"><h2>Change Password</h2><span>Security</span></div>

                <?php if (!empty($passwordErrors)): ?>
                    <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($passwordErrors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="post" action="<?php echo e(url_for('settings/account')); ?>" novalidate>
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
