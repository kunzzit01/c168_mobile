<?php
/**
 * Shared session + scope bootstrap for Data Capture submissions / process picker APIs.
 */

function dcSubmissionsApiInit(): array
{
    global $pdo, $company_id, $capture_scope_group, $capture_scope_ctx, $scopeParams;

    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/permissions.php';
    require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
    require_once __DIR__ . '/data_capture_scope_common.php';
    require_once __DIR__ . '/submitted_process_lib.php';

    dcEnsureSubmittedProcessesScopeColumns($pdo);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        session_write_close();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'User not authenticated']);
        exit;
    }

    $scopeParams = array_merge($_GET, $_POST);
    $capture_scope_group = false;
    $capture_scope_ctx = [];

    try {
        if (dcRequestHasExplicitScope($scopeParams)) {
            $scopeResolved = resolveDataCaptureRequestScope($pdo, $scopeParams);
            $capture_scope_ctx = dcFinalizeDualTenantCaptureScope($pdo, $scopeResolved, $scopeParams);
            $company_id = (int) $capture_scope_ctx['company_id'];
            $capture_scope_group = (bool) $capture_scope_ctx['is_group_scope'];
        } else {
            $company_id = null;
            if (isset($scopeParams['company_id']) && $scopeParams['company_id'] !== '') {
                $company_id = (int) $scopeParams['company_id'];
            } elseif (isset($_SESSION['company_id'])) {
                $company_id = (int) $_SESSION['company_id'];
            }
            $capture_scope_group = false;
            $capture_scope_ctx = [
                'company_id' => (int) ($company_id ?? 0),
                'anchor_company_id' => (int) ($company_id ?? 0),
                'is_group_scope' => false,
                'dual_tenant' => tenant_table_has_scope_columns($pdo, 'data_captures'),
                'submitted_dual_tenant' => dcSubmittedProcessesDualTenantEnabled($pdo),
                'scope_process_sql' => '',
            ];
        }
    } catch (Exception $scopeException) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $scopeException->getMessage()]);
        exit;
    }

    if (!$company_id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => '缺少公司信息']);
        exit;
    }

    $groupIdForAccess = dcNormalizeGroupId($scopeParams['group_id'] ?? '');
    if (!checkReportMaintenanceAccess($pdo, $company_id, $groupIdForAccess !== '' ? $groupIdForAccess : null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized category permission (Games or Bank required)']);
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $user_id = (int) $_SESSION['user_id'];

    return [
        'action' => $action,
        'user_id' => $user_id,
    ];
}

function dcSubmittedProcessScopeFilter(string $processAlias = 'p'): string
{
    global $capture_scope_group, $pdo, $company_id, $capture_scope_ctx;
    if (!empty($capture_scope_ctx['scope_process_sql'])) {
        return (string) $capture_scope_ctx['scope_process_sql'];
    }
    if ($capture_scope_group) {
        return dcSqlGroupProcessFilter($processAlias);
    }

    return dcSqlDataCaptureCompanyProcessFilter($pdo, (int) ($company_id ?? 0), $processAlias);
}

function dcSubmittedLedgerFilter(string $alias, string $table = 'submitted_processes'): array
{
    global $pdo, $capture_scope_ctx, $company_id;
    if (!empty($capture_scope_ctx)) {
        return dcBuildCaptureLedgerFilter($pdo, $capture_scope_ctx, $alias, $table);
    }

    return [
        'sql' => ' AND ' . preg_replace('/[^a-zA-Z0-9_]/', '', $alias) . '.company_id = ? ',
        'bind' => (int) $company_id,
        'uses_dual_tenant' => false,
    ];
}
