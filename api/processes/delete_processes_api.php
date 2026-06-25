<?php
/**
 * 批量删除 Process API（Games / Bank，仅允许删除 inactive）
 * 路径: api/processes/delete_processes_api.php
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../includes/process_modified_by.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';
require_once __DIR__ . '/../api_response.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
    exit;
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 删除 Process 前，先将历史表中的 process_id 断开关联，防止历史数据随主档删除而丢失。
 * 仅处理存在 process_id 列且允许 NULL 的表；若列不允许 NULL，则跳过以避免破坏既有约束。
 */
function detachProcessHistoryReferences(PDO $pdo, array $processIds, array $companyIds): void
{
    if (empty($processIds)) {
        return;
    }

    $targets = [
        'data_captures',
        'data_capture_details',
        'submitted_processes',
    ];

    $idPlaceholders = implode(',', array_fill(0, count($processIds), '?'));

    foreach ($targets as $table) {
        if (!tableHasColumn($pdo, $table, 'process_id')) {
            continue;
        }

        $nullabilityStmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE 'process_id'");
        $nullabilityStmt->execute();
        $columnMeta = $nullabilityStmt->fetch(PDO::FETCH_ASSOC);
        $isNullable = isset($columnMeta['Null']) && strtoupper((string)$columnMeta['Null']) === 'YES';
        if (!$isNullable) {
            // 保守处理：列不允许 NULL 时不强行改值，避免影响其他既有逻辑/约束。
            continue;
        }

        $where = "process_id IN ($idPlaceholders)";
        $params = $processIds;
        if (!empty($companyIds) && tableHasColumn($pdo, $table, 'company_id')) {
            $companyPlaceholders = implode(',', array_fill(0, count($companyIds), '?'));
            $where .= " AND company_id IN ($companyPlaceholders)";
            $params = array_merge($params, $companyIds);
        }

        $sql = "UPDATE `$table` SET process_id = NULL WHERE $where";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}

try {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
        api_error('User not logged in or company not selected', 401);
        exit;
    }

    if (is_partnership_audit_read_only_active($pdo)) {
        api_error('只读账号无法执行此操作', 403);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = isset($input['ids']) ? (array) $input['ids'] : (isset($_POST['ids']) ? (array) $_POST['ids'] : []);
    $ids = array_map('intval', array_filter($ids));
    $permission = isset($input['permission']) ? trim($input['permission']) : (isset($_POST['permission']) ? trim($_POST['permission']) : '');

    if (empty($ids)) {
        api_error('No process IDs provided', 400);
        exit;
    }

    $company_id_session = (int) $_SESSION['company_id'];
    if ($company_id_session <= 0) {
        api_error('User not logged in or company not selected', 401);
        exit;
    }

    try {
        $viewGroup = gc_is_group_login() ? gc_session_login_identifier() : null;
        gc_assert_api_company_access($pdo, $company_id_session, $viewGroup);
    } catch (RuntimeException $e) {
        api_error('No permission to access this company', 403);
        exit;
    }

    if ($permission === 'Bank') {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive'");
        $params = array_merge($ids, [$company_id_session]);
        $stmt->execute($params);
        $inactiveIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($inactiveIds)) {
            api_error('No inactive processes to delete', 400, ['error' => 'no_inactive_processes']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive' AND day_start IS NOT NULL");
        $stmt->execute($params);
        $withDayStart = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($withDayStart)) {
            api_error('Cannot delete bank process with day_start set', 400, ['error' => 'bank_has_day_start']);
            exit;
        }
        $hasSourceBankProcessId = false;
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
            $hasSourceBankProcessId = $colStmt && $colStmt->rowCount() > 0;
        } catch (PDOException $e) { /* ignore */ }
        if ($hasSourceBankProcessId) {
            $papPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
            $stmt = $pdo->prepare("SELECT source_bank_process_id FROM transactions WHERE company_id = ? AND source_bank_process_id IN ($papPlaceholders) LIMIT 1");
            $stmt->execute(array_merge([$company_id_session], $inactiveIds));
            if ($stmt->fetch()) {
                api_error('Process has transactions', 400, ['error' => 'process_has_transactions']);
                exit;
            }
        } else {
            $papPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
            $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND process_id IN ($papPlaceholders) LIMIT 1");
            $stmt->execute(array_merge([$company_id_session], $inactiveIds));
            if ($stmt->fetch()) {
                api_error('Process has transactions', 400, ['error' => 'process_has_transactions']);
                exit;
            }
        }
        $uTagProc = (string) ($_SESSION['login_id'] ?? $_SESSION['name'] ?? '');
        $pTagProc = '/api/processes/delete_processes_api.php';
        foreach ($inactiveIds as $bpid) {
            deletedLog($pdo, $uTagProc, $pTagProc, 'bank_process', (string) $bpid);
        }
        $delPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM bank_process WHERE id IN ($delPlaceholders) AND company_id = ? AND status = 'inactive'");
        $stmt->execute(array_merge($inactiveIds, [$company_id_session]));
        $deletedCount = $stmt->rowCount();
        api_success(['deleted' => $deletedCount], $deletedCount === 1 ? '1 process deleted' : $deletedCount . ' processes deleted');
        exit;
    }

    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $gamesParams = array_merge($ids, [$company_id_session]);
    $stmt = $pdo->prepare(
        "SELECT id, process_id, company_id FROM process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive'"
    );
    $stmt->execute($gamesParams);
    $processesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($processesToDelete) || count($processesToDelete) !== count($ids)) {
        api_error('No inactive processes to delete', 400, ['error' => 'no_inactive_processes']);
        exit;
    }

    $processIds = array_column($processesToDelete, 'id');
    $formulaCount = 0;
    if (!empty($processIds)) {
        $idPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
        $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates WHERE process_id IN ($idPlaceholders) AND company_id = ?";
        $formulaCheckParams = array_merge($processIds, [$company_id_session]);
        $stmt = $pdo->prepare($formulaCheckSql);
        $stmt->execute($formulaCheckParams);
        $formulaCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    if ($formulaCount > 0) {
        api_error('Process linked to formula', 400, ['error' => 'process_linked_to_formula']);
        exit;
    }

    $hasProcessIdCol = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'process_id'");
        $hasProcessIdCol = $colStmt && $colStmt->rowCount() > 0;
    } catch (PDOException $e) { /* ignore */ }
    if ($hasProcessIdCol) {
        $txnPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
        $stmt = $pdo->prepare(
            "SELECT process_id FROM transactions WHERE company_id = ? AND process_id IN ($txnPlaceholders) LIMIT 1"
        );
        $stmt->execute(array_merge([$company_id_session], $processIds));
        if ($stmt->fetch()) {
            api_error('Process has transactions', 400, ['error' => 'process_has_transactions']);
            exit;
        }
    }

    // 安全删除策略：
    // 为避免触发外键级联导致历史 ID_Product / Data Capture 数据被删除，
    // 这里改为软删除（status: inactive -> waiting），不执行物理 DELETE。
    // processlist API 仅展示 active / inactive，因此 waiting 不会在列表中出现。
    $pdo->beginTransaction();
    $modifier = resolveProcessModifierFromSession($pdo);
    $softDeletePlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
    $stmt = $pdo->prepare(
        "UPDATE process SET status = 'waiting'"
        . processModifiedBySqlSuffix()
        . " WHERE id IN ($softDeletePlaceholders) AND company_id = ? AND status = 'inactive'"
    );
    $stmt->execute(array_merge(
        processModifiedByBindParams($modifier),
        $processIds,
        [$company_id_session]
    ));
    $deletedCount = $stmt->rowCount();
    $pdo->commit();
    api_success(
        ['deleted' => $deletedCount, 'mode' => 'soft_delete_waiting'],
        $deletedCount === 1 ? '1 process deleted' : $deletedCount . ' processes deleted'
    );
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Delete process API error: " . $e->getMessage());
    api_error('Delete failed', 500);
}