<?php
session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once '../../includes/config.php';
require_once '../includes/ownership_history.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
if (strtolower($_SESSION['role'] ?? '') !== 'owner') {
    echo json_encode(['status' => 'error', 'message' => 'Read-only: only owner can modify ownership']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$company_id = intval($data['company_id'] ?? 0);
$login_or_group_id = trim($data['login_id'] ?? '');
$force_type = trim($data['force_type'] ?? '');

if (!$company_id || !$login_or_group_id) {
    echo json_encode(['status' => 'error', 'message' => 'Valid Company ID and Login ID/Group ID are required']);
    exit();
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS company_ownership (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            account_id INT NOT NULL,
            owner_type ENUM('account','owner','user','group') NOT NULL DEFAULT 'account',
            percentage DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            partner_group_id VARCHAR(50) DEFAULT NULL,
            read_only TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

try { $pdo->exec("ALTER TABLE company_ownership ADD COLUMN owner_type ENUM('account','owner','user','group') NOT NULL DEFAULT 'account'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE company_ownership MODIFY COLUMN owner_type ENUM('account','owner','user','group') NOT NULL DEFAULT 'account'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE company_ownership ADD COLUMN partner_group_id VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE company_ownership ADD COLUMN read_only TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
// Drop the legacy UNIQUE (company_id, account_id) key — it blocks multiple group-type
// rows (all share account_id=0). App-level dedupe handles uniqueness now.
try { $pdo->exec("ALTER TABLE company_ownership DROP INDEX unique_company_account"); } catch (Exception $e) {}

try {
    // Fetch native owner first
    $stmtCheckNative = $pdo->prepare("SELECT owner_id FROM company WHERE id = ?");
    $stmtCheckNative->execute([$company_id]);
    $nativeOwner = $stmtCheckNative->fetchColumn();
    $hasCompanyOwnership = $pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() > 0;
    $hasOwnerType = $hasCompanyOwnership && $pdo->query("SHOW COLUMNS FROM company_ownership LIKE 'owner_type'")->rowCount() > 0;
    $hasPartnerGroupId = $hasCompanyOwnership && $pdo->query("SHOW COLUMNS FROM company_ownership LIKE 'partner_group_id'")->rowCount() > 0;

    // 1. Check for Login ID (owner_code) match
    $partnerByLogin = null;
    if ($force_type === '' || $force_type === 'login') {
        $stmtLogin = $pdo->prepare("SELECT id, name, owner_code FROM owner WHERE UPPER(owner_code) = UPPER(?) AND status = 'active'");
        $stmtLogin->execute([$login_or_group_id]);
        $partnerByLogin = $stmtLogin->fetch(PDO::FETCH_ASSOC);
    }

    // 2. Check for Group ID match
    // Support both:
    // - native company.group_id
    // - externally linked partner_group_id saved in company_ownership
    $partnerByGroup = null;
    if ($force_type === '' || $force_type === 'group') {
        if ($hasCompanyOwnership && $hasOwnerType && $hasPartnerGroupId) {
            $stmtGrp = $pdo->prepare("
                SELECT o.id, o.name, grp.group_id
                FROM owner o
                JOIN (
                    SELECT c.owner_id, TRIM(c.group_id) COLLATE utf8mb4_unicode_ci AS group_id
                    FROM company c
                    WHERE c.group_id IS NOT NULL AND TRIM(c.group_id) <> ''
                    UNION
                    SELECT co.account_id AS owner_id, TRIM(co.partner_group_id) COLLATE utf8mb4_unicode_ci AS group_id
                    FROM company_ownership co
                    WHERE co.owner_type = 'owner'
                      AND co.partner_group_id IS NOT NULL
                      AND TRIM(co.partner_group_id) <> ''
                ) grp ON grp.owner_id = o.id
                WHERE UPPER(grp.group_id) = UPPER(TRIM(?) COLLATE utf8mb4_unicode_ci)
                  AND o.status = 'active'
                LIMIT 1
            ");
            $stmtGrp->execute([$login_or_group_id]);
        } else {
            $stmtGrp = $pdo->prepare("
                SELECT o.id, o.name, TRIM(c.group_id) AS group_id
                FROM company c
                JOIN owner o ON c.owner_id = o.id
                WHERE UPPER(TRIM(c.group_id)) = UPPER(TRIM(?))
                  AND o.status = 'active'
                LIMIT 1
            ");
            $stmtGrp->execute([$login_or_group_id]);
        }
        $partnerByGroup = $stmtGrp->fetch(PDO::FETCH_ASSOC);
    }

    $partner = null;
    $matched_by_group = null;

    if ($partnerByLogin && $partnerByGroup && (int)$partnerByLogin['id'] !== (int)$partnerByGroup['id']) {
        // Collision: Match found in both Login ID and Group ID. 
        // We prompt the user so they can decide whether to just share (Login) or formally join the group.
        echo json_encode([
            'status' => 'conflict', 
            'message' => 'Multiple matches found.',
            'data' => [
                'login_partner' => $partnerByLogin['name'] . ' (' . $partnerByLogin['owner_code'] . ')',
                'group_partner' => $partnerByGroup['name'] . ' (Group: ' . $partnerByGroup['group_id'] . ')'
            ]
        ]);
        exit();
    } elseif ($partnerByGroup) {
        $partner = $partnerByGroup;
        $matched_by_group = strtoupper($login_or_group_id);
    } elseif ($partnerByLogin) {
        $partner = $partnerByLogin;
    }

    if (!$partner) {
        echo json_encode(['status' => 'error', 'message' => 'Owner account or Group ID not found or inactive']);
        exit();
    }

    $partnerId = $partner['id'];

    $isSameOwnerGroupLink = ($matched_by_group !== null && (int)$nativeOwner === (int)$partnerId);

    // Login-ID path still cannot self-link.
    if (!$isSameOwnerGroupLink && (int)$nativeOwner === (int)$partnerId) {
        echo json_encode(['status' => 'error', 'message' => 'This account is already the main owner of the company']);
        exit();
    }

    // 2. Check if already linked
    if ($isSameOwnerGroupLink) {
        if ($hasCompanyOwnership && $hasOwnerType && $hasPartnerGroupId) {
            $stmtLink = $pdo->prepare("SELECT id FROM company_ownership WHERE company_id = ? AND owner_type = 'group' AND partner_group_id = ?");
            $stmtLink->execute([$company_id, $matched_by_group]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Current schema does not support same-owner group linking']);
            exit();
        }
    } elseif ($hasCompanyOwnership && $hasOwnerType) {
        $stmtLink = $pdo->prepare("SELECT id FROM company_ownership WHERE company_id = ? AND owner_type = 'owner' AND account_id = ?");
        $stmtLink->execute([$company_id, $partnerId]);
    } else {
        // Legacy table fallback (without owner_type)
        $stmtLink = $pdo->prepare("SELECT id FROM company_ownership WHERE company_id = ? AND account_id = ?");
        $stmtLink->execute([$company_id, $partnerId]);
    }
    if ($stmtLink->fetch()) {
        echo json_encode(['status' => 'error', 'message' => $isSameOwnerGroupLink ? 'Group is already linked to this company' : 'Partner is already linked to this company']);
        exit();
    }

    // 3. Link by inserting a 0% entry into company_ownership
    // If matched by Group ID, we set the partner_group_id so the partner sees it under this group,
    // while the original owner's dashboard remains completely unaffected.
    if ($isSameOwnerGroupLink) {
        $stmtInsert = $pdo->prepare("INSERT INTO company_ownership (company_id, owner_type, account_id, percentage, partner_group_id) VALUES (?, 'group', 0, 0, ?)");
        $stmtInsert->execute([$company_id, $matched_by_group]);
    } elseif ($hasCompanyOwnership && $hasOwnerType && $hasPartnerGroupId) {
        $stmtInsert = $pdo->prepare("INSERT INTO company_ownership (company_id, owner_type, account_id, percentage, partner_group_id) VALUES (?, 'owner', ?, 0, ?)");
        $stmtInsert->execute([$company_id, $partnerId, $matched_by_group]);
    } elseif ($hasCompanyOwnership && $hasOwnerType) {
        $stmtInsert = $pdo->prepare("INSERT INTO company_ownership (company_id, owner_type, account_id, percentage) VALUES (?, 'owner', ?, 0)");
        $stmtInsert->execute([$company_id, $partnerId]);
    } elseif ($hasCompanyOwnership && $hasPartnerGroupId) {
        $stmtInsert = $pdo->prepare("INSERT INTO company_ownership (company_id, account_id, percentage, partner_group_id) VALUES (?, ?, 0, ?)");
        $stmtInsert->execute([$company_id, $partnerId, $matched_by_group]);
    } else {
        // Legacy table fallback (minimum columns only)
        $stmtInsert = $pdo->prepare("INSERT INTO company_ownership (company_id, account_id, percentage) VALUES (?, ?, 0)");
        $stmtInsert->execute([$company_id, $partnerId]);
    }

    $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    ownership_history_snapshot_company_from_live_safe($pdo, (int) $company_id, $savedBy);

    echo json_encode([
        'status' => 'success',
        'message' => $isSameOwnerGroupLink
            ? "Group '{$matched_by_group}' linked successfully"
            : "Partner '{$partner['name']}' linked successfully"
    ]);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
