<?php
/**
 * 公告仪表盘 API：获取活跃公告（供仪表盘展示）
 * 路径: api/announcements/announcement_get_dashboard_api.php
 */
header('Content-Type: application/json; charset=utf-8');

function sendJson(bool $success, string $message, $data = null): void {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data === null ? [] : $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $configPaths = [__DIR__ . '/../../includes/config.php', dirname(__DIR__, 3) . '/includes/config.php'];
    foreach ($configPaths as $path) {
        if (is_file($path)) {
            require_once $path;
            break;
        }
    }
    if (!isset($pdo)) {
        throw new Exception('Config file not found');
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        sendJson(false, 'User not logged in', []);
    }

    $sql = "SELECT 
                a.id,
                a.title,
                a.content,
                DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i:%s') as created_at,
                COALESCE(u.name, o.name) as created_by_name
            FROM announcements a
            LEFT JOIN `user` u ON a.created_by = u.id AND a.user_type = 'user'
            LEFT JOIN `owner` o ON a.created_by = o.id AND a.user_type = 'owner'
            WHERE a.company_code = 'C168' AND a.status = 'active'
            ORDER BY a.created_at DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'] ?? '',
            'content' => $row['content'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'created_by' => $row['created_by_name'] ?? 'Unknown'
        ];
    }

    sendJson(true, '', $data);

} catch (Throwable $e) {
    error_log('Announcement get dashboard API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    sendJson(false, 'Server error', []);
}