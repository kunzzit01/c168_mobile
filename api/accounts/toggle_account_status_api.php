<?php
/**
 * Toggle Account Status API
 * 路径: api/accounts/toggle_account_status_api.php
 */

session_start();
session_write_close();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

function getAccountStatus(PDO $pdo, int $accountId): ?array {
    $stmt = $pdo->prepare('SELECT status FROM account WHERE id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateAccountStatus(PDO $pdo, string $newStatus, int $accountId): void {
    $stmt = $pdo->prepare('UPDATE account SET status = ? WHERE id = ?');
    $stmt->execute([$newStatus, $accountId]);
    if ($stmt->rowCount() == 0) {
        throw new Exception('状态更新失败');
    }
}

function resolveToggleContext(PDO $pdo): array
{
    return tenant_resolve_currency_context_from_request($pdo, [
        'group_id' => $_POST['group_id'] ?? null,
        'view_group' => $_POST['view_group'] ?? null,
        'company_id' => $_POST['company_id'] ?? null,
        'group_only' => $_POST['group_only'] ?? null,
        'session_company_id' => $_SESSION['company_id'] ?? null,
    ]);
}

try {
    if (!isset($_SESSION['user_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }
    if (is_partnership_audit_read_only_active($pdo)) {
        api_error('只读账号无法修改账户状态', 403);
        exit;
    }

    try {
        $accountCtx = resolveToggleContext($pdo);
    } catch (Exception $e) {
        api_error($e->getMessage(), 400);
        exit;
    }

    $permCompanyId = (int) ($accountCtx['company_id'] ?? 0);
    if ($permCompanyId <= 0) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }

    $groupCode = (string) ($accountCtx['group_code'] ?? '');
    if ($groupCode !== '' && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $permCompanyId, $groupCode);
    }

    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        api_error('无效的账户ID', 400);
        exit;
    }

    if (!tenant_account_belongs_to_context($pdo, $id, $accountCtx)) {
        api_error('无权限操作此账户', 403);
        exit;
    }

    $current = getAccountStatus($pdo, $id);
    if (!$current) {
        api_error('无权限操作此账户', 403);
        exit;
    }

    $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
    updateAccountStatus($pdo, $newStatus, $id);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => '状态更新成功',
        'data' => ['newStatus' => $newStatus],
        'newStatus' => $newStatus,
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}
