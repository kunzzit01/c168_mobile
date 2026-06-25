<?php
/**
 * 批量删除账户 API（仅允许删除 inactive，且需通过 session 校验）
 * 路径: api/accounts/delete_accounts_api.php
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';
require_once __DIR__ . '/../api_response.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
    exit;
}

function deleteAccountsResolveContext(PDO $pdo, array $input): array
{
    $merge = array_merge($_POST, $input);

    return tenant_resolve_currency_context_from_request($pdo, [
        'group_id' => $merge['group_id'] ?? null,
        'view_group' => $merge['view_group'] ?? null,
        'company_id' => $merge['company_id'] ?? null,
        'group_only' => $merge['group_only'] ?? null,
        'session_company_id' => $_SESSION['company_id'] ?? null,
    ]);
}

try {
    if (!isset($_SESSION['user_id'])) {
        api_error('User not logged in', 401);
        exit;
    }

    if (is_partnership_audit_read_only_active($pdo)) {
        api_error('只读账号无法删除账户', 403);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = isset($input['ids']) ? (array) $input['ids'] : (isset($_POST['ids']) ? (array) $_POST['ids'] : []);
    if (empty($ids) && isset($_POST['ids[]'])) {
        $ids = (array) $_POST['ids[]'];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

    if ($ids === []) {
        api_error('No account IDs provided', 400);
        exit;
    }

    try {
        $accountCtx = deleteAccountsResolveContext($pdo, $input);
    } catch (Exception $e) {
        api_error($e->getMessage(), 400);
        exit;
    }

    $company_id = (int) ($accountCtx['company_id'] ?? 0);
    if ($company_id <= 0) {
        api_error('Company not selected', 400);
        exit;
    }

    $isGroupScope = (($accountCtx['mode'] ?? '') === 'group');
    $groupPk = (int) ($accountCtx['group_pk'] ?? 0);
    $groupCode = (string) ($accountCtx['group_code'] ?? '');
    if ($groupCode !== '' && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $groupCode);
    }

    $scopedIds = [];
    foreach ($ids as $aid) {
        if (tenant_account_belongs_to_context($pdo, $aid, $accountCtx)) {
            $scopedIds[] = $aid;
        }
    }
    if ($scopedIds === []) {
        api_error('No deletable accounts found in current scope', 400);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
    $checkStmt = $pdo->prepare("
        SELECT id, account_id, status
        FROM account
        WHERE id IN ($placeholders)
    ");
    $checkStmt->execute($scopedIds);
    $accountsToDelete = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($accountsToDelete === []) {
        api_error('No deletable accounts found in current scope', 400);
        exit;
    }

    $activeAccounts = array_filter($accountsToDelete, static function ($account) {
        return ($account['status'] ?? '') === 'active';
    });
    if ($activeAccounts !== []) {
        $activeAccountIds = array_column($activeAccounts, 'account_id');
        api_error('Cannot delete active accounts: ' . implode(', ', $activeAccountIds), 400, ['accounts' => $activeAccountIds]);
        exit;
    }

    $accountsUsedInDatacapture = [];
    try {
        $check_dct_table = $pdo->query("SHOW TABLES LIKE 'data_capture_templates'");
        if ($check_dct_table->rowCount() > 0) {
            $checkDctStmt = $pdo->prepare("
                SELECT DISTINCT dct.account_id, a.account_id as account_display
                FROM data_capture_templates dct
                INNER JOIN account a ON dct.account_id = a.id
                WHERE dct.company_id = ?
                AND dct.account_id IN ($placeholders)
            ");
            $checkDctStmt->execute(array_merge([$company_id], $scopedIds));
            $usedAccounts = $checkDctStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($usedAccounts as $usedAccount) {
                $accountsUsedInDatacapture[] = $usedAccount['account_display'] ?: 'ID: ' . $usedAccount['account_id'];
            }
        }
    } catch (PDOException $e) {
        error_log('Error checking data_capture_templates: ' . $e->getMessage());
        api_error('Delete check failed', 500);
        exit;
    }

    if ($accountsUsedInDatacapture !== []) {
        api_error(
            'Cannot delete: used in datacapture formula: ' . implode(', ', $accountsUsedInDatacapture),
            400,
            ['accounts' => $accountsUsedInDatacapture]
        );
        exit;
    }

    $pageTag = '/api/accounts/delete_accounts_api.php';
    $userTag = (string) ($_SESSION['login_id'] ?? $_SESSION['name'] ?? '');
    $cidLog = (string) $company_id;
    foreach ($scopedIds as $aid) {
        if (deleted_log_account_has_other_company_links($pdo, $aid, $company_id)) {
            deletedLog($pdo, $userTag, $pageTag, 'account_company', $company_id . ':' . $aid, 'DELETE', [
                'company_id' => $company_id,
                'account_id' => $aid,
            ], $cidLog);
        }
    }

    $deleted_ac_count = 0;
    if ($isGroupScope && $groupPk > 0 && tenant_table_has_scope_columns($pdo, 'account_company')) {
        $delete_ac_stmt = $pdo->prepare("
            DELETE FROM account_company
            WHERE scope_type = 'group' AND scope_id = ? AND account_id IN ($placeholders)
        ");
        $delete_ac_stmt->execute(array_merge([$groupPk], $scopedIds));
        $deleted_ac_count = $delete_ac_stmt->rowCount();
        try {
            if ($pdo->query("SHOW TABLES LIKE 'account_group_map'")->rowCount() > 0) {
                $mapDel = $pdo->prepare("DELETE FROM account_group_map WHERE group_id = ? AND account_id IN ($placeholders)");
                $mapDel->execute(array_merge([$groupPk], $scopedIds));
                $deleted_ac_count += $mapDel->rowCount();
            }
        } catch (PDOException $e) {
            // ignore
        }
    } else {
        $delete_ac_params = array_merge([$company_id], $scopedIds);
        $subsidiarySql = tenant_sql_account_company_subsidiary_only($pdo, 'account_company');
        $delete_ac_stmt = $pdo->prepare("
            DELETE FROM account_company
            WHERE company_id = ?{$subsidiarySql} AND account_id IN ($placeholders)
        ");
        $delete_ac_stmt->execute($delete_ac_params);
        $deleted_ac_count = $delete_ac_stmt->rowCount();
    }

    $remaining_accounts = [];
    foreach ($scopedIds as $account_id) {
        $check_stmt = $pdo->prepare('SELECT COUNT(*) FROM account_company WHERE account_id = ?');
        $check_stmt->execute([$account_id]);
        if ((int) $check_stmt->fetchColumn() > 0) {
            continue;
        }
        $remaining_accounts[] = $account_id;
    }

    if ($remaining_accounts !== []) {
        foreach ($remaining_accounts as $raid) {
            deletedLog($pdo, $userTag, $pageTag, 'account', (string) $raid, 'DELETE', null, $cidLog);
        }
        $remaining_placeholders = implode(',', array_fill(0, count($remaining_accounts), '?'));
        $delete_stmt = $pdo->prepare("
            DELETE FROM account
            WHERE id IN ($remaining_placeholders)
            AND status = 'inactive'
        ");
        $delete_stmt->execute($remaining_accounts);
    }

    $deletedCount = $deleted_ac_count;
    api_success(['deleted' => $deletedCount], $deletedCount === 1 ? '1 account deleted' : $deletedCount . ' accounts deleted');
} catch (PDOException $e) {
    error_log('Delete account API error: ' . $e->getMessage());
    api_error('Delete failed', 500);
}
