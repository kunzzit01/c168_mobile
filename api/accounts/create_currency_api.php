<?php
/**
 * 创建货币 API：JSON body { code, company_id? }，返回 { success, data: { id, code } }
 * 路径: api/accounts/create_currency_api.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
}

function jsonOut(bool $success, string $message, $data = null) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
}

function userCanAccessCompany(PDO $pdo, int $companyId, ?string $viewGroup = null): bool {
    if (gc_is_group_login()) {
        return gc_session_can_access_company_id($pdo, $companyId, $viewGroup);
    }
    $userId = $_SESSION['user_id'] ?? 0;
    $role = $_SESSION['role'] ?? '';
    $ownerId = $_SESSION['owner_id'] ?? $userId;
    if ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$companyId, $ownerId]);
    } else {
        $stmt = $pdo->prepare("SELECT 1 FROM user_company_map WHERE user_id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$userId, $companyId]);
    }
    return (bool) $stmt->fetchColumn();
}

function normalizeGroupId(?string $groupId): ?string {
    $g = strtoupper(trim((string)($groupId ?? '')));
    return $g !== '' ? $g : null;
}

function resolveGroupEntityCompanyId(PDO $pdo, string $groupId): int {
    $stmt = $pdo->prepare("
        SELECT id
        FROM company
        WHERE UPPER(TRIM(company_id)) = ?
        LIMIT 1
    ");
    $stmt->execute([$groupId]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $placeholderStmt = $pdo->prepare("
        SELECT id
        FROM company
        WHERE TRIM(COALESCE(company_id, '')) = ''
          AND UPPER(TRIM(group_id)) = ?
        ORDER BY id ASC
        LIMIT 1
    ");
    $placeholderStmt->execute([$groupId]);
    return (int)($placeholderStmt->fetchColumn() ?: 0);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        jsonOut(false, 'Only POST allowed', null);
        exit;
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        jsonOut(false, '用户未登录', null);
        exit;
    }

    if (is_partnership_audit_read_only_active($pdo)) {
        http_response_code(403);
        jsonOut(false, '只读账号无法创建币种', null);
        exit;
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (is_array($input)) {
        $input = tenant_normalize_scope_params($input);
    }
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        http_response_code(400);
        jsonOut(false, 'Invalid JSON', null);
        exit;
    }

    $code = isset($input['code']) ? trim((string) $input['code']) : '';
    if ($code === '') {
        http_response_code(400);
        jsonOut(false, 'Currency code is required', null);
        exit;
    }
    $code = strtoupper($code);

    $groupScopeId = normalizeGroupId($input['group_id'] ?? null);
    $requestedCompanyId = 0;
    $groupOnly = !empty($input['group_only'])
        && filter_var($input['group_only'], FILTER_VALIDATE_BOOLEAN);

    $explicitCompanyId = 0;
    if (isset($input['company_id']) && $input['company_id'] !== '' && $input['company_id'] !== null) {
        $explicitCompanyId = (int) $input['company_id'];
    }

    if (gc_is_group_login()) {
        $groupScopeId = $groupScopeId ?? normalizeGroupId($_SESSION['login_identifier'] ?? null);
        if ($explicitCompanyId > 0) {
            // Group login + subsidiary Company pill → company ledger, not group-only.
            $groupOnly = false;
            $requestedCompanyId = $explicitCompanyId;
        } else {
            $groupOnly = true;
            $requestedCompanyId = 0;
        }
    } elseif ($groupOnly) {
        $requestedCompanyId = 0;
    } elseif ($explicitCompanyId > 0) {
        $requestedCompanyId = $explicitCompanyId;
    }

    try {
        $ctx = tenant_resolve_currency_context_from_request($pdo, [
            'group_id' => $groupScopeId,
            'company_id' => $requestedCompanyId > 0 ? $requestedCompanyId : null,
            'group_only' => $groupOnly,
            'session_company_id' => $_SESSION['company_id'] ?? null,
        ]);
    } catch (Exception $e) {
        if (!$groupOnly && $groupScopeId === null && isset($_SESSION['company_id'])) {
            $ctx = tenant_resolve_currency_context($pdo, (int) $_SESSION['company_id'], null);
        } else {
            http_response_code(400);
            jsonOut(false, $e->getMessage(), null);
            exit;
        }
    }

    $companyId = (int) $ctx['company_id'];
    if ($companyId > 0 && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $companyId, $groupScopeId);
    }

    if (!userCanAccessCompany($pdo, $companyId, $groupScopeId)) {
        http_response_code(403);
        jsonOut(false, '无权限访问该公司', null);
        exit;
    }

    try {
        $created = tenant_create_currency($pdo, $code, $ctx);
    } catch (Exception $e) {
        http_response_code(400);
        jsonOut(false, $e->getMessage(), null);
        exit;
    }

    jsonOut(true, 'OK', ['id' => $created['id'], 'code' => $created['code']]);
} catch (PDOException $e) {
    error_log('create_currency_api: ' . $e->getMessage());
    http_response_code(500);
    jsonOut(false, '数据库错误', null);
} catch (Exception $e) {
    http_response_code(400);
    jsonOut(false, $e->getMessage(), null);
}
