<?php
/**
 * Contra Reject API (Manager+)
 * 拒绝某一条 pending 的 CONTRA，直接删除记录
 * 路径: api/transactions/contra_reject_api.php
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

function canRejectTransactionType(string $transactionType): bool {
    $type = strtoupper(trim($transactionType));
    return in_array($type, ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM', 'CLEAR', 'ADJUSTMENT', 'PROFIT', 'WIN', 'LOSE'], true);
}

function deleteContraTransaction(PDO $pdo, int $transactionId, array $scope): void {
    $scopeWhere = tx_sql_transaction_scope_where($scope);
    $scopeBind = tx_bind_transaction_scope_id($scope);
    $stmt = $pdo->prepare("SELECT id, company_id, transaction_type FROM transactions WHERE id = ? AND {$scopeWhere} FOR UPDATE");
    $stmt->execute([$transactionId, $scopeBind]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('记录不存在或不属于当前范围');
    if (!canRejectTransactionType((string)($row['transaction_type'] ?? ''))) throw new Exception('该类型不在审批范围内');

    // 先删除 transaction_entry（若存在），避免外键约束失败
    try {
        $hasEntry = $pdo->query("SHOW TABLES LIKE 'transaction_entry'")->rowCount() > 0;
        if ($hasEntry) {
            $e = $pdo->prepare("DELETE FROM transaction_entry WHERE header_id = ?");
            $e->execute([$transactionId]);
        }
    } catch (Exception $e) {
        // 兼容旧环境：忽略
    }

    $del = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND {$scopeWhere}");
    $del->execute([$transactionId, $scopeBind]);
    if ($del->rowCount() === 0) throw new Exception('删除失败，记录可能已被删除');
}

try {
    if (!isset($_SESSION['user_id'])) { api_error('请先登录', 401); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { api_error('只支持 POST 请求', 405); exit; }
    $userRole = strtolower($_SESSION['role'] ?? '');
    $userType = strtolower($_SESSION['user_type'] ?? 'user');
    if ($userType === 'member' || !isManagerOrAboveRole($userRole)) { api_error('无权操作', 403); exit; }
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    if ($transactionId <= 0) { api_error('transaction_id 无效', 400); exit; }
    $scope = tx_resolve_transaction_list_scope($pdo, $_POST);
    $pdo->beginTransaction();
    try {
        deleteContraTransaction($pdo, $transactionId, $scope);
        $pdo->commit();
        api_success(null, 'Rejected and deleted');
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}