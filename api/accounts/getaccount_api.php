<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';

function validateCompanyAccess(PDO $pdo, int $company_id, ?string $view_group = null): void {
    $current_user_id = $_SESSION['user_id'] ?? null;
    if (!$current_user_id) {
        throw new Exception('用户未登录');
    }
    if (gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $view_group);
        return;
    }
    $current_user_role = $_SESSION['role'] ?? '';
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
}

function formatAccountIdForDisplay(string $rawAccountId): string {
    $rawAccountId = trim($rawAccountId);
    if ($rawAccountId === '') {
        return $rawAccountId;
    }

    if (preg_match('/^[^_]+_([0-9]+)(?:_[0-9]+)?$/', $rawAccountId, $matches)) {
        return $matches[1];
    }

    return $rawAccountId;
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }

    try {
        $accountCtx = tenant_resolve_currency_context_from_request($pdo, [
            'group_id' => $_GET['group_id'] ?? null,
            'view_group' => $_GET['view_group'] ?? null,
            'company_id' => $_GET['company_id'] ?? null,
            'group_only' => $_GET['group_only'] ?? null,
            'session_company_id' => $_SESSION['company_id'] ?? null,
        ]);
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }

    $permCompanyId = (int) ($accountCtx['company_id'] ?? 0);
    if ($permCompanyId <= 0) {
        throw new Exception('用户未登录或缺少公司信息');
    }

    $groupCode = (string) ($accountCtx['group_code'] ?? '');
    if ($groupCode !== '' && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $permCompanyId, $groupCode);
    }
    validateCompanyAccess($pdo, $permCompanyId, $groupCode !== '' ? $groupCode : null);

    $account_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if (!$account_id) {
        throw new Exception('Account ID is required');
    }

    $ledgerScope = tenant_resolve_account_ledger_scope($pdo, $account_id);
    if (($ledgerScope['group_code'] ?? '') === '' && (int) ($ledgerScope['group_pk'] ?? 0) > 0) {
        $ledgerScope['group_code'] = tenant_group_code_from_pk($pdo, (int) $ledgerScope['group_pk']);
    }
    $belongs = tenant_account_belongs_to_context($pdo, $account_id, $accountCtx);
    $ledgerCtx = tenant_resolve_currency_context_for_account($pdo, $account_id);
    if (!$belongs && $ledgerCtx !== null) {
        $belongs = tenant_account_belongs_to_context($pdo, $account_id, $ledgerCtx);
    }
    if (!$belongs) {
        throw new Exception('Account not found');
    }

    $currencyCtxForAccount = $ledgerCtx ?? $accountCtx;

    $sql = "SELECT 
                a.id,
                a.account_id,
                a.name,
                a.password,
                a.role,
                a.payment_alert,
                a.alert_day,
                a.alert_day AS alert_type,
                a.alert_specific_date,
                a.alert_specific_date AS alert_start_date,
                a.alert_amount,
                a.remark,
                a.status,
                a.last_login
            FROM account a
            WHERE a.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$account_id]);

    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception('Account not found');
    }

    if (($currencyCtxForAccount['mode'] ?? '') === 'group' && tenant_table_has_scope_columns($pdo, 'currency')) {
        $sql_currencies = "SELECT 
                            ac.currency_id,
                            c.code AS currency_code
                        FROM account_currency ac
                        INNER JOIN currency c ON ac.currency_id = c.id
                        WHERE ac.account_id = ? AND c.scope_type = 'group' AND c.scope_id = ?
                        ORDER BY ac.created_at ASC";
        $stmt_currencies = $pdo->prepare($sql_currencies);
        $stmt_currencies->execute([$account_id, (int) ($currencyCtxForAccount['group_pk'] ?? 0)]);
    } else {
        $permForCurrencies = (int) ($currencyCtxForAccount['company_id'] ?? $permCompanyId);
        $sql_currencies = "SELECT 
                            ac.currency_id,
                            c.code AS currency_code
                        FROM account_currency ac
                        INNER JOIN currency c ON ac.currency_id = c.id
                        WHERE ac.account_id = ? AND c.company_id = ?"
            . tenant_sql_currency_subsidiary_only($pdo, 'c')
            . ' ORDER BY ac.created_at ASC';
        $stmt_currencies = $pdo->prepare($sql_currencies);
        $stmt_currencies->execute([$account_id, $permForCurrencies]);
    }

    $account_currencies = $stmt_currencies->fetchAll(PDO::FETCH_ASSOC);

    $account['account_currencies'] = $account_currencies;
    $account['ledger_scope'] = [
        'mode' => (string) ($ledgerScope['mode'] ?? 'company'),
        'group_code' => (string) ($ledgerScope['group_code'] ?? ''),
        'group_pk' => (int) ($ledgerScope['group_pk'] ?? 0),
    ];
    $account['account_id'] = formatAccountIdForDisplay((string) ($account['account_id'] ?? ''));

    echo json_encode([
        'success' => true,
        'data' => $account,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage(),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '系统错误: ' . $e->getMessage(),
    ]);
}
