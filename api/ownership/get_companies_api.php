<?php
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';
require_once '../../includes/group_company_access.php';
require_once '../includes/money_decimal.php';
require_once '../includes/ownership_history.php';
require_once '../includes/ownership_schema.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['role'] ?? '';
// ?all=1 → bypass session group filter (used by ownership page's local group filter bar)
$fetchAll = isset($_GET['all']) && $_GET['all'] === '1';
$parsedMonth = ownership_history_parse_month_param($_GET['month'] ?? null);
$useHistory = $parsedMonth !== null && ownership_history_is_past_month($parsedMonth['month_key']);

try {
    $tableExists = ownership_table_exists($pdo, 'company_ownership');
    
    // Get companies available to this user
    require_once '../get_companies_helper.php';
    $companies = [];
    if ($current_user_role === 'owner') {
        // Use real_owner_id (permanent id) — owner_id can be swapped to another owner's id
        // when the user selects an external company (e.g. LOL selects JK's company TT).
        // Without this, we'd return JK's companies instead of LOL's.
        $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $current_user_id);
        $fetched = getCompaniesByOwner($pdo, $owner_id, $fetchAll);
        foreach ($fetched as $c) {
            $companies[] = [
                'id'              => $c['id'],
                'name'            => $c['company_id'],
                'company_id'      => $c['company_id'],
                'expiration_date' => $c['expiration_date'] ?? null,
                'group_id'        => $c['group_id'] ?? null,
            ];
        }
    } else {
        $fetched = getCompaniesByUser($pdo, $current_user_id, $fetchAll);
        foreach ($fetched as $c) {
            $companies[] = [
                'id'              => $c['id'],
                'name'            => $c['company_id'],
                'company_id'      => $c['company_id'],
                'expiration_date' => $c['expiration_date'] ?? null,
                'group_id'        => $c['group_id'] ?? null,
            ];
        }
    }

    $companies = gc_filter_real_company_rows($companies);
    $companies = gc_apply_login_scope_company_filter($pdo, $companies);

    // Get total ownership assigned for each company
    if (count($companies) > 0) {
        $company_ids = array_column($companies, 'id');
        $in = str_repeat('?,', count($company_ids) - 1) . '?';

        if ($useHistory) {
            ownership_history_ensure_tables($pdo);
            $stmt = $pdo->prepare("
                SELECT company_id, SUM(percentage) as total_percent
                FROM company_ownership_history
                WHERE company_id IN ($in)
                  AND effective_month = ?
                  AND owner_type != 'account'
                GROUP BY company_id
            ");
            $stmt->execute(array_merge($company_ids, [$parsedMonth['effective_month']]));
            $totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } elseif ($tableExists) {
            $hasOwnerType = ownership_column_exists($pdo, 'company_ownership', 'owner_type');

            if ($hasOwnerType) {
                $stmt = $pdo->prepare("
                    SELECT company_id, SUM(percentage) as total_percent
                    FROM company_ownership
                    WHERE company_id IN ($in) AND owner_type != 'account'
                    GROUP BY company_id
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT company_id, SUM(percentage) as total_percent
                    FROM company_ownership
                    WHERE company_id IN ($in) AND 1=0
                    GROUP BY company_id
                ");
            }
            $stmt->execute($company_ids);
            $totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } else {
            $totals = [];
        }

        foreach ($companies as &$company) {
            $company['allocated_percentage'] = isset($totals[$company['id']]) ? money_out($totals[$company['id']], 2) : '0';
        }
        unset($company);
    } else {
        foreach ($companies as &$company) {
            $company['allocated_percentage'] = '0';
        }
        unset($company);
    }

    echo json_encode([
        'status' => 'success',
        'data' => $companies,
        'meta' => [
            'is_historical' => $useHistory,
            'effective_month' => $useHistory ? $parsedMonth['month_key'] : ownership_history_current_month_key(),
        ],
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
