<?php
/**
 * Auto renew manual approval API.
 * Path: api/subscription/auto_renew_api.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../includes/auto_renew.php';
require_once __DIR__ . '/../c168/c168_domain_access.php';

session_start();

function auto_renew_json_response(bool $success, string $message, $data = null, ?int $httpCode = null): void
{
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    auto_renew_json_response(false, 'Unauthorized access', null, 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auto_renew_json_response(false, 'Invalid request method', null, 405);
}

$userType = strtolower(trim((string) ($_SESSION['user_type'] ?? '')));
if ($userType === 'member') {
    auto_renew_json_response(false, 'Members cannot access auto renew', null, 403);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$action = strtolower(trim((string) ($input['action'] ?? 'list')));

try {
    auto_renew_ensure_columns($pdo);
    auto_renew_ensure_request_table($pdo);

    if (!auto_renew_page_access($pdo, $_SESSION)) {
        session_write_close();
        auto_renew_json_response(false, 'Access denied', null, 403);
    }

    $canEdit = auto_renew_can_edit($_SESSION, $pdo);

    if ($action === 'list') {
        $statusFilter = strtolower(trim((string) ($input['status'] ?? 'pending')));
        $dateFrom = trim((string) ($input['date_from'] ?? ''));
        $dateTo = trim((string) ($input['date_to'] ?? ''));
        $entityType = trim((string) ($input['entity_type'] ?? 'company'));
        $result = auto_renew_list_approvals(
            $pdo,
            $statusFilter,
            $dateFrom !== '' ? $dateFrom : null,
            $dateTo !== '' ? $dateTo : null,
            $entityType !== '' ? $entityType : 'company'
        );
        session_write_close();
        auto_renew_json_response(true, 'success', [
            'rows' => $result['rows'],
            'accounts' => $result['accounts'],
            'counts' => $result['counts'],
            'tab_pending_counts' => $result['tab_pending_counts'] ?? ['company' => 0, 'group' => 0],
            'can_edit' => $canEdit,
            'fee_settings' => auto_renew_fee_settings_for_api($pdo),
        ]);
    }

    if ($action === 'list_accounts') {
        $c168Pk = auto_renew_get_c168_pk($pdo) ?? 0;
        $accounts = auto_renew_list_c168_accounts($pdo, $c168Pk);
        $companyCode = trim((string) ($input['company_code'] ?? ''));
        $defaultFrom = null;
        $defaultTo = null;
        if ($companyCode !== '' && $c168Pk > 0) {
            $defaultTo = auto_renew_resolve_default_to_account($pdo, $c168Pk);
            $defaultFrom = auto_renew_resolve_default_from_account(
                $pdo,
                $c168Pk,
                $companyCode,
                (int) ($defaultTo ?? 0)
            );
        } elseif ($c168Pk > 0) {
            $defaultTo = auto_renew_resolve_default_to_account($pdo, $c168Pk);
        }
        session_write_close();
        auto_renew_json_response(true, 'success', [
            'accounts' => $accounts,
            'default_from_account_id' => $defaultFrom,
            'default_to_account_id' => $defaultTo,
        ]);
    }

    if ($action === 'status_map') {
        if (!auto_renew_status_map_access($pdo, $_SESSION)) {
            session_write_close();
            auto_renew_json_response(false, 'Access denied', null, 403);
        }
        session_write_close();
        auto_renew_json_response(true, 'success', [
            'status_map' => auto_renew_status_map($pdo),
        ]);
    }

    if ($action === 'pending_count') {
        session_write_close();
        auto_renew_json_response(true, 'success', [
            'pending_count' => auto_renew_count_pending($pdo),
        ]);
    }

    // Legacy: keep list_companies for backward compatibility during transition
    if ($action === 'list_companies') {
        session_write_close();
        auto_renew_json_response(true, 'success', [
            'companies' => auto_renew_list_client_companies($pdo),
            'can_edit' => $canEdit,
        ]);
    }

    if ($action === 'delete') {
        if (!$canEdit) {
            session_write_close();
            auto_renew_json_response(false, 'Access denied', null, 403);
        }
        $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
        if ($requestId <= 0) {
            session_write_close();
            auto_renew_json_response(false, 'Invalid request_id', null, 400);
        }
        $row = auto_renew_delete($pdo, $requestId, $_SESSION, $input);
        session_write_close();
        auto_renew_json_response(true, 'Renewal deleted', $row);
    }

    $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
    if ($requestId <= 0) {
        session_write_close();
        auto_renew_json_response(false, 'Invalid request_id', null, 400);
    }

    if ($action === 'save_draft') {
        if (!$canEdit) {
            session_write_close();
            auto_renew_json_response(false, 'Access denied', null, 403);
        }
        $row = auto_renew_save_draft($pdo, $requestId, $input, $_SESSION);
        session_write_close();
        auto_renew_json_response(true, 'Draft saved', $row);
    }

    if ($action === 'approve') {
        if (!$canEdit) {
            session_write_close();
            auto_renew_json_response(false, 'Access denied', null, 403);
        }
        $row = auto_renew_approve($pdo, $requestId, $input, $_SESSION);
        session_write_close();
        auto_renew_json_response(true, 'Renewal approved', $row);
    }

    if ($action === 'reject') {
        if (!$canEdit) {
            session_write_close();
            auto_renew_json_response(false, 'Access denied', null, 403);
        }
        $row = auto_renew_reject($pdo, $requestId, $input, $_SESSION);
        session_write_close();
        auto_renew_json_response(true, 'Selection cleared', $row);
    }

    session_write_close();
    auto_renew_json_response(false, 'Unknown action', null, 400);
} catch (RuntimeException $e) {
    session_write_close();
    auto_renew_json_response(false, $e->getMessage(), null, 400);
} catch (PDOException $e) {
    error_log('auto_renew_api PDO: ' . $e->getMessage());
    session_write_close();
    auto_renew_json_response(false, 'Database error', null, 500);
} catch (Throwable $e) {
    error_log('auto_renew_api: ' . $e->getMessage());
    session_write_close();
    auto_renew_json_response(false, 'System error', null, 500);
}
