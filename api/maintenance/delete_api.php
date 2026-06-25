<?php
/**
 * Maintenance Marquee Delete API - 删除维护跑马灯内容
 * 路径: api/maintenance/delete_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../c168/c168_domain_access.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';

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
 * 检查维护记录是否存在且属于 C168
 */
function findMaintenanceById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id FROM maintenance_marquee WHERE id = ? AND company_code = 'C168'");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 删除维护记录
 */
function deleteMaintenanceById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("DELETE FROM maintenance_marquee WHERE id = ? AND company_code = 'C168'");
    $stmt->execute([$id]);
}

try {
    requireC168InformationManagementAccess($pdo);

    $maintenanceId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($maintenanceId <= 0) {
        throw new Exception('Maintenance ID cannot be empty');
    }

    if (!findMaintenanceById($pdo, $maintenanceId)) {
        throw new Exception('Maintenance content does not exist or you do not have permission to delete it');
    }

    deletedLog(
        $pdo,
        (string) ($_SESSION['login_id'] ?? $_SESSION['name'] ?? ''),
        '/api/maintenance/delete_api.php',
        'maintenance_marquee',
        (string) $maintenanceId
    );
    deleteMaintenanceById($pdo, $maintenanceId);
    jsonResponse(true, 'Maintenance content deleted successfully');
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}