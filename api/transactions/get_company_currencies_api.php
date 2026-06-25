<?php
/**
 * Transaction Get Company Currencies API
 * 获取指定 company 的所有 currency 列表
 * 路径: api/transactions/get_company_currencies_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/transaction_scope.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

function getCompanyCurrencies(PDO $pdo, int $companyId, bool $subsidiaryOnly = false): array
{
    $scopeSql = $subsidiaryOnly ? tenant_sql_currency_subsidiary_only($pdo, 'c') : '';
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.code
        FROM currency c
        WHERE c.company_id = ?{$scopeSql}
        ORDER BY c.id ASC
    ");
    $stmt->execute([$companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!isset($_SESSION['user_id'])) {
        api_error('用户未登录', 401);
        exit;
    }
    $companyId = tx_resolve_request_company_id($pdo, $_GET);
    $subsidiaryOnly = isset($_GET['subsidiary_accounts_only'])
        && (string) $_GET['subsidiary_accounts_only'] === '1';
    $currencies = getCompanyCurrencies($pdo, $companyId, $subsidiaryOnly);
    api_success($currencies);
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}
