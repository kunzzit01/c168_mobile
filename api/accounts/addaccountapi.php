<?php
/**
 * 添加账户 API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/password_hashing.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../includes/money_decimal.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
}

function translateApiMessage(string $message): string {
    $map = [
        'Only POST method is allowed' => 'Only POST method is allowed',
        '用户未登录' => 'User not logged in',
        '缺少公司信息' => 'Missing company information',
        '无权限访问该公司' => 'No permission to access this company',
        '请填写所有必填字段' => 'Please fill in all required fields',
        '当支付提醒为是时，必须填写提醒类型和开始日期' => 'When Payment Alert is enabled, Alert Type and Start Date are required',
        '账户ID已存在' => 'Account ID already exists',
        '选择的角色无效' => 'Invalid role selected',
        '账户创建成功！' => 'Account created successfully!',
    ];

    if (isset($map[$message])) {
        return $map[$message];
    }
    if (preg_match('/^账户ID已存在于\s+(.+)$/u', $message, $m)) {
        return 'Account ID already exists in ' . $m[1];
    }

    return $message;
}

function jsonResponse(bool $success, string $message, $data = null): void {
    $message = translateApiMessage($message);
    // 兼容旧前端：失败时部分页面读取 result.error 而不是 message
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'error' => $success ? null : $message,
        'data' => $data,
    ]);
}

function normalizeAlertAmount(?string $value): ?string {
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }
    if (!money_is_valid($value)) {
        throw new Exception('Alert amount must be a valid decimal amount');
    }
    return money_normalize($value);
}

function validateCompanyAccess(PDO $pdo, int $company_id, ?string $view_group = null): void {
    if (gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $view_group);
        return;
    }
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
}

function normalizeGroupId(?string $groupId): ?string {
    $g = strtoupper(trim((string)($groupId ?? '')));
    return $g !== '' ? $g : null;
}

function resolveGroupEntityCompanyId(PDO $pdo, string $groupId, ?int $ownerId = null): int {
    // Strict group entity row: company_id equals group code.
    $sql = "
        SELECT id
        FROM company
        WHERE UPPER(TRIM(company_id)) = ?
    ";
    $params = [$groupId];
    if ($ownerId !== null && $ownerId > 0) {
        $sql .= " AND owner_id = ? ";
        $params[] = $ownerId;
    }
    $sql .= " LIMIT 1 ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    // Legacy placeholder entity form: empty company_id + group_id = group code.
    $placeholderSql = "
        SELECT id
        FROM company
        WHERE TRIM(COALESCE(company_id, '')) = ''
          AND UPPER(TRIM(group_id)) = ?
    ";
    $placeholderParams = [$groupId];
    if ($ownerId !== null && $ownerId > 0) {
        $placeholderSql .= " AND owner_id = ? ";
        $placeholderParams[] = $ownerId;
    }
    $placeholderSql .= "
        ORDER BY id ASC
        LIMIT 1
    ";
    $placeholderStmt = $pdo->prepare($placeholderSql);
    $placeholderStmt->execute($placeholderParams);
    return (int)($placeholderStmt->fetchColumn() ?: 0);
}

function resolveOwnerIdForGroupScope(PDO $pdo, string $groupId, int $currentUserId, string $currentUserRole): int {
    if ($currentUserRole === 'owner') {
        $ownerId = (int)($_SESSION['owner_id'] ?? $currentUserId);
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM company
            WHERE owner_id = ?
              AND UPPER(TRIM(COALESCE(group_id, ''))) = ?
        ");
        $stmt->execute([$ownerId, $groupId]);
        return ((int)$stmt->fetchColumn() > 0) ? $ownerId : 0;
    }

    $stmt = $pdo->prepare("
        SELECT c.owner_id
        FROM user_company_map ucm
        INNER JOIN company c ON c.id = ucm.company_id
        WHERE ucm.user_id = ?
          AND c.owner_id IS NOT NULL
          AND UPPER(TRIM(COALESCE(c.group_id, ''))) = ?
        ORDER BY c.id ASC
        LIMIT 1
    ");
    $stmt->execute([$currentUserId, $groupId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Ensure a dedicated group entity company row exists (company_id == group code).
 * Returns that row id when available/created, otherwise 0.
 */
function ensureGroupEntityCompanyId(PDO $pdo, string $groupId, int $ownerId, int $currentUserId): int {
    $existing = resolveGroupEntityCompanyId($pdo, $groupId, $ownerId);
    if ($existing > 0) {
        return $existing;
    }
    if ($ownerId <= 0) {
        return 0;
    }

    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO company (company_id, owner_id, created_by, group_id)
            VALUES (?, ?, ?, ?)
        ");
        $insertStmt->execute([$groupId, $ownerId, (string)$currentUserId, $groupId]);
    } catch (PDOException $e) {
        // Duplicate/parallel create: ignore and re-read.
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
    }

    return resolveGroupEntityCompanyId($pdo, $groupId, $ownerId);
}

function hasAccountCompanyTable(PDO $pdo): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function accountExistsInCompany(PDO $pdo, string $account_id, int $company_id): bool {
    if (hasAccountCompanyTable($pdo)) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE UPPER(TRIM(COALESCE(a.account_id, ''))) = UPPER(TRIM(?)) AND ac.company_id = ?
            " . tenant_sql_account_company_subsidiary_only($pdo, 'ac'));
        $stmt->execute([$account_id, $company_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE UPPER(TRIM(COALESCE(account_id, ''))) = UPPER(TRIM(?)) AND company_id = ?");
        $stmt->execute([$account_id, $company_id]);
    }
    return $stmt->fetchColumn() > 0;
}

function accountExistsInGroupScope(PDO $pdo, string $account_id, int $groupPk): bool {
    if ($groupPk <= 0 || !tenant_table_has_scope_columns($pdo, 'account_company')) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE UPPER(TRIM(COALESCE(a.account_id, ''))) = UPPER(TRIM(?))
          AND ac.scope_type = 'group'
          AND ac.scope_id = ?
    ");
    $stmt->execute([$account_id, $groupPk]);

    return (int) $stmt->fetchColumn() > 0;
}

function resolveCompanyScopeLabel(PDO $pdo, int $companyId): string {
    $stmt = $pdo->prepare("
        SELECT company_id, group_id
        FROM company
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $companyCode = strtoupper(trim((string)($row['company_id'] ?? '')));
    $groupCode = strtoupper(trim((string)($row['group_id'] ?? '')));
    if ($companyCode !== '' && $groupCode !== '') {
        return $companyCode . ' (Group ' . $groupCode . ')';
    }
    if ($companyCode !== '') {
        return $companyCode;
    }
    if ($groupCode !== '') {
        return 'Group ' . $groupCode;
    }
    return '当前作用域';
}

function roleExists(PDO $pdo, string $role): bool {
    // 容错：角色 code 可能存在大小写差异，按不区分大小写匹配
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role WHERE LOWER(code) = LOWER(?)");
    $stmt->execute([$role]);
    if ($stmt->fetchColumn() > 0) {
        return true;
    }

    // 容错：部分环境 role 表可能缺少核心角色，但前端仍会展示（如 PARTNER/STAFF/DEBTOR）
    $core = strtoupper(trim($role));
    return in_array($core, ['PARTNER', 'STAFF', 'DEBTOR'], true);
}

function buildAccountCreateLockKey(int $companyId, string $accountId): string {
    $normalized = strtolower(trim($accountId));
    $normalized = preg_replace('/[^a-z0-9_:-]/', '_', $normalized);
    return 'add_account_' . $companyId . '_' . $normalized;
}

function acquireAccountCreateLock(PDO $pdo, string $lockKey, int $timeoutSeconds = 5): bool {
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute([$lockKey, $timeoutSeconds]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (PDOException $e) {
        error_log('Failed to acquire account create lock: ' . $e->getMessage());
        return false;
    }
}

function releaseAccountCreateLock(PDO $pdo, string $lockKey): void {
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockKey]);
    } catch (PDOException $e) {
        error_log('Failed to release account create lock: ' . $e->getMessage());
    }
}

function insertAccount(PDO $pdo, array $row): int {
    $stmt = $pdo->prepare("
        INSERT INTO account (account_id, name, role, password, payment_alert, alert_day, alert_specific_date, alert_amount, remark, status, last_login)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NULL)
    ");
    $stmt->execute([
        $row['account_id'], $row['name'], $row['role'], $row['password'],
        $row['payment_alert'], $row['alert_day'], $row['alert_specific_date'], $row['alert_amount'], $row['remark']
    ]);
    return (int) $pdo->lastInsertId();
}

function linkAccountToCompanies(PDO $pdo, int $accountId, array $companyIds): void {
    $stmt = $pdo->prepare("INSERT INTO account_company (account_id, company_id) VALUES (?, ?)");
    foreach ($companyIds as $comp_id) {
        try {
            $stmt->execute([$accountId, $comp_id]);
        } catch (PDOException $e) {
            if ($e->getCode() != 23000) {
                error_log("Error linking company to account: " . $e->getMessage());
                throw $e;
            }
        }
    }
}

function userCanAccessCompany(PDO $pdo, int $userId, int $companyId, string $role): bool {
    if (gc_is_group_login()) {
        $viewGroup = normalizeGroupId($_POST['group_id'] ?? null);
        return gc_session_can_access_company_id($pdo, $companyId, $viewGroup);
    }
    if ($role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $userId;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$companyId, $owner_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$userId, $companyId]);
    }
    return $stmt->fetchColumn() > 0;
}

function getUsersWithCompanyAccess(PDO $pdo, array $companyIds): array {
    $placeholders = str_repeat('?,', count($companyIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, ucp.account_permissions
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        LEFT JOIN user_company_permissions ucp ON u.id = ucp.user_id AND ucm.company_id = ucp.company_id
        WHERE ucm.company_id IN ($placeholders)
    ");
    $stmt->execute($companyIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateUserAccountPermissionsForNewAccount(PDO $pdo, array $users, array $companyIdsToLink, int $newAccountId, string $account_id): void {
    $updateStmt = $pdo->prepare("
        INSERT INTO user_company_permissions (user_id, company_id, account_permissions, process_permissions)
        VALUES (?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE account_permissions = VALUES(account_permissions)
    ");
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';

    foreach ($users as $user) {
        $currentPermissions = [];
        $hasPermissionsSet = false;
        if (isset($user['account_permissions']) && $user['account_permissions'] !== null && $user['account_permissions'] !== '') {
            if (strtolower(trim($user['account_permissions'])) === 'null') {
                $hasPermissionsSet = false;
            } else {
                $decoded = json_decode($user['account_permissions'], true);
                if (is_array($decoded)) {
                    $hasPermissionsSet = true;
                    if (!empty($decoded)) {
                        $currentPermissions = $decoded;
                    }
                }
            }
        }

        if ($hasPermissionsSet) {
            $accountExists = false;
            foreach ($currentPermissions as $permission) {
                if (isset($permission['id']) && (int)$permission['id'] == (int)$newAccountId) {
                    $accountExists = true;
                    break;
                }
            }
            if (!$accountExists) {
                $currentPermissions[] = ['id' => (int)$newAccountId, 'account_id' => $account_id];
                foreach ($companyIdsToLink as $comp_id) {
                    $updateStmt->execute([$user['id'], $comp_id, json_encode($currentPermissions)]);
                }
            }
        }
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    if (is_partnership_audit_read_only_active($pdo)) {
        jsonResponse(false, '只读账号无法添加账户', null);
        exit;
    }

    $group_scope_id = normalizeGroupId($_POST['group_id'] ?? null);
    $explicit_group_only = !empty($_POST['group_only'])
        && filter_var($_POST['group_only'], FILTER_VALIDATE_BOOLEAN);

    $company_id = null;
    if (isset($_POST['company_id']) && $_POST['company_id'] !== '') {
        $company_id = (int) $_POST['company_id'];
    }
    if (gc_is_group_login()) {
        $group_scope_id = $group_scope_id ?? normalizeGroupId($_SESSION['login_identifier'] ?? null);
        if ($explicit_group_only) {
            $company_id = null;
        }
    } elseif ($group_scope_id === null && isset($_SESSION['company_id'])) {
        // Group-scoped add must not implicitly fall back to session company (e.g. C168).
        $company_id = (int) $_SESSION['company_id'];
    }

    // 容错：前端有时仅传 company_ids，不传 company_id
    if (!$company_id && $group_scope_id === null && !gc_is_group_login()) {
        if (isset($_POST['company_ids']) && $_POST['company_ids'] !== '') {
            $decodedCompanyIds = json_decode($_POST['company_ids'], true);
            if (is_array($decodedCompanyIds)) {
                foreach ($decodedCompanyIds as $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) {
                        $company_id = $cid;
                        break;
                    }
                }
            }
        }
    }

    $forced_company_ids_to_link = [];
    $group_ledger_link = null;
    if ($group_scope_id !== null && (!$company_id || $company_id <= 0)) {
        $current_user_id = (int)($_SESSION['user_id'] ?? 0);
        $current_user_role = (string)($_SESSION['role'] ?? '');
        $group_scope_owner_id = resolveOwnerIdForGroupScope(
            $pdo,
            $group_scope_id,
            $current_user_id,
            $current_user_role
        );
        if ($group_scope_owner_id <= 0) {
            throw new Exception('无权限访问该公司');
        }
        $forceGroupLedger = $explicit_group_only
            || (gc_is_group_login() && (!$company_id || $company_id <= 0))
            || (function_exists('tenant_dual_tenant_enabled') && tenant_dual_tenant_enabled($pdo));
        if ($forceGroupLedger) {
            $groupPk = gc_resolve_group_pk_by_code($pdo, $group_scope_id);
            $anchorId = gc_resolve_group_anchor_company_id($pdo, $group_scope_id);
            if ($groupPk <= 0 || $anchorId <= 0) {
                throw new Exception('Missing company information');
            }
            $company_id = $anchorId;
            $group_ledger_link = ['group_pk' => $groupPk, 'anchor_company_id' => $anchorId];
        } else {
            $legacy_entity_id = gc_resolve_legacy_group_entity_company_id($pdo, $group_scope_id);
            if ($legacy_entity_id > 0) {
                $company_id = $legacy_entity_id;
                $forced_company_ids_to_link = [$legacy_entity_id];
            } else {
                $groupPk = gc_resolve_group_pk_by_code($pdo, $group_scope_id);
                $anchorId = gc_resolve_group_anchor_company_id($pdo, $group_scope_id);
                if ($groupPk <= 0 || $anchorId <= 0) {
                    throw new Exception('Missing company information');
                }
                $company_id = $anchorId;
                $group_ledger_link = ['group_pk' => $groupPk, 'anchor_company_id' => $anchorId];
            }
        }
        if (gc_is_group_login()) {
            gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $group_scope_id);
        }
    }

    if ($company_id > 0 && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $group_scope_id);
    }

    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }

    validateCompanyAccess($pdo, $company_id, $group_scope_id);

    $account_id = trim($_POST['account_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $payment_alert = isset($_POST['payment_alert']) ? (int)$_POST['payment_alert'] : 0;
    $alert_type = !empty($_POST['alert_type']) ? trim($_POST['alert_type']) : null;
    $alert_start_date = !empty($_POST['alert_start_date']) ? trim($_POST['alert_start_date']) : null;
    if ($alert_type === null && !empty($_POST['alert_day'])) {
        $alert_type = trim($_POST['alert_day']);
    }
    if ($alert_start_date === null && !empty($_POST['alert_specific_date'])) {
        $alert_start_date = trim($_POST['alert_specific_date']);
    }

    // 角色兼容映射：前端 SUPPLIER 等价于旧的 UPLINE
    $role_db_code = $role;
    if ($role_db_code !== '') {
        // 容错：前端/数据源可能拼错 PARTNER 为 PARTHER
        if (strcasecmp($role_db_code, 'PARTHER') === 0) {
            $role_db_code = 'PARTNER';
        }
        if (strcasecmp($role_db_code, 'SUPPLIER') === 0) {
            $role_db_code = 'UPLINE';
        }
    }

    if (empty($account_id) || empty($name) || empty($role_db_code) || empty($password)) {
        throw new Exception('请填写所有必填字段');
    }

    if ($alert_type !== null) {
        $alert_type_lower = strtolower($alert_type);
        if ($alert_type_lower !== 'weekly' && $alert_type_lower !== 'monthly') {
            $alert_type_int = (int)$alert_type;
            if ($alert_type_int < 1 || $alert_type_int > 31) {
                throw new Exception('Alert Type must be "weekly", "monthly", or a number between 1 and 31');
            }
            $alert_type = (string)$alert_type_int;
        } else {
            $alert_type = $alert_type_lower;
        }
    }

    if ($alert_start_date !== null) {
        $date_parts = explode('-', $alert_start_date);
        if (count($date_parts) !== 3 || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            throw new Exception('Alert Start Date must be a valid date (YYYY-MM-DD)');
        }
    }

    if ($payment_alert == 1 && ($alert_type === null || $alert_start_date === null)) {
        throw new Exception('当支付提醒为是时，必须填写提醒类型和开始日期');
    }

    if ($payment_alert == 0) {
        $alert_type = null;
        $alert_start_date = null;
        $alert_amount = null;
    } else {
        $alert_amount = normalizeAlertAmount($_POST['alert_amount'] ?? null);
    }

    $alert_day = $alert_type;
    $alert_specific_date = $alert_start_date;
    $remark = !empty($_POST['remark']) ? trim($_POST['remark']) : null;

    $lockKey = buildAccountCreateLockKey($company_id, $account_id);
    if (!acquireAccountCreateLock($pdo, $lockKey, 5)) {
        throw new Exception('Account creation is in progress for this ID, please retry');
    }
    try {
        if ($group_ledger_link !== null) {
            if (accountExistsInGroupScope($pdo, $account_id, (int) $group_ledger_link['group_pk'])) {
                throw new Exception('账户ID已存在于 Group ' . strtoupper($group_scope_id ?? ''));
            }
        } elseif (accountExistsInCompany($pdo, $account_id, $company_id)) {
            $scopeLabel = resolveCompanyScopeLabel($pdo, $company_id);
            throw new Exception('账户ID已存在于 ' . $scopeLabel);
        }
        if (!roleExists($pdo, $role_db_code)) {
            throw new Exception('选择的角色无效');
        }

        $current_user_id = $_SESSION['user_id'];
        $current_user_role = $_SESSION['role'] ?? '';

        $pdo->beginTransaction();
        try {
            // Re-check inside transaction while holding lock to avoid duplicate inserts under concurrency.
            if ($group_ledger_link !== null) {
                if (accountExistsInGroupScope($pdo, $account_id, (int) $group_ledger_link['group_pk'])) {
                    throw new Exception('账户ID已存在于 Group ' . strtoupper($group_scope_id ?? ''));
                }
            } elseif (accountExistsInCompany($pdo, $account_id, $company_id)) {
                $scopeLabel = resolveCompanyScopeLabel($pdo, $company_id);
                throw new Exception('账户ID已存在于 ' . $scopeLabel);
            }

            $newAccountId = insertAccount($pdo, [
                'account_id' => $account_id,
                'name' => $name,
                'role' => $role_db_code,
                'password' => secure_hash_password($password),
                'payment_alert' => $payment_alert,
                'alert_day' => $alert_day,
                'alert_specific_date' => $alert_specific_date,
                'alert_amount' => $alert_amount,
                'remark' => $remark,
            ]);

            $company_ids_to_link = [];
            if (!empty($forced_company_ids_to_link)) {
                $company_ids_to_link = $forced_company_ids_to_link;
            }
            // Group-only add must be scoped by group selection only.
            // Do not merge caller-provided company_ids (can drag links back to C168).
            if (empty($forced_company_ids_to_link) && isset($_POST['company_ids']) && $_POST['company_ids'] !== '') {
                $company_ids = json_decode($_POST['company_ids'], true);
                if (is_array($company_ids) && !empty($company_ids)) {
                    foreach ($company_ids as $comp_id) {
                        $comp_id = (int)$comp_id;
                        if ($comp_id > 0 && userCanAccessCompany($pdo, $current_user_id, $comp_id, $current_user_role)) {
                            $company_ids_to_link[] = $comp_id;
                        }
                    }
                }
            }
            if ($group_ledger_link !== null) {
                tenant_link_account_group_scope(
                    $pdo,
                    $newAccountId,
                    (int) $group_ledger_link['group_pk'],
                    (int) $group_ledger_link['anchor_company_id']
                );
                $company_ids_to_link = [(int) $group_ledger_link['anchor_company_id']];
            } else {
                if (empty($company_ids_to_link)) {
                    $company_ids_to_link[] = $company_id;
                }
                linkAccountToCompanies($pdo, $newAccountId, $company_ids_to_link);
            }
            $users = getUsersWithCompanyAccess($pdo, $company_ids_to_link);
            updateUserAccountPermissionsForNewAccount($pdo, $users, $company_ids_to_link, $newAccountId, $account_id);

            $pdo->commit();

            jsonResponse(true, '账户创建成功！', [
                'id' => $newAccountId,
                'account_id' => $account_id,
                'name' => $name,
                'role' => $role,
                'status' => 'active',
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } catch (Exception $e) {
        throw $e;
    } finally {
        releaseAccountCreateLock($pdo, $lockKey);
    }
} catch (PDOException $e) {
    http_response_code(500);
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
}