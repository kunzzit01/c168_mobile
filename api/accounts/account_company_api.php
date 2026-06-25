<?php
/**
 * Account Company API
 * 管理账户与公司的多对多关系
 * 路径: api/accounts/account_company_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../get_companies_helper.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';

/**
 * 标准 JSON 响应
 */
function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 检查 account_company 表是否存在
 */
function hasAccountCompanyTable($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 获取账户关联的公司列表
 */
function dbGetAccountCompanies($pdo, $account_id) {
    $sql = "SELECT ac.id, ac.account_id, ac.company_id, c.company_id AS company_code
            FROM account_company ac
            INNER JOIN company c ON ac.company_id = c.id
            WHERE ac.account_id = ?
            ORDER BY c.company_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$account_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dbGetAvailableCompaniesForUser($pdo, $user_id, $role, $owner_id) {
    if ($role === 'owner') {
        $fetched = getCompaniesByOwner($pdo, $owner_id, false);
        $res = [];
        foreach ($fetched as $c) {
            $res[] = ['id' => $c['id'], 'company_code' => $c['company_id']];
        }
        return $res;
    } else {
        $fetched = getCompaniesByUser($pdo, $user_id);
        $res = [];
        foreach ($fetched as $c) {
            $res[] = ['id' => $c['id'], 'company_code' => $c['company_id']];
        }
        return $res;
    }
}

/**
 * 获取账户已关联的公司 ID 列表
 */
function dbGetLinkedCompanyIds($pdo, $account_id) {
    $stmt = $pdo->prepare("SELECT company_id FROM account_company WHERE account_id = ?");
    $stmt->execute([$account_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 验证账户是否存在
 */
function dbAccountExists($pdo, $account_id) {
    $stmt = $pdo->prepare("SELECT id FROM account WHERE id = ?");
    $stmt->execute([$account_id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 验证用户是否有权访问该公司（owner 或 user_company_map）
 */
function dbUserCanAccessCompany($pdo, $company_id, $user_id, $role, $owner_id) {
    if ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$user_id, $company_id]);
    }
    return (bool) $stmt->fetchColumn();
}

/**
 * 检查账户-公司是否已关联
 */
function dbAccountCompanyLinked($pdo, $account_id, $company_id) {
    $stmt = $pdo->prepare("SELECT id FROM account_company WHERE account_id = ? AND company_id = ?");
    $stmt->execute([$account_id, $company_id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 添加账户-公司关联并同步货币（事务）
 */
function dbAddCompanyAndSyncCurrencies($pdo, $account_id, $company_id) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO account_company (account_id, company_id) VALUES (?, ?)");
        $stmt->execute([$account_id, $company_id]);

        $currencyStmt = $pdo->prepare("
            SELECT DISTINCT c.code FROM account_currency ac
            INNER JOIN currency c ON ac.currency_id = c.id
            WHERE ac.account_id = ?
        ");
        $currencyStmt->execute([$account_id]);
        $existingCurrencies = $currencyStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($existingCurrencies)) {
            $findStmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
            $insertStmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
            $linkStmt = $pdo->prepare("INSERT INTO account_currency (account_id, currency_id) VALUES (?, ?)");
            $checkStmt = $pdo->prepare("SELECT id FROM account_currency WHERE account_id = ? AND currency_id = ?");

            foreach ($existingCurrencies as $code) {
                $code = $code === null || $code === '' ? '' : strtoupper(trim($code));
                if ($code === '') continue;

                $findStmt->execute([$code, $company_id]);
                $currencyId = $findStmt->fetchColumn();
                if (!$currencyId) {
                    $insertStmt->execute([$code, $company_id]);
                    $currencyId = $pdo->lastInsertId();
                    tenant_sync_company_currency_to_parent_groups($pdo, (int) $company_id, $code);
                }
                $checkStmt->execute([$account_id, $currencyId]);
                if (!$checkStmt->fetchColumn()) {
                    try {
                        $linkStmt->execute([$account_id, $currencyId]);
                    } catch (PDOException $e) {
                        if ($e->getCode() != 23000) throw $e;
                    }
                }
            }
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 移除账户-公司关联；返回 [deleted_count, will_lose_access]
 */
function dbRemoveAccountCompany($pdo, $account_id, $company_id, $current_company_id) {
    deletedLog(
        $pdo,
        '',
        '/api/accounts/account_company_api.php',
        'account_company',
        (string) $account_id . '_' . (string) $company_id,
        'DELETE',
        ['account_id' => $account_id, 'company_id' => $company_id],
        (string) $company_id
    );

    $stmt = $pdo->prepare("DELETE FROM account_company WHERE account_id = ? AND company_id = ?");
    $stmt->execute([$account_id, $company_id]);
    $deleted = $stmt->rowCount();

    $will_lose_access = false;
    if ($deleted > 0 && $company_id == $current_company_id) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM account_company WHERE account_id = ? AND company_id != ?");
        $countStmt->execute([$account_id, $company_id]);
        if ($countStmt->fetchColumn() == 0) {
            $will_lose_access = true;
        }
    }
    return [$deleted, $will_lose_access];
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(false, '用户未登录', null, 401);
        exit;
    }

    if (!hasAccountCompanyTable($pdo)) {
        jsonResponse(false, 'account_company 表不存在，请先执行 create_account_company_table.sql', null, 400);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? '';
    $owner_id = $_SESSION['owner_id'] ?? $user_id;

    if ($method === 'GET') {
        if ($action === 'get_account_companies') {
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            if (!$account_id) {
                jsonResponse(false, '账户ID是必需的', null, 400);
                exit;
            }
            $companies = dbGetAccountCompanies($pdo, $account_id);
            jsonResponse(true, '', $companies);
            exit;
        }

        if ($action === 'get_available_companies') {
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            $all_companies = dbGetAvailableCompaniesForUser($pdo, $user_id, $role, $owner_id);
            $linked_ids = $account_id ? dbGetLinkedCompanyIds($pdo, $account_id) : [];
            foreach ($all_companies as &$c) {
                $c['is_linked'] = in_array($c['id'], $linked_ids);
            }
            unset($c);
            jsonResponse(true, '', $all_companies);
            exit;
        }

        jsonResponse(false, '无效的操作', null, 400);
        exit;
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if ($action === 'add_company') {
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $company_id = isset($data['company_id']) ? (int)$data['company_id'] : 0;
            if (!$account_id || !$company_id) {
                jsonResponse(false, '账户ID和公司ID是必需的', null, 400);
                exit;
            }
            if (!dbAccountExists($pdo, $account_id)) {
                jsonResponse(false, '账户不存在', null, 400);
                exit;
            }
            if (!dbUserCanAccessCompany($pdo, $company_id, $user_id, $role, $owner_id)) {
                jsonResponse(false, '公司不存在或您无权访问该公司', null, 403);
                exit;
            }
            if (dbAccountCompanyLinked($pdo, $account_id, $company_id)) {
                jsonResponse(true, '该公司已经关联到此账户', ['already_linked' => true]);
                exit;
            }
            dbAddCompanyAndSyncCurrencies($pdo, $account_id, $company_id);
            jsonResponse(true, '公司关联成功，并已同步现有货币设置到该公司');
            exit;
        }

        if ($action === 'remove_company') {
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $company_id = isset($data['company_id']) ? (int)$data['company_id'] : 0;
            $current_company_id = $_SESSION['company_id'] ?? null;
            if (!$account_id || !$company_id) {
                jsonResponse(false, '账户ID和公司ID是必需的', null, 400);
                exit;
            }
            if (!$current_company_id) {
                jsonResponse(false, '缺少当前公司信息', null, 400);
                exit;
            }
            if (!dbUserCanAccessCompany($pdo, $company_id, $user_id, $role, $owner_id)) {
                jsonResponse(false, '您无权访问该公司', null, 403);
                exit;
            }
            list($deleted, $will_lose_access) = dbRemoveAccountCompany($pdo, $account_id, $company_id, $current_company_id);
            if ($deleted === 0) {
                jsonResponse(false, '关联不存在', null, 400);
                exit;
            }
            $message = '公司关联已移除';
            if ($will_lose_access) {
                $message .= '。注意：移除后账户将不再属于当前公司，如需继续操作请切换到账户所属的其他公司';
            }
            jsonResponse(true, $message, ['will_lose_access' => $will_lose_access]);
            exit;
        }

        jsonResponse(false, '无效的操作', null, 400);
        exit;
    }

    jsonResponse(false, '不支持的请求方法', null, 405);

} catch (PDOException $e) {
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    jsonResponse(false, $e->getMessage(), null, $code);
}