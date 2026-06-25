<?php
/**
 * Transaction Get Categories API
 * 用于获取账户分类列表（account.role），填充 Category 下拉框
 * 路径: api/transactions/get_categories_api.php
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

function fetchRolesFromDb(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT code FROM role ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function orderRolesByPriority(array $roles): array
{
    $priority = ['CAPITAL', 'BANK', 'CASH', 'PROFIT', 'EXPENSES', 'COMPANY', 'PARTNER', 'STAFF', 'SUPPLIER', 'AGENT', 'MEMBER', 'DEBTOR'];
    $upper = array_map('strtoupper', $roles);
    $map = array_combine($upper, $roles);
    $ordered = [];
    $done = [];
    
    foreach ($priority as $p) {
        $pu = strtoupper($p);
        if (isset($map[$pu])) {
            // Keep existing case from DB if found
            $val = $map[$pu];
            // Display mapping: UPLINE -> SUPPLIER
            if (strtoupper($val) === 'UPLINE') {
                $val = 'SUPPLIER';
            }
            $ordered[] = strtoupper($val);
            $done[] = $pu;
        } else if ($pu === 'SUPPLIER' && isset($map['UPLINE'])) {
            // Mapping for sorting: map UPLINE to SUPPLIER priority position
            $ordered[] = 'SUPPLIER';
            $done[] = 'UPLINE';
        }
    }
    
    $rest = array_diff($upper, $done);
    // Remove UPLINE from rest if it was mapped
    $rest = array_filter($rest, fn($r) => $r !== 'UPLINE');
    sort($rest);
    
    $finalRoles = array_merge($ordered, $rest);
    
    // Ensure PARTNER is in the list (if it's in the priority list but not in DB)
    if (!in_array('PARTNER', $finalRoles)) {
        // Find COMPANY position to insert PARTNER after it
        $companyIdx = array_search('COMPANY', $finalRoles);
        if ($companyIdx !== false) {
            array_splice($finalRoles, $companyIdx + 1, 0, 'PARTNER');
        } else {
            $finalRoles[] = 'PARTNER';
        }
    }
    
    // Ensure STAFF is in the list
    if (!in_array('STAFF', $finalRoles)) {
        // Try to insert after PARTNER or COMPANY
        $insertAfter = array_search('PARTNER', $finalRoles);
        if ($insertAfter === false) $insertAfter = array_search('COMPANY', $finalRoles);
        
        if ($insertAfter !== false) {
            array_splice($finalRoles, $insertAfter + 1, 0, 'STAFF');
        } else {
            $finalRoles[] = 'STAFF';
        }
    }

    // Ensure DEBTOR is in the list (if it's in the priority list but not in DB)
    if (!in_array('DEBTOR', $finalRoles)) {
        // Try to insert after MEMBER
        $memberIdx = array_search('MEMBER', $finalRoles);
        if ($memberIdx !== false) {
            array_splice($finalRoles, $memberIdx + 1, 0, 'DEBTOR');
        } else {
            $finalRoles[] = 'DEBTOR';
        }
    }
    
    return $finalRoles;
}

try {
    $roles = fetchRolesFromDb($pdo);
    $orderedRoles = orderRolesByPriority($roles);
    api_success($orderedRoles);
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}