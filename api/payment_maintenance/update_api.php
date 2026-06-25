<?php
/**
 * Payment Maintenance Update API
 * 更新交易金额、描述与备注（sms）
 * 路径: api/payment_maintenance/update_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../includes/money_decimal.php';

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
 * 校验交易是否属于当前公司
 */
function checkTransactionBelongsToCompany(PDO $pdo, $transaction_id, $company_id) {
    $stmt = $pdo->prepare("
        SELECT t.id
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE t.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$transaction_id, $company_id]);
    return $stmt->fetchColumn() !== false;
}

/**
 * 更新交易金额、描述、备注
 */
function updateTransaction(PDO $pdo, $transaction_id, $amount, $description, $remark) {
    $sql = "UPDATE transactions SET amount = ?, description = ?, sms = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$amount, $description, $remark, $transaction_id]);
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    $company_id = (int) $_SESSION['company_id'];
    gc_assert_api_company_access(
        $pdo,
        $company_id,
        gc_is_group_login() ? gc_session_login_identifier() : null
    );

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('无效的请求数据');
    }

    $transaction_id = (int) ($payload['transaction_id'] ?? 0);
    $amount = isset($payload['amount']) ? money_normalize($payload['amount']) : null;
    $description = trim($payload['description'] ?? '');
    $remark = trim($payload['remark'] ?? '');

    if ($transaction_id <= 0) {
        throw new Exception('缺少交易记录 ID');
    }
    if ($amount === null || money_cmp($amount, '0') < 0) {
        throw new Exception('金额不能小于 0');
    }

    if (!checkTransactionBelongsToCompany($pdo, $transaction_id, $company_id)) {
        throw new Exception('交易不存在或无权访问');
    }

    updateTransaction($pdo, $transaction_id, $amount, $description, $remark);

    jsonResponse(true, '交易更新成功', [
        'transaction_id' => $transaction_id,
        'amount' => money_out($amount),
        'description' => $description,
        'remark' => $remark
    ]);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}