<?php
/**
 * Shared helpers for EDUPREDICT pages.
 */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function url_for(string $path = ''): string
{
    $base = rtrim(APP_BASE_PATH, '/');
    $cleanPath = ltrim($path, '/');

    return $cleanPath === '' ? $base . '/' : $base . '/' . $cleanPath;
}

function redirect_to(string $path): void
{
    header('Location: ' . url_for($path));
    exit;
}

function csrf_token(string $sessionKey): string
{
    if (empty($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION[$sessionKey];
}

function csrf_is_valid(string $sessionKey, string $submittedToken): bool
{
    $sessionToken = (string) ($_SESSION[$sessionKey] ?? '');

    return $sessionToken !== '' && $submittedToken !== '' && hash_equals($sessionToken, $submittedToken);
}

function rotate_csrf_token(string $sessionKey): void
{
    $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
}

function dashboard_path_for_role(string $role): string
{
    $routes = [
        'administrator' => 'pages/administrator/dashboard.php',
        'instructor'    => 'pages/instructor/dashboard.php',
        'student'       => 'pages/student/dashboard.php',
    ];

    return $routes[$role] ?? '';
}

function redirect_by_role(string $role): bool
{
    $path = dashboard_path_for_role($role);

    if ($path !== '') {
        redirect_to($path);
    }

    return false;
}

function require_role(string $requiredRole): void
{
    if (empty($_SESSION['id']) || empty($_SESSION['role'])) {
        redirect_to('pages/auth/login.php');
    }

    if ($_SESSION['role'] !== $requiredRole) {
        $redirectPath = dashboard_path_for_role((string) $_SESSION['role']);
        redirect_to($redirectPath !== '' ? $redirectPath : 'pages/auth/logout.php');
    }
}

function role_id_for_key(PDO $pdo, string $roleKey): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM user_roles WHERE role_key = :role_key LIMIT 1');
    $stmt->execute([':role_key' => $roleKey]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function current_display_name(string $fallback = 'User'): string
{
    $name = trim((string) ($_SESSION['name'] ?? ''));

    return $name !== '' ? $name : $fallback;
}

function first_initial(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'U';
    }

    return strtoupper(substr($name, 0, 1));
}
