<?php
/**
 * Group Ownership – Upsert single entry
 * POST body: { "group_id": "IG", "account_id": "O_1", "percentage": 30 }
 * 
 * Inserts or updates a single group_ownership row.
 * Used by Account Ownership tab when a group entry is saved.
 */
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';
require_once '../includes/money_decimal.php';
require_once '../includes/ownership_history.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$group_id   = trim($data['group_id'] ?? '');
$raw_id     = trim($data['account_id'] ?? '');
$percentage = money_normalize($data['percentage'] ?? 0, 2);

if (!$group_id || !$raw_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing group_id or account_id']);
    exit();
}

if (money_cmp($percentage, '0', 2) < 0 || money_cmp($percentage, '100', 2) > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Percentage must be between 0 and 100']);
    exit();
}

try {
    // Auto-create table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_ownership (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id VARCHAR(50) NOT NULL,
            owner_id INT NOT NULL,
            account_id INT NOT NULL,
            owner_type ENUM('owner','user') NOT NULL DEFAULT 'owner',
            percentage DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            partner_group_id VARCHAR(50) DEFAULT NULL,
            read_only TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_group_account (group_id, account_id, owner_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $owner_type = 'owner';
    $real_id = $raw_id;
    if (strpos($raw_id, 'O_') === 0) {
        $owner_type = 'owner';
        $real_id = (int) substr($raw_id, 2);
    } elseif (strpos($raw_id, 'U_') === 0) {
        $owner_type = 'user';
        $real_id = (int) substr($raw_id, 2);
    } else {
        $real_id = (int) $raw_id;
    }

    $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);

    // Check current total for this group (excluding the current account)
    $stmtTotal = $pdo->prepare("
        SELECT COALESCE(SUM(percentage), 0) as total
        FROM group_ownership
        WHERE group_id = ? AND NOT (account_id = ? AND owner_type = ?)
    ");
    $stmtTotal->execute([$group_id, $real_id, $owner_type]);
    $currentTotal = money_normalize($stmtTotal->fetchColumn(), 2);

    $newTotal = money_add($currentTotal, $percentage, 2);
    if (money_cmp($newTotal, '100', 2) > 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Total group allocation would exceed 100% (' . money_out($newTotal, 2) . '%)'
        ]);
        exit();
    }

    // Upsert: INSERT ... ON DUPLICATE KEY UPDATE
    $stmt = $pdo->prepare("
        INSERT INTO group_ownership (group_id, owner_id, account_id, owner_type, percentage)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE percentage = VALUES(percentage), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$group_id, $owner_id, $real_id, $owner_type, $percentage]);

    $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    ownership_history_snapshot_group_from_live($pdo, $group_id, $savedBy);

    echo json_encode([
        'status'  => 'success',
        'message' => "Group ownership for '{$group_id}' saved (" . money_out($percentage, 2) . "%)"
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
