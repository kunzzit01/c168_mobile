<?php
/**
 * Shared session + scope bootstrap for Summary APIs.
 */

function dcSummaryApiInitScope(): void
{
    global $pdo, $company_id, $capture_scope_group, $capture_scope_ctx, $scopeParams, $groupIdForAccess, $viewGroupForAccess;

    require_once __DIR__ . '/../../includes/config.php';
    require_once __DIR__ . '/../../includes/permissions.php';
    require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
    require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';
    require_once __DIR__ . '/../datacapture/submitted_process_lib.php';

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '用户未登录', 'data' => null]);
        exit;
    }

    $scopeParams = array_merge($_GET, $_POST);
    $capture_scope_group = false;
    $capture_scope_ctx = [];
    $req_action_for_company = isset($_GET['action']) ? (string) $_GET['action'] : '';
    $hasExplicitScope = dcRequestHasExplicitScope($scopeParams);

    try {
        if ($hasExplicitScope) {
            $scopeResolved = resolveDataCaptureRequestScope($pdo, $scopeParams);
            $scopeCtx = dcFinalizeCaptureMaintenanceScope($pdo, $scopeResolved, $scopeParams);
            $capture_scope_ctx = $scopeCtx;
            $company_id = (int) $scopeCtx['company_id'];
            $capture_scope_group = (bool) $scopeCtx['is_group_scope'];
        } else {
            $company_id = null;
            if (isset($scopeParams['company_id']) && $scopeParams['company_id'] !== '') {
                $company_id = (int) $scopeParams['company_id'];
            } elseif (isset($_SESSION['company_id'])) {
                $company_id = (int) $_SESSION['company_id'];
            }
            if (
                !$hasExplicitScope
                && ($req_action_for_company === 'save_summary_state' || $req_action_for_company === 'get_summary_state')
                && isset($_SESSION['company_id'])
                && (int) $_SESSION['company_id'] > 0
            ) {
                $company_id = (int) $_SESSION['company_id'];
            }
            $capture_scope_group = false;
        }
    } catch (Exception $scopeException) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $scopeException->getMessage(), 'data' => null]);
        exit;
    }

    if (!$company_id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '缺少公司信息', 'data' => null]);
        exit;
    }

    $groupIdForAccess = dcNormalizeGroupId($scopeParams['group_id'] ?? '');
    if (!checkReportGamesAccess($pdo, $company_id, $groupIdForAccess !== '' ? $groupIdForAccess : null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized permission category', 'data' => null]);
        exit;
    }

    $viewGroupForAccess = dcNormalizeGroupId(
        $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ''
    );
    try {
        dcAssertUserCanAccessCompany(
            $pdo,
            (int) $company_id,
            $viewGroupForAccess !== '' ? $viewGroupForAccess : null
        );
    } catch (Exception $accessException) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $accessException->getMessage(), 'data' => null]);
        exit;
    }
}

function dcSummaryApiStartSession(): void
{
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        session_write_close();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
}
