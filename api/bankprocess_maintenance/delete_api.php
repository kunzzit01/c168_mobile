<?php
/**
 * Bank Process Maintenance Delete API
 * 批量删除由 Bank process 入账的交易记录（仅允许 source_bank_process_id IS NOT NULL 的记录）
 * 路径: api/bankprocess_maintenance/delete_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/maintenance_accounting_resend_lib.php';
require_once __DIR__ . '/../includes/payment_delete_shared.php';

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
 * 兼容旧库：为 transactions_deleted 补齐 bank process 相关列
 */
function ensureTransactionsDeletedExtraColumns(PDO $pdo) {
    $checks = [
        'source_bank_process_id' => "ALTER TABLE transactions_deleted ADD COLUMN source_bank_process_id INT NULL",
        'source_bank_process_period_type' => "ALTER TABLE transactions_deleted ADD COLUMN source_bank_process_period_type VARCHAR(64) NULL",
        'currency_id' => "ALTER TABLE transactions_deleted ADD COLUMN currency_id INT NULL",
    ];
    foreach ($checks as $column => $ddl) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM transactions_deleted LIKE ?");
            $stmt->execute([$column]);
            if ($stmt->rowCount() === 0) {
                $pdo->exec($ddl);
            }
        } catch (Throwable $e) {
            // 并发/重复添加时忽略，避免阻塞删除流程
        }
    }
}

/**
 * 仅返回属于当前公司且 source_bank_process_id IS NOT NULL 的 transaction id 列表
 */
function filterBankProcessTransactionIds(PDO $pdo, array $ids, $company_id) {
    $hasColumn = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
        $hasColumn = $colStmt->rowCount() > 0;
    } catch (PDOException $e) {}
    if (!$hasColumn || empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT t.id FROM transactions t
            INNER JOIN account a ON t.account_id = a.id
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE t.id IN ($placeholders) AND ac.company_id = ? AND t.source_bank_process_id IS NOT NULL";
    $params = array_merge($ids, [$company_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * 将要删除的 transactions 备份到 transactions_deleted
 */
function backupTransactionsToDeleted(PDO $pdo, array $ids, $company_id, $deletedByUserId, $deletedByOwnerId) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        INSERT INTO transactions_deleted (
            transaction_id, company_id, transaction_type, account_id, from_account_id,
            amount, transaction_date, description, sms, created_by, created_by_owner, created_at,
            deleted_by_user_id, deleted_by_owner_id, deleted_at,
            source_bank_process_id, source_bank_process_period_type, currency_id
        )
        SELECT
            t.id AS transaction_id, ? AS company_id, t.transaction_type, t.account_id, t.from_account_id,
            t.amount, t.transaction_date, t.description, t.sms, t.created_by, t.created_by_owner, t.created_at,
            ?, ?, NOW(),
            t.source_bank_process_id, t.source_bank_process_period_type, t.currency_id
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE t.id IN ($placeholders) AND ac.company_id = ?
    ";
    $params = array_merge([$company_id, $deletedByUserId, $deletedByOwnerId], $ids, [$company_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * 删除 transaction_entry 中对应 header_id 的分录
 */
function deleteTransactionEntries(PDO $pdo, array $ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM transaction_entry WHERE header_id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
}

/**
 * 按公司权限删除 transactions 记录
 */
function deleteTransactions(PDO $pdo, array $ids, $company_id) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE t
            FROM transactions t
            INNER JOIN account a ON t.account_id = a.id
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE t.id IN ($placeholders) AND ac.company_id = ?";
    $params = array_merge($ids, [$company_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * 删除「Inactive Compensation Sell Price」后，立即清掉对应 manual_inactive posted 标记，
 * 让 Transaction Payment / Accounting Due 无需切状态即可实时出现。
 *
 * @return array{pap_removed:int,pending_removed:int}
 */
function clearManualInactiveMarkersAfterDelete(PDO $pdo, array $ids, int $company_id): array
{
    if (empty($ids)) {
        return ['pap_removed' => 0, 'pending_removed' => 0];
    }

    $hasSourceBpIdCol = false;
    $hasSourcePtCol = false;
    try {
        $hasSourceBpIdCol = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'")->rowCount() > 0;
    } catch (Throwable $e) {
        $hasSourceBpIdCol = false;
    }
    try {
        $hasSourcePtCol = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_period_type'")->rowCount() > 0;
    } catch (Throwable $e) {
        $hasSourcePtCol = false;
    }
    if (!$hasSourceBpIdCol) {
        return ['pap_removed' => 0, 'pending_removed' => 0];
    }

    $hasPendingTable = false;
    try {
        $hasPendingTable = $pdo->query("SHOW TABLES LIKE 'bank_process_maintenance_resend_pending'")->rowCount() > 0;
    } catch (Throwable $e) {
        $hasPendingTable = false;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $periodSelect = $hasSourcePtCol ? "COALESCE(t.source_bank_process_period_type, '')" : "''";
    $sql = "SELECT
                t.id,
                t.source_bank_process_id,
                DATE(t.transaction_date) AS txd,
                t.transaction_type,
                t.description,
                $periodSelect AS source_period_type
            FROM transactions t
            INNER JOIN account a ON t.account_id = a.id
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE t.id IN ($placeholders)
              AND ac.company_id = ?
              AND t.source_bank_process_id IS NOT NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ids, [$company_id]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    $papRemoved = 0;
    $pendingRemoved = 0;
    $delPendingStmt = null;
    if ($hasPendingTable) {
        $delPendingStmt = $pdo->prepare(
            "DELETE FROM bank_process_maintenance_resend_pending
             WHERE company_id = ? AND bank_process_id = ? AND period_type = 'manual_inactive'
               AND (transaction_date = ? OR transaction_date IS NULL)"
        );
    }

    foreach ($rows as $r) {
        $bpId = (int) ($r['source_bank_process_id'] ?? 0);
        $txd = trim((string) ($r['txd'] ?? ''));
        if ($bpId <= 0 || $txd === '') {
            continue;
        }
        $pt = strtolower(trim((string) ($r['source_period_type'] ?? '')));
        $desc = trim((string) ($r['description'] ?? ''));
        $isManualInactiveCompensation = ($pt === 'manual_inactive')
            || (stripos($desc, 'Inactive Compensation Sell Price') === 0);
        if (!$isManualInactiveCompensation) {
            continue;
        }
        $dedupeKey = $bpId . '|' . $txd;
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        $papRemoved += bmp_deletePapFallback($pdo, $company_id, $bpId, 'manual_inactive', $txd);
        if ($delPendingStmt) {
            $delPendingStmt->execute([$company_id, $bpId, $txd]);
            $pendingRemoved += $delPendingStmt->rowCount();
        }
    }

    return ['pap_removed' => $papRemoved, 'pending_removed' => $pendingRemoved];
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    $company_id = (int) $_SESSION['company_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('无效的请求数据');
    }

    $ids = $payload['transaction_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        throw new Exception('请选择要删除的交易记录');
    }
    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
    if (empty($ids)) {
        throw new Exception('无效的交易记录');
    }

    // 仅允许删除 Bank process 入账的交易（source_bank_process_id IS NOT NULL）
    $allowedIds = filterBankProcessTransactionIds($pdo, $ids, $company_id);
    if (empty($allowedIds)) {
        throw new Exception('所选记录中没有可删除的 Bank process 交易，或无权操作');
    }

    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    $userId = (int) $_SESSION['user_id'];
    $ownerId = isset($_SESSION['owner_id']) ? (int) $_SESSION['owner_id'] : null;
    $deletedByUserId = null;
    $deletedByOwnerId = null;
    if ($userRole === 'owner') {
        $deletedByOwnerId = $ownerId ?: $userId;
    } else {
        $deletedByUserId = $userId;
    }

    payment_delete_ensure_transactions_deleted_table($pdo);
    ensureTransactionsDeletedExtraColumns($pdo);
    // Ensure resend-pending table exists BEFORE starting a DB transaction
    // (DDL inside transaction can cause implicit commit).
    bmp_ensureMaintenanceResendPendingTable($pdo);

    $placeholdersBp = implode(',', array_fill(0, count($allowedIds), '?'));
    $bpStmt = $pdo->prepare(
        "SELECT DISTINCT t.source_bank_process_id FROM transactions t
         INNER JOIN account a ON t.account_id = a.id
         INNER JOIN account_company ac ON a.id = ac.account_id
         WHERE t.id IN ($placeholdersBp) AND ac.company_id = ? AND t.source_bank_process_id IS NOT NULL"
    );
    $bpStmt->execute(array_merge($allowedIds, [$company_id]));
    $affectedBankProcessIds = [];
    foreach ($bpStmt->fetchAll(PDO::FETCH_COLUMN) as $bid) {
        $bid = (int) $bid;
        if ($bid > 0 && !in_array($bid, $affectedBankProcessIds, true)) {
            $affectedBankProcessIds[] = $bid;
        }
    }

    $pdo->beginTransaction();

    bmp_recordResendPendingForTransactionIds($pdo, $company_id, $allowedIds);
    $manualInactiveSync = clearManualInactiveMarkersAfterDelete($pdo, $allowedIds, $company_id);
    backupTransactionsToDeleted($pdo, $allowedIds, $company_id, $deletedByUserId, $deletedByOwnerId);
    deleteTransactionEntries($pdo, $allowedIds);
    $deleted = deleteTransactions($pdo, $allowedIds, $company_id);

    $pdo->commit();

    foreach ($affectedBankProcessIds as $bpId) {
        bmp_pruneStaleAccountingResendDailyGuardsForProcess($pdo, $company_id, $bpId);
    }

    jsonResponse(true, "已删除 {$deleted} 条 Bank process 交易记录", [
        'deleted' => $deleted,
        'manual_inactive_posted_removed' => (int) ($manualInactiveSync['pap_removed'] ?? 0),
        'manual_inactive_pending_removed' => (int) ($manualInactiveSync['pending_removed'] ?? 0),
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}