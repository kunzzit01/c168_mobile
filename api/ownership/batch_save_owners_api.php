<?php
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';
require_once '../../includes/group_company_access.php';
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

/**
 * Expected JSON payload:
 * {
 *   "company_id": "1",
 *   "owners": [
 *     {"account_id": "U_3", "percentage": 50},
 *     {"account_id": "A_5", "percentage": 30}
 *   ]
 * }
 */
$inputData = json_decode(file_get_contents('php://input'), true);

$company_id = $inputData['company_id'] ?? null;
$owners = $inputData['owners'] ?? [];

if (!$company_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing company_id']);
    exit();
}

try {
    gc_assert_api_company_access($pdo, (int) $company_id, gc_session_login_identifier());
} catch (RuntimeException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
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
    if (money_cmp($pct, '0', 2) <= 0 || money_cmp($pct, '100', 2) > 0) {
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

$hasOwnerType = $pdo->query("SHOW COLUMNS FROM company_ownership LIKE 'owner_type'")->rowCount() > 0;

if ($saveHistoryOnly) {
    try {
        ownership_history_ensure_tables($pdo);
        $effectiveMonth = $parsedMonth['effective_month'];
        $companyIdInt = (int) $company_id;

        $existingGroups = [];
        $existingReadOnly = [];
        $stmtGroups = $pdo->prepare("
            SELECT account_id, partner_group_id, COALESCE(read_only, 1) as read_only
            FROM company_ownership_history
            WHERE company_id = ? AND effective_month = ? AND owner_type = 'owner'
        ");
        $stmtGroups->execute([$companyIdInt, $effectiveMonth]);
        while ($row = $stmtGroups->fetch(PDO::FETCH_ASSOC)) {
            $existingGroups[(int) $row['account_id']] = $row['partner_group_id'];
            $existingReadOnly[(int) $row['account_id']] = (int) $row['read_only'];
        }

        $historyRows = ownership_build_company_history_rows_from_payload($owners, $existingGroups, $existingReadOnly);
        $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        $pdo->beginTransaction();
        ownership_history_save_company_for_month($pdo, $companyIdInt, $historyRows, $savedBy, $effectiveMonth);
        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'Historical ownership saved successfully',
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
        ]);
    }
    exit();
}

try {
    // Auto-add 'group' to owner_type ENUM if not present
    try {
        $pdo->exec("ALTER TABLE company_ownership MODIFY COLUMN owner_type ENUM('account','owner','user','group') NOT NULL DEFAULT 'account'");
    } catch (Exception $e) { /* already has it or not applicable */ }
    // Drop legacy UNIQUE (company_id, account_id) key that blocks multi-group rows
    try { $pdo->exec("ALTER TABLE company_ownership DROP INDEX unique_company_account"); } catch (Exception $e) {}

    ownership_history_ensure_tables($pdo);
    ownership_ensure_sort_order_column($pdo, 'company_ownership');

    $pdo->beginTransaction();

    // Preserve existing partner_group_id and read_only for owner-type rows
    $existingGroups = [];
    $existingReadOnly = [];
    $stmtGroups = $pdo->prepare("SELECT account_id, partner_group_id, COALESCE(read_only, 1) as read_only FROM company_ownership WHERE company_id = ? AND owner_type = 'owner'");
    $stmtGroups->execute([$company_id]);
    while ($row = $stmtGroups->fetch(PDO::FETCH_ASSOC)) {
        $existingGroups[$row['account_id']] = $row['partner_group_id'];
        $existingReadOnly[$row['account_id']] = (int) $row['read_only'];
    }

    // Remove all existing owners for this company
    $stmt = $pdo->prepare("DELETE FROM company_ownership WHERE company_id = ?");
    $stmt->execute([$company_id]);

    $historyRows = [];

    // Insert new owners
    if (count($owners) > 0) {
        if ($hasOwnerType) {
            $insertStmt = $pdo->prepare("
                INSERT INTO company_ownership (company_id, account_id, owner_type, percentage, partner_group_id, read_only, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO company_ownership (company_id, account_id, percentage)
                VALUES (?, ?, ?)
            ");
        }

        foreach ($owners as $sortIdx => $owner) {
            $raw_id = (string) $owner['account_id'];
            $owner_type = 'account'; // default
            $real_id = $raw_id;
            $is_group_entry = false;
            $group_ref = null;
            $isExternal = !empty($owner['is_external_partner']);
            $sortOrder = isset($owner['sort_order']) ? (int) $owner['sort_order'] : (int) $sortIdx;

            if (strpos($raw_id, 'G_') === 0) {
                // Group entry: G_IG → owner_type='group', account_id=0, partner_group_id='IG'
                $owner_type = 'group';
                $real_id = 0;
                $group_ref = substr($raw_id, 2);
                $is_group_entry = true;
            } elseif (strpos($raw_id, 'O_') === 0) {
                $owner_type = 'owner';
                $real_id = substr($raw_id, 2);
            } elseif (strpos($raw_id, 'U_') === 0) {
                $owner_type = 'user';
                $real_id = substr($raw_id, 2);
            } elseif (strpos($raw_id, 'A_') === 0) {
                $owner_type = 'account';
                $real_id = substr($raw_id, 2);
            }

            if ($hasOwnerType) {
                $pgid = null;
                $roVal = isset($owner['read_only']) ? (int) $owner['read_only'] : 1;

                if ($is_group_entry) {
                    $pgid = $group_ref;
                } elseif ($owner_type === 'owner' && isset($existingGroups[(int) $real_id])) {
                    $pgid = $existingGroups[(int) $real_id];
                    if (!isset($owner['read_only'])) {
                        $roVal = $existingReadOnly[(int) $real_id] ?? 1;
                    }
                }
                $pctOut = ownershipPctOut($owner['percentage']);
                $insertStmt->execute([$company_id, (int) $real_id, $owner_type, $pctOut, $pgid, $roVal, $sortOrder]);

                $historyRows[] = [
                    'account_id' => (int) $real_id,
                    'owner_type' => $owner_type,
                    'percentage' => $pctOut,
                    'partner_group_id' => $pgid,
                    'read_only' => $roVal,
                ];

                // 同步 read_only 到 user 表的全局设置作为默认回退
                if ($owner_type === 'user') {
                    $uStmt = $pdo->prepare("UPDATE user SET read_only = ? WHERE id = ?");
                    $uStmt->execute([$roVal, (int) $real_id]);
                }
            } else {
                // If migration hasn't run, we must drop Users so it doesn't crash, or attempt.
                // In a perfect world, migration is run first. If not, only save numbers.
                $pctOut = ownershipPctOut($owner['percentage']);
                $insertStmt->execute([$company_id, (int) $real_id, $pctOut]);
                $historyRows[] = [
                    'account_id' => (int) $real_id,
                    'owner_type' => 'account',
                    'percentage' => $pctOut,
                    'partner_group_id' => null,
                    'read_only' => 1,
                ];
            }
        }
    }

    $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    ownership_history_save_company($pdo, (int) $company_id, $historyRows, $savedBy);

    $retrofillMonths = $inputData['retrofill_months'] ?? [];
    if (is_array($retrofillMonths) && count($retrofillMonths) > 0) {
        ownership_history_apply_retrofill_months($pdo, (int) $company_id, $historyRows, $savedBy, $retrofillMonths);
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Ownership saved successfully'
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>