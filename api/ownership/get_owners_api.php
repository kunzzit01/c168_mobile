<?php
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

$company_id = $_GET['company_id'] ?? null;
$monthRaw = $_GET['month'] ?? null;

if (!$company_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing company_id']);
    exit();
}

$parsedMonth = ownership_history_parse_month_param($monthRaw);
$useHistory = $parsedMonth !== null && ownership_history_is_past_month($parsedMonth['month_key']);

try {
    $tableExists = ownership_table_exists($pdo, 'company_ownership');

    if (!$tableExists) {
        echo json_encode(['status' => 'success', 'data' => [], 'meta' => ['is_historical' => false]]);
        exit();
    }

    $hasOwnerType = ownership_column_exists($pdo, 'company_ownership', 'owner_type');

    if ($useHistory) {
        ownership_history_ensure_tables($pdo);
        $effectiveMonth = $parsedMonth['effective_month'];
        $meta = ownership_history_company_meta($pdo, (int) $company_id, $effectiveMonth);

        if (!$hasOwnerType) {
            echo json_encode([
                'status' => 'success',
                'data' => [],
                'meta' => [
                    'is_historical' => true,
                    'effective_month' => $parsedMonth['month_key'],
                    'saved_at' => $meta['saved_at'],
                    'has_snapshot' => $meta['has_snapshot'],
                ],
            ]);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT coh.id as ownership_id, coh.percentage, coh.owner_type,
                   CASE
                       WHEN coh.owner_type = 'group' THEN CONCAT('G_', coh.partner_group_id)
                       ELSE CONCAT(
                           CASE
                               WHEN coh.owner_type = 'owner' THEN 'O_'
                               WHEN coh.owner_type = 'user' THEN 'U_'
                               ELSE 'A_'
                           END,
                           coh.account_id
                       )
                   END as account_id,
                   CASE
                       WHEN coh.owner_type = 'group' THEN CONCAT('Group: ', coh.partner_group_id)
                       ELSE COALESCE(coh.partner_group_id, a.account_id, o.owner_code, u.login_id)
                   END as account_name,
                   CASE
                       WHEN coh.owner_type = 'group' THEN 'Group Equity'
                       ELSE COALESCE(a.name, o.name, u.name)
                   END as name,
                   CASE
                       WHEN coh.owner_type = 'group' THEN 'GROUP'
                       WHEN coh.owner_type = 'user' THEN u.role
                       WHEN coh.owner_type = 'owner' THEN 'OWNER'
                       ELSE a.role
                   END as role,
                   coh.partner_group_id,
                   CASE WHEN coh.owner_type = 'user' THEN coh.account_id ELSE NULL END as user_raw_id,
                   CASE
                       WHEN coh.owner_type = 'user'  THEN coh.read_only
                       WHEN coh.owner_type = 'owner' AND comp.owner_id != coh.account_id THEN coh.read_only
                       ELSE NULL
                   END as read_only,
                   CASE
                       WHEN coh.owner_type = 'owner' AND comp.owner_id != coh.account_id THEN 1
                       ELSE 0
                   END as is_external_partner
            FROM company_ownership_history coh
            LEFT JOIN account a ON coh.account_id = a.id AND coh.owner_type = 'account'
            LEFT JOIN owner o ON coh.account_id = o.id AND coh.owner_type = 'owner'
            LEFT JOIN user u ON coh.account_id = u.id AND coh.owner_type = 'user'
            LEFT JOIN company comp ON comp.id = coh.company_id
            WHERE coh.company_id = ? AND coh.effective_month = ? AND coh.owner_type != 'account'
            ORDER BY coh.id ASC
        ");
        $stmt->execute([$company_id, $effectiveMonth]);
        $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($owners as &$owner) {
            $owner['percentage'] = money_out($owner['percentage'], 2);
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

    if ($hasOwnerType) {
        ownership_ensure_sort_order_column($pdo, 'company_ownership');

        $stmt = $pdo->prepare("
            SELECT co.id as ownership_id, co.percentage, co.owner_type,
                   CASE
                       WHEN co.owner_type = 'group' THEN CONCAT('G_', co.partner_group_id)
                       ELSE CONCAT(
                           CASE 
                               WHEN co.owner_type = 'owner' THEN 'O_'
                               WHEN co.owner_type = 'user' THEN 'U_'
                               ELSE 'A_' 
                           END, 
                           co.account_id
                       )
                   END as account_id,
                   CASE
                       WHEN co.owner_type = 'group' THEN CONCAT('Group: ', co.partner_group_id)
                       ELSE COALESCE(co.partner_group_id, a.account_id, o.owner_code, u.login_id)
                   END as account_name,
                   CASE
                       WHEN co.owner_type = 'group' THEN 'Group Equity'
                       ELSE COALESCE(a.name, o.name, u.name)
                   END as name,
                   CASE
                       WHEN co.owner_type = 'group' THEN 'GROUP'
                       WHEN co.owner_type = 'user' THEN u.role
                       WHEN co.owner_type = 'owner' THEN 'OWNER'
                       ELSE a.role
                   END as role,
                   co.partner_group_id,
                   CASE WHEN co.owner_type = 'user' THEN co.account_id ELSE NULL END as user_raw_id,
                   CASE
                       WHEN co.owner_type = 'user'  THEN co.read_only
                       WHEN co.owner_type = 'owner' AND comp.owner_id != co.account_id THEN co.read_only
                       ELSE NULL
                   END as read_only,
                   CASE
                       WHEN co.owner_type = 'owner' AND comp.owner_id != co.account_id THEN 1
                       ELSE 0
                   END as is_external_partner
            FROM company_ownership co
            LEFT JOIN account a ON co.account_id = a.id AND co.owner_type = 'account'
            LEFT JOIN owner o ON co.account_id = o.id AND co.owner_type = 'owner'
            LEFT JOIN user u ON co.account_id = u.id AND co.owner_type = 'user'
            LEFT JOIN company comp ON comp.id = co.company_id
            WHERE co.company_id = ? AND co.owner_type != 'account'
            ORDER BY co.sort_order ASC, co.id ASC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT co.id as ownership_id, co.percentage, 'account' as owner_type,
                   CONCAT('A_', co.account_id) as account_id,
                   a.account_id as account_name, a.name, a.role,
                   NULL as partner_group_id
            FROM company_ownership co
            JOIN account a ON co.account_id = a.id
            WHERE co.company_id = ? AND 1=0
            ORDER BY co.percentage DESC, a.account_id ASC
        ");
    }

    $stmt->execute([$company_id]);
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($owners as &$owner) {
        $owner['percentage'] = money_out($owner['percentage'], 2);
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
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
