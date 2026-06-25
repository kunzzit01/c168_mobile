<?php
/**
 * C168 平台管理角色白名单：Domain、Announcement 等共用。
 * 与 userlist 下拉框及 api/users/userlist_api.php 的 $validRoles 对齐，并包含 owner（非下拉创建）。
 */
function c168DomainPageAllowedRoles(): array
{
    return [
        'owner',
        'partnership',
        'admin',
        'manager',
        'supervisor',
        'accountant',
        'audit',
        'customer service',
        'company',
    ];
}

function userHasC168DomainPageAccess(string $roleLower): bool
{
    return in_array(strtolower(trim($roleLower)), c168DomainPageAllowedRoles(), true);
}

/** 公告管理与 Domain 同一白名单 */
function userHasC168AnnouncementPageAccess(string $roleLower): bool
{
    return userHasC168DomainPageAccess($roleLower);
}

/** Auto Renew：C168 公司上下文下的 owner / admin / partnership */
function c168AutoRenewAllowedRoles(): array
{
    return [
        'owner',
        'admin',
        'partnership',
    ];
}

function userHasC168AutoRenewAccess(PDO $pdo, string $roleLower, string $userType): bool
{
    if (strtolower(trim($userType)) === 'member') {
        return false;
    }
    if (!userSessionHasC168CompanyContext($pdo)) {
        return false;
    }
    return in_array(strtolower(trim($roleLower)), c168AutoRenewAllowedRoles(), true);
}

/**
 * 当前 session 是否处于 C168 公司上下文（与 announcement / maintenance 各 API 的 company 校验一致）。
 */
function userSessionHasC168CompanyContext(PDO $pdo): bool
{
    $companyCode = strtoupper($_SESSION['company_code'] ?? '');
    if ($companyCode === 'C168') {
        return true;
    }
    $companyId = $_SESSION['company_id'] ?? null;
    if (!$companyId) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
        $stmt->execute([(int) $companyId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * C168 下 Information 类功能（Domain 页、公告、维护跑马灯）的 API 总闸：
 * 角色须在 c168DomainPageAllowedRoles() 内，且当前公司为 C168。
 * 含 manager / supervisor / customer service（与页面 sidebar 一致）。
 */
function userCanAccessC168InformationApis(PDO $pdo): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
    if (!userHasC168AnnouncementPageAccess($role)) {
        return false;
    }
    return userSessionHasC168CompanyContext($pdo);
}

/**
 * Domain 自动创建的 MEMBER 旧格式为 OWNERCODE_COMPANY（如 QAA_QA）；新格式为公司短码（如 QA）。
 * 列表/维护页展示时统一为公司代码（去掉 owner 前缀）。
 */
function domainProvisionedMemberAccountIdForDisplay(string $accountId, string $role, ?string $createdSource): string
{
    $aid = trim($accountId);
    if ($aid === '') {
        return $aid;
    }
    if (strtolower(trim($role)) !== 'member') {
        return $aid;
    }
    if (strtolower(trim((string) $createdSource)) !== 'domain_auto') {
        return $aid;
    }
    $pos = strrpos($aid, '_');
    if ($pos === false || $pos === 0) {
        return $aid;
    }
    $suffix = substr($aid, $pos + 1);
    if ($suffix === '' || ctype_digit($suffix)) {
        return $aid;
    }
    return strtoupper(trim($suffix));
}
