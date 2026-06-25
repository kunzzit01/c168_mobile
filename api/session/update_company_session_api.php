<?php
/**
 * 更新 session 中的 company_id 的 API
 * 路径: api/session/update_company_session_api.php
 */

// 此 API 需要写入 session（切换公司），不能让 session_check.php 提前关闭锁
define('SESSION_KEEP_OPEN', true);

require_once __DIR__ . '/../../includes/session_check.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/company_expiration.php';
require_once __DIR__ . '/../../includes/session_user_payload_cache.php';
require_once __DIR__ . '/../get_companies_helper.php';

header('Content-Type: application/json');

function translateApiMessage(string $message): string {
    $map = [
        '用户未登录' => 'User not logged in',
        '缺少 company_id 参数' => 'Missing company_id parameter',
        '获取公司列表失败' => 'Failed to load company list',
        '无权限访问该公司' => 'No permission to access this company',
        'Company has expired' => 'Company has expired',
        'Company expiration date is not set' => 'Company expiration date is not set',
        'Company 已更新' => 'Company updated',
    ];

    return $map[$message] ?? $message;
}

function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    $message = translateApiMessage((string)$message);
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'error' => $success ? null : $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * @deprecated Use gc_get_company_expiration_state()
 */
function getCompanyExpirationState($expirationDate, $companyCode = null, $groupId = null): string {
    return gc_get_company_expiration_state($expirationDate, $companyCode, $groupId);
}

function getUserCompanies(PDO $pdo, $user_id, $user_role, $user_type) {
    if (strtolower($user_type) === 'member') {
        // member 可能来自不同登录入口：有的用 account_company(account_id)，有的仍走 user_company_map(user_id)
        // 为避免切换 company 误判无权限，这里同时检查两种映射。
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.company_id, c.group_id, c.expiration_date
            FROM company c
            INNER JOIN account_company ac ON c.id = ac.company_id
            WHERE ac.account_id = ?

            UNION

            SELECT DISTINCT c2.id, c2.company_id, c2.group_id, c2.expiration_date
            FROM company c2
            INNER JOIN user_company_map ucm ON c2.id = ucm.company_id
            WHERE ucm.user_id = ?

            ORDER BY company_id ASC
        ");
        $stmt->execute([$user_id, $user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (strtolower($user_role) === 'owner') {
        // Keep scope exactly aligned with get_owner_companies_api + dashboard company pills.
        $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $user_id);
        $rows = getCompaniesByOwner($pdo, $owner_id, true, true);
        return array_map(static function ($c) {
            return [
                'id' => isset($c['id']) ? (int)$c['id'] : 0,
                'company_id' => $c['company_id'] ?? '',
                'group_id' => $c['group_id'] ?? null,
                'expiration_date' => $c['expiration_date'] ?? null,
                'is_external' => isset($c['is_external']) ? (int)$c['is_external'] : 0,
            ];
        }, $rows);
    }
    $rows = getCompaniesByUser($pdo, (int)$user_id, true, true);
    return array_map(static function ($c) {
        return [
            'id' => isset($c['id']) ? (int)$c['id'] : 0,
            'company_id' => $c['company_id'] ?? '',
            'group_id' => $c['group_id'] ?? null,
            'expiration_date' => $c['expiration_date'] ?? null,
            'is_external' => 0,
        ];
    }, $rows);
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(false, '用户未登录', null, 401);
        exit;
    }

    $requested_company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requested_company_id = (int) $_GET['company_id'];
    } elseif (isset($_POST['company_id']) && $_POST['company_id'] !== '') {
        $requested_company_id = (int) $_POST['company_id'];
    }
    if (!$requested_company_id) {
        jsonResponse(false, '缺少 company_id 参数', null, 400);
        exit;
    }

    gc_hydrate_company_login_group_id($pdo);

    $current_user_id = $_SESSION['user_id'];
    $current_user_role = strtolower($_SESSION['role'] ?? '');
    $current_user_type = strtolower($_SESSION['user_type'] ?? '');

    try {
        $user_companies = getUserCompanies($pdo, $current_user_id, $current_user_role, $current_user_type);
        $user_companies = gc_filter_real_company_rows($user_companies);
        $user_companies = gc_apply_login_scope_company_filter($pdo, $user_companies);
    } catch (PDOException $e) {
        error_log("获取用户 company 列表失败: " . $e->getMessage());
        jsonResponse(false, '获取公司列表失败', null, 500);
        exit;
    }

    $valid = false;
    $is_external_view = false;
    $real_owner_id = null;
    $blockedReason = null;
    foreach ($user_companies as $comp) {
        if ((int) $comp['id'] === $requested_company_id) {
            $valid = true;
            $expState = gc_get_company_expiration_state(
                $comp['expiration_date'] ?? null,
                $comp['company_id'] ?? null,
                $comp['group_id'] ?? null
            );
            if ($expState === 'expired') {
                $blockedReason = 'expired';
            } elseif ($expState === 'no_set') {
                $blockedReason = 'no_set';
            }
            if (isset($comp['is_external']) && $comp['is_external'] == 1) {
                $is_external_view = true;
            }
            break;
        }
    }
    if (!$valid) {
        jsonResponse(false, '无权限访问该公司', null, 403);
        exit;
    }

    try {
        $viewGroup = gc_session_login_identifier();
        gc_assert_company_id_allowed_for_login_scope($pdo, $requested_company_id, $viewGroup);
    } catch (RuntimeException $e) {
        jsonResponse(false, '无权限访问该公司', null, 403);
        exit;
    }

    if ($blockedReason === 'expired') {
        jsonResponse(false, 'Company has expired', ['reason' => 'expired'], 403);
        exit;
    }
    if ($blockedReason === 'no_set') {
        jsonResponse(false, 'Company expiration date is not set', ['reason' => 'no_set'], 403);
        exit;
    }

    if ($current_user_role === 'owner' && $is_external_view) {
        $ownerStmt = $pdo->prepare("SELECT owner_id FROM company WHERE id = ? LIMIT 1");
        $ownerStmt->execute([$requested_company_id]);
        $real_owner_id = (int)($ownerStmt->fetchColumn() ?: 0);
    }

    // 更新当前会话的公司 ID 和外部视图状态
    $_SESSION['company_id'] = $requested_company_id;
    $_SESSION['is_external_view'] = $is_external_view;
    if ($current_user_role === 'owner') {
        // Preserve the REAL owner_id permanently (set once, never changes)
        if (!isset($_SESSION['real_owner_id'])) {
            $_SESSION['real_owner_id'] = $current_user_id;
        }
        if ($is_external_view && $real_owner_id !== null) {
            $_SESSION['owner_id'] = $real_owner_id;
        } else {
            $_SESSION['owner_id'] = $_SESSION['real_owner_id'];
        }
    }

    // 返回当前公司是否有 Games / Bank 权限，供侧边栏即时显示/隐藏 Data Capture、Maintenance > Process 等
    // 同时更新 session 中的 company_code，避免使用 C168 登录后切到其他公司时仍被视为 C168
    $has_gambling = false;
    $has_bank = false;
    $company_code = null;
    try {
        $flags = gc_resolve_company_category_flags($pdo, $requested_company_id);
        $has_gambling = (bool) ($flags['has_gambling'] ?? false);
        $has_bank = (bool) ($flags['has_bank'] ?? false);
        $stmt = $pdo->prepare('SELECT company_id FROM company WHERE id = ? LIMIT 1');
        $stmt->execute([$requested_company_id]);
        $company_code = $stmt->fetchColumn();
        if ($company_code !== false && $company_code !== null) {
            $company_code = (string) $company_code;
        } else {
            $company_code = null;
        }
    } catch (PDOException $e) {
        error_log("获取公司权限失败: " . $e->getMessage());
    }

    // 如果成功获取到公司代码，则同步更新到 session 中
    if ($company_code !== null) {
        $_SESSION['company_code'] = $company_code;
    }

    session_user_payload_cache_clear();

    // 写入完成，立即释放 session 锁
    session_write_close();

    jsonResponse(true, 'Company 已更新', [
        'company_id'   => $requested_company_id,
        'company_code' => $company_code,
        'has_gambling' => $has_gambling,
        'has_bank'     => $has_bank
    ]);
} catch (Exception $e) {
    session_write_close();
    jsonResponse(false, $e->getMessage(), null, 500);
}