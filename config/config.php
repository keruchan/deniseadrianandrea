<?php
/**
 * ============================================================
 * File     : config/config.php
 * Project  : EDUPREDICT - Academic Performance Monitoring and
 *            Prediction System
 * Purpose  : Central configuration file.
 *            - Starts the PHP session for authentication state.
 *            - Opens a shared PDO connection to MySQL.
 *            - Enforces UTF-8 and consistent error handling.
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

define('DISPLAY_ERRORS', false);

if (DISPLAY_ERRORS) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

// Update this if the project folder is renamed under htdocs.
define('APP_BASE_PATH', '');

define('DB_HOST', 'localhost');
define('DB_NAME', 'u763192172_edutrack');
define('DB_USER', 'u763192172_edutr');
define('DB_PASS', 'Romulorioqui44!');
define('DB_CHARSET', 'utf8mb4');

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    error_log('[EDUPREDICT DB CONNECTION ERROR] ' . $e->getMessage());
    die('Unable to connect to EDUPREDICT at this time. Please try again later.');
}

date_default_timezone_set('Asia/Manila');
