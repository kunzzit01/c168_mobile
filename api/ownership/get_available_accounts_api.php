<?php
require_once '../../includes/session_check.php';
require_once '../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$company_id = $_GET['company_id'] ?? null;

try {
    if ($company_id) {
        // Fetch native owner and any linked external partners
        $stmtOwner = $pdo->prepare("
            SELECT DISTINCT CONCAT('O_', o.id) as id, 
                   COALESCE(co.partner_group_id, o.owner_code) as account_name, 
                   o.name, 'OWNER' as role, 'owner' as type,
                   CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END as is_main_owner
            FROM owner o
            LEFT JOIN company c ON o.id = c.owner_id AND c.id = :comp_id1
            LEFT JOIN company_ownership co ON o.id = co.account_id AND co.owner_type = 'owner' AND co.company_id = :comp_id2
            WHERE (c.id IS NOT NULL OR co.company_id IS NOT NULL)
              AND LOWER(o.status) = 'active'
        ");
        $stmtOwner->execute(['comp_id1' => $company_id, 'comp_id2' => $company_id]);
        $users = $stmtOwner->fetchAll(PDO::FETCH_ASSOC);
        // External partners (Link Partner) must not appear in "+ Add Account" dropdown.
        $users = array_values(array_filter($users, static function ($row) {
            return (int) ($row['is_main_owner'] ?? 0) === 1;
        }));

        // Fetch linked group entries for this company so existing G_xxx rows
        // always have a matching option in the account dropdown.
        $groups = [];
        try {
            $stmtGroup = $pdo->prepare("
                SELECT DISTINCT
                    CONCAT('G_', co.partner_group_id) as id,
                    CONCAT('Group: ', co.partner_group_id) as account_name,
                    'Group Equity' as name,
                    'GROUP' as role,
                    'group' as type,
                    0 as is_main_owner
                FROM company_ownership co
                WHERE co.company_id = ?
                  AND co.owner_type = 'group'
                  AND co.partner_group_id IS NOT NULL
                  AND TRIM(co.partner_group_id) <> ''
            ");
            $stmtGroup->execute([$company_id]);
            $groups = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $groups = [];
        }

        // Fetch user partners mapped to this company (both owner and partnership roles can see this so their own names appear in dropdowns)
        $partners = [];
        if (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['owner', 'partnership'])) {
            $stmtPartner = $pdo->prepare("
                SELECT DISTINCT CONCAT('U_', u.id) as id, 
                       u.login_id as account_name, 
                       u.name, 'PARTNERSHIP' as role, 'user' as type,
                       0 as is_main_owner
                FROM user u
                INNER JOIN user_company_map ucm ON u.id = ucm.user_id
                WHERE ucm.company_id = ? AND LOWER(u.role) = 'partnership' AND LOWER(u.status) = 'active'
            ");
            $stmtPartner->execute([$company_id]);
            $partners = $stmtPartner->fetchAll(PDO::FETCH_ASSOC);
        }

        // Sort by account_name
        $combined = array_merge($users, $partners, $groups);

        // Sort alphabetically by account_name
        usort($combined, function ($a, $b) {
            return strcmp($a['account_name'], $b['account_name']);
        });

        echo json_encode([
            'status' => 'success',
            'data' => $combined
        ]);

    } else {
        // Fallback or global mode, return generally available
        $stmtOwner = $pdo->prepare("
            SELECT CONCAT('O_', id) as id, owner_code as account_name, name, 'OWNER' as role, 'owner' as type, 0 as is_main_owner
            FROM owner
            WHERE LOWER(status) = 'active'
              AND id = ?
        ");
        $stmtOwner->execute([$_SESSION['user_id']]);
        $users = $stmtOwner->fetchAll(PDO::FETCH_ASSOC);

        // For fallback, fetch all active partners in the system (both owner and partnership roles can see this)
        $partners = [];
        if (isset($_SESSION['role']) && in_array(strtolower($_SESSION['role']), ['owner', 'partnership'])) {
            $stmtPartner = $pdo->prepare("
                SELECT DISTINCT CONCAT('U_', id) as id, 
                       login_id as account_name, 
                       name, 'PARTNERSHIP' as role, 'user' as type,
                       0 as is_main_owner
                FROM user
                WHERE LOWER(role) = 'partnership' AND LOWER(status) = 'active'
            ");
            $stmtPartner->execute();
            $partners = $stmtPartner->fetchAll(PDO::FETCH_ASSOC);
        }

        $combined = array_merge($users, $partners);
        usort($combined, function ($a, $b) {
            return strcmp($a['account_name'], $b['account_name']);
        });

        echo json_encode([
            'status' => 'success',
            'data' => $combined
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>