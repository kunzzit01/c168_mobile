<?php
/**
 * Transaction Get Currencies API
 * 获取指定日期范围内 data_capture 中提交过的货币列表
 * 路径: api/transactions/get_currencies_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

function getCurrenciesByDateRange(PDO $pdo, string $dateFrom, string $dateTo, int $companyId): array {
    $sql = "SELECT DISTINCT a.currency
            FROM data_captures dc
            JOIN data_capture_details dcd ON dc.id = dcd.capture_id
            JOIN account a ON dcd.account_id = a.id
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE dc.capture_date BETWEEN ? AND ?
              AND ac.company_id = ?
              AND a.currency IS NOT NULL
              AND a.currency != ''
            ORDER BY a.currency ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$dateFrom, $dateTo, $companyId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

try {
    if (!isset($_SESSION['company_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }
    $companyId = (int)$_SESSION['company_id'];

    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    if (!$dateFrom || !$dateTo) {
        api_error('日期范围是必填项', 400);
        exit;
    }

    $dateFromDb = date('Y-m-d', strtotime(str_replace('/', '-', $dateFrom)));
    $dateToDb = date('Y-m-d', strtotime(str_replace('/', '-', $dateTo)));
    $currencies = getCurrenciesByDateRange($pdo, $dateFromDb, $dateToDb, $companyId);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => '',
        'data' => $currencies,
        'count' => count($currencies)
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}