<?php
/**
 * Dashboard / group scope: currencies from account_currency on KPI-scoped accounts.
 * Path: api/transactions/get_scope_account_currencies_api.php
 */

session_start();
session_write_close();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/transaction_scope.php';
require_once __DIR__ . '/../reports/report_scope_common.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../api_response.php';

define('DASHBOARD_API_SKIP_MAIN', true);
require_once __DIR__ . '/dashboard_api.php';

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        api_error('用户未登录', 401);
        exit;
    }

    $groupCode = reportNormalizeGroupId($_GET['group_id'] ?? $_GET['view_group'] ?? '');
    $companyIdsRaw = trim((string) ($_GET['company_ids'] ?? ''));
    $companyIds = [];
    if ($companyIdsRaw !== '') {
        foreach (explode(',', $companyIdsRaw) as $part) {
            $n = (int) trim($part);
            if ($n > 0) {
                $companyIds[$n] = true;
            }
        }
        $companyIds = array_keys($companyIds);
    }

    $primaryCompanyId = 0;
    if (isset($_GET['company_id']) && trim((string) $_GET['company_id']) !== '') {
        $primaryCompanyId = (int) tx_resolve_request_company_id($pdo, $_GET);
        if ($primaryCompanyId > 0 && !in_array($primaryCompanyId, $companyIds, true)) {
            $companyIds[] = $primaryCompanyId;
        }
    }

    $viewGroup = reportNormalizeGroupId($_GET['view_group'] ?? $groupCode);
    $groupAggregateOnly = isset($_GET['group_aggregate']) && (string) $_GET['group_aggregate'] === '1';
    $subsidiaryAccountsOnly = isset($_GET['subsidiary_accounts_only']) && (string) $_GET['subsidiary_accounts_only'] === '1';

    // Strict group Currency Setting only when no subsidiary company is selected (Company pill).
    $strictGroupCurrency = $groupAggregateOnly
        || ($viewGroup !== '' && $primaryCompanyId <= 0 && !$subsidiaryAccountsOnly && $companyIdsRaw === '');

    if ($strictGroupCurrency && $viewGroup !== '') {
        if (!gc_session_can_access_group_ledger($pdo, $viewGroup)) {
            api_error('无权访问该 Group Ledger', 403);
            exit;
        }
        $map = dashboardResolveGroupScopeCurrencyMap($pdo, $viewGroup);
        $rows = [];
        foreach ($map as $id => $code) {
            $rows[] = ['id' => (int) $id, 'code' => $code];
        }
        usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        api_success($rows);
        exit;
    }

    if ($groupAggregateOnly && $viewGroup !== '') {
        $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroup);
        if ($entityId > 0) {
            $primaryCompanyId = $entityId;
            $companyIds = [$entityId];
        }
    }

    $currencyCompanyIds = [];
    $accountIds = [];

    if ($viewGroup !== '' && !$subsidiaryAccountsOnly) {
        if (!gc_session_can_access_group_ledger($pdo, $viewGroup)) {
            api_error('无权访问该 Group Ledger', 403);
            exit;
        }
        $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroup);
        if ($entityId > 0) {
            $currencyCompanyIds = [$entityId];
        }
        $accountIds = dashboardCollectGroupOnlyAccountIds($pdo, $viewGroup);
        // Group-only currency: never merge subsidiary / linked company accounts.
        if (!$groupAggregateOnly && $primaryCompanyId > 0) {
            $subsidiaryIds = gc_company_numeric_ids_for_group_code($pdo, $viewGroup);
            foreach ($subsidiaryIds as $subId) {
                $accountIds = array_merge(
                    $accountIds,
                    dashboardCollectScopeAccountIds($pdo, (int) $subId, $viewGroup, 0)
                );
            }
        }
        $accountIds = array_values(array_unique(array_filter($accountIds)));
    } elseif ($groupCode !== '' && $primaryCompanyId <= 0 && $companyIds === []) {
        if (!gc_session_can_access_group_ledger($pdo, $groupCode)) {
            api_error('无权访问该 Group Ledger', 403);
            exit;
        }
        $map = dashboardResolveGroupScopeCurrencyMap($pdo, $groupCode);
        $rows = [];
        foreach ($map as $id => $code) {
            $rows[] = ['id' => (int) $id, 'code' => $code];
        }
        usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        api_success($rows);
        exit;
    } else {
        if ($primaryCompanyId <= 0 && $companyIds !== []) {
            $primaryCompanyId = (int) $companyIds[0];
        }
        if ($primaryCompanyId <= 0) {
            // Prefetch / warm-cache callers may race before scope is ready; avoid noisy 400.
            api_success([]);
            exit;
        }

        if ($groupAggregateOnly && $viewGroup !== '') {
            $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroup);
            if ($entityId > 0) {
                $currencyCompanyIds = [$entityId];
                $primaryCompanyId = $entityId;
            }
            $accountIds = dashboardCollectGroupOnlyAccountIds($pdo, $viewGroup);
        } elseif ($subsidiaryAccountsOnly && $primaryCompanyId > 0) {
            // Subsidiary drill-down: Currency Setting table only (exclude group SGD on shared anchor FK).
            $map = dashboardLoadCurrencyMap($pdo, $primaryCompanyId, true);
            $rows = [];
            foreach ($map as $id => $code) {
                $rows[] = ['id' => (int) $id, 'code' => $code];
            }
            usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
            api_success($rows);
            exit;
        } else {
            foreach ($companyIds as $cid) {
                $currencyCompanyIds[] = (int) $cid;
            }
            if ($viewGroup !== '') {
                $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroup);
                if ($entityId > 0) {
                    $currencyCompanyIds[] = $entityId;
                }
            }
            $currencyCompanyIds = array_values(array_unique(array_filter($currencyCompanyIds)));
            $accountIds = dashboardCollectScopeAccountIds(
                $pdo,
                $primaryCompanyId,
                $viewGroup !== '' ? $viewGroup : null,
                0
            );
        }
    }

    $map = dashboardLoadAccountCurrencyMap($pdo, $accountIds, $currencyCompanyIds, false);
    // Subsidiary drill-down (e.g. C168 under AP): use that company's Currency Setting, not group-only SGD.
    if (
        $viewGroup !== ''
        && !$subsidiaryAccountsOnly
        && ($groupAggregateOnly || $primaryCompanyId <= 0)
    ) {
        $map = dashboardRestrictCurrencyMapToGroupTenant($pdo, $viewGroup, $map);
    }

    $rows = [];
    foreach ($map as $id => $code) {
        $rows[] = ['id' => (int) $id, 'code' => $code];
    }
    usort($rows, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

    api_success($rows);
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}
