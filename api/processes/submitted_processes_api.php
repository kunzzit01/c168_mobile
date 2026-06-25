<?php
/**
 * Legacy submitted-process API (week/date/today reports).
 * Data Capture actions forward to api/datacapture/submissions_api.php.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';
require_once __DIR__ . '/../datacapture/submitted_process_lib.php';

dcEnsureSubmittedProcessesScopeColumns($pdo);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    session_write_close();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$captureActions = [
    'get_submissions_by_capture_date',
    'get_processes_by_day',
    'save_submission',
    'get_group_process_id',
];
if (in_array($action, $captureActions, true)) {
    require __DIR__ . '/../datacapture/submissions_api.php';
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

$user_id = (int) $_SESSION['user_id'];

$groupIdForAccess = dcNormalizeGroupId($scopeParams['group_id'] ?? '');
if (!checkReportGamesAccess($pdo, $company_id, $groupIdForAccess !== '' ? $groupIdForAccess : null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized category permission (Games required)']);
    exit;
}

try {
    switch ($action) {
        case 'get_week_submissions':
            getWeekSubmissions($user_id);
            break;
        case 'get_submissions_by_date':
            getSubmissionsByDate($user_id);
            break;
        case 'get_today_entries':
            getTodayEntries($user_id);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    error_log('Submitted Processes API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

function getWeekSubmissions($user_id)
{
    global $pdo, $company_id;

    $currentCompanyId = $company_id;
    if (!$currentCompanyId) {
        echo json_encode(['success' => false, 'error' => 'User company_id not found']);
        return;
    }

    $start_of_week = date('Y-m-d', strtotime('monday this week'));
    $end_of_week = date('Y-m-d', strtotime('sunday this week'));
    $processIds = [];
    $user_type = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' ? 'owner' : 'user';

    if ($user_type === 'user') {
        $processIds = dcLoadCaptureProcessPermissionIds(
            $pdo,
            (int) $user_id,
            (int) $currentCompanyId,
            $user_type
        );
    }

    $permissionCondition = '';
    if (!empty($processIds)) {
        $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
        $permissionCondition = "AND p.id IN ($placeholders)";
    }

    $stmt = $pdo->prepare("
        SELECT
            sp.id,
            sp.process_id,
            sp.date_submitted,
            sp.created_at,
            sp.user_type,
            p.process_id as process_code,
            d.name as description_name,
            COALESCE(u.login_id, o.owner_code) as submitted_by
        FROM submitted_processes sp
        JOIN process p ON sp.process_id = p.id
        LEFT JOIN description d ON p.description_id = d.id
        LEFT JOIN user u ON sp.user_id = u.id AND sp.user_type = 'user'
        LEFT JOIN owner o ON sp.user_id = o.id AND sp.user_type = 'owner'
        WHERE sp.company_id = ?
          AND sp.date_submitted BETWEEN ? AND ?
          AND p.company_id = ?
        $permissionCondition
        ORDER BY sp.date_submitted DESC, sp.created_at DESC
    ");

    $params = array_merge(
        [$currentCompanyId, $start_of_week, $end_of_week, $currentCompanyId],
        !empty($processIds) ? $processIds : []
    );

    try {
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'data' => $submissions,
            'week_range' => ['start' => $start_of_week, 'end' => $end_of_week],
        ]);
    } catch (PDOException $e) {
        error_log('SQL Error in getWeekSubmissions: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getSubmissionsByDate($user_id)
{
    global $pdo, $company_id;

    try {
        $currentCompanyId = $company_id;
        if (!$currentCompanyId) {
            echo json_encode(['success' => false, 'error' => 'User company_id not found']);
            return;
        }

        $selected_date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
            echo json_encode(['success' => false, 'error' => 'Invalid date format']);
            return;
        }

        $processIds = [];
        $user_type = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' ? 'owner' : 'user';
        if ($user_type === 'user') {
            $processIds = dcLoadCaptureProcessPermissionIds(
                $pdo,
                (int) $user_id,
                (int) $currentCompanyId,
                $user_type
            );
        }

        $permissionCondition = '';
        if (!empty($processIds) && is_array($processIds)) {
            $processIds = array_values(array_filter($processIds, 'is_numeric'));
            if (!empty($processIds)) {
                $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
                $permissionCondition = "AND p.id IN ($placeholders)";
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                sp.id,
                sp.process_id,
                sp.date_submitted,
                sp.capture_date,
                sp.created_at,
                sp.user_type,
                p.process_id as process_code,
                d.name as description_name,
                COALESCE(u.login_id, o.owner_code) as submitted_by
            FROM submitted_processes sp
            JOIN process p ON sp.process_id = p.id
            LEFT JOIN description d ON p.description_id = d.id
            LEFT JOIN user u ON sp.user_id = u.id AND sp.user_type = 'user'
            LEFT JOIN owner o ON sp.user_id = o.id AND sp.user_type = 'owner'
            WHERE sp.company_id = ?
              AND DATE(sp.date_submitted) = ?
              AND p.company_id = ?
            $permissionCondition
            ORDER BY sp.created_at DESC
        ");

        $params = array_merge(
            [$currentCompanyId, $selected_date, $currentCompanyId],
            !empty($processIds) ? $processIds : []
        );
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $submissions,
            'selected_date' => $selected_date,
        ]);
    } catch (PDOException $e) {
        error_log('SQL Error in getSubmissionsByDate: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log('Error in getSubmissionsByDate: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Internal error: ' . $e->getMessage()]);
    }
}

function getTodayEntries($user_id)
{
    global $pdo, $company_id;

    try {
        $currentCompanyId = $company_id;
        if (!$currentCompanyId) {
            echo json_encode(['success' => false, 'error' => 'Company ID not found']);
            return;
        }

        $processIds = [];
        $user_type = $_SESSION['user_type'] ?? 'user';
        if ($user_type === 'user') {
            $processIds = dcLoadCaptureProcessPermissionIds(
                $pdo,
                (int) $user_id,
                (int) $currentCompanyId,
                $user_type
            );
        }

        $permissionCondition = '';
        $params = [$currentCompanyId];
        if (!empty($processIds)) {
            $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
            $permissionCondition = "AND p.id IN ($placeholders)";
            $params = array_merge($params, $processIds);
        }

        $stmt = $pdo->prepare("
            SELECT
                sp.id, sp.process_id, sp.date_submitted, sp.capture_date, sp.created_at,
                p.process_id as process_code, d.name as description_name,
                COALESCE(u.login_id, o.owner_code) as submitted_by
            FROM submitted_processes sp
            JOIN process p ON sp.process_id = p.id
            LEFT JOIN description d ON p.description_id = d.id
            LEFT JOIN user u ON sp.user_id = u.id AND sp.user_type = 'user'
            LEFT JOIN owner o ON sp.user_id = o.id AND sp.user_type = 'owner'
            WHERE sp.company_id = ?
              AND DATE(sp.created_at) = CURDATE()
              $permissionCondition
            ORDER BY sp.created_at DESC
        ");

        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $submissions]);
    } catch (Exception $e) {
        error_log('Error in getTodayEntries: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
