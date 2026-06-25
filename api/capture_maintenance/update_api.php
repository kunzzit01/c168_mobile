<?php
/**
 * Capture Maintenance Update API
 * 用于更新 Data Capture 的 Win/Loss 数据
 * 路径: api/capture_maintenance/update_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../includes/money_decimal.php';

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
 * 查询匹配的 data_capture_details 记录
 */
function getRecordsToUpdate(PDO $pdo, $company_id, $account_id, string $date_from_db, string $date_to_db, ?string $process) {
    $where_conditions = [
        "dc.capture_date BETWEEN ? AND ?",
        "dcd.account_id = ?",
        "p.company_id = ?"
    ];
    $params = [$date_from_db, $date_to_db, $account_id, $company_id];
    if ($process) {
        $where_conditions[] = "p.process_id = ?";
        $params[] = $process;
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    $sql = "SELECT dcd.id, dcd.processed_amount,
            SUM(dcd.processed_amount) OVER() as current_total,
            COUNT(*) OVER() as record_count
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN process p ON dc.process_id = p.id
            $where_sql";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 按比例或平均更新 processed_amount
 */
function updateProcessedAmounts(PDO $pdo, array $records, string $total_amount) {
    $current_total = money_normalize($records[0]['current_total'] ?? '0');
    $record_count = count($records);
    $updateStmt = $pdo->prepare("UPDATE data_capture_details SET processed_amount = ? WHERE id = ?");
    if (money_cmp($current_total, '0') === 0) {
        $amount_per_record = money_div($total_amount, (string)$record_count);
        foreach ($records as $record) {
            $updateStmt->execute([$amount_per_record, $record['id']]);
        }
    } else {
        foreach ($records as $record) {
            $ratio = money_div($record['processed_amount'] ?? '0', $current_total, MONEY_CALC_SCALE);
            $new_amount = money_mul($total_amount, $ratio);
            $updateStmt->execute([$new_amount, $record['id']]);
        }
    }
}

try {
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('无效的请求数据');
    }

    $account_id = $input['account_id'] ?? null;
    $date_from = $input['date_from'] ?? null;
    $date_to = $input['date_to'] ?? null;
    $process = $input['process'] ?? null;
    $win = money_optional($input['win'] ?? '0') ?? '0';
    $loss = money_optional($input['loss'] ?? '0') ?? '0';

    if (!$account_id) {
        throw new Exception('Account ID是必填项');
    }
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    if (money_cmp($win, '0') > 0 && money_cmp($loss, '0') > 0) {
        throw new Exception('Win 和 Loss 不能同时有值');
    }

    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    $total_amount = money_cmp($win, '0') > 0 ? $win : (money_cmp($loss, '0') > 0 ? money_sub('0', $loss) : '0');

    $records = getRecordsToUpdate($pdo, $company_id, $account_id, $date_from_db, $date_to_db, $process);
    if (empty($records)) {
        throw new Exception('未找到匹配的记录');
    }

    $pdo->beginTransaction();
    try {
        updateProcessedAmounts($pdo, $records, $total_amount);
        $pdo->commit();
        jsonResponse(true, '数据更新成功', null);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}