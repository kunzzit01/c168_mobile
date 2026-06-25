<?php
/**
 * 验证公司 ID 是否有效
 * 路径: api/company/verify_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';

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

function getCompanyByCode(PDO $pdo, $company_id) {
    $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE UPPER(company_id) = UPPER(?) OR UPPER(group_id) = UPPER(?) LIMIT 1");
    $stmt->execute([$company_id, $company_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    if (!isset($pdo) || !$pdo) {
        jsonResponse(false, '数据库连接失败', null, 500);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, '无效的请求方法', null, 405);
        exit;
    }

    $company_id = trim($_POST['company_id'] ?? '');
    if ($company_id === '') {
        jsonResponse(false, '请输入公司ID', null, 400);
        exit;
    }

    $company = getCompanyByCode($pdo, $company_id);
    if ($company) {
        jsonResponse(true, '公司ID有效', ['company_name' => $company['company_id'] ?? '']);
    } else {
        jsonResponse(false, '公司ID不存在', null, 404);
    }
} catch (PDOException $e) {
    error_log("Verify Company API PDO Error: " . $e->getMessage());
    jsonResponse(false, '数据库错误，请稍后重试', null, 500);
} catch (Exception $e) {
    error_log("Verify Company API Error: " . $e->getMessage());
    jsonResponse(false, '系统错误：' . $e->getMessage(), null, 500);
}