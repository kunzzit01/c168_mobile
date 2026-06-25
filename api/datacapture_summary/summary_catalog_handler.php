<?php
/**
 * Summary form catalog — currencies and accounts for Edit Formula / Add Account.
 */
require_once __DIR__ . '/summary_api_lib.php';

function dcSummaryApiHandleLoadCatalog(): void
{
    global $pdo, $company_id, $capture_scope_group, $scopeParams, $groupIdForAccess;

    try {
        $groupCodeForCatalog = dcNormalizeGroupId(
            $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ($groupIdForAccess ?? '')
        );
        $isGroupCatalog = !empty($capture_scope_group);
        $currencies = dcSummaryLoadFormCurrencies($pdo, $isGroupCatalog, (int) $company_id, $groupCodeForCatalog);
        $accounts = dcSummaryLoadFormAccounts($pdo, $isGroupCatalog, (int) $company_id, $groupCodeForCatalog);

        error_log(
            'Summary form catalog - scope='
            . ($isGroupCatalog ? 'group' : 'company')
            . ' group=' . $groupCodeForCatalog
            . ' accounts=' . count($accounts)
            . ' currencies=' . count($currencies)
            . ' company_id=' . (int) $company_id
        );

        echo json_encode([
            'success' => true,
            'currencies' => $currencies,
            'accounts' => $accounts,
            'scope' => $isGroupCatalog ? 'group' : 'company',
            'debug' => [
                'accounts_count' => count($accounts),
                'currencies_count' => count($currencies),
                'company_id' => $company_id,
                'capture_scope_group' => $isGroupCatalog,
                'group_code' => $groupCodeForCatalog,
            ],
        ]);
    } catch (Exception $e) {
        error_log('Summary catalog error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
        ]);
    }
}
