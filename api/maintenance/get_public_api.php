<?php
/**
 * Maintenance Marquee Public API - 获取公开维护内容（无需登录）
 * 路径: api/maintenance/get_public_api.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
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

if (!isset($pdo) || !$pdo instanceof PDO) {
    jsonResponse(false, 'Database unavailable', null, 503);
    exit;
}

/**
 * 获取 C168 公司下所有活跃的维护跑马灯内容
 */
function fetchActiveMaintenanceList(PDO $pdo) {
    $prefixSelect = maintenanceMarqueeHasPrefixColumn($pdo) ? 'm.prefix' : "'' AS prefix";
    $sql = "SELECT m.id, {$prefixSelect}, m.content, m.status
            FROM maintenance_marquee m
            WHERE m.company_code = 'C168' AND m.status = 'active'
            ORDER BY m.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 格式化为前端所需结构
 */
function formatPublicRows(array $rows) {
    $list = [];
    foreach ($rows as $row) {
        $list[] = [
            'id' => (int)$row['id'],
            'prefix' => $row['prefix'] ?? '',
            'content' => $row['content'] ?? ''
        ];
    }
    return $list;
}

try {
    $rows = fetchActiveMaintenanceList($pdo);
    $data = formatPublicRows($rows);
    jsonResponse(true, 'success', $data);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}