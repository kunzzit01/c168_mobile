<?php
// permissions.php
function getCurrentUserAccountPermissions($pdo) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 获取当前用户ID和公司ID
    $currentUserId = $_SESSION['user_id'] ?? $_SESSION['login_id'] ?? null;
    $companyId = $_SESSION['company_id'] ?? null;

    if (!$currentUserId || !$companyId) {
        return [];
    }

    // 如果存储的是 login_id，需要先获取 user id
    if (is_string($currentUserId)) {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ?");
        $stmt->execute([$currentUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return [];
        }
        $currentUserId = $user['id'];
    }

    // 从 user_company_permissions 表获取当前公司下的账户权限
    $stmt = $pdo->prepare("SELECT account_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$currentUserId, $companyId]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($permission && $permission['account_permissions'] !== null) {
        $permissions = json_decode($permission['account_permissions'], true);
        return is_array($permissions) ? $permissions : [];
    }

    // 如果 user_company_permissions 表中没有记录，返回空数组（表示未设置权限，默认可以看到所有账户）
    return [];
}

/**
 * Roles that bypass user_company_permissions.account_permissions whitelist (full ledger visibility).
 * Partnership / audit are read-only reviewers and must see the same accounts as owner.
 */
function permissions_user_sees_all_accounts(?string $role = null, ?string $userType = null): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $role = strtolower(trim((string) ($role ?? $_SESSION['role'] ?? '')));
    $userType = strtolower(trim((string) ($userType ?? $_SESSION['user_type'] ?? '')));

    if ($role === 'owner' || $userType === 'member') {
        return true;
    }

    return in_array($role, ['partnership', 'audit'], true);
}

/**
 * @param int|null $permissionCompanyId 查询「指定公司」账户时传入该公司主键，用于读取 user_company_permissions；
 *                                        为 null 时用 $_SESSION['company_id']（与旧行为一致）。
 */
function filterAccountsByPermissions($pdo, $baseQuery, $params = [], $permissionCompanyId = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (permissions_user_sees_all_accounts()) {
        return [$baseQuery, $params];
    }

    // 获取当前用户ID和公司ID
    $currentUserId = $_SESSION['user_id'] ?? $_SESSION['login_id'] ?? null;
    $companyId = ($permissionCompanyId !== null && (int)$permissionCompanyId > 0)
        ? (int)$permissionCompanyId
        : (int)($_SESSION['company_id'] ?? 0);

    if (!$currentUserId || !$companyId) {
        // 如果没有用户ID或公司ID，不添加过滤条件，显示所有账户
        return [$baseQuery, $params];
    }

    // 如果存储的是 login_id，需要先获取 user id
    if (is_string($currentUserId)) {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ?");
        $stmt->execute([$currentUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return [$baseQuery, $params];
        }
        $currentUserId = $user['id'];
    }

    // 从 user_company_permissions 表获取当前公司下的账户权限
    $stmt = $pdo->prepare("SELECT account_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$currentUserId, $companyId]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);

    // 如果 user_company_permissions 表中没有记录，或者 account_permissions 是 null（未设置），默认可以看到所有账户
    if (!$permission || $permission['account_permissions'] === null) {
        return [$baseQuery, $params];
    }

    // 解析 JSON 数据
    $userAccountPermissions = json_decode($permission['account_permissions'], true);
    
    // 如果 account_permissions 是空数组 []（已设置但清空），用户看不到任何账户
    if (empty($userAccountPermissions) || !is_array($userAccountPermissions)) {
        $hasWhere = stripos($baseQuery, ' WHERE ') !== false;
        if ($hasWhere) {
            $baseQuery .= " AND 1=0";
        } else {
            $baseQuery .= " WHERE 1=0";
        }
        return [$baseQuery, $params];
    }
    
    // 如果 account_permissions 有值，只显示权限列表中的账户
    $accountIds = array_column($userAccountPermissions, 'id');
    // 确保所有 ID 都是整数类型，避免类型不匹配问题
    $accountIds = array_map('intval', $accountIds);
    $accountIds = array_filter($accountIds, function($id) { return $id > 0; }); // 过滤无效的 ID
    $accountIds = array_unique($accountIds); // 去重
    $accountIds = array_values($accountIds); // 重新索引数组
    
    // 只有当有有效的账户 ID 时，才添加过滤条件
    if (!empty($accountIds)) {
        $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';
        $baseQuery .= " AND id IN ($placeholders)";
        $params = array_merge($params, $accountIds);
    } else {
        // 如果 accountIds 为空（虽然理论上不应该发生），不显示任何账户
        $hasWhere = stripos($baseQuery, ' WHERE ') !== false;
        if ($hasWhere) {
            $baseQuery .= " AND 1=0";
        } else {
            $baseQuery .= " WHERE 1=0";
        }
    }

    return [$baseQuery, $params];
}

function getCurrentUserProcessPermissions($pdo) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // 获取当前用户ID和公司ID
    $currentUserId = $_SESSION['user_id'] ?? $_SESSION['login_id'] ?? null;
    $companyId = $_SESSION['company_id'] ?? null;

    if (!$currentUserId || !$companyId) {
        return [];
    }

    // 如果存储的是 login_id，需要先获取 user id
    if (is_string($currentUserId)) {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ?");
        $stmt->execute([$currentUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return [];
        }
        $currentUserId = $user['id'];
    }

    // 从 user_company_permissions 表获取当前公司下的流程权限
    $stmt = $pdo->prepare("SELECT process_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$currentUserId, $companyId]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($permission && $permission['process_permissions'] !== null) {
        $permissions = json_decode($permission['process_permissions'], true);
        return is_array($permissions) ? $permissions : [];
    }

    // 如果 user_company_permissions 表中没有记录，返回空数组（表示未设置权限，默认可以看到所有流程）
    return [];
}

/**
 * @param int|null $permissionCompanyId Company for user_company_permissions lookup.
 *   When the list/query targets a company via ?company_id=, pass that id so permissions
 *   match the requested company — not a stale $_SESSION['company_id'] (e.g. Bank CX → Games 95).
 */
function filterProcessesByPermissions($pdo, $baseQuery, $params = [], $permissionCompanyId = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // owner 不受权限限制，自动显示全部
    $currentUserRole = $_SESSION['role'] ?? '';
    if ($currentUserRole === 'owner') {
        return [$baseQuery, $params];
    }

    // 获取当前用户ID和公司ID
    $currentUserId = $_SESSION['user_id'] ?? $_SESSION['login_id'] ?? null;
    $companyId = $permissionCompanyId !== null && $permissionCompanyId !== ''
        ? (int) $permissionCompanyId
        : ($_SESSION['company_id'] ?? null);
    if ($companyId !== null && $companyId <= 0) {
        $companyId = null;
    }

    if (!$currentUserId || !$companyId) {
        // 如果没有用户ID或公司ID，不添加过滤条件，显示所有流程
        return [$baseQuery, $params];
    }

    // 如果存储的是 login_id，需要先获取 user id
    if (is_string($currentUserId)) {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ?");
        $stmt->execute([$currentUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return [$baseQuery, $params];
        }
        $currentUserId = $user['id'];
    }

    // 从 user_company_permissions 表获取当前公司下的流程权限
    $stmt = $pdo->prepare("SELECT process_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$currentUserId, $companyId]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);

    // 如果 user_company_permissions 表中没有记录，或者 process_permissions 是 null（未设置），默认可以看到所有流程
    if (!$permission || $permission['process_permissions'] === null) {
        return [$baseQuery, $params];
    }

    // 解析 JSON 数据
    $userProcessPermissions = json_decode($permission['process_permissions'], true);
    
    // 如果 process_permissions 是空数组 []（已设置但清空），用户看不到任何流程
    if (empty($userProcessPermissions) || !is_array($userProcessPermissions)) {
        $hasWhere = stripos($baseQuery, ' WHERE ') !== false;
        if ($hasWhere) {
            $baseQuery .= " AND 1=0";
        } else {
            $baseQuery .= " WHERE 1=0";
        }
        return [$baseQuery, $params];
    }

    // 如果 process_permissions 有值，只显示权限列表中的流程
    $processIds = array_column($userProcessPermissions, 'id');
    // 确保所有 ID 都是整数类型
    $processIds = array_map('intval', $processIds);
    $processIds = array_filter($processIds, function($id) { return $id > 0; }); // 过滤无效的 ID
    $processIds = array_unique($processIds); // 去重
    $processIds = array_values($processIds); // 重新索引数组
    
    if (!empty($processIds)) {
        $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
        
        // 检查是否已经有 WHERE 条件
        $hasWhere = stripos($baseQuery, ' WHERE ') !== false;
        
        if ($hasWhere) {
            // 如果已经有 WHERE 条件，添加 AND 条件
            $baseQuery .= " AND p.id IN ($placeholders)";
        } else {
            // 如果没有 WHERE 条件，添加 WHERE 条件
            $baseQuery .= " WHERE p.id IN ($placeholders)";
        }
        $params = array_merge($params, $processIds);
    } else {
        // 如果 processIds 为空（虽然理论上不应该发生），不显示任何流程
        $hasWhere = stripos($baseQuery, ' WHERE ') !== false;
        if ($hasWhere) {
            $baseQuery .= " AND 1=0";
        } else {
            $baseQuery .= " WHERE 1=0";
        }
    }

    return [$baseQuery, $params];
}

if (!function_exists('checkCompanyCategoryPermission')) {
    /**
     * Helper to verify if a company has access to a specific UI category (Data-Level Access Control).
     *
     * @param PDO $pdo
     * @param int|string $companyId
     * @param string $category (e.g., 'Games', 'Bank', 'Loan', 'Rate', 'Money')
     * @return bool
     */
    function checkCompanyCategoryPermission(PDO $pdo, $companyId, $category) {
        if (empty($companyId)) return false;
        try {
            $stmt = $pdo->prepare("SELECT permissions FROM company WHERE id = ?");
            // If companyId is string like 'C168', ensure we handle it, but table 'id' is int.
            // Assuming companyId here is the `id` column. If it's the string code, caller must provide `id`.
            $stmt->execute([$companyId]);
            $permsJson = $stmt->fetchColumn();
            
            if ($permsJson === false || $permsJson === null || $permsJson === '') {
                return false;
            }

            $perms = json_decode($permsJson, true);
            if (!is_array($perms)) return false;

            // Handle "Games" vs "Gambling" backward compatibility
            if ($category === 'Games' || $category === 'Gambling') {
                return in_array('Games', $perms) || in_array('Gambling', $perms);
            }

            return in_array($category, $perms);
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('checkCompanyGamesOrBankCategoryPermission')) {
    /** Data Capture / maintenance process list: Games/Gambling or Bank (e.g. CX payroll channel). */
    function checkCompanyGamesOrBankCategoryPermission(PDO $pdo, $companyId): bool
    {
        return checkCompanyCategoryPermission($pdo, $companyId, 'Games')
            || checkCompanyCategoryPermission($pdo, $companyId, 'Bank');
    }
}

if (!function_exists('user_sidebar_permissions_list')) {
    /**
     * Sidebar permission keys from user.permissions JSON.
     * Empty/null = unrestricted (owner / legacy full access).
     *
     * @return array<int, string>|null null = unrestricted
     */
    function user_sidebar_permissions_list(PDO $pdo, ?int $userId = null): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $role = strtolower((string) ($_SESSION['role'] ?? ''));
        if ($role === 'owner') {
            return null;
        }

        $userType = strtolower((string) ($_SESSION['user_type'] ?? ''));
        if ($userType === 'member') {
            return [];
        }

        $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }

        $stmt = $pdo->prepare('SELECT permissions FROM user WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || trim((string) $raw) === '') {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (count($decoded) === 0) {
            return null;
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }
}

if (!function_exists('user_has_sidebar_permission')) {
    function user_has_sidebar_permission(PDO $pdo, string $key, ?int $userId = null): bool
    {
        if ($key === 'ownership') {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (!role_supports_ownership_permission($_SESSION['role'] ?? '')) {
                return false;
            }
        }

        $perms = user_sidebar_permissions_list($pdo, $userId);
        if ($perms === null) {
            return true;
        }
        return in_array($key, $perms, true);
    }
}

if (!function_exists('user_can_access_dashboard')) {
    function user_can_access_dashboard(PDO $pdo, ?int $userId = null): bool
    {
        return user_has_sidebar_permission($pdo, 'home', $userId);
    }
}

if (!function_exists('role_supports_ownership_permission')) {
  function role_supports_ownership_permission(?string $role): bool
  {
    $r = strtolower(trim((string) $role));
    return $r === 'owner' || $r === 'partnership';
  }
}

if (!function_exists('sanitize_sidebar_permissions_for_role')) {
  /**
   * @param array<int, string>|null $permissions
   * @return array<int, string>
   */
  function sanitize_sidebar_permissions_for_role(?string $role, $permissions): array
  {
    if (!is_array($permissions)) {
      return [];
    }
    if (role_supports_ownership_permission($role)) {
      return array_values($permissions);
    }
    return array_values(array_filter($permissions, static function ($perm) {
      return $perm !== 'ownership';
    }));
  }
}
?>