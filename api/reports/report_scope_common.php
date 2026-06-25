<?php
/**
 * Shared report scope helpers (Customer / Domain report APIs).
 */

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../transactions/transaction_scope.php';

function reportNormalizeGroupId(?string $groupId): string
{
    return strtoupper(trim((string) $groupId));
}

function assertGroupEntityAccess(PDO $pdo, string $groupId, int $entityCompanyId): void
{
    $g = reportNormalizeGroupId($groupId);
    if ($g === '' || $entityCompanyId <= 0) {
        throw new Exception('无效的 group_id');
    }

    if (gc_is_group_login()) {
        if (gc_session_can_access_company_id($pdo, $entityCompanyId, $g)) {
            return;
        }
        $subs = gc_company_numeric_ids_for_group_code($pdo, $g);
        if (in_array($entityCompanyId, $subs, true)) {
            return;
        }
        if (gc_session_can_access_group_ledger($pdo, $g)) {
            return;
        }
        throw new Exception('无权访问该集团');
    }

    $role = strtolower($_SESSION['role'] ?? '');
    if ($role === 'owner') {
        $ownerId = (int) ($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id']);
        $stmt = $pdo->prepare('SELECT id FROM company WHERE id = ? AND owner_id = ? LIMIT 1');
        $stmt->execute([$entityCompanyId, $ownerId]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $subs = gc_company_numeric_ids_for_group_code($pdo, $g);
        if (in_array($entityCompanyId, $subs, true)) {
            return;
        }

        if (gc_has_groups_table($pdo) && $ownerId > 0 && gc_session_can_access_group_ledger($pdo, $g)) {
            return;
        }

        throw new Exception('无权访问该集团');
    }

    tx_resolve_request_company_id($pdo, [
        'company_id' => (string) $entityCompanyId,
        'view_group' => $g,
        'group_id' => $g,
    ]);
}

function reportGroupHasCategorySubsidiary(PDO $pdo, string $groupId, string $category): bool
{
    $g = reportNormalizeGroupId($groupId);
    if ($g === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM company
        WHERE UPPER(TRIM(COALESCE(group_id, ''))) = ?
          AND TRIM(COALESCE(company_id, '')) <> ''
          AND UPPER(TRIM(company_id)) <> ?
    ");
    $stmt->execute([$g, $g]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sid = (int) ($row['id'] ?? 0);
        if ($sid > 0 && checkCompanyCategoryPermission($pdo, $sid, $category)) {
            return true;
        }
    }

    return false;
}

function reportGroupHasGamesSubsidiary(PDO $pdo, string $groupId): bool
{
    return reportGroupHasCategorySubsidiary($pdo, $groupId, 'Games');
}

function reportGroupHasBankSubsidiary(PDO $pdo, string $groupId): bool
{
    return reportGroupHasCategorySubsidiary($pdo, $groupId, 'Bank');
}

function checkReportGamesAccess(PDO $pdo, int $companyId, ?string $groupId): bool
{
    if (checkCompanyCategoryPermission($pdo, $companyId, 'Games')) {
        return true;
    }

    return reportGroupHasGamesSubsidiary($pdo, (string) ($groupId ?? ''));
}

/** Games or Bank — used by maintenance / data-capture scope APIs. */
function checkReportMaintenanceAccess(PDO $pdo, int $companyId, ?string $groupId): bool
{
    if (checkCompanyCategoryPermission($pdo, $companyId, 'Games')) {
        return true;
    }
    if (checkCompanyCategoryPermission($pdo, $companyId, 'Bank')) {
        return true;
    }

    $g = (string) ($groupId ?? '');
    return reportGroupHasGamesSubsidiary($pdo, $g) || reportGroupHasBankSubsidiary($pdo, $g);
}

/**
 * Resolve report scope (group ledger vs subsidiary company) — aligned with Transaction Payment.
 *
 * @param string $categoryAccess 'games' (customer/domain reports) or 'maintenance' (Games + Bank)
 * @return array{
 *   company_id: int,
 *   group_id: string,
 *   report_scope_hint: string,
 *   list_scope: array<string, mixed>,
 *   request_params: array<string, mixed>
 * }
 */
function resolveReportRequestCompanyScope(PDO $pdo, array $get, string $categoryAccess = 'games'): array
{
    $listScope = tx_resolve_transaction_list_scope($pdo, $get);
    $permCompanyId = tx_permission_company_id_for_scope($pdo, $listScope);
    $companyIdForAccess = (int) ($listScope['company_id'] ?? 0);
    if ($companyIdForAccess <= 0) {
        $companyIdForAccess = $permCompanyId;
    }
    if ($companyIdForAccess <= 0 && ($listScope['mode'] ?? '') !== 'group') {
        throw new Exception('缺少公司或集团信息');
    }

    $groupId = reportNormalizeGroupId($get['group_id'] ?? $listScope['group_code'] ?? '');
    $viewGroup = reportNormalizeGroupId($get['view_group'] ?? $listScope['view_group'] ?? '');
    $groupForAccess = $groupId !== '' ? $groupId : ($viewGroup !== '' ? $viewGroup : null);

    if (($listScope['mode'] ?? '') === 'group' && $groupId !== '') {
        gc_assert_group_ledger_access($pdo, $groupId);
    }

    $hasAccess = $categoryAccess === 'maintenance'
        ? checkReportMaintenanceAccess($pdo, $companyIdForAccess, $groupForAccess)
        : checkReportGamesAccess($pdo, $companyIdForAccess, $groupForAccess);
    if (!$hasAccess) {
        throw new Exception('Unauthorized permission category');
    }

    $reportScopeHint = strtolower(trim((string) ($get['report_scope'] ?? '')));
    if ($reportScopeHint === '') {
        $reportScopeHint = (($listScope['mode'] ?? '') === 'group') ? 'group' : 'company';
    }

    return [
        'company_id' => $companyIdForAccess,
        'group_id' => $groupId,
        'report_scope_hint' => $reportScopeHint,
        'list_scope' => $listScope,
        'request_params' => $get,
    ];
}
