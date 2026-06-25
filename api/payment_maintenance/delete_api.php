<?php
/**
 * Payment Maintenance Delete API
 * 批量删除交易记录
 * 路径: api/payment_maintenance/delete_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../includes/payment_delete_shared.php';
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

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('无效的请求数据');
    }

    $viewGroupForAccess = dcNormalizeGroupId(
        $payload['view_group'] ?? $payload['group_id'] ?? ''
    );

    $listScope = payment_delete_resolve_list_scope($pdo, $payload);
    $permCompanyId = tx_permission_company_id_for_scope($pdo, $listScope);
    if ($permCompanyId <= 0 && ($listScope['mode'] ?? '') !== 'group') {
        throw new Exception('缺少公司或集团信息');
    }
    if ($permCompanyId > 0) {
        dcAssertUserCanAccessCompany(
            $pdo,
            $permCompanyId,
            $viewGroupForAccess !== '' ? $viewGroupForAccess : null
        );
    }

    $ids = $payload['transaction_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        throw new Exception('请选择要删除的交易记录');
    }

    $result = payment_delete_transactions_by_ids(
        $pdo,
        $permCompanyId,
        $ids,
        $_SESSION,
        '/api/payment_maintenance/delete_api.php',
        true,
        $listScope
    );
    $deleted = (int) ($result['deleted'] ?? 0);

    jsonResponse(true, "已删除 {$deleted} 条记录", ['deleted' => $deleted]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}
