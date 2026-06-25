<?php
/**
 * Password-change auth invalidation: remember-me tokens and session password fingerprints.
 */

function auth_password_fingerprint(string $storedPassword): string
{
    return substr((string) $storedPassword, 0, 32);
}

function auth_store_password_fingerprint(string $storedPassword): void
{
    if ($storedPassword === '') {
        return;
    }
    $_SESSION['password_fingerprint'] = auth_password_fingerprint($storedPassword);
}

function invalidate_user_remember_token(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'UPDATE user SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?'
        );
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log('invalidate_user_remember_token failed: ' . $e->getMessage());
    }
}

function auth_cookie_secure_flag(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

function clear_remember_token_cookie(): void
{
    $cookieParams = session_get_cookie_params();
    $secure = auth_cookie_secure_flag();

    setcookie('remember_token', '', [
        'expires' => time() - 42000,
        'path' => '/',
        'domain' => $cookieParams['domain'] ?: '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_session_password_stale(PDO $pdo): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    $userId = (int) $_SESSION['user_id'];
    if ($userId <= 0) {
        return false;
    }

    $userType = strtolower((string) ($_SESSION['user_type'] ?? ''));
    if ($userType === '') {
        $userType = isset($_SESSION['role']) && strtolower((string) $_SESSION['role']) === 'owner'
            ? 'owner'
            : 'user';
    }

    $storedPassword = null;

    try {
        if ($userType === 'member') {
            $stmt = $pdo->prepare('SELECT password FROM account WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $storedPassword = $stmt->fetchColumn();
        } elseif ($userType === 'owner') {
            $stmt = $pdo->prepare('SELECT password FROM owner WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $storedPassword = $stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare('SELECT password FROM user WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $storedPassword = $stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('auth_session_password_stale lookup failed: ' . $e->getMessage());
        return false;
    }

    if ($storedPassword === false || $storedPassword === null || $storedPassword === '') {
        return true;
    }

    $storedPassword = (string) $storedPassword;

    if (!isset($_SESSION['password_fingerprint'])) {
        auth_store_password_fingerprint($storedPassword);
        return false;
    }

    return auth_password_fingerprint($storedPassword) !== (string) $_SESSION['password_fingerprint'];
}

/**
 * Destroy session and remember-me cookie after password change elsewhere.
 */
function auth_force_logout_session(?PDO $pdo, bool $isApiRequest): void
{
    if ($pdo instanceof PDO && isset($_SESSION['user_id'])) {
        $userType = strtolower((string) ($_SESSION['user_type'] ?? ''));
        if ($userType === 'user' || $userType === '') {
            invalidate_user_remember_token($pdo, (int) $_SESSION['user_id']);
        }
    }

    if (function_exists('session_user_payload_cache_clear')) {
        session_user_payload_cache_clear();
    }

    $cookieParams = session_get_cookie_params();
    $secure = auth_cookie_secure_flag();

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

    clear_remember_token_cookie();

    $message = 'Password was changed. Please login again.';

    if ($isApiRequest) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $message,
            'redirect' => '/login',
            'data' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: /login');
    exit;
}
