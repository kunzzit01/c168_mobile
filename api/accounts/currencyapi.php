<?php
/**
 * 公司货币列表 API：按当前 session 公司返回货币列表
 * 路径: api/accounts/currencyapi.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行

function fetchCurrenciesByCompany(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("SELECT id, code FROM currency WHERE company_id = ? ORDER BY code ASC");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function jsonResponse(bool $success, string $message, $data = null): void {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
}

try {
    if (!isset($_SESSION['company_id'])) {
        http_response_code(400);
        jsonResponse(false, '用户未登录或缺少公司信息', null);
        return;
    }

    $companyId = (int) $_SESSION['company_id'];
    $currencies = fetchCurrenciesByCompany($pdo, $companyId);
    jsonResponse(true, '', $currencies);

} catch (PDOException $e) {
    error_log('Currency API DB error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null);
} catch (Exception $e) {
    http_response_code(500);
    jsonResponse(false, '系统错误: ' . $e->getMessage(), null);
}