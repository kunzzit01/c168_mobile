<?php
/**
 * Toggle User Status API
 * 路径: api/users/toggle_status_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/group_scope_resolve.php';
require_once __DIR__ . '/../get_companies_helper.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

function toggle_normalize_group_id(?string $groupId): ?string
{
    $g = strtoupper(trim((string) $groupId));

    return $g !== '' ? $g : null;
}

/** @return array<int, array<string, mixed>> */
function toggle_fetch_accessible_companies(PDO $pdo): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }

    gc_hydrate_company_login_group_id($pdo);

    $userRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    $userType = strtolower(trim((string) ($_SESSION['user_type'] ?? '')));
    if ($userRole === 'owner' || $userType === 'owner') {
        $ownerId = (int) ($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);
        $rows = getCompaniesByOwner($pdo, $ownerId, true, true);
    } else {
        $rows = getCompaniesByUser($pdo, $userId, true, true);
    }

    $active = [];
    foreach ($rows as $c) {
        if (!empty($c['expiration_date']) && strtotime((string) $c['expiration_date']) < strtotime(date('Y-m-d'))) {
            continue;
        }
        $active[] = $c;
    }

    gc_hydrate_accessible_group_ids($pdo, $active);

    return gc_filter_companies_for_login_scope($active);
}

/** @return list<int> */
function toggle_company_ids_for_group(array $accessibleCompanies, string $groupId): array
{
    $g = toggle_normalize_group_id($groupId);
    if ($g === null) {
        return [];
    }
    $out = [];
    foreach ($accessibleCompanies as $c) {
        $linkSrc = strtoupper(trim((string) ($c['link_source_group'] ?? '')));
        if ($linkSrc !== '') {
            continue;
        }
        $code = strtoupper(trim((string) ($c['company_id'] ?? '')));
        $gid = strtoupper(trim((string) ($c['group_id'] ?? '')));
        $isGroupEntity = $code === $g || ($code === '' && $gid === $g);
        if (!$isGroupEntity) {
            continue;
        }
        $id = (int) ($c['id'] ?? 0);
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return array_values(array_unique($out));
}

/** @return list<int> */
function toggle_group_entity_company_ids(PDO $pdo, string $groupId): array
{
    $g = toggle_normalize_group_id($groupId);
    if ($g === null) {
        return [];
    }

    $ids = [];

    $stmt = $pdo->prepare("
        SELECT id
        FROM company
        WHERE UPPER(TRIM(company_id)) = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$g]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $nid = (int) $id;
        if ($nid > 0) {
            $ids[] = $nid;
        }
    }

    if ($ids === []) {
        $placeholderStmt = $pdo->prepare("
            SELECT id
            FROM company
            WHERE TRIM(COALESCE(company_id, '')) = ''
              AND UPPER(TRIM(group_id)) = ?
            ORDER BY id ASC
        ");
        $placeholderStmt->execute([$g]);
        foreach ($placeholderStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $nid = (int) $id;
            if ($nid > 0) {
                $ids[] = $nid;
            }
        }
    }

    $allowed = [];
    foreach (array_values(array_unique($ids)) as $cid) {
        if (gc_session_can_access_company_id($pdo, (int) $cid, $g)) {
            $allowed[] = (int) $cid;
        }
    }

    return $allowed;
}

/** @return list<int> */
function toggle_resolve_scope_company_ids(PDO $pdo, ?int $postedCompanyId, ?string $groupId): array
{
    $sessionCompanyId = (int) ($_SESSION['company_id'] ?? 0);
    $primary = ($postedCompanyId !== null && $postedCompanyId > 0) ? $postedCompanyId : $sessionCompanyId;
    $accessible = toggle_fetch_accessible_companies($pdo);
    $allowed = gc_resolve_allowed_company_numeric_ids($pdo, $accessible);

    $groupNorm = toggle_normalize_group_id($groupId);
    if ($groupNorm !== null) {
        $groupIds = toggle_group_entity_company_ids($pdo, $groupNorm);
        if ($groupIds === []) {
            $groupIds = toggle_company_ids_for_group($accessible, $groupNorm);
        }
        if ($groupIds !== []) {
            return $groupIds;
        }
    }

    if ($primary > 0) {
        if ($allowed !== [] && !in_array($primary, $allowed, true)) {
            return [];
        }
        return [$primary];
    }

    return $allowed;
}

function getUserStatus(PDO $pdo, int $userId, int $companyId): ?array {
    $stmt = $pdo->prepare("
        SELECT u.status FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE u.id = ? AND ucm.company_id = ? LIMIT 1
    ");
    $stmt->execute([$userId, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getOwnerStatus(PDO $pdo, int $ownerId, int $companyId): ?array {
    $stmt = $pdo->prepare("
        SELECT o.status FROM owner o
        INNER JOIN company c ON c.owner_id = o.id
        WHERE o.id = ? AND c.id = ?
    ");
    $stmt->execute([$ownerId, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getUserRoleInCompany(PDO $pdo, int $userId, int $companyId): string {
    $stmt = $pdo->prepare("
        SELECT u.role
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE u.id = ? AND ucm.company_id = ? LIMIT 1
    ");
    $stmt->execute([$userId, $companyId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    return strtolower(trim((string) ($target['role'] ?? '')));
}

/**
 * @param list<int> $scopeCompanyIds
 * @return array{current: array<string, mixed>, isOwnerShadow: bool, targetRole: string}|null
 */
function toggle_assert_group_id_allowed(PDO $pdo, string $groupId): bool
{
    $g = toggle_normalize_group_id($groupId);
    if ($g === null) {
        return false;
    }

    toggle_fetch_accessible_companies($pdo);

    if (gc_session_can_access_group_code($pdo, $g)) {
        return true;
    }
    if (function_exists('gc_session_can_access_group_ledger') && gc_session_can_access_group_ledger($pdo, $g)) {
        return true;
    }
    if (function_exists('gc_session_assigned_group_codes') && in_array($g, gc_session_assigned_group_codes(), true)) {
        return true;
    }
    if (gc_is_group_login()) {
        $accessible = gc_session_accessible_group_ids();
        if ($accessible === [] || in_array($g, $accessible, true)) {
            $ident = gc_session_login_identifier();
            return $accessible !== [] || $ident === null || $ident === $g;
        }
        return false;
    }

    $role = isset($_SESSION['role']) ? strtolower(trim((string) $_SESSION['role'])) : '';
    if ($role === 'owner') {
        $accessible = gc_session_accessible_group_ids();
        return $accessible === [] || in_array($g, $accessible, true);
    }

    return false;
}

function toggle_table_exists(PDO $pdo, string $table): bool
{
    try {
        return $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function toggle_ucm_has_scope_columns(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $cache = $pdo->query("SHOW COLUMNS FROM user_company_map LIKE 'scope_type'")->rowCount() > 0;
    } catch (Throwable $e) {
        $cache = false;
    }

    return $cache;
}

/** @return list<int> */
function toggle_fetch_group_only_user_ids(PDO $pdo, string $groupScope): array
{
    $g = toggle_normalize_group_id($groupScope);
    if ($g === null) {
        return [];
    }

    $groupPk = gc_resolve_group_pk_by_code($pdo, $g);
    $ids = [];

    if (toggle_table_exists($pdo, 'user_group_map')) {
        if ($groupPk > 0) {
            $stmt = $pdo->prepare('SELECT user_id FROM user_group_map WHERE group_id = ?');
            $stmt->execute([$groupPk]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $ids[(int) $uid] = true;
            }
        }
        if (gc_has_groups_table($pdo)) {
            try {
                $stmt = $pdo->prepare('
                    SELECT ugm.user_id
                    FROM user_group_map ugm
                    INNER JOIN `groups` grp ON grp.id = ugm.group_id
                    WHERE UPPER(TRIM(grp.group_code)) = ?
                ');
                $stmt->execute([$g]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $ids[(int) $uid] = true;
                }
            } catch (Throwable $e) {
                // fall through
            }
        }
    }

    if ($groupPk > 0 && toggle_ucm_has_scope_columns($pdo)) {
        $stmt = $pdo->prepare("
            SELECT user_id FROM user_company_map
            WHERE scope_type = 'group' AND scope_id = ?
        ");
        $stmt->execute([$groupPk]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $ids[(int) $uid] = true;
        }
    }

    return array_values(array_filter(array_map('intval', array_keys($ids)), static fn (int $id): bool => $id > 0));
}

/**
 * Group-only ledger users are bound via user_group_map, not subsidiary user_company_map.
 *
 * @return array{current: array<string, mixed>, isOwnerShadow: bool, targetRole: string}|null
 */
function toggle_find_group_only_target(PDO $pdo, int $targetId, string $groupScope): ?array
{
    if (!toggle_assert_group_id_allowed($pdo, $groupScope)) {
        return null;
    }

    $allowed = toggle_fetch_group_only_user_ids($pdo, $groupScope);
    if (!in_array($targetId, $allowed, true)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT status, role FROM user WHERE id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'current' => ['status' => $row['status']],
        'isOwnerShadow' => false,
        'targetRole' => strtolower(trim((string) ($row['role'] ?? ''))),
    ];
}

function toggle_find_target(PDO $pdo, int $targetId, array $scopeCompanyIds): ?array
{
    foreach ($scopeCompanyIds as $companyId) {
        $companyId = (int) $companyId;
        if ($companyId <= 0) {
            continue;
        }
        $current = getUserStatus($pdo, $targetId, $companyId);
        if ($current) {
            return [
                'current' => $current,
                'isOwnerShadow' => false,
                'targetRole' => getUserRoleInCompany($pdo, $targetId, $companyId),
            ];
        }
    }

    foreach ($scopeCompanyIds as $companyId) {
        $companyId = (int) $companyId;
        if ($companyId <= 0) {
            continue;
        }
        $current = getOwnerStatus($pdo, $targetId, $companyId);
        if ($current) {
            return [
                'current' => $current,
                'isOwnerShadow' => true,
                'targetRole' => 'owner',
            ];
        }
    }

    return null;
}

function toggleRoleLevel(string $role): int
{
    $hierarchy = [
        'owner' => 0,
        'partnership' => 1,
        'admin' => 2,
        'manager' => 3,
        'supervisor' => 4,
        'accountant' => 5,
        'audit' => 6,
        'customer service' => 7,
    ];

    return $hierarchy[strtolower(trim($role))] ?? 999;
}

function updateUserStatus(PDO $pdo, string $newStatus, int $userId): void {
    $stmt = $pdo->prepare("UPDATE user SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $userId]);
    if ($stmt->rowCount() == 0) throw new Exception('状态更新失败');
}

function updateOwnerStatus(PDO $pdo, string $newStatus, int $ownerId): void {
    $stmt = $pdo->prepare("UPDATE owner SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $ownerId]);
    if ($stmt->rowCount() == 0) throw new Exception('状态更新失败');
}

try {
    if (!isset($_SESSION['company_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }
    $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $currentUserRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        api_error('无效的用户ID', 400);
        exit;
    }

    if (is_partnership_audit_read_only_active($pdo)) {
        api_error('只读账号无法执行此操作', 403);
        exit;
    }

    $postedCompanyId = (int) ($_POST['company_id'] ?? 0);
    $groupId = toggle_normalize_group_id($_POST['group_id'] ?? null);
    $groupOnlyRequest = $groupId !== null && (
        !empty($_POST['group_only']) || !empty($_POST['group_aggregate'])
    );

    $target = null;
    if ($groupOnlyRequest) {
        $target = toggle_find_group_only_target($pdo, $id, $groupId);
    }

    if ($target === null) {
        $scopeCompanyIds = toggle_resolve_scope_company_ids(
            $pdo,
            $postedCompanyId > 0 ? $postedCompanyId : null,
            $groupId
        );
        if ($scopeCompanyIds === []) {
            api_error('无权限操作此用户', 403);
            exit;
        }
        $target = toggle_find_target($pdo, $id, $scopeCompanyIds);
    }

    if ($target === null) {
        api_error('无权限操作此用户', 403);
        exit;
    }

    $current = $target['current'];
    $isOwnerShadow = $target['isOwnerShadow'];
    $targetRole = $target['targetRole'];

    if ($currentUserId > 0 && $currentUserId === $id) {
        api_error('You cannot toggle your own status', 403);
        exit;
    }

    if ($isOwnerShadow && $currentUserRole !== 'owner') {
        api_error('Only owner can toggle owner records', 403);
        exit;
    }

    if (!$isOwnerShadow) {
        $currentUserLevel = toggleRoleLevel($currentUserRole);
        $targetUserLevel = toggleRoleLevel($targetRole);

        if ($currentUserLevel === $targetUserLevel) {
            api_error('You cannot toggle status of accounts with the same role level', 403);
            exit;
        }

        if ($targetUserLevel < $currentUserLevel) {
            api_error('You cannot toggle status of accounts with higher role level', 403);
            exit;
        }
    }

    if ($isOwnerShadow) {
        $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
        updateOwnerStatus($pdo, $newStatus, $id);
    } else {
        $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
        updateUserStatus($pdo, $newStatus, $id);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => '状态更新成功', 'data' => ['newStatus' => $newStatus], 'newStatus' => $newStatus], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}
