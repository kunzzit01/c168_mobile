<?php
/**
 * Shared company scope resolution for transaction APIs (group login + view_group).
 */

require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/group_scope_resolve.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../includes/member_linked_closure.php';

function tx_normalize_view_group(?string $viewGroup): ?string
{
    if ($viewGroup === null) {
        return null;
    }
    $g = strtoupper(trim($viewGroup));
    return $g !== '' ? $g : null;
}

/**
 * Legacy group-entity company row only (company_id = AP/IG).
 * Do not fall back to a subsidiary — use groups.id ledger scope instead.
 */
function tx_resolve_group_entity_company_id(PDO $pdo, string $groupId): int
{
    return gc_resolve_legacy_group_entity_company_id($pdo, $groupId);
}

/** Numeric company id when an API still requires one (legacy entity, else first subsidiary). */
function tx_resolve_group_anchor_company_id(PDO $pdo, string $groupId): int
{
    return gc_resolve_group_anchor_company_id($pdo, $groupId);
}

function tx_request_has_group_only_scope(array $params): bool
{
    if (isset($params['subsidiary_accounts_only']) && (string) $params['subsidiary_accounts_only'] === '1') {
        return false;
    }
    $requestedRaw = $params['company_id'] ?? null;
    if ($requestedRaw !== null && trim((string) $requestedRaw) !== '') {
        return false;
    }
    if (isset($params['group_aggregate']) && (string) $params['group_aggregate'] === '1') {
        return true;
    }
    $viewGroup = tx_normalize_view_group(isset($params['view_group']) ? (string) $params['view_group'] : null);
    $groupScope = tx_normalize_view_group(isset($params['group_id']) ? (string) $params['group_id'] : null);

    return $viewGroup !== null || $groupScope !== null;
}

/**
 * Resolve transaction list scope (group ledger vs company).
 *
 * @return array{
 *   mode: 'group'|'company',
 *   company_id: int,
 *   group_code: string,
 *   group_scope_id: int,
 *   view_group: ?string
 * }
 */
function tx_resolve_transaction_list_scope(PDO $pdo, array $params): array
{
    $viewGroup = tx_normalize_view_group(isset($params['view_group']) ? (string) $params['view_group'] : null);
    $groupScope = tx_normalize_view_group(isset($params['group_id']) ? (string) $params['group_id'] : null);
    if ($groupScope !== null && $viewGroup === null) {
        $viewGroup = $groupScope;
    }
    $groupCode = $viewGroup ?? $groupScope ?? '';

    if (tx_request_has_group_only_scope($params)) {
        if ($groupCode === '') {
            throw new Exception('缺少 group_id');
        }
        gc_assert_group_ledger_access($pdo, $groupCode);
        $groupScopeId = gc_resolve_group_pk_by_code($pdo, $groupCode);
        if ($groupScopeId > 0) {
            return [
                'mode' => 'group',
                'company_id' => 0,
                'group_code' => $groupCode,
                'group_scope_id' => $groupScopeId,
                'view_group' => $groupCode,
            ];
        }
        $legacyEntityId = gc_resolve_legacy_group_entity_company_id($pdo, $groupCode);
        if ($legacyEntityId > 0) {
            return [
                'mode' => 'company',
                'company_id' => $legacyEntityId,
                'group_code' => $groupCode,
                'group_scope_id' => 0,
                'view_group' => $groupCode,
            ];
        }

        throw new Exception('无效的 group_id');
    }

    $companyId = tx_resolve_request_company_id($pdo, $params);

    return [
        'mode' => 'company',
        'company_id' => $companyId,
        'group_code' => $groupCode,
        'group_scope_id' => $groupCode !== '' ? gc_resolve_group_pk_by_code($pdo, $groupCode) : 0,
        'view_group' => $viewGroup,
    ];
}

function tx_sql_transaction_scope_where(array $scope, string $alias = 't'): string
{
    return (($scope['mode'] ?? '') === 'group')
        ? "{$alias}.scope_type = 'group' AND {$alias}.scope_id = ?"
        : "{$alias}.company_id = ?";
}

/** Company subsidiary ledger only — exclude rows stored on anchor FK with scope_type=group. */
function tx_sql_transaction_company_ledger_only(string $alias = 't'): string
{
    return " AND (COALESCE({$alias}.scope_type, '') = '' OR {$alias}.scope_type = 'company')";
}

/**
 * WHERE fragment + bind id for transaction search/submit (group vs company ledger).
 *
 * @return array{sql: string, bind: int, is_group: bool, perm_company_id: int}
 */
function tx_search_transaction_filter(PDO $pdo, array $scope, string $alias = 't'): array
{
    $isGroup = (($scope['mode'] ?? '') === 'group');
    $companyId = (int) ($scope['company_id'] ?? 0);
    if (
        !$isGroup
        && $companyId > 0
        && function_exists('tenant_dual_tenant_enabled')
        && tenant_dual_tenant_enabled($pdo)
        && tx_table_has_scope_column($pdo, 'transactions')
    ) {
        $sql = "COALESCE(NULLIF({$alias}.scope_id, 0), {$alias}.company_id) = ?"
            . tx_sql_transaction_company_ledger_only($alias);
        $bind = $companyId;
    } else {
        $sql = tx_sql_transaction_scope_where($scope, $alias);
        if (!$isGroup && tx_table_has_scope_column($pdo, 'transactions')) {
            $sql .= tx_sql_transaction_company_ledger_only($alias);
        }
        $bind = tx_bind_transaction_scope_id($scope);
    }

    return [
        'sql' => $sql,
        'bind' => $bind,
        'is_group' => $isGroup,
        'perm_company_id' => tx_permission_company_id_for_scope($pdo, $scope),
    ];
}

function tx_bind_transaction_scope_id(array $scope): int
{
    return (($scope['mode'] ?? '') === 'group')
        ? (int) ($scope['group_scope_id'] ?? 0)
        : (int) ($scope['company_id'] ?? 0);
}

function tx_permission_company_id_for_scope(PDO $pdo, array $scope): int
{
    $companyId = (int) ($scope['company_id'] ?? 0);
    if ($companyId > 0) {
        return $companyId;
    }
    $groupCode = (string) ($scope['group_code'] ?? '');

    return $groupCode !== '' ? tx_resolve_group_anchor_company_id($pdo, $groupCode) : 0;
}

/**
 * @param array<string, mixed> $params GET/POST params (company_id, view_group, group_id)
 */
function tx_resolve_request_company_id(PDO $pdo, array $params): int
{
    $viewGroup = tx_normalize_view_group(isset($params['view_group']) ? (string) $params['view_group'] : null);
    $groupScope = tx_normalize_view_group(isset($params['group_id']) ? (string) $params['group_id'] : null);
    if ($groupScope !== null && $viewGroup === null) {
        $viewGroup = $groupScope;
    }
    $requestedRaw = $params['company_id'] ?? null;

    if ($requestedRaw !== null && $requestedRaw !== '') {
        $requested = (int) $requestedRaw;
        if ($requested <= 0) {
            throw new Exception('无效的 company_id');
        }

        if (gc_is_group_login()) {
            if (!gc_session_can_access_company_id($pdo, $requested, $viewGroup)) {
                throw new Exception('无权访问该公司');
            }
            return $requested;
        }

        $userRole = isset($_SESSION['role']) ? strtolower((string) $_SESSION['role']) : '';
        $userType = isset($_SESSION['user_type']) ? strtolower((string) $_SESSION['user_type']) : '';

        if ($userRole === 'owner') {
            $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare('SELECT id FROM company WHERE id = ? AND owner_id = ?');
            $stmt->execute([$requested, $ownerId]);
            if ($stmt->fetchColumn()) {
                return $requested;
            }
            throw new Exception('无权访问该公司');
        }

        if ($userType === 'member') {
            $memberAccountId = member_session_canonical_account_id();
            $stmt = $pdo->prepare('
                SELECT 1
                FROM account_company ac
                WHERE ac.account_id = ? AND ac.company_id = ?
                LIMIT 1
            ');
            $stmt->execute([$memberAccountId, $requested]);
            if ($stmt->fetchColumn()) {
                return $requested;
            }
            throw new Exception('无权访问该公司');
        }

        if (isset($_SESSION['company_id']) && (int) $_SESSION['company_id'] === $requested) {
            return $requested;
        }

        $ucm = $pdo->prepare('SELECT 1 FROM user_company_map WHERE user_id = ? AND company_id = ? LIMIT 1');
        $ucm->execute([$_SESSION['user_id'], $requested]);
        if ($ucm->fetchColumn()) {
            return $requested;
        }

        // Group entity company: user session may be a subsidiary (e.g. C168) while API targets AP entity id.
        if ($viewGroup !== null) {
            $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroup);
            if ($entityId > 0 && $requested === $entityId) {
                $grpStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM user_company_map ucm
                    INNER JOIN company c ON c.id = ucm.company_id
                    WHERE ucm.user_id = ?
                      AND UPPER(TRIM(COALESCE(c.group_id, ''))) = ?
                ");
                $grpStmt->execute([$_SESSION['user_id'], $viewGroup]);
                if ((int) $grpStmt->fetchColumn() > 0) {
                    return $requested;
                }
            }
            if (gc_session_can_access_company_id($pdo, $requested, $viewGroup)) {
                return $requested;
            }
        }

        throw new Exception('无权访问该公司');
    }

    if ($groupScope !== null && ($requestedRaw === null || trim((string) $requestedRaw) === '')) {
        $entityId = tx_resolve_group_entity_company_id($pdo, $groupScope);
        if ($entityId > 0) {
            if (gc_is_group_login()) {
                if (!gc_session_can_access_company_id($pdo, $entityId, $viewGroup)) {
                    throw new Exception('无权访问该公司');
                }
            }
            return $entityId;
        }
        throw new Exception('缺少 company_id');
    }

    $sessionCompanyId = (int) ($_SESSION['company_id'] ?? 0);
    if ($sessionCompanyId <= 0) {
        if (gc_is_group_login()) {
            $view = $viewGroup ?? $groupScope ?? gc_session_login_identifier();
            if ($view !== null && $view !== '') {
                $anchor = tx_resolve_group_anchor_company_id($pdo, $view);
                if ($anchor > 0 && gc_session_can_access_company_id($pdo, $anchor, $view)) {
                    return $anchor;
                }
            }
        }
        throw new Exception('缺少公司信息');
    }
    if (gc_is_group_login()) {
        $view = $viewGroup ?? $groupScope ?? gc_session_login_identifier();
        if (!gc_session_can_access_company_id($pdo, $sessionCompanyId, $view)) {
            throw new Exception('无权访问该公司');
        }
    }

    return $sessionCompanyId;
}

function tx_table_has_scope_column(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = strtolower(trim($table));
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $cache[$key] = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'scope_type'")->rowCount() > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

/**
 * Group ledger account ids only (scope_type=group), never subsidiary/entity rows.
 *
 * @return list<int>
 */
function tx_allowed_account_ids_for_scope(PDO $pdo, array $scope): array
{
    if (($scope['mode'] ?? '') !== 'group') {
        return [];
    }
    $groupPk = (int) ($scope['group_scope_id'] ?? 0);
    if ($groupPk <= 0) {
        return [];
    }

    return tenant_collect_group_account_ids($pdo, $groupPk);
}

/**
 * @return array<string, mixed>|null
 */
function tx_fetch_account_row(PDO $pdo, int $accountId, array $scope): ?array
{
    if ($accountId <= 0) {
        return null;
    }

    $ctx = (($scope['mode'] ?? '') === 'group')
        ? ['mode' => 'group', 'group_pk' => (int) ($scope['group_scope_id'] ?? 0), 'company_id' => 0]
        : ['mode' => 'company', 'group_pk' => 0, 'company_id' => (int) ($scope['company_id'] ?? 0)];

    if (!tenant_account_belongs_to_context($pdo, $accountId, $ctx)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, account_id, name FROM account WHERE id = ? LIMIT 1');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function tx_resolve_currency_id_for_scope(PDO $pdo, string $currencyCode, array $scope): int
{
    $code = strtoupper(trim($currencyCode));
    if ($code === '') {
        throw new Exception('请选择货币');
    }

    if (($scope['mode'] ?? '') === 'group' && tx_table_has_scope_column($pdo, 'currency')) {
        $scopeId = (int) ($scope['group_scope_id'] ?? 0);
        if ($scopeId <= 0) {
            throw new Exception('无效的 group_id');
        }
        $stmt = $pdo->prepare("
            SELECT id FROM currency
            WHERE code = ? AND scope_type = 'group' AND scope_id = ?
            LIMIT 1
        ");
        $stmt->execute([$code, $scopeId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }
        $anchor = tx_permission_company_id_for_scope($pdo, $scope);
        if ($anchor > 0) {
            $ins = $pdo->prepare("
                INSERT INTO currency (code, company_id, scope_type, scope_id)
                VALUES (?, ?, 'group', ?)
            ");
            $ins->execute([$code, $anchor, $scopeId]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO currency (code, scope_type, scope_id)
                VALUES (?, 'group', ?)
            ");
            $ins->execute([$code, $scopeId]);
        }

        return (int) $pdo->lastInsertId();
    }

    $companyId = (int) ($scope['company_id'] ?? 0);
    if ($companyId <= 0) {
        $companyId = tx_permission_company_id_for_scope($pdo, $scope);
    }
    if ($companyId <= 0) {
        throw new Exception('缺少 company_id');
    }

    $stmt = $pdo->prepare('SELECT id FROM currency WHERE code = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$code, $companyId]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }

    $ins = $pdo->prepare('INSERT INTO currency (code, company_id) VALUES (?, ?)');
    $ins->execute([$code, $companyId]);

    return (int) $pdo->lastInsertId();
}

/** Attach scope_type / scope_id (and FK company_id) before INSERT INTO transactions. */
function tx_apply_scope_columns_to_row(PDO $pdo, array &$row, array $scope): void
{
    if (!tx_table_has_scope_column($pdo, 'transactions')) {
        return;
    }

    if (($scope['mode'] ?? '') === 'group') {
        $row['scope_type'] = 'group';
        $row['scope_id'] = (int) ($scope['group_scope_id'] ?? 0);
        $anchor = tx_permission_company_id_for_scope($pdo, $scope);
        if ($anchor > 0) {
            $row['company_id'] = $anchor;
        }
        return;
    }

    $companyId = (int) ($scope['company_id'] ?? 0);
    $row['scope_type'] = 'company';
    $row['scope_id'] = $companyId;
    $row['company_id'] = $companyId;
}

function tx_idempotency_scope_key(array $scope): string
{
    return (($scope['mode'] ?? '') === 'group' ? 'g' : 'c') . ':' . (string) tx_bind_transaction_scope_id($scope);
}
