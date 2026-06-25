<?php
/**
 * Transaction Maintenance Delete API
 * 根据筛选条件批量删除 transactions 数据
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';
require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';

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

    $maintenance_scope_group = false;
    $hasExplicitScope = dcRequestHasExplicitScope($payload);

    if ($hasExplicitScope) {
        $scopeResolved = resolveDataCaptureRequestScope($pdo, $payload);
        $scopeCtx = dcFinalizeCaptureMaintenanceScope($pdo, $scopeResolved, $payload);
        $company_id = (int) $scopeCtx['company_id'];
        $maintenance_scope_group = (bool) $scopeCtx['is_group_scope'];
        $viewGroupForAccess = dcNormalizeGroupId(
            $payload['view_group'] ?? $payload['group_id'] ?? ''
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
        $maintenance_scope_group = dcIsGroupScopeHint([
            'company_id' => $company_id,
            'group_id' => dcNormalizeGroupId($payload['view_group'] ?? $payload['group_id'] ?? ''),
            'report_scope_hint' => '',
        ]);
    }

    $scopeProcessFilter = $maintenance_scope_group
        ? dcSqlGroupProcessFilter('p')
        : dcSqlCompanyProcessFilter('p');

    $date_from = $payload['date_from'] ?? null;
    $date_to   = $payload['date_to']   ?? null;
    $items     = $payload['items']     ?? [];

    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    if (!is_array($items) || empty($items)) {
        throw new Exception('请选择要删除的记录');
    }

    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db   = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));

    // 收集 transaction_id
    $transactionIds = [];
    foreach ($items as $item) {
        $tid = isset($item['transaction_id']) ? (int)$item['transaction_id'] : 0;
        if ($tid > 0) {
            $transactionIds[] = $tid;
        }
    }
    if (empty($transactionIds)) {
        throw new Exception('没有有效的记录可删除');
    }
    $transactionIds = array_unique($transactionIds);

    // 当前操作用户
    $userRole = strtolower($_SESSION['role'] ?? '');
    $userId   = (int)($_SESSION['user_id'] ?? 0);
    $ownerId  = isset($_SESSION['owner_id']) ? (int)$_SESSION['owner_id'] : null;

    $deletedByUserId  = null;
    $deletedByOwnerId = null;
    if ($userRole === 'owner') {
        $deletedByOwnerId = $ownerId ?: $userId;
    } else {
        $deletedByUserId = $userId;
    }

    // 确保日志表存在
    $createLogTable = "
        CREATE TABLE IF NOT EXISTS transactions_deleted (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            company_id INT NOT NULL,
            process_id INT NOT NULL,
            account_id INT NOT NULL,
            currency_id INT NULL,
            transaction_date DATE NOT NULL,
            description TEXT NULL,
            remark TEXT NULL,
            source VARCHAR(255) NULL,
            rate DECIMAL(15,6) NULL,
            credit DECIMAL(18,6) NULL,
            debit DECIMAL(18,6) NULL,
            created_at TIMESTAMP NULL,
            created_by INT NULL,
            created_by_owner INT NULL,
            deleted_by_user_id INT NULL,
            deleted_by_owner_id INT NULL,
            deleted_at TIMESTAMP NULL,
            INDEX idx_company_date (company_id, transaction_date),
            INDEX idx_transaction_id (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($createLogTable);

    $pdo->beginTransaction();

    // 验证这些 transaction 是否属于当前公司且在日期范围
    $ph = str_repeat('?,', count($transactionIds) - 1) . '?';
    $verifySql = "
        SELECT t.id
        FROM transactions t
        INNER JOIN process p ON t.process_id = p.id
        WHERE t.company_id = ?
          AND p.company_id = ?
          AND t.id IN ($ph)
          AND t.transaction_date BETWEEN ? AND ?
          $scopeProcessFilter
    ";
    $verifyParams = array_merge([$company_id, $company_id], $transactionIds, [$date_from_db, $date_to_db]);
    $verifyStmt = $pdo->prepare($verifySql);
    $verifyStmt->execute($verifyParams);
    $validIds = $verifyStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validIds)) {
        $pdo->rollBack();
        throw new Exception('没有找到符合条件且属于当前公司的记录');
    }

    $validPh = str_repeat('?,', count($validIds) - 1) . '?';

    // 1) 备份到日志表
    $logSql = "
        INSERT INTO transactions_deleted (
            transaction_id,
            company_id,
            process_id,
            account_id,
            currency_id,
            transaction_date,
            description,
            remark,
            source,
            rate,
            credit,
            debit,
            created_at,
            created_by,
            created_by_owner,
            deleted_by_user_id,
            deleted_by_owner_id,
            deleted_at
        )
        SELECT
            t.id,
            t.company_id,
            t.process_id,
            t.account_id,
            t.currency_id,
            t.transaction_date,
            t.description,
            t.remark,
            t.source,
            t.rate,
            t.credit,
            t.debit,
            t.created_at,
            t.created_by,
            t.created_by_owner,
            ?,
            ?,
            NOW()
        FROM transactions t
        INNER JOIN process p ON t.process_id = p.id
        WHERE t.id IN ($validPh)
          AND t.company_id = ?
          AND p.company_id = ?
          $scopeProcessFilter
    ";
    $logParams = array_merge(
        [$deletedByUserId, $deletedByOwnerId],
        $validIds,
        [$company_id, $company_id]
    );
    $logStmt = $pdo->prepare($logSql);
    $logStmt->execute($logParams);

    $pageTag = '/api/transactions/maintenance_delete_api.php';
    $userTag = (string) ($_SESSION['login_id'] ?? $_SESSION['name'] ?? '');
    foreach ($validIds as $tid) {
        $entryIdsStmt = $pdo->prepare('SELECT id FROM transaction_entry WHERE header_id = ?');
        $entryIdsStmt->execute([(int) $tid]);
        while ($eid = $entryIdsStmt->fetchColumn()) {
            deletedLog($pdo, $userTag, $pageTag, 'transaction_entry', (string) $eid);
        }
        deletedLog($pdo, $userTag, $pageTag, 'transactions', (string) $tid);
    }

    // 2) 先删除 transaction_entry（若存在），避免外键约束导致无法删除 transactions
    try {
        $hasEntry = $pdo->query("SHOW TABLES LIKE 'transaction_entry'")->rowCount() > 0;
        if ($hasEntry) {
            $entrySql = "DELETE FROM transaction_entry WHERE header_id IN ($validPh)";
            $entryStmt = $pdo->prepare($entrySql);
            $entryStmt->execute($validIds);
        }
    } catch (Exception $e) {
        // 兼容旧环境：忽略
    }

    // 3) 删除 transactions 记录
    $deleteSql = "DELETE FROM transactions WHERE company_id = ? AND id IN ($validPh)";
    $deleteParams = array_merge([$company_id], $validIds);
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute($deleteParams);
    $totalDeleted = $deleteStmt->rowCount();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "已删除 {$totalDeleted} 条记录",
        'deleted' => $totalDeleted
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
