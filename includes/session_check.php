<?php
/**
 * 统一的Session检查和管理文件
 * 所有需要登录的页面都应该在开头包含此文件
 */

// 确保 session 已启动；设置 Cookie 为 SameSite=Lax，使同站 fetch POST 会带上 Cookie（避免无痕等环境下 403）
if (session_status() == PHP_SESSION_NONE) {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

// 检测是否为API请求（通过文件路径或请求头）
$currentFile = basename($_SERVER['PHP_SELF']);
$isApiRequest = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
) || (
    strpos($currentFile, 'api') !== false
);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_invalidation.php';

// 统一的超时时间（秒）- 1小时
define('SESSION_TIMEOUT', 3600);

// 检查remember me cookie自动登录
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $remember_token = $_COOKIE['remember_token'];
    
    try {
        // 验证remember token - 支持user表
        $stmt = $pdo->prepare("SELECT * FROM user WHERE remember_token = ? AND remember_token_expires > NOW() AND status = 'active'");
        $stmt->execute([$remember_token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // 重新建立session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['login_id'] = $user['login_id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_type'] = 'user';
            
            // 获取用户的 company_id（从 user_company_map 获取第一个，或使用 user 表中的 company_id）
            $company_id = null;
            try {
                // 优先从 user_company_map 获取第一个 company
                $stmt2 = $pdo->prepare("
                    SELECT c.id 
                    FROM company c
                    INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                    WHERE ucm.user_id = ?
                    ORDER BY c.company_id ASC
                    LIMIT 1
                ");
                $stmt2->execute([$user['id']]);
                $company_id = $stmt2->fetchColumn();
            } catch (PDOException $e) {
                error_log("获取用户 company 失败: " . $e->getMessage());
            }
            
            // 如果 user_company_map 中没有，尝试使用 user 表中的 company_id（向后兼容）
            if (!$company_id && isset($user['company_id'])) {
                $company_id = $user['company_id'];
            }
            
            $_SESSION['company_id'] = $company_id ? (int)$company_id : null;
            $_SESSION['last_activity'] = time();
            
            // 更新最后登录时间
            $stmt = $pdo->prepare("UPDATE user SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            if (!empty($user['password'])) {
                auth_store_password_fingerprint((string) $user['password']);
            }
        } else {
            // Token无效或过期，清除cookie
            setcookie('remember_token', '', time() - 3600, "/", "", false, true);
        }
    } catch (PDOException $e) {
        error_log("Remember me check error: " . $e->getMessage());
    }
}

// 检查用户是否已登录
if (isset($_SESSION['user_id'])) {
    if ($pdo instanceof PDO && auth_session_password_stale($pdo)) {
        auth_force_logout_session($pdo, $isApiRequest);
    }

    // 检查session超时（如果没有remember me的话）
    if (
        isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) &&
        !isset($_COOKIE['remember_token'])
    ) {
        // 清除 session
        session_unset();
        session_destroy();
        
        // 如果是API请求，返回JSON错误
        if ($isApiRequest) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode(['status' => 'error', 'message' => 'Session expired. Please login again.', 'redirect' => '/login']);
            exit();
        }
        
        // 重定向到登录页
        header("Location: /login");
        exit();
    }
    
    // 检查owner是否已通过二级密码验证（排除二级密码验证页面本身）
    $currentFile = basename($_SERVER['PHP_SELF']);
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
        if (!isset($_SESSION['secondary_password_verified']) || $_SESSION['secondary_password_verified'] !== true) {
            // Owner未通过二级密码验证，重定向到二级密码验证页面
            if ($isApiRequest) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode(['status' => 'error', 'message' => 'Secondary password verification required.', 'redirect' => '/owner-secondary-password']);
                exit();
            }
            
            header("Location: /owner-secondary-password");
            exit();
        }
    }
    
    // 检查user（c168公司）是否已通过二级密码验证（排除二级密码验证页面本身）
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'user') {
        // 检查是否是c168公司的用户
        $company_code = $_SESSION['company_code'] ?? null;
        $company_id = $_SESSION['company_id'] ?? null;
        $is_c168 = false;
        
        if ($company_code && strtoupper($company_code) === 'C168') {
            $is_c168 = true;
        } elseif ($company_id) {
            try {
                $stmt = $pdo->prepare("SELECT company_id FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
                $stmt->execute([$company_id]);
                if ($stmt->fetch()) {
                    $is_c168 = true;
                }
            } catch (PDOException $e) {
                error_log("Company check error in session_check: " . $e->getMessage());
            }
        }
        
        // 如果是c168公司的用户，检查是否设置了二级密码
        if ($is_c168) {
            try {
                $user_id = $_SESSION['user_id'];
                $stmt = $pdo->prepare("SELECT secondary_password FROM user WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // 如果用户设置了二级密码，则必须验证
                if ($user && !empty($user['secondary_password'])) {
                    if (!isset($_SESSION['secondary_password_verified']) || $_SESSION['secondary_password_verified'] !== true) {
                        // User未通过二级密码验证，重定向到二级密码验证页面
                        if ($isApiRequest) {
                            if (!headers_sent()) {
                                header('Content-Type: application/json');
                            }
                            echo json_encode(['status' => 'error', 'message' => 'Secondary password verification required.', 'redirect' => '/user-secondary-password']);
                            exit();
                        }
                        
                        header("Location: /user-secondary-password");
                        exit();
                    }
                }
            } catch (PDOException $e) {
                error_log("Secondary password check error: " . $e->getMessage());
            }
        }
    }
    
    // 更新活动时间戳 - 每次页面访问都更新
    // 注意：此行需在 session_write_close() 之前执行
    $_SESSION['last_activity'] = time();
    
    // 动态刷新 user 账户的 role/read_only 状态（避免需要重新登录才能生效）
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'user') {
        try {
            $user_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT role, read_only, status FROM user WHERE id = ?");
            $stmt->execute([$user_id]);
            $latestUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($latestUser) {
                if (!empty($latestUser['role'])) {
                    $_SESSION['role'] = $latestUser['role'];
                }
                $_SESSION['read_only'] = isset($latestUser['read_only']) ? (int)$latestUser['read_only'] : 1;

                // 账号被禁用时立即失效当前会话
                if (isset($latestUser['status']) && strtolower((string)$latestUser['status']) !== 'active') {
                    session_unset();
                    session_destroy();
                    if ($isApiRequest) {
                        if (!headers_sent()) {
                            header('Content-Type: application/json');
                        }
                        echo json_encode(['status' => 'error', 'message' => 'Account is inactive.', 'redirect' => '/login']);
                        exit();
                    }
                    header("Location: /login");
                    exit();
                }
            }
        } catch (PDOException $e) {
            error_log("Failed to refresh user role/read_only: " . $e->getMessage());
        }
    }

    // 动态刷新 Partnership 账户的 read_only 状态（避免需要重新登录才能生效）
    if (isset($_SESSION['user_type'], $_SESSION['role']) && 
        $_SESSION['user_type'] === 'user' && 
        strtolower($_SESSION['role']) === 'partnership') {
        try {
            $user_id = $_SESSION['user_id'];
            $company_id = $_SESSION['company_id'] ?? null;
            
            $ro_updated = false;
            if ($company_id) {
                // 优先从 company_ownership 获取当前公司下的局部只读状态
                $stmt = $pdo->prepare("SELECT read_only FROM company_ownership WHERE account_id = ? AND company_id = ? AND owner_type = 'user'");
                $stmt->execute([$user_id, $company_id]);
                $co_ro = $stmt->fetchColumn();
                
                if ($co_ro !== false) {
                    $_SESSION['read_only'] = (int)$co_ro;
                    $ro_updated = true;
                }
            }
            
            // 如果细分表里没有，则回退到 user 全局表
            if (!$ro_updated) {
                $stmt = $pdo->prepare("SELECT read_only FROM user WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_ro = $stmt->fetchColumn();
                if ($user_ro !== false) {
                    $_SESSION['read_only'] = (int)$user_ro;
                }
            }
        } catch (PDOException $e) {
            error_log("Failed to refresh read_only status: " . $e->getMessage());
        }
    }
    
} else {
    // 未登录
    // 如果是API请求，返回JSON错误
    if ($isApiRequest) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => 'error', 'message' => 'Please login first.', 'redirect' => '/login']);
        exit();
    }
    
    // 重定向到登录页
    header("Location: /login");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// Session 解锁优化（防止 PHP Session 排队阻塞并发 AJAX 请求）
//
// 默认：验证完登录状态后立即释放 session 文件锁，让多个并发 AJAX 请求排队变并发。
// 例外：如果某个 API 文件需要在验证后继续写入 session（如切换公司/账户），
//       则在 require session_check.php 之前定义常量 SESSION_KEEP_OPEN，
//       然后在该文件内写完 session 后自己负责调用 session_write_close()。
// ─────────────────────────────────────────────────────────────────────────────
if (!defined('SESSION_KEEP_OPEN')) {
    session_write_close();
}

