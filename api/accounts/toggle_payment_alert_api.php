<?php
/**
 * Toggle Account Payment Alert API
 * 路径: api/accounts/toggle_payment_alert_api.php
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

function getAccountPaymentAlert(PDO $pdo, int $accountId): ?array {
    $stmt = $pdo->prepare('SELECT payment_alert FROM account WHERE id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateAccountPaymentAlert(PDO $pdo, int $value, int $accountId): void {
    $stmt = $pdo->prepare('UPDATE account SET payment_alert = ? WHERE id = ?');
    $stmt->execute([$value, $accountId]);
    if ($stmt->rowCount() == 0) {
        throw new Exception('Payment alert 更新失败');
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
        api_error('只读账号无法修改支付提醒', 403);
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

    $current = getAccountPaymentAlert($pdo, $id);
    if (!$current) {
        api_error('无权限操作此账户', 403);
        exit;
    }

    $newPaymentAlert = $current['payment_alert'] == 1 ? 0 : 1;
    updateAccountPaymentAlert($pdo, $newPaymentAlert, $id);
    api_success(['newPaymentAlert' => $newPaymentAlert], 'Payment alert 更新成功');
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}
