<?php
/**
 * 账户列表 API：按公司、搜索、状态与权限返回账户列表
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/group_scope_resolve.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../get_companies_helper.php';
require_once __DIR__ . '/../includes/money_decimal.php';
session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行

// ---------- 数据库与业务辅助函数 ----------

/** 返回当前用户在某公司下的账户权限列表（用于展示/调试）。未设置时返回 []。 */
function getCurrentUserAccountPermissions(PDO $pdo, int $company_id): array {
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT account_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$currentUserId, $company_id]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($permission && $permission['account_permissions'] !== null) {
        $permissions = json_decode($permission['account_permissions'], true);
        return is_array($permissions) ? $permissions : [];
    }
    return [];
}

/**
 * Owner / member / partnership / audit bypass account_permissions whitelist (same rules as permissions.php).
 */
function accountlist_user_sees_all_accounts(string $current_user_role): bool
{
    $role = strtolower(trim($current_user_role));
    $userType = strtolower(trim((string) ($_SESSION['user_type'] ?? '')));
    if ($role === 'owner' || $userType === 'member') {
        return true;
    }

    return in_array($role, ['partnership', 'audit'], true);
}

/**
 * 返回账户 ID 过滤：null = 不限制，[] = 不显示任何账户，[id,...] = 只显示这些账户。
 */
function getAccountPermissionFilterForCompany(PDO $pdo, int $company_id, string $current_user_role): ?array {
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId || accountlist_user_sees_all_accounts($current_user_role)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT account_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$currentUserId, $company_id]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$permission || $permission['account_permissions'] === null) {
        return null;
    }
    $userAccountPermissions = json_decode($permission['account_permissions'], true);
    if (empty($userAccountPermissions) || !is_array($userAccountPermissions)) {
        return [];
    }
    $accountIds = array_values(array_unique(array_filter(array_map('intval', array_column($userAccountPermissions, 'id')), function ($id) {
        return $id > 0;
    })));
    return $accountIds;
}

function validateCompanyAccess(PDO $pdo, int $company_id): void {
    if (gc_is_group_login()) {
        $current_user_id = (int)($_SESSION['user_id'] ?? 0);
        $current_user_role = strtolower((string)($_SESSION['role'] ?? ''));
        $current_user_type = strtolower((string)($_SESSION['user_type'] ?? ''));
        $view_group = normalizeGroupId($_GET['group_id'] ?? null);

        if ($current_user_id <= 0) {
            throw new Exception('无权限访问该公司');
        }

        // Keep account-list visibility aligned with the same helper used by
        // dashboard company pills / session switch (supports linked & virtual rows).
        if ($current_user_type === 'member') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM company c
                INNER JOIN account_company ac ON c.id = ac.company_id
                WHERE c.id = ? AND ac.account_id = ?
            ");
            $stmt->execute([$company_id, $current_user_id]);
            $memberAllowed = (int)$stmt->fetchColumn() > 0;
            if (!$memberAllowed) {
                $stmt2 = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM company c
                    INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                    WHERE c.id = ? AND ucm.user_id = ?
                ");
                $stmt2->execute([$company_id, $current_user_id]);
                $memberAllowed = (int)$stmt2->fetchColumn() > 0;
            }
            if (
                !$memberAllowed
                && gc_session_can_access_company_id($pdo, $company_id, $view_group)
            ) {
                $memberAllowed = true;
            }
            if (!$memberAllowed) {
                throw new Exception('无权限访问该公司');
            }
        } else {
            if ($current_user_role === 'owner') {
                $owner_id = (int)($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $current_user_id);
                $rows = getCompaniesByOwner($pdo, $owner_id, true, true);
            } else {
                $rows = getCompaniesByUser($pdo, $current_user_id, true, true);
            }
            $allowed = false;
            foreach ($rows as $row) {
                if ((int)($row['id'] ?? 0) === $company_id) {
                    $allowed = true;
                    break;
                }
            }
            if (
                !$allowed
                && gc_session_can_access_company_id($pdo, $company_id, $view_group)
            ) {
                $allowed = true;
            }
            if (!$allowed) {
                throw new Exception('无权限访问该公司');
            }
        }
        return;
    }
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    $view_group = normalizeGroupId($_GET['group_id'] ?? $_GET['view_group'] ?? null);
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            if (
                $view_group !== null
                && gc_session_can_access_subsidiary_under_view_group($pdo, $company_id, $view_group)
            ) {
                return;
            }
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            if (
                $view_group !== null
                && gc_session_can_access_subsidiary_under_view_group($pdo, $company_id, $view_group)
            ) {
                return;
            }
            throw new Exception('无权限访问该公司');
        }
    }
}

function normalizeGroupId(?string $groupId): ?string {
    $g = strtoupper(trim((string)($groupId ?? '')));
    return $g !== '' ? $g : null;
}

/** Legacy group-entity company row only (company_id = AP/IG). No subsidiary anchor. */
function resolveGroupEntityCompanyId(PDO $pdo, string $groupId): int
{
    return gc_resolve_legacy_group_entity_company_id($pdo, $groupId);
}

function accountListHasAccountScopeColumn(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $cache = $pdo->query("SHOW COLUMNS FROM account_company LIKE 'scope_type'")->rowCount() > 0;
    } catch (PDOException $e) {
        $cache = false;
    }

    return $cache;
}

/**
 * Group ledger accounts (account_company.scope_type = group).
 */
function accountListTableExists(PDO $pdo, string $table): bool
{
    try {
        return $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 与 processlist 一致的状态筛选：
 * - 默认 / 仅分页：active
 * - showInactive：inactive（分页）
 * - showAll：全部 active（不分页由前端控制）
 * - showAll + showInactive：全部 inactive
 */
function appendAccountStatusSqlFilter(string &$sql, bool $showInactive, bool $showAll): void
{
    if ($showAll && $showInactive) {
        $sql .= " AND a.status = 'inactive'";
    } elseif ($showAll) {
        $sql .= " AND a.status = 'active'";
    } elseif ($showInactive) {
        $sql .= " AND a.status = 'inactive'";
    } else {
        $sql .= " AND a.status = 'active'";
    }
}

function fetchAccountsForGroupScope(
    PDO $pdo,
    int $groupScopePk,
    string $searchTerm,
    bool $showInactive,
    bool $showAll,
    ?array $accountIdFilter,
    ?array $rolesFilter = null
): array {
    if ($groupScopePk <= 0) {
        return [];
    }

    $accountIds = [];
    if (accountListHasAccountScopeColumn($pdo)) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT ac.account_id
            FROM account_company ac
            WHERE ac.scope_type = 'group' AND ac.scope_id = ?
        ");
        $stmt->execute([$groupScopePk]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $accountIds[(int) $id] = true;
        }
    }
    if (accountListTableExists($pdo, 'account_group_map')) {
        $stmt = $pdo->prepare('SELECT DISTINCT account_id FROM account_group_map WHERE group_id = ?');
        $stmt->execute([$groupScopePk]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $accountIds[(int) $id] = true;
        }
    }
    $accountIds = array_values(array_filter(array_map('intval', array_keys($accountIds)), static fn (int $id): bool => $id > 0));
    if ($accountIds === []) {
        return [];
    }

    $hasCreatedSource = hasAccountCreatedSourceColumn($pdo);
    $selectCreatedSource = $hasCreatedSource ? ', a.created_source' : ', NULL AS created_source';
    $idPh = implode(',', array_fill(0, count($accountIds), '?'));
    $sql = "SELECT DISTINCT a.id, a.account_id, a.name, a.status, a.last_login, a.role,
            COALESCE(a.payment_alert, 0) AS payment_alert,
            a.alert_day, a.alert_day AS alert_type, a.alert_specific_date, a.alert_specific_date AS alert_start_date,
            a.alert_amount, a.remark
            {$selectCreatedSource}
            FROM account a
            WHERE a.id IN ($idPh)";
    $params = $accountIds;

    if ($rolesFilter !== null && !empty($rolesFilter)) {
        $placeholders = implode(',', array_fill(0, count($rolesFilter), '?'));
        $sql .= " AND a.role IN ($placeholders)";
        $params = array_merge($params, $rolesFilter);
    }

    if ($accountIdFilter !== null) {
        if (empty($accountIdFilter)) {
            $sql .= ' AND 1=0';
        } else {
            $placeholders = str_repeat('?,', count($accountIdFilter) - 1) . '?';
            $sql .= " AND a.id IN ($placeholders)";
            $params = array_merge($params, $accountIdFilter);
        }
    }

    if ($searchTerm !== '') {
        $searchParam = "%$searchTerm%";
        $sql .= ' AND (a.account_id LIKE ? OR a.name LIKE ? OR a.status LIKE ? OR a.role LIKE ?)';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    appendAccountStatusSqlFilter($sql, $showInactive, $showAll);

    $sql .= ' ORDER BY a.account_id ASC, a.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $createdSource = strtolower(trim((string) ($row['created_source'] ?? '')));
        if ($createdSource === 'domain_auto' || shouldFormatAsCompanyId((string) ($row['account_id'] ?? ''))) {
            $row['account_id'] = formatDomainAutoDisplayAccountId((string) ($row['account_id'] ?? ''));
        }
        if ($row['alert_amount'] !== null && $row['alert_amount'] !== '') {
            $row['alert_amount'] = money_out($row['alert_amount']);
        }
        unset($row['created_source']);
        $out[] = $row;
    }

    return $out;
}

function mergeAccountRowsById(array $primary, array $secondary): array
{
    $byId = [];
    foreach (array_merge($primary, $secondary) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $row;
        }
    }

    return array_values($byId);
}

function hasAccountCreatedSourceColumn(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `account` LIKE 'created_source'");
        $has = $stmt && $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has = false;
    }
    return $has;
}

function formatDomainAutoDisplayAccountId(string $rawAccountId): string {
    $rawAccountId = trim($rawAccountId);
    if ($rawAccountId === '') {
        return $rawAccountId;
    }

    // Domain 自动建账兼容显示：
    // 1) OWNERCODE_COMPANY -> COMPANY（如 TEST_AA / K_95 -> AA / 95）
    // 2) OWNERCODE_COMPANY_数字(冲突后缀) -> COMPANY（如 TEST_AA_1 / K_95_1 -> AA / 95）
    if (strpos($rawAccountId, '_') !== false) {
        $parts = explode('_', $rawAccountId);
        $count = count($parts);
        if ($count >= 3) {
            $last = trim((string)$parts[count($parts) - 1]);
            $prev = trim((string)$parts[count($parts) - 2]);

            // 仅当存在 3 段及以上且末段是数字时，视为冲突后缀（..._COMPANY_1）
            if ($last !== '' && ctype_digit($last) && $prev !== '') {
                return $prev;
            }
        }

        if ($count >= 2) {
            $last = trim((string)$parts[$count - 1]);
            // 常规 OWNERCODE_COMPANY，直接显示 company_id（最后一段）
            if ($last !== '') {
                return $last;
            }
        }
    }

    return $rawAccountId;
}

function shouldFormatAsCompanyId(string $rawAccountId): bool {
    $rawAccountId = trim($rawAccountId);
    if ($rawAccountId === '') {
        return false;
    }

    // 仅处理类似 K_95 或 K_95_1 这类“前缀 + 数字公司ID(+冲突后缀)”格式
    if (preg_match('/^[^_]+_[0-9]+(?:_[0-9]+)?$/', $rawAccountId)) {
        return true;
    }

    return false;
}

function fetchAccountsForCompany(PDO $pdo, int $company_id, string $searchTerm, bool $showInactive, bool $showAll, ?array $accountIdFilter, ?array $rolesFilter = null): array {
    $hasCreatedSource = hasAccountCreatedSourceColumn($pdo);
    $selectCreatedSource = $hasCreatedSource ? ", a.created_source" : ", NULL AS created_source";
    $sql = "SELECT DISTINCT a.id, a.account_id, a.name, a.status, a.last_login, a.role,
            COALESCE(a.payment_alert, 0) AS payment_alert,
            a.alert_day, a.alert_day AS alert_type, a.alert_specific_date, a.alert_specific_date AS alert_start_date,
            a.alert_amount, a.remark
            {$selectCreatedSource}
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ?" . tenant_sql_account_company_subsidiary_only($pdo, 'ac');
    $params = [$company_id];

    if ($rolesFilter !== null && !empty($rolesFilter)) {
        $placeholders = implode(',', array_fill(0, count($rolesFilter), '?'));
        $sql .= " AND a.role IN ($placeholders)";
        $params = array_merge($params, $rolesFilter);
    }

    if ($accountIdFilter !== null) {
        if (empty($accountIdFilter)) {
            $sql .= " AND 1=0";
        } else {
            $placeholders = str_repeat('?,', count($accountIdFilter) - 1) . '?';
            $sql .= " AND a.id IN ($placeholders)";
            $params = array_merge($params, $accountIdFilter);
        }
    }

    if ($searchTerm !== '') {
        $searchParam = "%$searchTerm%";
        $sql .= " AND (a.account_id LIKE ? OR a.name LIKE ? OR a.status LIKE ? OR a.role LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    appendAccountStatusSqlFilter($sql, $showInactive, $showAll);

    $sql .= " ORDER BY a.account_id ASC, a.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $row) {
        $createdSource = strtolower(trim((string)($row['created_source'] ?? '')));
        if ($createdSource === 'domain_auto' || shouldFormatAsCompanyId((string)($row['account_id'] ?? ''))) {
            $row['account_id'] = formatDomainAutoDisplayAccountId((string)($row['account_id'] ?? ''));
        }
        if ($row['alert_amount'] !== null && $row['alert_amount'] !== '') {
            $row['alert_amount'] = money_out($row['alert_amount']);
        }
        unset($row['created_source']);
        $out[] = $row;
    }
    return $out;
}

function computeAlertStatus(array $accounts): array {
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    foreach ($accounts as &$account) {
        $is_alert = false;
        if (isset($account['payment_alert']) && $account['payment_alert'] == 1) {
            $alert_type = $account['alert_type'] ?? $account['alert_day'] ?? null;
            $alert_start_date = $account['alert_start_date'] ?? $account['alert_specific_date'] ?? null;
            if ($alert_type && $alert_start_date) {
                try {
                    $startDate = new DateTime($alert_start_date);
                    $startDate->setTime(0, 0, 0);
                    if ($startDate > $today) {
                        $account['is_alert'] = 0;
                        continue;
                    }
                    $daysDiff = (int) $startDate->diff($today)->days;
                    $alert_type_lower = strtolower($alert_type);
                    if ($alert_type_lower === 'weekly') {
                        $is_alert = ($daysDiff >= 0 && $daysDiff % 7 === 0);
                    } elseif ($alert_type_lower === 'monthly') {
                        $startDay = (int) $startDate->format('j');
                        $todayDay = (int) $today->format('j');
                        $is_alert = ($startDay === $todayDay && $startDate <= $today);
                    } else {
                        $daysInterval = (int) $alert_type;
                        if ($daysInterval >= 1 && $daysInterval <= 31) {
                            $is_alert = ($daysDiff >= 0 && $daysDiff % $daysInterval === 0);
                        }
                    }
                } catch (Exception $e) {
                    $is_alert = false;
                }
            }
        }
        $account['is_alert'] = $is_alert ? 1 : 0;
    }
    unset($account);
    return $accounts;
}

// ---------- 主逻辑 ----------

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    $group_scope_id = normalizeGroupId($_GET['group_id'] ?? null);
    if ($group_scope_id === null && gc_is_group_login()) {
        $group_scope_id = normalizeGroupId(gc_session_login_identifier());
    }

    $company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $company_id = (int) $_GET['company_id'];
    } elseif ($group_scope_id === null && !gc_is_group_login() && isset($_SESSION['company_id'])) {
        $company_id = (int) $_SESSION['company_id'];
    }

    $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
    $showInactive = isset($_GET['showInactive']) ? filter_var($_GET['showInactive'], FILTER_VALIDATE_BOOLEAN) : false;
    $showAll = isset($_GET['showAll']) ? filter_var($_GET['showAll'], FILTER_VALIDATE_BOOLEAN) : false;

    $rolesFilter = null;
    if (isset($_GET['roles']) && $_GET['roles'] !== '') {
        $rolesFilter = array_map('trim', explode(',', $_GET['roles']));
        $rolesFilter = array_values(array_filter($rolesFilter, function ($r) {
            return $r !== '';
        }));
    }

    $groupOnlyLedger = false;
    $explicitGroupOnly = isset($_GET['group_only']) && filter_var($_GET['group_only'], FILTER_VALIDATE_BOOLEAN);
    if ($group_scope_id !== null) {
        if ($company_id > 0 && !$explicitGroupOnly) {
            if (gc_is_group_login()) {
                gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $group_scope_id);
            }
        } else {
            $groupScopePk = gc_resolve_group_pk_by_code($pdo, $group_scope_id);
            if ($groupScopePk <= 0) {
                echo json_encode([
                    'success' => true,
                    'message' => '',
                    'data' => [
                        'accounts' => [],
                        'count' => 0,
                        'searchTerm' => $searchTerm,
                        'showInactive' => $showInactive,
                        'showAll' => $showAll,
                        'company_id' => null,
                        'user_permissions_count' => 0,
                    ],
                ]);
                exit;
            }
            $useGroupLedgerOnly = $explicitGroupOnly
                || gc_is_group_login()
                || (function_exists('tenant_dual_tenant_enabled') && tenant_dual_tenant_enabled($pdo));
            if ($useGroupLedgerOnly) {
                $groupOnlyLedger = true;
                $company_id = null;
            } else {
                $legacyEntityId = resolveGroupEntityCompanyId($pdo, $group_scope_id);
                if ($legacyEntityId > 0) {
                    $company_id = $legacyEntityId;
                    if (gc_is_group_login()) {
                        gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $group_scope_id);
                    }
                } else {
                    $groupOnlyLedger = true;
                    $company_id = null;
                }
            }
        }
    }

    if (!$company_id && !$groupOnlyLedger) {
        throw new Exception('缺少公司信息');
    }

    if ($company_id > 0) {
        validateCompanyAccess($pdo, $company_id);
    } elseif ($group_scope_id !== null) {
        gc_assert_group_ledger_access($pdo, $group_scope_id);
    }

    $current_user_role = $_SESSION['role'] ?? '';
    $accountIdFilter = $company_id > 0
        ? getAccountPermissionFilterForCompany($pdo, $company_id, $current_user_role)
        : null;
    $userAccountPermissions = $company_id > 0
        ? getCurrentUserAccountPermissions($pdo, $company_id)
        : [];

    $accounts = [];
    if ($groupOnlyLedger && $group_scope_id !== null) {
        $groupScopePk = gc_resolve_group_pk_by_code($pdo, $group_scope_id);
        $accounts = fetchAccountsForGroupScope(
            $pdo,
            $groupScopePk,
            $searchTerm,
            $showInactive,
            $showAll,
            $accountIdFilter,
            $rolesFilter
        );
    } elseif ($group_scope_id !== null && $company_id > 0) {
        // Subsidiary company pill (e.g. C168): that company only — do not merge group ledger rows.
        $accounts = fetchAccountsForCompany(
            $pdo,
            $company_id,
            $searchTerm,
            $showInactive,
            $showAll,
            $accountIdFilter,
            $rolesFilter
        );
    } else {
        $accounts = fetchAccountsForCompany($pdo, $company_id, $searchTerm, $showInactive, $showAll, $accountIdFilter, $rolesFilter);
    }
    $accounts = computeAlertStatus($accounts);

    echo json_encode([
        'success' => true,
        'message' => '',
        'data' => [
            'accounts' => $accounts,
            'count' => count($accounts),
            'searchTerm' => $searchTerm,
            'showInactive' => $showInactive,
            'showAll' => $showAll,
            'company_id' => $company_id,
            'user_permissions_count' => count($userAccountPermissions),
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage(), 'data' => null]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系统错误: ' . $e->getMessage(), 'data' => null]);
}