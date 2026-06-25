<?php
/**
 * Capture Maintenance Delete API
 * 根据筛选条件批量删除 Data Capture 明细数据
 * 路径: api/capture_maintenance/delete_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';
require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';

/**
 * 标准 JSON 响应：success, message, data
 */
function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 确保删除日志表存在
 */
function ensureDeletedLogTable(PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS data_captures_deleted (
            id INT AUTO_INCREMENT PRIMARY KEY,
            capture_id INT NOT NULL,
            company_id INT NOT NULL,
            process_id INT NOT NULL,
            currency_id INT NOT NULL,
            capture_date DATE NOT NULL,
            created_at TIMESTAMP NULL,
            created_by INT NULL,
            user_type ENUM('user', 'owner') NOT NULL DEFAULT 'user',
            remark TEXT NULL,
            deleted_by_user_id INT NULL,
            deleted_by_owner_id INT NULL,
            deleted_at TIMESTAMP NULL,
            INDEX idx_company_date (company_id, capture_date),
            INDEX idx_capture_id (capture_id),
            INDEX idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($sql);
}

/**
 * 验证 capture_id 是否属于当前公司且在日期范围内，返回有效 ID 列表
 */
function validateCaptureIds(
    PDO $pdo,
    array $scopeCtx,
    array $captureIds,
    string $date_from_db,
    string $date_to_db,
    string $scopeProcessFilter = ''
) {
    if (empty($captureIds)) {
        return [];
    }
    $ledgerDc = dcBuildCaptureLedgerFilter($pdo, $scopeCtx, 'dc', 'data_captures');
    $processCompanyId = dcCaptureProcessCompanyId($scopeCtx);
    $placeholders = str_repeat('?,', count($captureIds) - 1) . '?';
    $sql = "SELECT dc.id
            FROM data_captures dc
            INNER JOIN process p ON dc.process_id = p.id
            WHERE 1=1 {$ledgerDc['sql']}
              AND dc.id IN ($placeholders)
              AND dc.capture_date BETWEEN ? AND ?
              AND p.company_id = ?
              $scopeProcessFilter";
    $params = array_merge(
        dcCaptureLedgerBindParams($ledgerDc),
        $captureIds,
        [$date_from_db, $date_to_db, $processCompanyId]
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 将待删除记录备份到 data_captures_deleted
 */
function backupToDeletedLog(PDO $pdo, int $company_id, array $validCaptureIds, ?int $deletedByUserId, ?int $deletedByOwnerId) {
    $placeholders = str_repeat('?,', count($validCaptureIds) - 1) . '?';
    $sql = "
        INSERT INTO data_captures_deleted (
            capture_id, company_id, process_id, currency_id, capture_date,
            created_at, created_by, user_type, remark, deleted_by_user_id, deleted_by_owner_id, deleted_at
        )
        SELECT dc.id AS capture_id, ?, dc.process_id, dc.currency_id, dc.capture_date,
               dc.created_at, dc.created_by, dc.user_type, dc.remark, ?, ?, NOW()
        FROM data_captures dc
        INNER JOIN process p ON dc.process_id = p.id
        WHERE dc.id IN ($placeholders) AND dc.company_id = ? AND p.company_id = ?
    ";
    $params = array_merge([$company_id, $deletedByUserId, $deletedByOwnerId], $validCaptureIds, [$company_id, $company_id]);
    $pdo->prepare($sql)->execute($params);
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('无效的请求数据');
    }

    $scopeParams = $payload;
    $capture_scope_group = false;
    $hasExplicitScope = dcRequestHasExplicitScope($scopeParams);

    if ($hasExplicitScope) {
        $scopeResolved = resolveDataCaptureRequestScope($pdo, $scopeParams);
        $scopeCtx = dcFinalizeCaptureMaintenanceScope($pdo, $scopeResolved, $scopeParams);
        $company_id = (int) $scopeCtx['company_id'];
        $capture_scope_group = (bool) $scopeCtx['is_group_scope'];
        $scopeProcessFilter = (string) $scopeCtx['scope_process_sql'];
        $viewGroupForAccess = dcNormalizeGroupId(
            $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ''
        );
        dcAssertUserCanAccessCompany(
            $pdo,
            $company_id,
            $viewGroupForAccess !== '' ? $viewGroupForAccess : null
        );
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = (int) $_SESSION['company_id'];
        $capture_scope_group = false;
        $scopeCtx = [
            'company_id' => $company_id,
            'anchor_company_id' => $company_id,
            'is_group_scope' => false,
            'dual_tenant' => tenant_table_has_scope_columns($pdo, 'data_captures'),
            'submitted_dual_tenant' => dcSubmittedProcessesDualTenantEnabled($pdo),
        ];
        $scopeProcessFilter = dcSqlCompanyProcessFilter('p');
    }

    if ($capture_scope_group) {
        if ($company_id <= 0) {
            throw new Exception('集团范围无效或未配置集团公司');
        }
    } elseif ($company_id > 0 && dcCompanyIdIsGroupEntity($pdo, $company_id)) {
        throw new Exception('公司范围不能操作集团实体抓数记录');
    }

    $date_from = $payload['date_from'] ?? null;
    $date_to = $payload['date_to'] ?? null;
    $items = $payload['items'] ?? [];

    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    if (!is_array($items) || empty($items)) {
        throw new Exception('请选择要删除的记录');
    }

    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));

    $captureIds = [];
    foreach ($items as $item) {
        $capture_id = isset($item['capture_id']) ? (int)$item['capture_id'] : 0;
        if ($capture_id > 0) {
            $captureIds[] = $capture_id;
        }
    }
    if (empty($captureIds)) {
        throw new Exception('没有有效的记录可删除');
    }
    $captureIds = array_unique($captureIds);

    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    $userId = (int)$_SESSION['user_id'];
    $ownerId = isset($_SESSION['owner_id']) ? (int)$_SESSION['owner_id'] : null;
    $deletedByUserId = null;
    $deletedByOwnerId = null;
    if ($userRole === 'owner') {
        $deletedByOwnerId = $ownerId ?: $userId;
    } else {
        $deletedByUserId = $userId;
    }

    ensureDeletedLogTable($pdo);

    $pdo->beginTransaction();

    $validCaptureIds = validateCaptureIds(
        $pdo,
        $scopeCtx,
        $captureIds,
        $date_from_db,
        $date_to_db,
        $scopeProcessFilter
    );
    if (empty($validCaptureIds)) {
        $pdo->rollBack();
        throw new Exception('没有找到符合条件且属于当前公司的记录');
    }

    $pageTagCap = '/api/capture_maintenance/delete_api.php';
    $userTagCap = (string) ($_SESSION['login_id'] ?? $_SESSION['name'] ?? '');
    foreach ($validCaptureIds as $capId) {
        deletedLog($pdo, $userTagCap, $pageTagCap, 'data_captures', (string) $capId);
        $detIdsStmt = $pdo->prepare(
            'SELECT id FROM data_capture_details WHERE capture_id = ? AND company_id = ?'
        );
        $detIdsStmt->execute([(int) $capId, $company_id]);
        while ($did = $detIdsStmt->fetchColumn()) {
            deletedLog($pdo, $userTagCap, $pageTagCap, 'data_capture_details', (string) $did);
        }
    }

    backupToDeletedLog($pdo, $company_id, $validCaptureIds, $deletedByUserId, $deletedByOwnerId);

    // 同步删除 submitted_processes 里对应的「已提交记录」，
    // 确保当某个 Data Capture 被维护页删除后，Data Capture 页面右侧的 Submitted Processes 也不再显示这条记录。
    // 只按 company + process + capture_date 精准删除，不影响其他功能或历史记录。
    $placeholders = str_repeat('?,', count($validCaptureIds) - 1) . '?';
    $ledgerDc = dcBuildCaptureLedgerFilter($pdo, $scopeCtx, 'dc', 'data_captures');
    $processCompanyId = dcCaptureProcessCompanyId($scopeCtx);
    $captureMetaSql = "
        SELECT dc.id AS capture_id, dc.process_id, dc.capture_date
        FROM data_captures dc
        INNER JOIN process p ON dc.process_id = p.id
        WHERE 1=1 {$ledgerDc['sql']} AND p.company_id = ? AND dc.id IN ($placeholders)
    ";
    $captureMetaParams = array_merge(
        dcCaptureLedgerBindParams($ledgerDc),
        [$processCompanyId],
        $validCaptureIds
    );
    $captureMetaStmt = $pdo->prepare($captureMetaSql);
    $captureMetaStmt->execute($captureMetaParams);
    $captureMetaRows = $captureMetaStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($captureMetaRows)) {
        $ledgerSp = dcBuildCaptureLedgerFilter($pdo, $scopeCtx, 'sp', 'submitted_processes');
        $useSpScope = !empty($scopeCtx['submitted_dual_tenant']);
        if ($useSpScope) {
            $deleteSubmittedStmt = $pdo->prepare("
                DELETE FROM submitted_processes
                WHERE scope_type = ?
                  AND scope_id = ?
                  AND process_id = ?
                  AND (
                        (DATE(capture_date) = ?)
                     OR (capture_date IS NULL AND DATE(date_submitted) = ?)
                  )
            ");
        } else {
            $deleteSubmittedStmt = $pdo->prepare("
                DELETE FROM submitted_processes
                WHERE company_id = ?
                  AND process_id = ?
                  AND (
                        (DATE(capture_date) = ?)
                     OR (capture_date IS NULL AND DATE(date_submitted) = ?)
                  )
            ");
        }
        $scopeInsert = dcCaptureScopeInsertValues($scopeCtx);
        foreach ($captureMetaRows as $metaRow) {
            $procId = isset($metaRow['process_id']) ? (int)$metaRow['process_id'] : 0;
            $capDate = $metaRow['capture_date'] ?? null;
            if ($procId > 0 && $capDate) {
                if ($useSpScope) {
                    $findSp = $pdo->prepare(
                        'SELECT id FROM submitted_processes WHERE scope_type = ? AND scope_id = ? AND process_id = ?
                         AND ((DATE(capture_date) = ?) OR (capture_date IS NULL AND DATE(date_submitted) = ?))'
                    );
                    $findSp->execute([
                        $scopeInsert['scope_type'],
                        $scopeInsert['scope_id'],
                        $procId,
                        $capDate,
                        $capDate,
                    ]);
                } else {
                    $findSp = $pdo->prepare(
                        'SELECT id FROM submitted_processes WHERE company_id = ? AND process_id = ?
                         AND ((DATE(capture_date) = ?) OR (capture_date IS NULL AND DATE(date_submitted) = ?))'
                    );
                    $findSp->execute([$company_id, $procId, $capDate, $capDate]);
                }
                while ($sid = $findSp->fetchColumn()) {
                    deletedLog($pdo, $userTagCap, $pageTagCap, 'submitted_processes', (string) $sid);
                }
                if ($useSpScope) {
                    $deleteSubmittedStmt->execute([
                        $scopeInsert['scope_type'],
                        $scopeInsert['scope_id'],
                        $procId,
                        $capDate,
                        $capDate,
                    ]);
                } else {
                    $deleteSubmittedStmt->execute([
                        $company_id,
                        $procId,
                        $capDate,
                        $capDate,
                    ]);
                }
            }
        }
    }

    $placeholders = str_repeat('?,', count($validCaptureIds) - 1) . '?';
    $params = array_merge([$company_id], $validCaptureIds);
    $deleteSql = "DELETE FROM data_capture_details WHERE company_id = ? AND capture_id IN ($placeholders)";
    $stmt = $pdo->prepare($deleteSql);
    $stmt->execute($params);
    $totalDeleted = $stmt->rowCount();

    $cleanupSql = "DELETE FROM data_captures WHERE company_id = ? AND id IN ($placeholders)";
    $pdo->prepare($cleanupSql)->execute($params);

    $cleanupOrphanSql = "DELETE dc FROM data_captures dc
        INNER JOIN process p ON dc.process_id = p.id
        LEFT JOIN data_capture_details dcd ON dc.id = dcd.capture_id AND dcd.company_id = ?
        WHERE dc.company_id = ? AND dcd.id IS NULL AND p.company_id = ? AND dc.capture_date BETWEEN ? AND ?";
    $pdo->prepare($cleanupOrphanSql)->execute([$company_id, $company_id, $company_id, $date_from_db, $date_to_db]);

    $pdo->commit();

    jsonResponse(true, "已删除 {$totalDeleted} 条明细记录", ['deleted' => $totalDeleted]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}