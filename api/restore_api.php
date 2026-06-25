<?php
/**
 * 从 deleted_logs 恢复单条记录到原表（仅 admin / owner）
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session_check.php';
require_once __DIR__ . '/deleted_log/deleted_log.php';
require_once __DIR__ . '/api_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
    exit;
}

$role = strtolower((string) ($_SESSION['role'] ?? ''));
$userType = strtolower((string) ($_SESSION['user_type'] ?? ''));
$canRestore = ($role === 'admin' || $role === 'owner' || $userType === 'owner');
if (!$canRestore) {
    api_error('Restore is only allowed for admin or owner', 403);
    exit;
}

if (empty($_SESSION['user_id'])) {
    api_error('Not logged in', 401);
    exit;
}

$raw = file_get_contents('php://input');
$body = [];
if ($raw !== false && $raw !== '') {
    $body = json_decode($raw, true) ?: [];
}
$logId = isset($body['log_id']) ? (int) $body['log_id'] : (int) ($_POST['log_id'] ?? 0);
if ($logId <= 0) {
    api_error('log_id is required', 400);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM `deleted_logs` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$logId]);
    $logRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$logRow) {
        api_error('Log not found', 404);
        exit;
    }

    // Admin/Owner 可在 Deleted Log 中查看任意公司的记录；恢复目标以日志内 company_id / deleted_data 为准，
    // 不得要求与当前侧栏 session company_id 一致（否则会 Forbidden: company mismatch）。

    if (isset($logRow['action_type']) && strtoupper((string) $logRow['action_type']) === 'RESTORE') {
        api_error('This entry was already restored', 400);
        exit;
    }

    $table = isset($logRow['table_name']) ? (string) $logRow['table_name'] : '';
    if ($table === '' || !deleted_log_validate_table($table)) {
        api_error('Invalid or unsupported table for restore', 400);
        exit;
    }

    $payload = $logRow['deleted_data'] ?? null;
    if ($payload === null || $payload === '') {
        api_error('No deleted_data payload', 400);
        exit;
    }

    if (is_array($payload)) {
        $data = $payload;
    } else {
        $data = json_decode((string) $payload, true);
    }
    if (!is_array($data)) {
        api_error('Invalid deleted_data JSON', 400);
        exit;
    }

    unset($data['id'], $data['ID']);

    $cols = [];
    $vals = [];
    foreach ($data as $k => $v) {
        if (!is_string($k) || !preg_match('/^[a-zA-Z0-9_]+$/', $k)) {
            continue;
        }
        $cols[] = '`' . $k . '`';
        $vals[] = $v;
    }

    if ($cols === []) {
        api_error('Nothing to restore', 400);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . $placeholders . ')';

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare($sql);
        $ins->execute($vals);

        if ($table === 'account') {
            $newAccountId = (int) $pdo->lastInsertId();
            $linkCid = (int) trim((string) ($logRow['company_id'] ?? ''));
            if ($newAccountId > 0 && $linkCid > 0) {
                $chkTbl = $pdo->query("SHOW TABLES LIKE 'account_company'");
                if ($chkTbl && $chkTbl->rowCount() > 0) {
                    $ex = $pdo->prepare(
                        'SELECT 1 FROM account_company WHERE account_id = ? AND company_id = ? LIMIT 1'
                    );
                    $ex->execute([$newAccountId, $linkCid]);
                    if (!$ex->fetchColumn()) {
                        $linkIns = $pdo->prepare(
                            'INSERT INTO account_company (account_id, company_id) VALUES (?, ?)'
                        );
                        $linkIns->execute([$newAccountId, $linkCid]);
                    }
                }
            }
        }

        $upd = $pdo->prepare('UPDATE `deleted_logs` SET `action_type` = ? WHERE `id` = ?');
        $upd->execute(['RESTORE', $logId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $logCompanyOut = trim((string) ($logRow['company_id'] ?? ''));
    api_success([
        'log_id' => $logId,
        'log_company_id' => $logCompanyOut,
        'table_name' => $table,
    ], 'Restored successfully');
} catch (Throwable $e) {
    error_log('restore_api error: ' . $e->getMessage());
    api_error('Restore failed', 500);
}
