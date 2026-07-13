<?php
/**
 * Public Student registration page.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/helpers.php';

$errors = [];
$successMessage = '';
$formData = [
    'first_name'  => '',
    'middle_name' => '',
    'last_name'   => '',
    'student_no'  => '',
    'email'       => '',
    'contact'     => '',
    'username'    => '',
];

$csrfToken = csrf_token('csrf_register_token');

if (!empty($_SESSION['register_success'])) {
    $successMessage = (string) $_SESSION['register_success'];
    unset($_SESSION['register_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formData as $key => $value) {
        $formData[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!csrf_is_valid('csrf_register_token', $submittedToken)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }

    if ($formData['first_name'] === '') {
        $errors[] = 'First name is required.';
    } elseif (text_length($formData['first_name']) > 100) {
        $errors[] = 'First name must not exceed 100 characters.';
    }

    if ($formData['middle_name'] !== '' && text_length($formData['middle_name']) > 100) {
        $errors[] = 'Middle name must not exceed 100 characters.';
    }

    if ($formData['last_name'] === '') {
        $errors[] = 'Last name is required.';
    } elseif (text_length($formData['last_name']) > 100) {
        $errors[] = 'Last name must not exceed 100 characters.';
    }

    if ($formData['student_no'] !== '' && text_length($formData['student_no']) > 50) {
        $errors[] = 'Student number must not exceed 50 characters.';
    }

    if ($formData['email'] === '') {
        $errors[] = 'Email address is required.';
    } elseif (text_length($formData['email']) > 150) {
        $errors[] = 'Email address must not exceed 150 characters.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($formData['contact'] !== '' && !preg_match('/^[0-9+\-\s().]{7,20}$/', $formData['contact'])) {
        $errors[] = 'Contact number may contain only numbers, spaces, +, -, parentheses, and periods.';
    }

    if ($formData['username'] === '') {
        $errors[] = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $formData['username'])) {
        $errors[] = 'Username must be 3-50 characters and may contain letters, numbers, underscores, periods, or hyphens.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    } elseif (strlen($password) > 128) {
        $errors[] = 'Password must not exceed 128 characters.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Please confirm your password.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Password and confirmation password do not match.';
    }

    if (empty($errors)) {
        try {
            $studentRoleId = role_id_for_key($pdo, 'student');

            if ($studentRoleId === null) {
                $errors[] = 'Student role is missing. Please run the database schema first.';
            } else {
                $checkStmt = $pdo->prepare(
                    'SELECT username, email FROM users WHERE username = :username OR email = :email'
                );
                $checkStmt->execute([
                    ':username' => $formData['username'],
                    ':email'    => $formData['email'],
                ]);

                foreach ($checkStmt->fetchAll() as $existingUser) {
                    if (isset($existingUser['username']) && strcasecmp($existingUser['username'], $formData['username']) === 0) {
                        $errors[] = 'Username is already taken.';
                    }

                    if (isset($existingUser['email']) && strcasecmp($existingUser['email'], $formData['email']) === 0) {
                        $errors[] = 'Email address is already registered.';
                    }
                }
            }

            if (empty($errors)) {
                $pdo->beginTransaction();

                $insertUserStmt = $pdo->prepare(
                    'INSERT INTO users (role_id, username, email, password, status)
                     VALUES (:role_id, :username, :email, :password, :status)'
                );
                $insertUserStmt->execute([
                    ':role_id'  => $studentRoleId,
                    ':username' => $formData['username'],
                    ':email'    => $formData['email'],
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':status'   => 'pending',
                ]);

                $userId = (int) $pdo->lastInsertId();

                $insertStudentStmt = $pdo->prepare(
                    'INSERT INTO students (user_id, student_no, first_name, middle_name, last_name, contact)
                     VALUES (:user_id, :student_no, :first_name, :middle_name, :last_name, :contact)'
                );
                $insertStudentStmt->execute([
                    ':user_id'     => $userId,
                    ':student_no'  => $formData['student_no'] !== '' ? $formData['student_no'] : null,
                    ':first_name'  => $formData['first_name'],
                    ':middle_name' => $formData['middle_name'] !== '' ? $formData['middle_name'] : null,
                    ':last_name'   => $formData['last_name'],
                    ':contact'     => $formData['contact'] !== '' ? $formData['contact'] : null,
                ]);

                $pdo->commit();

                rotate_csrf_token('csrf_register_token');
                $_SESSION['register_success'] = 'Student registration submitted successfully. Your account is pending administrator approval.';

                header('Location: register.php');
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[EDUPREDICT REGISTER ERROR] ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = 'Username, email, or student number is already registered.';
            } else {
                $errors[] = 'Unable to submit registration at this time. Please try again later.';
            }
        }
    }

    $csrfToken = csrf_token('csrf_register_token');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EDUPREDICT | Student Registration</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(url_for('css/auth.css')); ?>">
</head>
<body>
    <main class="auth-shell spacious">
        <section class="register-card" aria-label="Student registration form">
            <div class="register-intro">
                <a class="auth-brand dark" href="<?php echo e(url_for('pages/index.html')); ?>">
                    <span><i class="bi bi-bar-chart-steps"></i></span>
                    EDUPREDICT
                </a>
                <p class="section-label">Student access</p>
                <h1>Create your learner account</h1>
                <p>Student accounts begin as pending records so administrators can verify access before class enrollment begins.</p>
            </div>

            <div class="register-form-panel">
                <?php if ($successMessage !== ''): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo e($successMessage); ?>
                        <div class="mt-2"><a class="alert-link" href="login.php">Go to login</a></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger" role="alert">
                        <p class="fw-semibold mb-2">Please fix the following:</p>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="register.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="first_name" class="form-label">First name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo e($formData['first_name']); ?>" maxlength="100" autocomplete="given-name" required>
                        </div>
                        <div class="col-md-4">
                            <label for="middle_name" class="form-label">Middle name</label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo e($formData['middle_name']); ?>" maxlength="100" autocomplete="additional-name">
                        </div>
                        <div class="col-md-4">
                            <label for="last_name" class="form-label">Last name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo e($formData['last_name']); ?>" maxlength="100" autocomplete="family-name" required>
                        </div>
                        <div class="col-md-6">
                            <label for="student_no" class="form-label">Student number</label>
                            <input type="text" class="form-control" id="student_no" name="student_no" value="<?php echo e($formData['student_no']); ?>" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="Student" disabled>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo e($formData['email']); ?>" maxlength="150" autocomplete="email" required>
                        </div>
                        <div class="col-md-6">
                            <label for="contact" class="form-label">Contact number</label>
                            <input type="text" class="form-control" id="contact" name="contact" value="<?php echo e($formData['contact']); ?>" maxlength="20" autocomplete="tel">
                        </div>
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?php echo e($formData['username']); ?>" maxlength="50" autocomplete="username" required>
                        </div>
                        <div class="col-md-6"></div>
                        <div class="col-md-6">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" minlength="8" maxlength="128" autocomplete="new-password" required>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                        </div>
                        <div class="col-12 mt-4">
                            <button type="submit" class="btn btn-edupredict w-100"><i class="bi bi-person-plus"></i> Submit student registration</button>
                        </div>
                    </div>
                </form>

                <p class="switch-link">Already registered? <a href="login.php">Login to your account</a></p>
            </div>
        </section>
    </main>
</body>
</html>
