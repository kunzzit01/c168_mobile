<?php
/**
 * Group Earnings API — Add External Partner to a group
 * POST body: { "group_id": "AP", "login_id": "JK123", "force_type": "" }
 *
 * Supports three flavors:
 *   1. Login ID match → external owner partner (owner_type='owner', account_id=partnerId).
 *   2. Group ID match to a DIFFERENT owner → external group partner (same as above + partner_group_id).
 *   3. Group ID match to SAME owner (self-link) → pools another of this owner's groups
 *      into this group (owner_type='group', account_id=0, partner_group_id=matched_group).
 */
session_start();
session_write_close();
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
$group_id        = trim($data['group_id'] ?? '');
$login_or_group_id = trim($data['login_id'] ?? '');
$force_type      = trim($data['force_type'] ?? '');

if (!$group_id || !$login_or_group_id) {
    echo json_encode(['status' => 'error', 'message' => 'Group ID and Login ID/Group ID are required']);
    exit();
}

// Auto-create / migrate schema
try {
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
} catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE group_ownership MODIFY COLUMN owner_type ENUM('owner','user','group') NOT NULL DEFAULT 'owner'"); } catch (Exception $e) {}
// The original UNIQUE KEY (group_id, account_id, owner_type) blocks multiple group-type
// rows (they all share account_id=0). Drop it; app-level dedupe now enforces uniqueness.
try { $pdo->exec("ALTER TABLE group_ownership DROP INDEX uq_group_account"); } catch (Exception $e) {}

try {
    // Resolve the EFFECTIVE owner id for this group:
    //   - owner-role session → use their own owner id from session
    //   - admin/other session → derive from the target group's native company.owner_id
    //     (admin's $_SESSION['user_id'] points at user table, not owner table)
    $sessionRole = strtolower($_SESSION['role'] ?? '');
    if ($sessionRole === 'owner') {
        $currentOwnerId = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);
    } else {
        $stmtOwn = $pdo->prepare("SELECT DISTINCT owner_id FROM company WHERE UPPER(TRIM(group_id)) = UPPER(TRIM(?)) LIMIT 1");
        $stmtOwn->execute([$group_id]);
        $currentOwnerId = (int) $stmtOwn->fetchColumn();
    }
    if ($currentOwnerId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot determine the owner of this group']);
        exit();
    }
    $hasCompanyOwnership = $pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() > 0;

    // 1. Check for Login ID (owner_code) match — login-id path still requires a different owner
    $partnerByLogin = null;
    if ($force_type === '' || $force_type === 'login') {
        $stmtLogin = $pdo->prepare("SELECT id, name, owner_code FROM owner WHERE UPPER(owner_code) = UPPER(?) AND id != ? AND status = 'active'");
        $stmtLogin->execute([$login_or_group_id, $currentOwnerId]);
        $partnerByLogin = $stmtLogin->fetch(PDO::FETCH_ASSOC);
    }

    // 2. Check for Group ID match — allow SAME-owner match (self group-to-group link)
    $partnerByGroup = null;
    if ($force_type === '' || $force_type === 'group') {
        if ($hasCompanyOwnership) {
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
                ORDER BY (o.id = ?) DESC
                LIMIT 1
            ");
            $stmtGrp->execute([$login_or_group_id, $currentOwnerId]);
        } else {
            $stmtGrp = $pdo->prepare("
                SELECT o.id, o.name, TRIM(c.group_id) AS group_id
                FROM company c
                JOIN owner o ON c.owner_id = o.id
                WHERE UPPER(TRIM(c.group_id)) = UPPER(TRIM(?))
                  AND o.status = 'active'
                ORDER BY (o.id = ?) DESC
                LIMIT 1
            ");
            $stmtGrp->execute([$login_or_group_id, $currentOwnerId]);
        }
        $partnerByGroup = $stmtGrp->fetch(PDO::FETCH_ASSOC);
    }

    $partner = null;
    $matched_by_group = null;

    if ($partnerByLogin && $partnerByGroup && (int)$partnerByLogin['id'] !== (int)$partnerByGroup['id']) {
        echo json_encode([
            'status'  => 'conflict',
            'message' => 'Multiple matches found.',
            'data'    => [
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

    $partnerId = (int) $partner['id'];

    // A self-group link requires (a) group-id match AND (b) partner == current owner
    $isSameOwnerGroupLink = ($matched_by_group !== null && $partnerId === $currentOwnerId);

    // Login-ID path still cannot self-link (already filtered at query level, but belt-and-suspenders)
    if (!$isSameOwnerGroupLink && $partnerId === $currentOwnerId) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot link yourself as an external partner']);
        exit();
    }

    // Prevent linking the same group to itself (e.g. IG + partner_group 'IG')
    if ($isSameOwnerGroupLink && strcasecmp(trim($group_id), trim($matched_by_group)) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Cannot link a group to itself']);
        exit();
    }

    // Already-linked check
    if ($isSameOwnerGroupLink) {
        $stmtLink = $pdo->prepare("
            SELECT id FROM group_ownership
            WHERE group_id = ?
              AND owner_type = 'group'
              AND UPPER(TRIM(partner_group_id)) = UPPER(TRIM(?))
            LIMIT 1
        ");
        $stmtLink->execute([$group_id, $matched_by_group]);
    } else {
        $stmtLink = $pdo->prepare("
            SELECT id FROM group_ownership
            WHERE group_id = ?
              AND owner_type = 'owner'
              AND account_id = ?
            LIMIT 1
        ");
        $stmtLink->execute([$group_id, $partnerId]);
    }
    if ($stmtLink->fetch()) {
        echo json_encode([
            'status'  => 'error',
            'message' => $isSameOwnerGroupLink
                ? 'Group is already linked to this group'
                : 'Partner is already linked to this group'
        ]);
        exit();
    }

    // Insert 0% entry
    if ($isSameOwnerGroupLink) {
        $stmtInsert = $pdo->prepare("
            INSERT INTO group_ownership (group_id, owner_id, account_id, owner_type, percentage, partner_group_id)
            VALUES (?, ?, 0, 'group', 0, ?)
        ");
        $stmtInsert->execute([$group_id, $currentOwnerId, $matched_by_group]);
    } else {
        $stmtInsert = $pdo->prepare("
            INSERT INTO group_ownership (group_id, owner_id, account_id, owner_type, percentage, partner_group_id)
            VALUES (?, ?, ?, 'owner', 0, ?)
        ");
        $stmtInsert->execute([$group_id, $currentOwnerId, $partnerId, $matched_by_group]);
    }

    $savedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    ownership_history_snapshot_group_from_live_safe($pdo, $group_id, $savedBy);

    echo json_encode([
        'status'  => 'success',
        'message' => $isSameOwnerGroupLink
            ? "Group '{$matched_by_group}' linked to group '{$group_id}' successfully"
            : "Partner '{$partner['name']}' linked to group '{$group_id}' successfully"
    ]);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
