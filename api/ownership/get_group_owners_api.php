<?php
/**
 * Group Earnings API — Get ownership rows for a specific group
 * GET ?group_id=AP&month=2026-03 (optional, past months read from history)
 */
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';
require_once '../includes/money_decimal.php';
require_once '../includes/ownership_history.php';
require_once '../includes/ownership_schema.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$group_id = $_GET['group_id'] ?? null;
$monthRaw = $_GET['month'] ?? null;

if (!$group_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing group_id']);
    exit();
}

$parsedMonth = ownership_history_parse_month_param($monthRaw);
$useHistory = $parsedMonth !== null && ownership_history_is_past_month($parsedMonth['month_key']);

try {
    if ($useHistory) {
        ownership_history_ensure_tables($pdo);
        $tableExists = ownership_table_exists($pdo, 'group_ownership_history');
        if (!$tableExists) {
            echo json_encode([
                'status' => 'success',
                'data' => [],
                'meta' => [
                    'is_historical' => true,
                    'effective_month' => $parsedMonth['month_key'],
                    'has_snapshot' => false,
                ],
            ]);
            exit();
        }

        $effectiveMonth = $parsedMonth['effective_month'];
        $meta = ownership_history_group_meta($pdo, $group_id, $effectiveMonth);

        $stmt = $pdo->prepare("
            SELECT goh.id as ownership_id,
                   goh.percentage,
                   goh.owner_type,
                   goh.account_id,
                   CASE
                       WHEN goh.owner_type = 'group' THEN CONCAT('G_', goh.partner_group_id)
                       WHEN goh.owner_type = 'user'  THEN CONCAT('U_', goh.account_id)
                       ELSE                              CONCAT('O_', goh.account_id)
                   END as composite_id,
                   CASE
                       WHEN goh.owner_type = 'group' THEN CONCAT('Group: ', goh.partner_group_id)
                       ELSE COALESCE(goh.partner_group_id, o.owner_code, u.login_id)
                   END as account_name,
                   CASE
                       WHEN goh.owner_type = 'group' THEN 'Group Equity'
                       ELSE COALESCE(o.name, u.name)
                   END as name,
                   CASE
                       WHEN goh.owner_type = 'group' THEN 'GROUP'
                       WHEN goh.owner_type = 'user'  THEN u.role
                       WHEN goh.owner_type = 'owner' THEN 'OWNER'
                   END as role,
                   goh.partner_group_id,
                   CASE WHEN goh.owner_type = 'user' THEN goh.account_id ELSE NULL END as user_raw_id,
                   goh.read_only,
                   CASE
                       WHEN goh.owner_type = 'owner' AND goh.account_id != goh.owner_id THEN 1
                       ELSE 0
                   END as is_external_partner
            FROM group_ownership_history goh
            LEFT JOIN owner o ON goh.account_id = o.id AND goh.owner_type = 'owner'
            LEFT JOIN user u ON goh.account_id = u.id AND goh.owner_type = 'user'
            WHERE goh.group_id = ? AND goh.effective_month = ?
            ORDER BY goh.percentage DESC
        ");

        $stmt->execute([$group_id, $effectiveMonth]);
        $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($owners as &$owner) {
            $owner['percentage'] = money_out($owner['percentage'], 2);
            $owner['account_id'] = $owner['composite_id'];
        }
        unset($owner);

        echo json_encode([
            'status' => 'success',
            'data' => $owners,
            'meta' => [
                'is_historical' => true,
                'effective_month' => $parsedMonth['month_key'],
                'saved_at' => $meta['saved_at'],
                'has_snapshot' => $meta['has_snapshot'],
            ],
        ]);
        exit();
    }

    $tableExists = ownership_table_exists($pdo, 'group_ownership');
    if (!$tableExists) {
        echo json_encode(['status' => 'success', 'data' => [], 'meta' => ['is_historical' => false]]);
        exit();
    }

    ownership_ensure_sort_order_column($pdo, 'group_ownership');

    $stmt = $pdo->prepare("
        SELECT go.id as ownership_id,
               go.percentage,
               go.owner_type,
               go.account_id,
               CASE
                   WHEN go.owner_type = 'group' THEN CONCAT('G_', go.partner_group_id)
                   WHEN go.owner_type = 'user'  THEN CONCAT('U_', go.account_id)
                   ELSE                              CONCAT('O_', go.account_id)
               END as composite_id,
               CASE
                   WHEN go.owner_type = 'group' THEN CONCAT('Group: ', go.partner_group_id)
                   ELSE COALESCE(go.partner_group_id, o.owner_code, u.login_id)
               END as account_name,
               CASE
                   WHEN go.owner_type = 'group' THEN 'Group Equity'
                   ELSE COALESCE(o.name, u.name)
               END as name,
               CASE
                   WHEN go.owner_type = 'group' THEN 'GROUP'
                   WHEN go.owner_type = 'user'  THEN u.role
                   WHEN go.owner_type = 'owner' THEN 'OWNER'
               END as role,
               go.partner_group_id,
               CASE WHEN go.owner_type = 'user' THEN go.account_id ELSE NULL END as user_raw_id,
               go.read_only,
               CASE
                   WHEN go.owner_type = 'owner' AND go.account_id != go.owner_id THEN 1
                   ELSE 0
               END as is_external_partner
        FROM group_ownership go
        LEFT JOIN owner o ON go.account_id = o.id AND go.owner_type = 'owner'
        LEFT JOIN user u ON go.account_id = u.id AND go.owner_type = 'user'
        WHERE go.group_id = ?
        ORDER BY go.sort_order ASC, go.id ASC
    ");

    $stmt->execute([$group_id]);
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($owners as &$owner) {
        $owner['percentage'] = money_out($owner['percentage'], 2);
        $owner['account_id'] = $owner['composite_id'];
    }
    unset($owner);

    echo json_encode([
        'status' => 'success',
        'data' => $owners,
        'meta' => [
            'is_historical' => false,
            'effective_month' => ownership_history_current_month_key(),
        ],
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
