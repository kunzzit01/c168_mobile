<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
}

function api_success(array $data = [], string $message = 'success'): void
{
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getBankProcessIssueFlagColumns(PDO $pdo): array
{
    try {
        $columns = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM bank_process LIKE 'issue_flag'");
        if ($stmt && $stmt->rowCount() > 0) {
            $columns[] = 'issue_flag';
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM bank_process LIKE 'flag'");
        if ($stmt && $stmt->rowCount() > 0) {
            $columns[] = 'flag';
        }
        return $columns;
    } catch (Throwable $e) {
        return [];
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Invalid request method', 405);
    }

    $companyId = $_SESSION['company_id'] ?? null;
    if (!$companyId) {
        api_error('Company not found in session', 401);
    }

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $issueFlag = strtolower(trim((string) ($_POST['issue_flag'] ?? '')));
    $issueFlag = str_replace([' ', '-'], '_', $issueFlag);

    if ($id <= 0) {
        api_error('Missing process ID');
    }

    if (!in_array($issueFlag, ['', 'official', 'e_invoice', 'block'], true)) {
        api_error('Invalid issue flag value');
    }

    $issueFlagColumns = getBankProcessIssueFlagColumns($pdo);
    if (empty($issueFlagColumns)) {
        api_error('bank_process.flag / issue_flag column is missing. Please run the latest SQL update first.', 400);
    }

    $valueToSave = ($issueFlag === '') ? null : $issueFlag;

    $setClauses = array_map(function ($column) {
        return "`$column` = ?";
    }, $issueFlagColumns);
    $stmt = $pdo->prepare("UPDATE bank_process SET " . implode(', ', $setClauses) . ", dts_modified = NOW() WHERE id = ? AND company_id = ?");
    $params = array_fill(0, count($issueFlagColumns), $valueToSave);
    $params[] = $id;
    $params[] = $companyId;
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM bank_process WHERE id = ? AND company_id = ?");
        $checkStmt->execute([$id, $companyId]);
        if (!$checkStmt->fetchColumn()) {
            api_error('Process not found or no permission', 404);
        }
    }

    api_success(['issue_flag' => $valueToSave], '状态选项更新成功');
} catch (Throwable $e) {
    api_error('Failed to update status option: ' . $e->getMessage(), 500);
}
