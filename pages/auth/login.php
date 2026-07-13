<?php
/**
 * Secure login page for Administrator, Instructor, and Student users.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (!empty($_SESSION['id']) && !empty($_SESSION['role'])) {
    redirect_by_role((string) $_SESSION['role']);
}

$errors = [];
$loginIdentifier = '';
$csrfToken = csrf_token('csrf_login_token');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginIdentifier = trim((string) ($_POST['login_identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!csrf_is_valid('csrf_login_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($loginIdentifier === '') {
        $errors[] = 'Username or email is required.';
    } elseif (strlen($loginIdentifier) > 150) {
        $errors[] = 'Username or email must not exceed 150 characters.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT
                    u.id,
                    u.username,
                    u.email,
                    u.password,
                    u.status,
                    r.role_key,
                    COALESCE(
                        CONCAT(a.first_name, " ", a.last_name),
                        CONCAT(i.first_name, " ", i.last_name),
                        CONCAT(s.first_name, " ", s.last_name),
                        u.username
                    ) AS display_name
                 FROM users u
                 INNER JOIN user_roles r ON r.id = u.role_id
                 LEFT JOIN administrators a ON a.user_id = u.id
                 LEFT JOIN instructors i ON i.user_id = u.id
                 LEFT JOIN students s ON s.user_id = u.id
                 WHERE u.username = :username_login OR u.email = :email_login
                 LIMIT 1'
            );
            $stmt->execute([
                ':username_login' => $loginIdentifier,
                ':email_login'    => $loginIdentifier,
            ]);

            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string) $user['password'])) {
                $errors[] = 'Invalid username/email or password.';
            } elseif ($user['status'] === 'pending') {
                $errors[] = 'Your account is still pending approval by the administrator.';
            } elseif ($user['status'] === 'disabled') {
                $errors[] = 'Your account has been disabled. Please contact the administrator.';
            } elseif ($user['status'] !== 'active') {
                $errors[] = 'Your account cannot sign in at this time.';
            } else {
                session_regenerate_id(true);

                $_SESSION['id'] = (int) $user['id'];
                $_SESSION['username'] = (string) $user['username'];
                $_SESSION['name'] = trim((string) $user['display_name']);
                $_SESSION['role'] = (string) $user['role_key'];

                unset($_SESSION['csrf_login_token']);

                $updateLogin = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
                $updateLogin->execute([':id' => (int) $user['id']]);

                if (redirect_by_role((string) $user['role_key'])) {
                    exit;
                }

                error_log('[EDUPREDICT LOGIN ERROR] Unknown role for user ID ' . (int) $user['id']);
                unset($_SESSION['id'], $_SESSION['username'], $_SESSION['name'], $_SESSION['role']);
                rotate_csrf_token('csrf_login_token');
                $errors[] = 'Unable to determine your dashboard. Please contact the administrator.';
            }
        } catch (PDOException $e) {
            error_log('[EDUPREDICT LOGIN ERROR] ' . $e->getMessage());
            $errors[] = 'Unable to sign in at this time. Please try again later.';
        }
    }

    $csrfToken = csrf_token('csrf_login_token');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EDUPREDICT | Login</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(url_for('css/auth.css')); ?>">
</head>
<body>
    <main class="auth-shell">
        <section class="auth-card" aria-label="Login form">
            <div class="auth-side">
                <a class="auth-brand" href="<?php echo e(url_for('pages/index.html')); ?>">
                    <span><i class="bi bi-bar-chart-steps"></i></span>
                    EDUPREDICT
                </a>
                <div>
                    <p class="section-label">Secure academic access</p>
                    <h1>Performance monitoring starts here.</h1>
                    <p>Sign in to continue to your assigned workspace for classes, progress, and prediction-ready academic records.</p>
                </div>
                <div class="auth-footnote">Administrator, Instructor, and Student access</div>
            </div>

            <div class="auth-form-panel">
                <p class="section-label">Login</p>
                <h2>Welcome back</h2>
                <p class="form-copy">Use your username or email address.</p>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="login.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                    <div class="mb-3">
                        <label for="login_identifier" class="form-label">Username or email</label>
                        <input type="text" class="form-control" id="login_identifier" name="login_identifier" value="<?php echo e($loginIdentifier); ?>" maxlength="150" autocomplete="username" required>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn btn-edupredict w-100"><i class="bi bi-box-arrow-in-right"></i> Login</button>
                </form>

                <p class="switch-link">Need a student account? <a href="register.php">Register here</a></p>
            </div>
        </section>
    </main>
</body>
</html>
