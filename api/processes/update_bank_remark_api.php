<?php
session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

try {
    if (!isset($_SESSION['company_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }

    $companyId = (int) $_SESSION['company_id'];
    $id = (int) ($_POST['id'] ?? 0);
    $remark = trim((string) ($_POST['remark'] ?? ''));

    if ($id <= 0) {
        api_error('无效的流程ID', 400);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE bank_process SET remark = ?, dts_modified = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$remark, $id, $companyId]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM bank_process WHERE id = ? AND company_id = ?");
        $checkStmt->execute([$id, $companyId]);
        if (!$checkStmt->fetchColumn()) {
            api_error('无权限操作此流程', 403);
            exit;
        }
    }

    api_success(['remark' => $remark], 'Remark updated successfully');
} catch (Throwable $e) {
    api_error($e->getMessage(), 400);
}
