<?php
/**
 * Group Earnings API — List all groups for the current owner
 * Returns group_id, total_allocation, remaining, company count
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

$current_user_role = $_SESSION['role'] ?? '';
$parsedMonth = ownership_history_parse_month_param($_GET['month'] ?? null);
$useHistory = $parsedMonth !== null && ownership_history_is_past_month($parsedMonth['month_key']);

try {
    ownership_ensure_group_ownership_table($pdo);

    // Get companies with groups for this user
    require_once '../get_companies_helper.php';
    $companies = [];

    if (strtolower($current_user_role) === 'owner') {
        $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);
        $fetched = getCompaniesByOwner($pdo, $owner_id, true); // all=true to get all companies
        foreach ($fetched as $c) {
            if (!empty($c['group_id'])) {
                $companies[] = [
                    'id'       => $c['id'],
                    'name'     => $c['company_id'],
                    'group_id' => $c['group_id'],
                ];
            }
        }
    } else {
        $fetched = getCompaniesByUser($pdo, (int)$_SESSION['user_id'], true);
        foreach ($fetched as $c) {
            if (!empty($c['group_id'])) {
                $companies[] = [
                    'id'       => $c['id'],
                    'name'     => $c['company_id'],
                    'group_id' => $c['group_id'],
                ];
            }
        }
    }

    // Group companies by group_id
    $groups = [];
    foreach ($companies as $comp) {
        $gid = $comp['group_id'];
        if (!isset($groups[$gid])) {
            $groups[$gid] = [
                'group_id'   => $gid,
                'companies'  => [],
            ];
        }
        $groups[$gid]['companies'][] = [
            'id'   => $comp['id'],
            'name' => $comp['name'],
        ];
    }

    // Get per-company group equity from company_ownership (owner_type='group')
    $companyGroupEquity = [];
    $allCompanyIds = array_map(fn($c) => $c['id'], $companies);
    if (!empty($allCompanyIds)) {
        $in = str_repeat('?,', count($allCompanyIds) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT company_id, partner_group_id, percentage
            FROM company_ownership
            WHERE company_id IN ($in) AND owner_type = 'group'
        ");
        $stmt->execute($allCompanyIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $companyGroupEquity[$row['company_id'] . '_' . $row['partner_group_id']] = money_out($row['percentage'], 2);
        }
    }

    // Get total allocation for each group
    $groupIds = array_keys($groups);
    $totals = [];
    if (!empty($groupIds)) {
        $in = str_repeat('?,', count($groupIds) - 1) . '?';
        if ($useHistory) {
            ownership_history_ensure_tables($pdo);
            $stmt = $pdo->prepare("
                SELECT group_id, SUM(percentage) as total_percent
                FROM group_ownership_history
                WHERE group_id IN ($in) AND effective_month = ?
                GROUP BY group_id
            ");
            $stmt->execute(array_merge($groupIds, [$parsedMonth['effective_month']]));
        } else {
            $stmt = $pdo->prepare("
                SELECT group_id, SUM(percentage) as total_percent
                FROM group_ownership
                WHERE group_id IN ($in)
                GROUP BY group_id
            ");
            $stmt->execute($groupIds);
        }
        $totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Build result
    $result = [];
    foreach ($groups as $gid => $grp) {
        $alloc = isset($totals[$gid]) ? money_out($totals[$gid], 2) : '0';
        // Add per-company group equity to each company entry
        $companiesWithEquity = [];
        foreach ($grp['companies'] as $comp) {
            $key = $comp['id'] . '_' . $gid;
            $comp['group_equity'] = $companyGroupEquity[$key] ?? '0';
            $companiesWithEquity[] = $comp;
        }
        $result[] = [
            'group_id'             => $gid,
            'companies'            => $companiesWithEquity,
            'company_count'        => count($grp['companies']),
            'allocated_percentage' => $alloc,
        ];
    }

    // Sort by group_id
    usort($result, function($a, $b) { return strcmp($a['group_id'], $b['group_id']); });

    echo json_encode([
        'status' => 'success',
        'data'   => $result,
        'meta'   => [
            'is_historical' => $useHistory,
            'effective_month' => $useHistory ? $parsedMonth['month_key'] : ownership_history_current_month_key(),
        ],
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
