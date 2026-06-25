<?php
/**
 * Maintenance Marquee Update API - 更新维护跑马灯内容
 * 路径: api/maintenance/update_api.php
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
 * 检查维护记录是否存在且属于 C168
 */
function findMaintenanceById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id FROM maintenance_marquee WHERE id = ? AND company_code = 'C168'");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 更新维护内容
 */
function updateMaintenanceContent(PDO $pdo, int $id, string $prefix, string $content) {
    ensureMaintenanceMarqueePrefixColumn($pdo);
    if (maintenanceMarqueeHasPrefixColumn($pdo)) {
        $stmt = $pdo->prepare("UPDATE maintenance_marquee SET prefix = ?, content = ?, updated_at = NOW() WHERE id = ? AND company_code = 'C168'");
        $stmt->execute([$prefix, $content, $id]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE maintenance_marquee SET content = ?, updated_at = NOW() WHERE id = ? AND company_code = 'C168'");
    $stmt->execute([$prefix !== '' ? ($prefix . ' ' . $content) : $content, $id]);
}

try {
    requireC168InformationManagementAccess($pdo);

    $maintenanceId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $prefix = trim($_POST['prefix'] ?? '');
    $content = trim($_POST['content'] ?? '');

    if ($maintenanceId <= 0) {
        throw new Exception('Maintenance ID cannot be empty');
    }
    if ($prefix === '') {
        throw new Exception('Prefix cannot be empty');
    }
    if ($content === '') {
        throw new Exception('Content cannot be empty');
    }

    if (!findMaintenanceById($pdo, $maintenanceId)) {
        throw new Exception('Maintenance content does not exist or you do not have permission to update it');
    }

    updateMaintenanceContent($pdo, $maintenanceId, $prefix, $content);
    jsonResponse(true, 'Maintenance content updated successfully');
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}