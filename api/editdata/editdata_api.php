<?php
/**
 * Edit Data API - 提供编辑表单所需的货币与角色列表
 * 路径: api/editdata/editdata_api.php
 *
 * Roles are global (role table). Currencies are optional and scoped to a company when resolvable.
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../transactions/transaction_scope.php';

/**
 * 标准 JSON 响应：success, message, data
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
 * 按公司 ID 获取货币列表
 */
function getCurrenciesByCompany(PDO $pdo, int $company_id) {
    $stmt = $pdo->prepare("SELECT id, code FROM currency WHERE company_id = ? ORDER BY code ASC");
    $stmt->execute([$company_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function normalizeGroupId(?string $groupId): ?string
{
    $g = strtoupper(trim((string) ($groupId ?? '')));
    return $g !== '' ? $g : null;
}

/**
 * 获取所有角色代码
 */
function getRoles(PDO $pdo) {
    $stmt = $pdo->query("SELECT code FROM role ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Account modal roles: ensure DEBTOR appears after MEMBER when missing from role table.
 */
function ensureAccountModalRoles(array $roles): array
{
    $normalized = array_map(static fn($r) => strtoupper(trim((string) $r)), $roles);
    if (in_array('DEBTOR', $normalized, true)) {
        return $roles;
    }
    $memberIdx = array_search('MEMBER', $normalized, true);
    if ($memberIdx !== false) {
        array_splice($roles, $memberIdx + 1, 0, 'DEBTOR');
        return $roles;
    }
    $roles[] = 'DEBTOR';
    return $roles;
}

/**
 * Resolve numeric company id for currency list (optional).
 */
function editdataResolveCurrencyCompanyId(PDO $pdo): int
{
    if (isset($_GET['company_id']) && trim((string) $_GET['company_id']) !== '') {
        $cid = (int) $_GET['company_id'];
        if ($cid > 0) {
            $viewGroup = isset($_GET['group_id']) ? normalizeGroupId($_GET['group_id']) : null;
            gc_assert_api_company_access($pdo, $cid, $viewGroup);
            return $cid;
        }
    }

    if (isset($_SESSION['company_id']) && (int) $_SESSION['company_id'] > 0) {
        return (int) $_SESSION['company_id'];
    }

    if (gc_is_group_login()) {
        $groupCode = normalizeGroupId(gc_session_login_identifier());
        if ($groupCode !== null) {
            $entityId = tx_resolve_group_entity_company_id($pdo, $groupCode);
            if ($entityId > 0) {
                return $entityId;
            }
        }
    }

    return 0;
}

try {
    if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0) {
        throw new Exception('用户未登录');
    }

    $roles = ensureAccountModalRoles(getRoles($pdo));
    $currencies = [];
    try {
        $company_id = editdataResolveCurrencyCompanyId($pdo);
        if ($company_id > 0) {
            $currencies = getCurrenciesByCompany($pdo, $company_id);
        }
    } catch (Throwable $currencyScopeError) {
        // Roles are global; currency scope may be unavailable in group-only view.
        $currencies = [];
    }

    jsonResponse(true, 'OK', [
        'currencies' => $currencies,
        'roles' => $roles,
    ]);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Throwable $e) {
    jsonResponse(false, $e->getMessage(), null, 401);
}
