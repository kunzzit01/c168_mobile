<?php
/**
 * Contra Approve API (Manager+)
 * 将某一条 pending 的 CONTRA 标记为 APPROVED
 * 路径: api/transactions/contra_approve_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/transaction_scope.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

function isManagerOrAboveRole(string $role): bool {
    return in_array(strtolower(trim($role)), ['manager', 'admin', 'owner'], true);
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

function canApproveTransactionType(string $transactionType): bool {
    $type = strtoupper(trim($transactionType));
    return in_array($type, ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM', 'CLEAR', 'ADJUSTMENT', 'PROFIT', 'WIN', 'LOSE'], true);
}

function approveContraTransaction(PDO $pdo, int $transactionId, array $scope, string $userType): void {
    $hasApprovedBy = tableHasColumn($pdo, 'transactions', 'approved_by');
    $hasApprovedByOwner = tableHasColumn($pdo, 'transactions', 'approved_by_owner');
    $hasApprovedAt = tableHasColumn($pdo, 'transactions', 'approved_at');
    $scopeWhere = tx_sql_transaction_scope_where($scope);
    $scopeBind = tx_bind_transaction_scope_id($scope);
    $stmt = $pdo->prepare("SELECT id, company_id, transaction_type, approval_status FROM transactions WHERE id = ? AND {$scopeWhere} FOR UPDATE");
    $stmt->execute([$transactionId, $scopeBind]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('记录不存在或不属于当前范围');
    if (!canApproveTransactionType((string)($row['transaction_type'] ?? ''))) throw new Exception('该类型不在审批范围内');
    if (strtoupper((string)$row['approval_status']) === 'APPROVED') return;
    $setParts = ["approval_status = 'APPROVED'"];
    $params = [];
    if ($hasApprovedBy) { $setParts[] = "approved_by = ?"; $params[] = ($userType === 'user') ? (int)($_SESSION['user_id'] ?? 0) : null; }
    if ($hasApprovedByOwner) { $setParts[] = "approved_by_owner = ?"; $params[] = ($userType === 'owner') ? (int)($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0) : null; }
    if ($hasApprovedAt) $setParts[] = "approved_at = NOW()";
    $params[] = $transactionId;
    $params[] = $scopeBind;
    $sql = "UPDATE transactions SET " . implode(', ', $setParts) . " WHERE id = ? AND {$scopeWhere}";
    $pdo->prepare($sql)->execute($params);
}

try {
    if (!isset($_SESSION['user_id'])) { api_error('请先登录', 401); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('只支持 POST 请求', 405); exit; }
    $userRole = strtolower($_SESSION['role'] ?? '');
    $userType = strtolower($_SESSION['user_type'] ?? 'user');
    if ($userType === 'member' || !isManagerOrAboveRole($userRole)) { api_error('无权操作', 403); exit; }
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    if ($transactionId <= 0) { api_error('transaction_id 无效', 400); exit; }
    if (!tableHasColumn($pdo, 'transactions', 'approval_status')) {
        api_error('系统未启用 Contra 审批字段（approval_status），请先更新数据库', 400);
        exit;
    }
    $scope = tx_resolve_transaction_list_scope($pdo, $_POST);
    $pdo->beginTransaction();
    try {
        approveContraTransaction($pdo, $transactionId, $scope, $userType);
        $pdo->commit();
        api_success(null, 'Approved');
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}