<?php
/**
 * Maintenance Marquee List API - 获取维护跑马灯列表（需 C168 权限）
 * 路径: api/maintenance/list_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../c168/c168_domain_access.php';
require_once __DIR__ . '/maintenance_common.php';

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
 * 与 announcement.php / Domain 白名单一致：C168 上下文 + userlist 角色（含 manager、supervisor、customer service）。
 */
function requireC168InformationManagementAccess(PDO $pdo): void {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }
    if (!userCanAccessC168InformationApis($pdo)) {
        throw new Exception('No permission to access this function');
    }
}

/**
 * 获取 C168 下所有维护记录（含创建人信息）
 */
function fetchMaintenanceList(PDO $pdo) {
    $prefixSelect = maintenanceMarqueeHasPrefixColumn($pdo) ? 'm.prefix' : "'' AS prefix";
    $sql = "SELECT m.id, {$prefixSelect}, m.content, m.status,
                   DATE_FORMAT(m.created_at, '%d/%m/%Y %H:%i:%s') as created_at,
                   COALESCE(u.name, o.name) as created_by_name,
                   COALESCE(u.login_id, o.owner_code) as created_by_login
            FROM maintenance_marquee m
            LEFT JOIN user u ON m.created_by = u.id AND m.user_type = 'user'
            LEFT JOIN owner o ON m.created_by = o.id AND m.user_type = 'owner'
            WHERE m.company_code = 'C168'
            ORDER BY m.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 格式化为前端所需结构
 */
function formatListRows(array $rows) {
    $list = [];
    foreach ($rows as $row) {
        $list[] = [
            'id' => (int)$row['id'],
            'prefix' => $row['prefix'] ?? '',
            'content' => $row['content'] ?? '',
            'status' => $row['status'] ?? 'active',
            'created_at' => $row['created_at'] ?? '',
            'created_by' => $row['created_by_name'] ?? ($row['created_by_login'] ?? 'Unknown')
        ];
    }
    return $list;
}

try {
    requireC168InformationManagementAccess($pdo);

    $rows = fetchMaintenanceList($pdo);
    $data = formatListRows($rows);
    jsonResponse(true, 'success', $data);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}