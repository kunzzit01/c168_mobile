<?php
/**
 * Log out: clear remember-me, SPA session payload cache, and destroy PHP session.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/session_user_payload_cache.php';

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if ($userId > 0 && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('UPDATE user SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?');
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log('logout_api token cleanup failed: ' . $e->getMessage());
    }
}

session_user_payload_cache_clear();

$cookieParams = session_get_cookie_params();
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookieParams['path'] ?: '/',
            'domain' => $cookieParams['domain'] ?: '',
            'secure' => $secure,
            'httponly' => (bool) ($cookieParams['httponly'] ?? true),
            'samesite' => $cookieParams['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', [
        'expires' => time() - 42000,
        'path' => '/',
        'domain' => $cookieParams['domain'] ?: '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Logged out',
], JSON_UNESCAPED_UNICODE);
