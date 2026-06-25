<?php
/**
 * Maintenance Marquee Create API - 创建维护跑马灯内容
 * 路径: api/maintenance/create_api.php
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
    echo json_encode(array_merge(
        ['success' => (bool) $success, 'message' => $message],
        $data !== null ? ['data' => $data] : []
    ), JSON_UNESCAPED_UNICODE);
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
 * 获取当前活跃维护条数
 */
function countActiveMaintenance(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_marquee WHERE company_code = 'C168' AND status = 'active'");
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * 插入新维护内容
 */
function insertMaintenance(PDO $pdo, string $prefix, string $content, $createdBy, string $userType) {
    ensureMaintenanceMarqueePrefixColumn($pdo);
    if (maintenanceMarqueeHasPrefixColumn($pdo)) {
        $sql = "INSERT INTO maintenance_marquee (prefix, content, company_code, created_by, user_type, status)
                VALUES (?, ?, 'C168', ?, ?, 'active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$prefix, $content, $createdBy, $userType]);
    } else {
        $sql = "INSERT INTO maintenance_marquee (content, company_code, created_by, user_type, status)
                VALUES (?, 'C168', ?, ?, 'active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$prefix !== '' ? ($prefix . ' ' . $content) : $content, $createdBy, $userType]);
    }
    return (int) $pdo->lastInsertId();
}

try {
    requireC168InformationManagementAccess($pdo);

    $prefix = trim($_POST['prefix'] ?? '');
    $content = trim($_POST['content'] ?? '');
    if ($prefix === '') {
        throw new Exception('Prefix cannot be empty');
    }
    if ($content === '') {
        throw new Exception('Content cannot be empty');
    }

    if (countActiveMaintenance($pdo) > 0) {
        throw new Exception('Maintenance content already exists. Please delete the existing content before creating a new one.');
    }

    $user_id = $_SESSION['user_id'];
    $user_type = $_SESSION['user_type'] ?? 'user';
    $created_by = ($user_type === 'owner') ? ($_SESSION['owner_id'] ?? $user_id) : $user_id;

    $id = insertMaintenance($pdo, $prefix, $content, $created_by, $user_type);
    jsonResponse(true, 'Maintenance content created successfully', ['id' => $id]);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}