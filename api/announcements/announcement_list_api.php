<?php
/**
 * 公告列表 API：获取管理端公告列表（需 C168 + userlist 角色白名单）
 * 路径: api/announcements/announcement_list_api.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../c168/c168_domain_access.php';
session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行

function hasC168AnnouncementAccess(PDO $pdo): bool {
    $companyCode = strtoupper($_SESSION['company_code'] ?? '');
    if ($companyCode === 'C168') {
        return true;
    }
    $companyId = $_SESSION['company_id'] ?? null;
    if (!$companyId) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
    $stmt->execute([$companyId]);
    return $stmt->fetchColumn() > 0;
}

function fetchAllAnnouncements(PDO $pdo): array {
    $sql = "SELECT 
                a.id,
                a.title,
                a.content,
                a.status,
                DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i:%s') as created_at,
                COALESCE(u.name, o.name) as created_by_name,
                COALESCE(u.login_id, o.owner_code) as created_by_login
            FROM announcements a
            LEFT JOIN `user` u ON a.created_by = u.id AND a.user_type = 'user'
            LEFT JOIN `owner` o ON a.created_by = o.id AND a.user_type = 'owner'
            WHERE a.company_code = 'C168'
            ORDER BY a.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function formatListRows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'title' => $row['title'] ?? '',
            'content' => $row['content'] ?? '',
            'status' => $row['status'] ?? 'active',
            'created_at' => $row['created_at'] ?? '',
            'created_by' => $row['created_by_name'] ?? ($row['created_by_login'] ?? 'Unknown')
        ];
    }
    return $out;
}

function jsonResponse(bool $success, string $message, $data = null): void {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
}

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        jsonResponse(false, 'User not logged in', null);
        return;
    }

    $userRole = strtolower($_SESSION['role'] ?? '');
    if (!userHasC168AnnouncementPageAccess($userRole)) {
        http_response_code(403);
        jsonResponse(false, 'No permission to access this function', null);
        return;
    }

    if (!hasC168AnnouncementAccess($pdo)) {
        http_response_code(403);
        jsonResponse(false, 'No permission to access this function', null);
        return;
    }

    $rows = fetchAllAnnouncements($pdo);
    $data = formatListRows($rows);
    jsonResponse(true, '', $data);

} catch (PDOException $e) {
    error_log('Announcement list API DB error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
}