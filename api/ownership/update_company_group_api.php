<?php
/**
 * Ownership – Update Company Group API
 * POST body: { "company_id": <int>, "group_id": "<string|null>" }
 *
 * group_id = null / "" → clear group (make independent)
 * group_id = "G1"     → assign to that group
 *
 * Only the company's owner (or admin) may call this.
 */
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
if (strtolower($_SESSION['role'] ?? '') !== 'owner') {
    echo json_encode(['status' => 'error', 'message' => 'Read-only: only owner can modify ownership']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$company_id = isset($body['company_id']) ? (int)$body['company_id'] : 0;
$group_id   = isset($body['group_id']) && $body['group_id'] !== '' ? trim($body['group_id']) : null;

if ($company_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid company_id']);
    exit;
}

try {
    $current_user_role = strtolower($_SESSION['role'] ?? '');
    $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);

    // Verify the company belongs to this owner (security check)
    if ($current_user_role === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ? LIMIT 1");
        $stmt->execute([$company_id, $owner_id]);
    } else {
        // For non-owner users, verify via user_company_map
        $stmt = $pdo->prepare("
            SELECT c.id FROM company c
            INNER JOIN user_company_map ucm ON c.id = ucm.company_id
            WHERE c.id = ? AND ucm.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$company_id, (int)$_SESSION['user_id']]);
    }

    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Company not found or access denied']);
        exit;
    }

    // Update group_id
    $updateStmt = $pdo->prepare("UPDATE company SET group_id = ? WHERE id = ?");
    $updateStmt->execute([$group_id, $company_id]);

    $action = $group_id ? "joined group \"$group_id\"" : "removed from group";
    echo json_encode(['status' => 'success', 'message' => "Company $action successfully"]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
