<?php
/**
 * Group vs Company login scope — shared server-side access rules.
 *
 * Session keys (set on login via login_scope.php):
 *   login_scope      — "group" | "company"
 *   login_identifier — uppercased group id or company code from the login form
 *   login_group_id   — native group_id of the login company (UI default; not a visibility fence)
 *
 * Group login: group-only mode; companies in login group + linked groups (e.g. AP+IG).
 * Company login: all companies/groups the owner already has (AP+IG when linked);
 *                 no group-only aggregate; may switch companies across linked groups.
 */

declare(strict_types=1);

require_once __DIR__ . '/group_scope_resolve.php';

const GC_LOGIN_SCOPE_GROUP = 'group';
const GC_LOGIN_SCOPE_COMPANY = 'company';

function gc_normalize_scope(?string $scope): ?string
{
    $s = strtolower(trim((string) $scope));
    if ($s === GC_LOGIN_SCOPE_GROUP || $s === GC_LOGIN_SCOPE_COMPANY) {
        return $s;
    }
    return null;
}

function gc_session_login_scope(): ?string
{
    return gc_normalize_scope($_SESSION['login_scope'] ?? null);
}

function gc_session_login_identifier(): ?string
{
    $id = trim((string) ($_SESSION['login_identifier'] ?? ''));
    return $id !== '' ? strtoupper($id) : null;
}

/** Cache native group_id for company login (default group filter on boot). */
function gc_hydrate_company_login_group_id(PDO $pdo): void
{
    if (!gc_is_company_login() || array_key_exists('login_group_id', $_SESSION)) {
        return;
    }

    $ident = gc_session_login_identifier();
    if ($ident === null) {
        $_SESSION['login_group_id'] = '';
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT UPPER(TRIM(group_id)) AS group_id FROM company WHERE UPPER(company_id) = ? LIMIT 1'
    );
    $stmt->execute([$ident]);
    $gid = $stmt->fetchColumn();
    $_SESSION['login_group_id'] = ($gid !== false && $gid !== null && trim((string) $gid) !== '')
        ? strtoupper(trim((string) $gid))
        : '';
}

/** Native group of the login company (default tab); null if none. */
function gc_session_login_group_id(): ?string
{
    if (!gc_is_company_login() || !array_key_exists('login_group_id', $_SESSION)) {
        return null;
    }
    $g = strtoupper(trim((string) $_SESSION['login_group_id']));
    return $g !== '' ? $g : null;
}

function gc_is_group_login(): bool
{
    return gc_session_login_scope() === GC_LOGIN_SCOPE_GROUP;
}

function gc_is_company_login(): bool
{
    return gc_session_login_scope() === GC_LOGIN_SCOPE_COMPANY;
}

/**
 * @param array<string, mixed> $companyRow expects company_id, group_id keys
 */
function gc_company_row_matches_login_scope(array $companyRow): bool
{
    $scope = gc_session_login_scope();
    $ident = gc_session_login_identifier();
    if ($scope === null || $ident === null) {
        return true;
    }

    if ($scope === GC_LOGIN_SCOPE_COMPANY) {
        // Owner/access list from get_companies_helper already scopes rows (incl. AP↔IG links).
        return true;
    }

    $linkSrc = strtoupper(trim((string) ($companyRow['link_source_group'] ?? '')));
    if ($linkSrc === '') {
        $nativeGid = strtoupper(trim((string) ($companyRow['native_group_id'] ?? $companyRow['group_id'] ?? '')));
        if ($nativeGid === '') {
            return true;
        }
    }

    $gid = strtoupper(trim((string) ($companyRow['group_id'] ?? '')));

    $accessible = gc_session_accessible_group_ids();
    if (!empty($accessible)) {
        if ($gid !== '' && in_array($gid, $accessible, true)) {
            return true;
        }
        if ($linkSrc !== '' && in_array($linkSrc, $accessible, true)) {
            return true;
        }

        return false;
    }

    return $ident !== null && ($gid === $ident || $linkSrc === $ident);
}

/**
 * Filter company list for login scope. Company login: no extra filter.
 *
 * @param array<int, array<string, mixed>> $companies
 * @return array<int, array<string, mixed>>
 */
function gc_filter_companies_for_login_scope(array $companies): array
{
    if (gc_is_company_login() || gc_session_login_scope() === null) {
        return $companies;
    }

    return array_values(array_filter($companies, 'gc_company_row_matches_login_scope'));
}

/**
 * Normalize optional dashboard view_group (GroupID pill).
 */
function gc_normalize_view_group(?string $viewGroup): ?string
{
    $g = strtoupper(trim((string) $viewGroup));
    return $g !== '' ? $g : null;
}

/**
 * Whether a company is pooled into $targetGroup via group_ownership / company_ownership
 * (same rules as get_companies_helper virtual rows / companiesInGroupList).
 */
function gc_company_linked_to_target_group(PDO $pdo, int $companyId, string $sourceGroup, string $targetGroup): bool
{
    $src = strtoupper(trim($sourceGroup));
    $tgt = strtoupper(trim($targetGroup));
    if ($companyId <= 0 || $src === '' || $tgt === '') {
        return false;
    }
    if ($src === $tgt) {
        return true;
    }

    $ownerId = 0;
    try {
        $stmt = $pdo->prepare('SELECT owner_id FROM company WHERE id = ? LIMIT 1');
        $stmt->execute([$companyId]);
        $ownerId = (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return false;
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0 && $ownerId > 0) {
            $goStmt = $pdo->prepare("
                SELECT 1 FROM group_ownership
                WHERE percentage > 0
                  AND UPPER(TRIM(partner_group_id)) = ?
                  AND UPPER(TRIM(group_id)) = ?
                  AND (
                    (owner_type = 'group' AND owner_id = ?)
                    OR (owner_type = 'owner' AND account_id = ?)
                  )
                LIMIT 1
            ");
            $goStmt->execute([$tgt, $src, $ownerId, $ownerId]);
            if ($goStmt->fetchColumn()) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // continue
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() > 0) {
            $coStmt = $pdo->prepare("
                SELECT 1 FROM company_ownership
                WHERE company_id = ?
                  AND owner_type = 'group'
                  AND percentage > 0
                  AND UPPER(TRIM(partner_group_id)) = ?
                LIMIT 1
            ");
            $coStmt->execute([$companyId, $tgt]);
            if ($coStmt->fetchColumn()) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // continue
    }

    return false;
}

/**
 * Whether the numeric company id is visible under the current login scope.
 * Group login: native group, group_company_map, ownership link, or view_group pill (IG tab).
 * Company login: caller should still validate owner/user_company_map; this returns true.
 *
 * @param string|null $viewGroup Dashboard GroupID filter (e.g. IG); aligns with companiesInGroupList.
 */
function gc_session_can_access_company_id(PDO $pdo, int $companyId, ?string $viewGroup = null): bool
{
    if ($companyId <= 0) {
        return false;
    }

    if (!gc_is_group_login()) {
        return true;
    }

    $stmt = $pdo->prepare(
        'SELECT id, UPPER(TRIM(group_id)) AS group_id FROM company WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $nativeGid = strtoupper(trim((string) ($row['group_id'] ?? '')));
    $viewGroupNorm = gc_normalize_view_group($viewGroup);

    $groupCodes = gc_session_accessible_group_ids();
    $ident = gc_session_login_identifier();
    if ($ident !== null) {
        $groupCodes[] = $ident;
    }
    if ($viewGroupNorm !== null) {
        $groupCodes[] = $viewGroupNorm;
    }
    $groupCodes = array_values(array_unique(array_filter($groupCodes)));

    try {
        if ($pdo->query("SHOW TABLES LIKE 'group_company_map'")->rowCount() > 0 && !empty($groupCodes)) {
            $placeholders = implode(',', array_fill(0, count($groupCodes), '?'));
            $mapStmt = $pdo->prepare("
                SELECT 1
                FROM group_company_map gcm
                INNER JOIN `groups` g ON g.id = gcm.group_id
                WHERE gcm.company_id = ?
                  AND UPPER(TRIM(g.group_code)) IN ($placeholders)
                LIMIT 1
            ");
            $mapStmt->execute(array_merge([$companyId], $groupCodes));
            if ($mapStmt->fetchColumn()) {
                return true;
            }
        }
    } catch (Throwable $e) {
        // fall through
    }

    foreach ($groupCodes as $g) {
        if ($nativeGid !== '' && $nativeGid === $g) {
            return true;
        }
        if ($nativeGid !== '' && gc_company_linked_to_target_group($pdo, $companyId, $nativeGid, $g)) {
            return true;
        }
    }

    if ($viewGroupNorm !== null) {
        if ($nativeGid === $viewGroupNorm) {
            return true;
        }
        if ($nativeGid !== '' && gc_company_linked_to_target_group($pdo, $companyId, $nativeGid, $viewGroupNorm)) {
            return true;
        }
    }

    $linkSrc = '';
    if ($viewGroupNorm !== null && $nativeGid !== '' && $nativeGid !== $viewGroupNorm
        && gc_company_linked_to_target_group($pdo, $companyId, $nativeGid, $viewGroupNorm)) {
        $linkSrc = $nativeGid;
    }

    return gc_company_row_matches_login_scope([
        'group_id' => $nativeGid,
        'link_source_group' => $linkSrc,
    ]);
}

/**
 * Company login: access is enforced by getUserCompanies / owner map (incl. linked groups).
 * Group login: company must pass gc_session_can_access_company_id.
 */
function gc_assert_company_id_allowed_for_login_scope(PDO $pdo, int $numericCompanyId, ?string $viewGroup = null): void
{
    if (!gc_is_group_login()) {
        return;
    }
    if (!gc_session_can_access_company_id($pdo, $numericCompanyId, $viewGroup)) {
        throw new RuntimeException('No permission to access this company');
    }
}

/**
 * Dashboard group tab + subsidiary company pill (e.g. IG + 95): user may drill down via view_group
 * without a direct user_company_map row on the subsidiary.
 */
function gc_session_can_access_subsidiary_under_view_group(PDO $pdo, int $companyId, ?string $viewGroup): bool
{
    if ($companyId <= 0) {
        return false;
    }

    $g = gc_normalize_group_code($viewGroup ?? '');
    if ($g === '') {
        return false;
    }

    if (gc_is_group_login()) {
        return gc_session_can_access_company_id($pdo, $companyId, $g);
    }

    if (!gc_session_can_access_group_ledger($pdo, $g)) {
        return false;
    }

    foreach (gc_company_numeric_ids_for_group_code($pdo, $g) as $cid) {
        if ((int) $cid === $companyId) {
            return true;
        }
    }

    return false;
}

/**
 * Filter company rows for list/switch APIs (same rules as get_owner_companies_api).
 *
 * @param array<int, array<string, mixed>> $companies
 * @return array<int, array<string, mixed>>
 */
function gc_apply_login_scope_company_filter(PDO $pdo, array $companies): array
{
    gc_hydrate_accessible_group_ids($pdo, $companies);

    return gc_filter_companies_for_login_scope($companies);
}

/**
 * Exclude legacy empty company_id placeholder rows from UI lists.
 *
 * @param array<int, array<string, mixed>> $companies
 * @return array<int, array<string, mixed>>
 */
function gc_filter_real_company_rows(array $companies): array
{
    return array_values(array_filter($companies, static function ($c) {
        $code = strtoupper(trim((string) ($c['company_id'] ?? '')));

        return $code !== '';
    }));
}

/**
 * Standard write/read API guard for a numeric company id (group login fence + owner/user map).
 */
function gc_assert_api_company_access(PDO $pdo, int $companyId, ?string $viewGroup = null): void
{
    if ($companyId <= 0) {
        throw new RuntimeException('无效的 company_id');
    }

    if (gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $companyId, $viewGroup);

        return;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('用户未登录');
    }

    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    $userType = strtolower((string) ($_SESSION['user_type'] ?? ''));

    if ($role === 'owner' || $userType === 'owner') {
        $ownerId = (int) ($_SESSION['owner_id'] ?? $userId);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?');
        $stmt->execute([$companyId, $ownerId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        throw new RuntimeException('无权限访问该公司');
    }

    if ($userType === 'member') {
        $memberId = $userId;
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM account_company ac
            WHERE ac.account_id = ? AND ac.company_id = ?
        ');
        $stmt->execute([$memberId, $companyId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
    }

    $ucm = $pdo->prepare('SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?');
    $ucm->execute([$userId, $companyId]);
    if ((int) $ucm->fetchColumn() > 0) {
        return;
    }

    throw new RuntimeException('无权限访问该公司');
}

/** Block company-login callers from group-only APIs unless owner or user_group_map assignment. */
function gc_assert_group_only_operation_allowed(): void
{
    if (gc_is_group_login()) {
        return;
    }
    if (gc_session_company_login_has_group_ledger_privilege()) {
        return;
    }
    if (gc_session_assigned_group_codes() !== []) {
        return;
    }
    throw new RuntimeException('Group-only operation is not allowed for company login');
}

/** Company login: owner may use group ledger without user_group_map. */
function gc_session_company_login_has_group_ledger_privilege(): bool
{
    if (!gc_is_company_login()) {
        return false;
    }
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    $userType = strtolower(trim((string) ($_SESSION['user_type'] ?? '')));

    return $role === 'owner' || $userType === 'owner';
}

/** Numeric company ids allowed for aggregation under current scope. */
function gc_resolve_allowed_company_numeric_ids(PDO $pdo, array $accessibleCompanies): array
{
    $ids = [];
    foreach ($accessibleCompanies as $c) {
        if (!gc_company_row_matches_login_scope($c)) {
            continue;
        }
        $id = isset($c['id']) ? (int) $c['id'] : 0;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Group ids for filter pills when AP↔IG (etc.) are linked via group_ownership.
 *
 * @return list<string>
 */
function gc_session_accessible_group_ids(): array
{
    if (!isset($_SESSION['accessible_group_ids']) || !is_array($_SESSION['accessible_group_ids'])) {
        return [];
    }
    $out = [];
    foreach ($_SESSION['accessible_group_ids'] as $g) {
        $g = strtoupper(trim((string) $g));
        if ($g !== '') {
            $out[] = $g;
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

/**
 * @param array<int, array<string, mixed>> $companies
 */
/**
 * Group codes explicitly assigned via Admin (user_group_map).
 *
 * @return list<string>
 */
function gc_fetch_user_assigned_group_codes(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $codes = [];

    try {
        if ($pdo->query("SHOW TABLES LIKE 'user_group_map'")->rowCount() > 0 && gc_has_groups_table($pdo)) {
            $stmt = $pdo->prepare('
                SELECT UPPER(TRIM(g.group_code)) AS group_code
                FROM user_group_map ugm
                INNER JOIN `groups` g ON g.id = ugm.group_id
                WHERE ugm.user_id = ?
            ');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
                $c = gc_normalize_group_code((string) $raw);
                if ($c !== '') {
                    $codes[$c] = true;
                }
            }
        }
    } catch (Throwable $e) {
        // fall through
    }

    try {
        if ($pdo->query("SHOW COLUMNS FROM user_company_map LIKE 'scope_type'")->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT UPPER(TRIM(g.group_code)) AS group_code
                FROM user_company_map ucm
                INNER JOIN `groups` g ON g.id = ucm.scope_id
                WHERE ucm.user_id = ?
                  AND ucm.scope_type = 'group'
                  AND ucm.scope_id > 0
            ");
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
                $c = gc_normalize_group_code((string) $raw);
                if ($c !== '') {
                    $codes[$c] = true;
                }
            }
        }
    } catch (Throwable $e) {
        // fall through
    }

    $out = array_keys($codes);
    sort($out);

    return $out;
}

function gc_hydrate_session_assigned_group_codes(PDO $pdo): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $_SESSION['assigned_group_codes'] = $userId > 0
        ? gc_fetch_user_assigned_group_codes($pdo, $userId)
        : [];
}

/**
 * @return list<string>
 */
function gc_session_assigned_group_codes(): array
{
    if (!isset($_SESSION['assigned_group_codes']) || !is_array($_SESSION['assigned_group_codes'])) {
        return [];
    }
    $out = [];
    foreach ($_SESSION['assigned_group_codes'] as $g) {
        $g = gc_normalize_group_code((string) $g);
        if ($g !== '') {
            $out[] = $g;
        }
    }
    sort($out);

    return array_values(array_unique($out));
}

function gc_user_assigned_to_group_code(PDO $pdo, int $userId, string $groupCode): bool
{
    $g = gc_normalize_group_code($groupCode);
    if ($g === '' || $userId <= 0) {
        return false;
    }

    return in_array($g, gc_fetch_user_assigned_group_codes($pdo, $userId), true);
}

/**
 * Group ledger (group_only APIs): group login, owner, or user_group_map assignment.
 */
function gc_session_can_access_group_ledger(PDO $pdo, string $groupCode): bool
{
    $g = gc_normalize_group_code($groupCode);
    if ($g === '') {
        return false;
    }

    if (gc_is_group_login()) {
        $ident = gc_session_login_identifier();
        if ($ident !== null && $ident === $g) {
            return true;
        }

        if (in_array($g, gc_session_accessible_group_ids(), true)) {
            return true;
        }

        // Linked group tab (e.g. AP login → IG): allow ledger when user can access subsidiaries under G.
        foreach (gc_company_numeric_ids_for_group_code($pdo, $g) as $cid) {
            if (gc_session_can_access_company_id($pdo, (int) $cid, $g)) {
                return true;
            }
        }

        return false;
    }

    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($role === 'owner' && gc_has_groups_table($pdo)) {
        $ownerId = (int) ($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($ownerId > 0) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM `groups` WHERE UPPER(TRIM(group_code)) = ? AND owner_id = ? LIMIT 1'
                );
                $stmt->execute([$g, $ownerId]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            } catch (Throwable $e) {
                // fall through
            }
        }
    }

    if (gc_session_company_login_has_group_ledger_privilege() && gc_session_can_access_group_code($pdo, $g)) {
        return true;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId > 0 && gc_user_assigned_to_group_code($pdo, $userId, $g)) {
        return true;
    }

    $hydrated = gc_session_assigned_group_codes();
    if ($hydrated !== [] && in_array($g, $hydrated, true)) {
        return true;
    }

    return false;
}

/**
 * Assert caller may use group ledger for the given group code (throws on deny).
 */
function gc_assert_group_ledger_access(PDO $pdo, string $groupCode): void
{
    if (!gc_session_can_access_group_ledger($pdo, $groupCode)) {
        throw new RuntimeException('无权访问该 Group Ledger');
    }
}

/** Whether session may enter group ledger at all (group login, owner, or user_group_map assignment). */
function gc_session_can_use_group_ledger(): bool
{
    if (gc_is_group_login()) {
        return true;
    }

    if (gc_session_company_login_has_group_ledger_privilege()) {
        return true;
    }

    return gc_session_assigned_group_codes() !== [];
}

/**
 * Admin-assigned subsidiary company ids (user_company_map scope_type=company).
 *
 * @return list<int>
 */
function gc_fetch_user_assigned_company_ids(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $ids = [];

    try {
        if ($pdo->query("SHOW COLUMNS FROM user_company_map LIKE 'scope_type'")->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT company_id
                FROM user_company_map
                WHERE user_id = ?
                  AND scope_type = 'company'
                ORDER BY company_id ASC
            ");
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $id = (int) $cid;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }
    } catch (Throwable $e) {
        // fall through
    }

    try {
        $hasUgm = $pdo->query("SHOW TABLES LIKE 'user_group_map'")->rowCount() > 0;
        if (!$hasUgm) {
            $stmt = $pdo->prepare('SELECT company_id FROM user_company_map WHERE user_id = ? ORDER BY company_id ASC');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $id = (int) $cid;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        $stmt = $pdo->prepare('
            SELECT ucm.company_id
            FROM user_company_map ucm
            WHERE ucm.user_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM user_group_map ugm WHERE ugm.user_id = ucm.user_id
              )
            ORDER BY ucm.company_id ASC
        ');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $id = (int) $cid;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_unique($ids));
}

function gc_hydrate_session_assigned_company_ids(PDO $pdo): void
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $_SESSION['assigned_company_ids'] = $userId > 0
        ? gc_fetch_user_assigned_company_ids($pdo, $userId)
        : [];
}

/**
 * @return list<int>
 */
function gc_session_assigned_company_ids(): array
{
    if (!isset($_SESSION['assigned_company_ids']) || !is_array($_SESSION['assigned_company_ids'])) {
        return [];
    }
    $out = [];
    foreach ($_SESSION['assigned_company_ids'] as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $out[] = $n;
        }
    }
    sort($out);

    return array_values(array_unique($out));
}

/** Hydrate both admin-assigned group codes and subsidiary company ids into session. */
function gc_hydrate_session_assigned_tenants(PDO $pdo): void
{
    gc_hydrate_session_assigned_group_codes($pdo);
    gc_hydrate_session_assigned_company_ids($pdo);
}

/**
 * Whether the logged-in user may use this group code (group login, owner, or subsidiary access).
 */
function gc_session_can_access_group_code(PDO $pdo, string $groupCode): bool
{
    $g = gc_normalize_group_code($groupCode);
    if ($g === '') {
        return false;
    }

    if (gc_is_group_login()) {
        $ident = gc_session_login_identifier();
        if ($ident !== null && $ident === $g) {
            return true;
        }
        if (in_array($g, gc_session_accessible_group_ids(), true)) {
            return true;
        }
    }

    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($role === 'owner' && gc_has_groups_table($pdo)) {
        $ownerId = (int) ($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($ownerId > 0) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM `groups` WHERE UPPER(TRIM(group_code)) = ? AND owner_id = ? LIMIT 1'
                );
                $stmt->execute([$g, $ownerId]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            } catch (Throwable $e) {
                // fall through
            }
        }
    }

    foreach (gc_company_numeric_ids_for_group_code($pdo, $g) as $cid) {
        if (gc_session_can_access_company_id($pdo, (int) $cid, $g)) {
            return true;
        }
    }

    return gc_resolve_group_pk_by_code($pdo, $g) > 0;
}

function gc_hydrate_accessible_group_ids(PDO $pdo, array $companies): void
{
    if (gc_session_login_scope() === null) {
        return;
    }

    $groups = [];

    if (gc_is_group_login()) {
        $ident = gc_session_login_identifier();
        if ($ident !== null) {
            $groups[$ident] = true;
        }
        if (gc_has_groups_table($pdo) && $ident !== null) {
            $pk = gc_resolve_group_pk_by_code($pdo, $ident);
            if ($pk > 0) {
                $_SESSION['login_group_scope_id'] = $pk;
            }
        }
    }

    foreach ($companies as $c) {
        $g = strtoupper(trim((string) ($c['group_id'] ?? '')));
        if ($g !== '') {
            $groups[$g] = true;
        }
        $src = strtoupper(trim((string) ($c['link_source_group'] ?? '')));
        if ($src !== '') {
            $groups[$src] = true;
        }
    }

    if (gc_is_company_login()) {
        $loginGroup = gc_session_login_group_id();
        if ($loginGroup !== null) {
            $groups[$loginGroup] = true;
        }
    }

    $ownerIds = gc_resolve_owner_ids_for_group_links($pdo, $companies);
    if (!empty($ownerIds)) {
        foreach (gc_fetch_linked_group_id_pairs($pdo, $ownerIds) as $pair) {
            $groups[$pair['source']] = true;
            $groups[$pair['target']] = true;
        }
    }

    $_SESSION['accessible_group_ids'] = array_keys($groups);
    sort($_SESSION['accessible_group_ids']);
}

/**
 * @param array<int, array<string, mixed>> $companies
 * @return list<int>
 */
function gc_resolve_owner_ids_for_group_links(PDO $pdo, array $companies): array
{
    $ownerIds = [];

    if (strtolower((string) ($_SESSION['role'] ?? '')) === 'owner') {
        $oid = (int) ($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($oid > 0) {
            $ownerIds[] = $oid;
        }
    }

    $ident = gc_session_login_identifier();
    if ($ident !== null) {
        if (gc_is_group_login()) {
            if (gc_has_groups_table($pdo)) {
                try {
                    $gOwner = $pdo->prepare(
                        'SELECT owner_id FROM `groups` WHERE UPPER(TRIM(group_code)) = ? AND owner_id IS NOT NULL LIMIT 1'
                    );
                    $gOwner->execute([$ident]);
                    $oid = (int) ($gOwner->fetchColumn() ?: 0);
                    if ($oid > 0) {
                        $ownerIds[] = $oid;
                    }
                } catch (Throwable $e) {
                    // fall through
                }
            }
            $stmt = $pdo->prepare(
                'SELECT DISTINCT owner_id FROM company
                 WHERE UPPER(TRIM(group_id)) = ? AND owner_id IS NOT NULL'
            );
            $stmt->execute([$ident]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oid) {
                if ($oid) {
                    $ownerIds[] = (int) $oid;
                }
            }
        } else {
            $stmt = $pdo->prepare('SELECT owner_id FROM company WHERE UPPER(company_id) = ? LIMIT 1');
            $stmt->execute([$ident]);
            $oid = $stmt->fetchColumn();
            if ($oid) {
                $ownerIds[] = (int) $oid;
            }
        }
    }

    foreach ($companies as $c) {
        if (!empty($c['owner_id'])) {
            $ownerIds[] = (int) $c['owner_id'];
        }
    }

    return array_values(array_unique(array_filter($ownerIds)));
}

/**
 * @param list<int> $ownerIds
 * @return list<array{source: string, target: string}>
 */
function gc_fetch_linked_group_id_pairs(PDO $pdo, array $ownerIds): array
{
    $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds))));
    if (empty($ownerIds)) {
        return [];
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() === 0) {
            return [];
        }
    } catch (Exception $e) {
        return [];
    }

    $in = str_repeat('?,', count($ownerIds) - 1) . '?';
    $params = array_merge($ownerIds, $ownerIds);
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            UPPER(TRIM(group_id)) AS source_group,
            UPPER(TRIM(partner_group_id)) AS target_group
        FROM group_ownership
        WHERE owner_type = 'group'
          AND percentage > 0
          AND partner_group_id IS NOT NULL
          AND TRIM(partner_group_id) <> ''
          AND owner_id IN ($in)

        UNION

        SELECT DISTINCT
            UPPER(TRIM(group_id)) AS source_group,
            UPPER(TRIM(partner_group_id)) AS target_group
        FROM group_ownership
        WHERE owner_type = 'owner'
          AND percentage > 0
          AND partner_group_id IS NOT NULL
          AND TRIM(partner_group_id) <> ''
          AND account_id IN ($in)
    ");
    $stmt->execute($params);

    $pairs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $src = strtoupper(trim((string) ($row['source_group'] ?? '')));
        $tgt = strtoupper(trim((string) ($row['target_group'] ?? '')));
        if ($src === '' || $tgt === '') {
            continue;
        }
        $pairs[] = ['source' => $src, 'target' => $tgt];
    }

    return $pairs;
}

/** True when company row is a group entity (AP/IG), including empty company_id placeholder. */
function gc_company_row_is_group_entity(?string $companyCode, ?string $groupId): bool
{
    $gid = strtoupper(trim((string) $groupId));
    if ($gid === '') {
        return false;
    }
    $code = strtoupper(trim((string) $companyCode));
    if ($code === $gid) {
        return true;
    }

    return $code === '';
}

/**
 * Games/Bank flags for a company row; group entities aggregate subsidiary permissions.
 *
 * @return array{has_gambling: bool, has_bank: bool, permissions: list<string>}
 */
function gc_resolve_company_category_flags(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare('SELECT company_id, group_id, permissions FROM company WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['has_gambling' => false, 'has_bank' => false, 'permissions' => []];
    }

    $groupId = strtoupper(trim((string) ($row['group_id'] ?? '')));
    $perms = json_decode((string) ($row['permissions'] ?? ''), true);
    $perms = is_array($perms) ? array_values($perms) : [];
    $hasGambling = in_array('Games', $perms, true) || in_array('Gambling', $perms, true);
    $hasBank = in_array('Bank', $perms, true);

    if (gc_company_row_is_group_entity($row['company_id'] ?? '', $row['group_id'] ?? '') && $groupId !== '') {
        $stmtSubs = $pdo->prepare("
            SELECT permissions
            FROM company
            WHERE UPPER(TRIM(COALESCE(group_id, ''))) = ?
              AND TRIM(COALESCE(company_id, '')) <> ''
              AND UPPER(TRIM(company_id)) <> ?
        ");
        $stmtSubs->execute([$groupId, $groupId]);
        foreach ($stmtSubs->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            $subsPerms = json_decode((string) $raw, true);
            if (!is_array($subsPerms)) {
                continue;
            }
            foreach ($subsPerms as $perm) {
                if (!is_string($perm) || $perm === '') {
                    continue;
                }
                if (!in_array($perm, $perms, true)) {
                    $perms[] = $perm;
                }
            }
            if (
                !$hasGambling
                && (in_array('Games', $subsPerms, true) || in_array('Gambling', $subsPerms, true))
            ) {
                $hasGambling = true;
            }
            if (!$hasBank && in_array('Bank', $subsPerms, true)) {
                $hasBank = true;
            }
            if ($hasGambling && $hasBank) {
                break;
            }
        }
    }

    return [
        'has_gambling' => $hasGambling,
        'has_bank' => $hasBank,
        'permissions' => $perms,
    ];
}
