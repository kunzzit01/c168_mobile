<?php
/**
 * Group Earnings API — Batch save group ownership
 * POST body: { "group_id": "AP", "owners": [{ "account_id": "O_1", "percentage": 30, "read_only": 1 }] }
 */
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';
require_once '../includes/money_decimal.php';
require_once '../includes/ownership_history.php';
require_once '../includes/ownership_schema.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
if (strtolower($_SESSION['role'] ?? '') !== 'owner') {
    echo json_encode(['status' => 'error', 'message' => 'Read-only: only owner can modify ownership']);
    exit();
}

$inputData = json_decode(file_get_contents('php://input'), true);

$group_id = $inputData['group_id'] ?? null;
$owners   = $inputData['owners'] ?? [];

if (!$group_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing group_id']);
    exit();
}

function ownershipPct($value): string {
    return money_normalize($value, 2);
}

function ownershipPctOut($value): string {
    return money_out($value, 2);
}

// Validate total percentage (external partners at 0% are excluded)
$total_percentage = '0.00';
foreach ($owners as $owner) {
    if (!isset($owner['account_id']) || !isset($owner['percentage'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid owner data format']);
        exit();
    }
    $isExternal = !empty($owner['is_external_partner']);
    $pct = ownershipPct($owner['percentage']);
    if ($isExternal) {
        if (money_cmp($pct, '0', 2) !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'External partner rows must stay at 0%']);
            exit();
        }
        continue;
    }
    if (money_cmp($pct, '0', 2) < 0 || money_cmp($pct, '100', 2) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Percentage must be between 0 and 100']);
        exit();
    }
    $total_percentage = money_add($total_percentage, $pct, 2);
}

if (money_cmp($total_percentage, '100', 2) > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Total allocation exceeds 100%']);
    exit();
}

$monthRaw = $inputData['month'] ?? null;
$parsedMonth = ownership_history_parse_month_param($monthRaw);
$saveHistoryOnly = $parsedMonth !== null && ownership_history_is_past_month($parsedMonth['month_key']);

if ($saveHistoryOnly) {
    try {
        ownership_history_ensure_tables($pdo);
        $effectiveMonth = $parsedMonth['effective_month'];

        $sessionRole = strtolower($_SESSION['role'] ?? '');
        if ($sessionRole === 'owner') {
            $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);
        } else {
            $stmtOwn = $pdo->prepare("SELECT DISTINCT owner_id FROM company WHERE UPPER(TRIM(group_id)) = UPPER(TRIM(?)) LIMIT 1");
            $stmtOwn->execute([$group_id]);
            $owner_id = (int) $stmtOwn->fetchColumn();
        }
        if ($owner_id <= 0) {
            $owner_id = ownership_history_resolve_group_owner_id($pdo, $group_id);
        }

        $existingGroups = [];
        $existingReadOnly = [];
        $stmtGroups = $pdo->prepare("
            SELECT account_id, partner_group_id, COALESCE(read_only, 1) as read_only
            FROM group_ownership_history
            WHERE group_id = ? AND effective_month = ? AND owner_type = 'owner'
        ");
        $stmtGroups->execute([$group_id, $effectiveMonth]);
        while ($row = $stmtGroups->fetch(PDO::FETCH_ASSOC)) {
            $existingGroups[(int) $row['account_id']] = $row['partner_group_id'];
            $existingReadOnly[(int) $row['account_id']] = (int) $row['read_only'];
        }

        $existingGroupReadOnly = [];
        $stmtGrp = $pdo->prepare("
            SELECT partner_group_id, COALESCE(read_only, 1) as read_only
            FROM group_ownership_history
            WHERE group_id = ? AND effective_month = ? AND owner_type = 'group'
        ");
        $stmtGrp->execute([$group_id, $effectiveMonth]);
        while ($row = $stmtGrp->fetch(PDO::FETCH_ASSOC)) {
            $key = strtoupper(trim((string) $row['partner_group_id']));
            if ($key !== '') {
                $existingGroupReadOnly[$key] = (int) $row['read_only'];
            }
        }

        $historyRows = ownership_build_group_history_rows_from_payload(
            $owners,
            $existingGroups,
            $existingReadOnly,
            $existingGroupReadOnly
        );
        $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        $pdo->beginTransaction();
        ownership_history_save_group_for_month($pdo, $group_id, $owner_id, $historyRows, $savedBy, $effectiveMonth);
        $pdo->commit();

        echo json_encode([
            'status'  => 'success',
            'message' => 'Historical group ownership saved successfully',
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
        ]);
    }
    exit();
}

try {
    // Auto-create table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_ownership (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id VARCHAR(50) NOT NULL,
            owner_id INT NOT NULL,
            account_id INT NOT NULL,
            owner_type ENUM('owner','user','group') NOT NULL DEFAULT 'owner',
            percentage DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            partner_group_id VARCHAR(50) DEFAULT NULL,
            read_only TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try { $pdo->exec("ALTER TABLE group_ownership MODIFY COLUMN owner_type ENUM('owner','user','group') NOT NULL DEFAULT 'owner'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE group_ownership DROP INDEX uq_group_account"); } catch (Exception $e) {}

    // Resolve effective owner id (admin sessions carry user.id, not owner.id)
    $sessionRole = strtolower($_SESSION['role'] ?? '');
    if ($sessionRole === 'owner') {
        $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);
    } else {
        $stmtOwn = $pdo->prepare("SELECT DISTINCT owner_id FROM company WHERE UPPER(TRIM(group_id)) = UPPER(TRIM(?)) LIMIT 1");
        $stmtOwn->execute([$group_id]);
        $owner_id = (int) $stmtOwn->fetchColumn();
    }
    if ($owner_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot determine the owner of this group']);
        exit();
    }

    ownership_history_ensure_tables($pdo);
    ownership_ensure_sort_order_column($pdo, 'group_ownership');

    $pdo->beginTransaction();

    // Preserve existing partner_group_id + read_only for owner-type rows
    $existingGroups = [];
    $existingReadOnly = [];
    $stmtGroups = $pdo->prepare("SELECT account_id, partner_group_id, COALESCE(read_only, 1) as read_only FROM group_ownership WHERE group_id = ? AND owner_type = 'owner'");
    $stmtGroups->execute([$group_id]);
    while ($row = $stmtGroups->fetch(PDO::FETCH_ASSOC)) {
        $existingGroups[$row['account_id']] = $row['partner_group_id'];
        $existingReadOnly[$row['account_id']] = (int) $row['read_only'];
    }

    // Preserve existing read_only for group-type rows (keyed by partner_group_id)
    $existingGroupReadOnly = [];
    $stmtGrp = $pdo->prepare("SELECT partner_group_id, COALESCE(read_only, 1) as read_only FROM group_ownership WHERE group_id = ? AND owner_type = 'group'");
    $stmtGrp->execute([$group_id]);
    while ($row = $stmtGrp->fetch(PDO::FETCH_ASSOC)) {
        $key = strtoupper(trim((string) $row['partner_group_id']));
        if ($key !== '') {
            $existingGroupReadOnly[$key] = (int) $row['read_only'];
        }
    }

    // Remove all existing rows for this group
    $stmt = $pdo->prepare("DELETE FROM group_ownership WHERE group_id = ?");
    $stmt->execute([$group_id]);

    $historyRows = [];

    // Insert new rows
    if (count($owners) > 0) {
        $insertStmt = $pdo->prepare("
            INSERT INTO group_ownership (group_id, owner_id, account_id, owner_type, percentage, partner_group_id, read_only, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($owners as $sortIdx => $owner) {
            $raw_id = (string) $owner['account_id'];
            $owner_type = 'owner';
            $real_id = 0;
            $pgid = null;
            $roVal = isset($owner['read_only']) ? (int) $owner['read_only'] : 1;
            $sortOrder = isset($owner['sort_order']) ? (int) $owner['sort_order'] : (int) $sortIdx;

            if (strpos($raw_id, 'G_') === 0) {
                // Self-group link: G_AP → owner_type='group', account_id=0, partner_group_id='AP'
                $owner_type = 'group';
                $real_id = 0;
                $pgid = substr($raw_id, 2);
                if (!isset($owner['read_only'])) {
                    $key = strtoupper(trim((string) $pgid));
                    if ($key !== '' && isset($existingGroupReadOnly[$key])) {
                        $roVal = $existingGroupReadOnly[$key];
                    }
                }
            } elseif (strpos($raw_id, 'O_') === 0) {
                $owner_type = 'owner';
                $real_id = (int) substr($raw_id, 2);
                if (isset($existingGroups[$real_id])) {
                    $pgid = $existingGroups[$real_id];
                    if (!isset($owner['read_only'])) {
                        $roVal = $existingReadOnly[$real_id] ?? 1;
                    }
                }
            } elseif (strpos($raw_id, 'U_') === 0) {
                $owner_type = 'user';
                $real_id = (int) substr($raw_id, 2);
            } else {
                // Legacy numeric id → assume owner
                $owner_type = 'owner';
                $real_id = (int) $raw_id;
            }

            $pctOut = ownershipPctOut($owner['percentage']);
            $insertStmt->execute([$group_id, $owner_id, $real_id, $owner_type, $pctOut, $pgid, $roVal, $sortOrder]);

            $historyRows[] = [
                'account_id' => $real_id,
                'owner_type' => $owner_type,
                'percentage' => $pctOut,
                'partner_group_id' => $pgid,
                'read_only' => $roVal,
            ];

            // Sync read_only to user table
            if ($owner_type === 'user') {
                $uStmt = $pdo->prepare("UPDATE user SET read_only = ? WHERE id = ?");
                $uStmt->execute([$roVal, $real_id]);
            }
        }
    }

    $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    ownership_history_save_group($pdo, $group_id, $owner_id, $historyRows, $savedBy);

    $retrofillMonths = $inputData['retrofill_months'] ?? [];
    if (is_array($retrofillMonths) && count($retrofillMonths) > 0) {
        ownership_history_apply_group_retrofill_months($pdo, $group_id, $owner_id, $historyRows, $savedBy, $retrofillMonths);
    }

    $pdo->commit();

    echo json_encode([
        'status'  => 'success',
        'message' => 'Group ownership saved successfully'
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
