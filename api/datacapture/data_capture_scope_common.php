<?php
/**
 * Shared scope helpers for Data Capture / Summary / submitted-process APIs.
 */

require_once __DIR__ . '/../reports/report_scope_common.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../includes/process_modified_by.php';

function dcNormalizeGroupId(?string $groupId): string
{
    return reportNormalizeGroupId($groupId);
}

function dcIsGroupScopeHint(array $resolved): bool
{
    $hint = strtolower(trim((string) ($resolved['report_scope_hint'] ?? '')));
    if ($hint === 'group') {
        return true;
    }
    $groupId = dcNormalizeGroupId($resolved['group_id'] ?? '');
    if ($groupId === '') {
        return false;
    }
    $companyId = (int) ($resolved['company_id'] ?? 0);
    if ($companyId <= 0) {
        return true;
    }
    global $pdo;
    if (!isset($pdo)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT company_id FROM company WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $code = strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
    return $code !== '' && $code === $groupId;
}

/** Ordered group payroll process codes (Data Capture group-only: PROFIT first, then SALARY, COMMISSION, BONUS). */
function dcGroupPayrollProcessCodes(): array
{
    return ['PROFIT', 'SALARY', 'COMMISSION', 'BONUS'];
}

function dcIsGroupPayrollProcessCode(string $code): bool
{
    return in_array(strtoupper(trim($code)), dcGroupPayrollProcessCodes(), true);
}

/** Group payroll table drafts: SALARY / COMMISSION / BONUS only (not PROFIT). */
function dcGroupPayrollDraftProcessCodes(): array
{
    return ['SALARY', 'COMMISSION', 'BONUS'];
}

function dcIsGroupPayrollDraftProcessCode(string $code): bool
{
    return in_array(strtoupper(trim($code)), dcGroupPayrollDraftProcessCodes(), true);
}

function dcSqlQuotedGroupPayrollProcessCodes(): string
{
    return implode(', ', array_map(static fn (string $c): string => "'" . $c . "'", dcGroupPayrollProcessCodes()));
}

function dcSqlOrderByGroupPayrollProcessField(string $fieldExpr): string
{
    $fieldExpr = trim($fieldExpr);
    if ($fieldExpr === '') {
        $fieldExpr = 'UPPER(TRIM(p.process_id))';
    }

    return 'ORDER BY FIELD(' . $fieldExpr . ', ' . dcSqlQuotedGroupPayrollProcessCodes() . ')';
}

/**
 * Group payroll submitted list: SALARY(1), SALARY(2) when same code appears multiple times on one day.
 * Rows must be sorted by created_at ASC before calling.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function dcAnnotateSameDayPayrollSubmissionLabels(array $rows): array
{
    $totals = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string) ($row['process_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $totals[$code] = ($totals[$code] ?? 0) + 1;
    }

    $seqByCode = [];
    $out = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string) ($row['process_code'] ?? '')));
        if ($code === '') {
            $row['same_day_seq'] = 1;
            $row['process_display'] = '';
            $out[] = $row;
            continue;
        }
        $seqByCode[$code] = ($seqByCode[$code] ?? 0) + 1;
        $seq = $seqByCode[$code];
        $multi = ($totals[$code] ?? 1) > 1;
        $row['same_day_seq'] = $seq;
        $row['process_display'] = $multi ? $code . '(' . $seq . ')' : $code;
        $out[] = $row;
    }

    return $out;
}

/**
 * SQL fragment restricting to group-only processes (SALARY / COMMISSION / BONUS).
 */
function dcSqlGroupProcessFilter(string $processAlias = 'p'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $processAlias) ?: 'p';
    return ' AND UPPER(TRIM(' . $a . '.process_id)) IN (' . dcSqlQuotedGroupPayrollProcessCodes() . ') ';
}

/**
 * SQL fragment excluding group-only processes from company scope.
 */
function dcSqlCompanyProcessFilter(string $processAlias = 'p'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $processAlias) ?: 'p';
    return ' AND UPPER(TRIM(' . $a . '.process_id)) NOT IN (' . dcSqlQuotedGroupPayrollProcessCodes() . ') ';
}

/**
 * Data Capture / submitted-process picker: subsidiaries may use SALARY/BONUS defined on that company.
 * Group-entity rows (AP/IG) still exclude them from company-scope picker (use group-only mode).
 */
function dcSqlDataCaptureCompanyProcessFilter(PDO $pdo, int $companyId, string $processAlias = 'p'): string
{
    if ($companyId > 0 && !dcCompanyIdIsGroupEntity($pdo, $companyId)) {
        return '';
    }
    return dcSqlCompanyProcessFilter($processAlias);
}

/** Subsidiary company scope may capture SALARY/BONUS; group-entity company scope may not. */
function dcCompanyScopeAllowsSalaryBonusProcess(PDO $pdo, int $companyId): bool
{
    return $companyId > 0 && !dcCompanyIdIsGroupEntity($pdo, $companyId);
}

/**
 * @param array<string, mixed> $params GET/POST merged params
 * @return array{company_id: int, group_id: string, report_scope_hint: string, is_group_scope: bool, request_params: array<string, mixed>}
 */
function resolveDataCaptureRequestScope(PDO $pdo, array $params): array
{
    $resolved = resolveReportRequestCompanyScope($pdo, $params, 'maintenance');
    $scopeHint = strtolower(trim((string) ($params['report_scope'] ?? $params['capture_scope'] ?? '')));
    $isGroupScope = dcIsGroupScopeHint($resolved);
    // UI report_scope wins over dcIsGroupScopeHint (e.g. subsidiary must not use group SALARY filter).
    if ($scopeHint === 'company') {
        $isGroupScope = false;
        $resolved['report_scope_hint'] = 'company';
    } elseif ($scopeHint === 'group') {
        $isGroupScope = true;
        $resolved['report_scope_hint'] = 'group';
    } elseif (($resolved['report_scope_hint'] ?? '') !== 'group' && $isGroupScope) {
        $resolved['report_scope_hint'] = 'group';
    }

    $companyId = (int) $resolved['company_id'];
    $groupId = dcNormalizeGroupId(
        $params['view_group'] ?? $params['group_id'] ?? ($resolved['group_id'] ?? '')
    );
    // Legacy only: dual-tenant capture resolves group ledger in dcFinalizeDualTenantCaptureScope.
    if (
        $isGroupScope
        && $groupId !== ''
        && !tenant_table_has_scope_columns($pdo, 'data_captures')
    ) {
        $entityCompanyId = dcResolveGroupCaptureCompanyId($pdo, $groupId);
        if ($entityCompanyId > 0) {
            $companyId = $entityCompanyId;
        }
    }

    return [
        'company_id' => $companyId,
        'group_id' => (string) ($resolved['group_id'] ?? ''),
        'report_scope_hint' => (string) ($resolved['report_scope_hint'] ?? ''),
        'is_group_scope' => $isGroupScope,
        'request_params' => $resolved['request_params'] ?? $params,
    ];
}

/**
 * Dual-tenant capture scope (align with Transaction: group → groups.id, company → company.id).
 *
 * @param array<string, mixed> $params
 * @return array{
 *   company_id: int,
 *   anchor_company_id: int,
 *   is_group_scope: bool,
 *   scope_type: string,
 *   scope_id: int,
 *   group_id: string,
 *   group_scope_id: int,
 *   scope_process_sql: string,
 *   scope_company_sql: string,
 *   scope_company_sql_deleted: string,
 *   dual_tenant: bool,
 *   submitted_dual_tenant: bool
 * }
 */
function dcFinalizeDualTenantCaptureScope(PDO $pdo, array $scopeResolved, array $params): array
{
    $isGroupScope = (bool) ($scopeResolved['is_group_scope'] ?? false);
    $groupId = dcNormalizeGroupId(
        $params['view_group'] ?? $params['group_id'] ?? ($scopeResolved['group_id'] ?? '')
    );
    $dualTenant = tenant_table_has_scope_columns($pdo, 'data_captures');
    $submittedDualTenant = dcSubmittedProcessesDualTenantEnabled($pdo);
    $companyId = (int) ($scopeResolved['company_id'] ?? 0);

    if ($isGroupScope) {
        if ($groupId === '') {
            throw new Exception('缺少 group_id');
        }
        $groupPk = gc_resolve_group_pk_by_code($pdo, $groupId);
        $anchorId = gc_resolve_group_anchor_company_id($pdo, $groupId);

        if ($dualTenant) {
            if ($groupPk <= 0) {
                throw new Exception('无效的 group_id');
            }
            if ($anchorId <= 0) {
                throw new Exception('缺少公司信息');
            }

            return [
                'company_id' => $anchorId,
                'anchor_company_id' => $anchorId,
                'is_group_scope' => true,
                'scope_type' => 'group',
                'scope_id' => $groupPk,
                'group_id' => $groupId,
                'group_scope_id' => $groupPk,
                'scope_process_sql' => dcSqlGroupProcessFilter('p'),
                'scope_company_sql' => '',
                'scope_company_sql_deleted' => '',
                'dual_tenant' => true,
                'submitted_dual_tenant' => $submittedDualTenant,
            ];
        }

        if ($anchorId > 0) {
            $companyId = $anchorId;
        } else {
            $entityId = dcResolveGroupCaptureCompanyId($pdo, $groupId);
            if ($entityId > 0) {
                $companyId = $entityId;
            }
        }
        $scopeCompanySql = '';
        if ($companyId > 0 && dcCompanyIdIsGroupEntity($pdo, $companyId)) {
            $scopeCompanySql = dcSqlCaptureOnGroupEntityCompany('dc');
        }

        return [
            'company_id' => $companyId,
            'anchor_company_id' => $companyId,
            'is_group_scope' => true,
            'scope_type' => 'group',
            'scope_id' => $groupPk,
            'group_id' => $groupId,
            'group_scope_id' => $groupPk,
            'scope_process_sql' => dcSqlGroupProcessFilter('p'),
            'scope_company_sql' => $scopeCompanySql,
            'scope_company_sql_deleted' => $scopeCompanySql === ''
                ? ''
                : dcSqlCaptureOnGroupEntityCompany('dcd'),
            'dual_tenant' => false,
            'submitted_dual_tenant' => false,
        ];
    }

    if ($dualTenant) {
        return [
            'company_id' => $companyId,
            'anchor_company_id' => $companyId,
            'is_group_scope' => false,
            'scope_type' => 'company',
            'scope_id' => $companyId,
            'group_id' => $groupId,
            'group_scope_id' => $groupId !== '' ? gc_resolve_group_pk_by_code($pdo, $groupId) : 0,
            'scope_process_sql' => dcSqlDataCaptureCompanyProcessFilter($pdo, $companyId, 'p'),
            'scope_company_sql' => '',
            'scope_company_sql_deleted' => '',
            'dual_tenant' => true,
            'submitted_dual_tenant' => $submittedDualTenant,
        ];
    }

    return [
        'company_id' => $companyId,
        'anchor_company_id' => $companyId,
        'is_group_scope' => false,
        'scope_type' => 'company',
        'scope_id' => $companyId,
        'group_id' => $groupId,
        'group_scope_id' => $groupId !== '' ? gc_resolve_group_pk_by_code($pdo, $groupId) : 0,
        'scope_process_sql' => dcSqlCompanyProcessFilter('p'),
        'scope_company_sql' => dcSqlCaptureOnSubsidiaryCompany('dc'),
        'scope_company_sql_deleted' => dcSqlCaptureOnSubsidiaryCompany('dcd'),
        'dual_tenant' => false,
        'submitted_dual_tenant' => false,
    ];
}

/** Process.company_id for joins (anchor under group ledger). */
function dcCaptureProcessCompanyId(array $scopeCtx): int
{
    return (int) ($scopeCtx['anchor_company_id'] ?? $scopeCtx['company_id'] ?? 0);
}

/** Bind values for a ledger SQL fragment (empty when placeholders are inlined). */
function dcCaptureLedgerBindParams(array $ledger): array
{
    if (isset($ledger['params']) && is_array($ledger['params'])) {
        return $ledger['params'];
    }
    $sql = (string) ($ledger['sql'] ?? '');
    if (strpos($sql, '?') === false) {
        return [];
    }

    return [(int) ($ledger['bind'] ?? 0)];
}

/** Bind value for ledger scope filter on a table alias. */
function dcCaptureLedgerBindId(array $scopeCtx): int
{
    if (!empty($scopeCtx['is_group_scope']) && !empty($scopeCtx['dual_tenant'])) {
        return (int) ($scopeCtx['group_scope_id'] ?? $scopeCtx['scope_id'] ?? 0);
    }

    return (int) ($scopeCtx['company_id'] ?? 0);
}

/**
 * SQL fragment + metadata for data_captures / submitted_processes ledger isolation.
 *
 * @return array{sql: string, bind: int, uses_dual_tenant: bool}
 */
function dcBuildCaptureLedgerFilter(PDO $pdo, array $scopeCtx, string $alias, string $table = 'data_captures'): array
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'dc';
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: 'data_captures';
    $hasScope = tenant_table_has_scope_columns($pdo, $tableName);

    if (($scopeCtx['dual_tenant'] ?? false) && $hasScope) {
        if (!empty($scopeCtx['is_group_scope'])) {
            $groupPk = (int) ($scopeCtx['group_scope_id'] ?? $scopeCtx['scope_id'] ?? 0);

            return [
                'sql' => " AND {$a}.scope_type = 'group' AND {$a}.scope_id = ? ",
                'bind' => $groupPk,
                'params' => [$groupPk],
                'uses_dual_tenant' => true,
            ];
        }

        $companyId = (int) ($scopeCtx['company_id'] ?? 0);

        return [
            'sql' => " AND {$a}.company_id = ? AND (COALESCE({$a}.scope_type, '') = '' OR {$a}.scope_type = 'company') ",
            'bind' => $companyId,
            'params' => [$companyId],
            'uses_dual_tenant' => true,
        ];
    }

    if (!empty($scopeCtx['is_group_scope'])) {
        $legacySql = (string) ($scopeCtx['scope_company_sql'] ?? '');
        if ($legacySql === '' && $tableName === 'data_captures') {
            $companyId = (int) ($scopeCtx['company_id'] ?? 0);
            if ($companyId > 0 && dcCompanyIdIsGroupEntity($pdo, $companyId)) {
                $legacySql = dcSqlCaptureOnGroupEntityCompany($a);
            }
        }

        $bind = (int) ($scopeCtx['company_id'] ?? 0);

        return [
            'sql' => " AND {$a}.company_id = ? {$legacySql} ",
            'bind' => $bind,
            'params' => [$bind],
            'uses_dual_tenant' => false,
        ];
    }

    $legacySql = (string) ($scopeCtx['scope_company_sql'] ?? '');
    if ($legacySql === '' && $tableName === 'data_captures') {
        $legacySql = dcSqlCaptureOnSubsidiaryCompany($a);
    }

    $bind = (int) ($scopeCtx['company_id'] ?? 0);

    return [
        'sql' => " AND {$a}.company_id = ? {$legacySql} ",
        'bind' => $bind,
        'params' => [$bind],
        'uses_dual_tenant' => false,
    ];
}

/** Whether submitted_processes supports scope_type / scope_id (live check, not cached). */
function dcSubmittedProcessesDualTenantEnabled(PDO $pdo): bool
{
    dcEnsureSubmittedProcessesScopeColumns($pdo);
    try {
        return $pdo->query("SHOW COLUMNS FROM submitted_processes LIKE 'scope_type'")->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Ensure submitted_processes has dual-tenant scope columns (idempotent). */
function dcEnsureSubmittedProcessesScopeColumns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        if ($pdo->query("SHOW COLUMNS FROM submitted_processes LIKE 'scope_type'")->rowCount() > 0) {
            $ensured = true;
            return;
        }
        $pdo->exec("
            ALTER TABLE submitted_processes
              ADD COLUMN scope_type ENUM('company','group') NOT NULL DEFAULT 'company' AFTER company_id,
              ADD COLUMN scope_id BIGINT UNSIGNED NULL AFTER scope_type,
              ADD KEY idx_sp_scope_date (scope_type, scope_id, capture_date)
        ");
        $pdo->exec("
            UPDATE submitted_processes
            SET scope_type = 'company', scope_id = company_id
            WHERE scope_id IS NULL AND company_id IS NOT NULL
        ");
    } catch (Throwable $e) {
        error_log('dcEnsureSubmittedProcessesScopeColumns: ' . $e->getMessage());
        return;
    }
    $ensured = true;
}

/** Values for INSERT into scope-aware capture / submitted tables. */
function dcCaptureScopeInsertValues(array $scopeCtx): array
{
    $companyId = (int) ($scopeCtx['company_id'] ?? 0);
    if (!empty($scopeCtx['dual_tenant'])) {
        if (!empty($scopeCtx['is_group_scope'])) {
            return [
                'company_id' => $companyId,
                'scope_type' => 'group',
                'scope_id' => (int) ($scopeCtx['group_scope_id'] ?? $scopeCtx['scope_id'] ?? 0),
            ];
        }

        return [
            'company_id' => $companyId,
            'scope_type' => 'company',
            'scope_id' => $companyId,
        ];
    }

    return [
        'company_id' => $companyId,
        'scope_type' => null,
        'scope_id' => null,
    ];
}

/** Submitted-process / picker queries: isolate group-entity vs subsidiary capture rows. */
function dcSqlSubmittedCaptureScopeCompany(
    PDO $pdo,
    bool $isGroupScope,
    int $companyId,
    string $alias = 'sp'
): string {
    if ($isGroupScope) {
        if ($companyId > 0 && dcCompanyIdIsGroupEntity($pdo, $companyId)) {
            return dcSqlCaptureOnGroupEntityCompany($alias);
        }
        return '';
    }
    return dcSqlCaptureOnSubsidiaryCompany($alias);
}

function dcRequestHasExplicitScope(array $params): bool
{
    $scopeHint = strtolower(trim((string) ($params['report_scope'] ?? $params['capture_scope'] ?? '')));
    if (in_array($scopeHint, ['group', 'company', 'aggregate'], true)) {
        return true;
    }
    if (isset($params['company_id']) && trim((string) $params['company_id']) !== '') {
        return true;
    }
    $groupId = dcNormalizeGroupId($params['group_id'] ?? '');
    if ($groupId !== '' && trim((string) ($params['company_id'] ?? '')) === '') {
        return true;
    }
    return false;
}

/**
 * Resolve process.id for SALARY/BONUS under scoped company.
 */
function dcResolveProcessIdByCode(PDO $pdo, int $companyId, string $processCode, bool $groupScope): ?int
{
    $code = strtoupper(trim($processCode));
    if ($code === '') {
        return null;
    }
    if ($groupScope && !dcIsGroupPayrollProcessCode($code)) {
        return null;
    }
    if (
        !$groupScope
        && dcIsGroupPayrollProcessCode($code)
        && !dcCompanyScopeAllowsSalaryBonusProcess($pdo, $companyId)
    ) {
        return null;
    }
    $sql = 'SELECT id FROM process WHERE company_id = ? AND UPPER(TRIM(process_id)) = ?';
    if ($groupScope) {
        $sql .= ' AND UPPER(TRIM(process_id)) IN (' . dcSqlQuotedGroupPayrollProcessCodes() . ')';
    } elseif (!dcCompanyScopeAllowsSalaryBonusProcess($pdo, $companyId)) {
        $sql .= ' AND UPPER(TRIM(process_id)) NOT IN (' . dcSqlQuotedGroupPayrollProcessCodes() . ')';
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$companyId, $code]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

function dcCompanyGroupId(PDO $pdo, int $companyId): string
{
    $stmt = $pdo->prepare('SELECT UPPER(TRIM(COALESCE(group_id, ""))) FROM company WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    return dcNormalizeGroupId((string) ($stmt->fetchColumn() ?: ''));
}

function dcFirstCurrencyIdForCompany(PDO $pdo, int $companyId): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM currency WHERE company_id = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$companyId]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

function dcFirstCurrencyIdInGroup(PDO $pdo, string $groupId): ?int
{
    $g = dcNormalizeGroupId($groupId);
    if ($g === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT cur.id
        FROM currency cur
        INNER JOIN company c ON c.id = cur.company_id
        WHERE UPPER(TRIM(COALESCE(c.group_id, ''))) = ?
        ORDER BY cur.id ASC
        LIMIT 1
    ");
    $stmt->execute([$g]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

/**
 * @return array<string, mixed>|null
 */
function dcFindSiblingGroupProcessRow(PDO $pdo, string $groupId, string $processCode): ?array
{
    $g = dcNormalizeGroupId($groupId);
    $code = strtoupper(trim($processCode));
    if ($g === '' || $code === '') {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT p.id, p.currency_id, p.description_id, p.remove_word, p.replace_word_from,
               p.replace_word_to, p.remark, p.company_id AS source_company_id
        FROM process p
        INNER JOIN company c ON c.id = p.company_id
        WHERE UPPER(TRIM(COALESCE(c.group_id, ''))) = ?
          AND TRIM(COALESCE(c.company_id, '')) <> ''
          AND UPPER(TRIM(c.company_id)) <> ?
          AND UPPER(TRIM(p.process_id)) = ?
          AND p.status = 'active'
        ORDER BY p.id ASC
        LIMIT 1
    ");
    $stmt->execute([$g, $g, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Active (preferred) or any SALARY/BONUS row on the group entity company.
 *
 * @return array<string, mixed>|null
 */
function dcFindGroupEntityProcessRow(PDO $pdo, int $entityCompanyId, string $processCode): ?array
{
    $code = strtoupper(trim($processCode));
    if ($entityCompanyId <= 0 || !dcIsGroupPayrollProcessCode($code)) {
        return null;
    }
    $stmt = $pdo->prepare("
        SELECT p.id, p.currency_id, p.description_id, p.remove_word, p.replace_word_from,
               p.replace_word_to, p.remark, p.company_id AS source_company_id
        FROM process p
        WHERE p.company_id = ?
          AND UPPER(TRIM(p.process_id)) = ?
        ORDER BY CASE WHEN p.status = 'active' THEN 0 ELSE 1 END, p.id ASC
        LIMIT 1
    ");
    $stmt->execute([$entityCompanyId, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dcSetGroupProcessEnsureError(string $message): void
{
    $GLOBALS['dc_group_process_ensure_error'] = $message;
}

function dcGroupProcessEnsureLastError(): string
{
    return (string) ($GLOBALS['dc_group_process_ensure_error'] ?? '');
}

/**
 * Currency from the capture form must belong to the group entity or a subsidiary in the group.
 */
function dcValidatePreferredCurrencyId(
    PDO $pdo,
    int $currencyId,
    int $entityCompanyId,
    string $groupId
): bool {
    if ($currencyId <= 0 || $entityCompanyId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT company_id FROM currency WHERE id = ? LIMIT 1');
    $stmt->execute([$currencyId]);
    $curCompanyId = (int) ($stmt->fetchColumn() ?: 0);
    if ($curCompanyId <= 0) {
        return false;
    }
    if ($curCompanyId === $entityCompanyId) {
        return true;
    }
    $g = dcNormalizeGroupId($groupId);
    if ($g === '') {
        return false;
    }
    $grpStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM company c
        WHERE c.id = ?
          AND UPPER(TRIM(COALESCE(c.group_id, ''))) = ?
    ");
    $grpStmt->execute([$curCompanyId, $g]);
    return (int) $grpStmt->fetchColumn() > 0;
}

/**
 * Pick template row for auto-create: same code on subsidiary, else SALARY on entity/subsidiary for BONUS.
 *
 * @return array<string, mixed>|null
 */
function dcResolveGroupProcessTemplateRow(
    PDO $pdo,
    int $entityCompanyId,
    string $groupId,
    string $processCode
): ?array {
    $code = strtoupper(trim($processCode));
    $g = dcNormalizeGroupId($groupId);

    $template = $g !== '' ? dcFindSiblingGroupProcessRow($pdo, $g, $code) : null;
    if ($template !== null) {
        return $template;
    }

    if ($code === 'BONUS' || $code === 'COMMISSION') {
        $template = dcFindGroupEntityProcessRow($pdo, $entityCompanyId, 'SALARY');
        if ($template !== null) {
            return $template;
        }
        if ($g !== '') {
            return dcFindSiblingGroupProcessRow($pdo, $g, 'SALARY');
        }
    }

    return null;
}

/**
 * @return array{created_by: ?int, created_by_type: string, created_by_owner_id: ?int}
 */
function dcCaptureCreatedByFields(): array
{
    if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
        return [
            'created_by' => null,
            'created_by_type' => 'owner',
            'created_by_owner_id' => isset($_SESSION['owner_id']) ? (int) $_SESSION['owner_id'] : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null),
        ];
    }
    $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    return [
        'created_by' => $uid,
        'created_by_type' => 'user',
        'created_by_owner_id' => null,
    ];
}

function dcAllDayIds(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id FROM day ORDER BY id ASC');
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function dcDayIdsForProcess(PDO $pdo, int $processId): array
{
    $stmt = $pdo->prepare('SELECT day_id FROM process_day WHERE process_id = ? ORDER BY day_id ASC');
    $stmt->execute([$processId]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) ($row['day_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function dcInsertProcessDays(PDO $pdo, int $processId, array $dayIds): void
{
    if ($processId <= 0 || empty($dayIds)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO process_day (process_id, day_id) VALUES (?, ?)');
    foreach ($dayIds as $dayId) {
        $stmt->execute([$processId, (int) $dayId]);
    }
}

/**
 * SQL expression: group SALARY/BONUS use process code as product label (not shared description name).
 */
function dcSqlCaptureProductLabel(string $processAlias = 'p', string $descriptionAlias = 'd'): string
{
    $p = preg_replace('/[^a-zA-Z0-9_]/', '', $processAlias) ?: 'p';
    $d = preg_replace('/[^a-zA-Z0-9_]/', '', $descriptionAlias) ?: 'd';
    return 'CASE WHEN UPPER(TRIM(' . $p . '.process_id)) IN (' . dcSqlQuotedGroupPayrollProcessCodes() . ') '
        . 'THEN UPPER(TRIM(' . $p . '.process_id)) ELSE COALESCE(' . $d . '.name, ' . $p . '.process_id) END';
}

function dcRemapTemplateProductFieldsForTargetCode(array $templateRow, string $targetProcessCode): array
{
    $target = strtoupper(trim($targetProcessCode));
    if ($target === '') {
        return $templateRow;
    }
    $payrollCodes = dcGroupPayrollProcessCodes();
    $remap = static function ($value) use ($target, $payrollCodes) {
        if ($value === null || $value === '') {
            return $value;
        }
        $v = strtoupper(trim((string) $value));
        if (in_array($v, $payrollCodes, true) && in_array($target, $payrollCodes, true)) {
            return $target;
        }
        return $value;
    };

    $templateRow['id_product'] = $remap($templateRow['id_product'] ?? '');
    $templateRow['parent_id_product'] = $remap($templateRow['parent_id_product'] ?? null);
    $key = trim((string) ($templateRow['template_key'] ?? ''));
    if ($key !== '') {
        $upper = strtoupper($key);
        if (in_array($upper, $payrollCodes, true) && in_array($target, $payrollCodes, true)) {
            $templateRow['template_key'] = $target;
        }
    }
    return $templateRow;
}

function dcCopyTemplatesToProcess(
    PDO $pdo,
    int $companyId,
    int $targetProcessId,
    int $sourceProcessId,
    ?string $targetProcessCode = null
): void {
    if ($targetProcessId <= 0 || $sourceProcessId <= 0) {
        return;
    }
    if ($targetProcessCode === null || $targetProcessCode === '') {
        $codeStmt = $pdo->prepare('SELECT UPPER(TRIM(process_id)) FROM process WHERE id = ? LIMIT 1');
        $codeStmt->execute([$targetProcessId]);
        $targetProcessCode = (string) ($codeStmt->fetchColumn() ?: '');
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM data_capture_templates WHERE process_id = ? AND company_id = ?');
    $stmt->execute([$targetProcessId, $companyId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $src = $pdo->prepare('SELECT * FROM data_capture_templates WHERE process_id = ? LIMIT 500');
    $src->execute([$sourceProcessId]);
    $templates = $src->fetchAll(PDO::FETCH_ASSOC);
    if (empty($templates)) {
        return;
    }
    $sql = 'INSERT INTO data_capture_templates (
        company_id, process_id, data_capture_id, row_index, sub_order,
        id_product, product_type, formula_variant, parent_id_product,
        template_key, description, account_id, account_display, currency_id, currency_display,
        source_columns, formula_operators, source_percent, enable_source_percent,
        input_method, enable_input_method, batch_selection, columns_display, formula_display,
        last_source_value, last_processed_amount, updated_at, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
    $ins = $pdo->prepare($sql);
    foreach ($templates as $t) {
        $t = dcRemapTemplateProductFieldsForTargetCode($t, (string) $targetProcessCode);
        try {
            $ins->execute([
                $companyId,
                $targetProcessId,
                $t['data_capture_id'] ?? null,
                $t['row_index'] ?? null,
                isset($t['sub_order']) && $t['sub_order'] !== '' ? $t['sub_order'] : null,
                $t['id_product'] ?? '',
                $t['product_type'] ?? 'main',
                isset($t['formula_variant']) ? (int) $t['formula_variant'] : 1,
                $t['parent_id_product'] ?? null,
                $t['template_key'] ?? '',
                $t['description'] ?? null,
                $t['account_id'] ?? 0,
                $t['account_display'] ?? null,
                $t['currency_id'] ?? null,
                $t['currency_display'] ?? null,
                $t['source_columns'] ?? null,
                $t['formula_operators'] ?? null,
                isset($t['source_percent']) && $t['source_percent'] !== '' ? $t['source_percent'] : '1',
                isset($t['enable_source_percent']) ? (int) $t['enable_source_percent'] : 1,
                $t['input_method'] ?? null,
                isset($t['enable_input_method']) ? (int) $t['enable_input_method'] : 0,
                $t['batch_selection'] ?? null,
                $t['columns_display'] ?? null,
                $t['formula_display'] ?? null,
                $t['last_source_value'] ?? null,
                $t['last_processed_amount'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log('dcCopyTemplatesToProcess: ' . $e->getMessage());
        }
    }
}

/**
 * Create SALARY/BONUS on group entity when missing.
 * Uses form currency when provided; clones days/templates from subsidiary or entity SALARY.
 */
function dcCreateGroupProcessByCode(
    PDO $pdo,
    int $companyId,
    string $processCode,
    ?string $groupId = null,
    ?int $preferredCurrencyId = null
): ?int {
    dcSetGroupProcessEnsureError('');

    $code = strtoupper(trim($processCode));
    if (!dcIsGroupPayrollProcessCode($code)) {
        return null;
    }

    $g = dcNormalizeGroupId($groupId ?? '');
    if ($g === '') {
        $g = dcCompanyGroupId($pdo, $companyId);
    }

    $template = dcResolveGroupProcessTemplateRow($pdo, $companyId, $g, $code);

    $currencyId = 0;
    if ($preferredCurrencyId !== null && $preferredCurrencyId > 0
        && dcValidatePreferredCurrencyId($pdo, $preferredCurrencyId, $companyId, $g)) {
        $currencyId = $preferredCurrencyId;
    }
    if ($currencyId <= 0 && $template !== null) {
        $currencyId = (int) ($template['currency_id'] ?? 0);
    }
    if ($currencyId <= 0) {
        $currencyId = (int) (dcFirstCurrencyIdForCompany($pdo, $companyId) ?? 0);
    }
    if ($currencyId <= 0 && $g !== '') {
        $currencyId = (int) (dcFirstCurrencyIdInGroup($pdo, $g) ?? 0);
    }
    if ($currencyId <= 0) {
        dcSetGroupProcessEnsureError(
            'Cannot create process: select a currency or add a currency for the group entity first'
        );
        return null;
    }

    $created = dcCaptureCreatedByFields();
    $templateDescriptionId = isset($template['description_id']) && $template['description_id'] !== ''
        ? (int) $template['description_id']
        : null;
    if ($templateDescriptionId !== null && $templateDescriptionId <= 0) {
        $templateDescriptionId = null;
    }
    $descriptionId = dcResolveProcessDescriptionId($pdo, $companyId, $code, $templateDescriptionId);
    if ($descriptionId === null || $descriptionId <= 0) {
        dcSetGroupProcessEnsureError('Cannot create process: unable to resolve description for scope');
        return null;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO process (
                process_id, description_id, currency_id, remove_word, replace_word_from, replace_word_to, remark,
                created_by, created_by_type, created_by_owner_id, dts_created, company_id, sync_source_process_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $code,
            $descriptionId,
            $currencyId,
            $template['remove_word'] ?? null,
            $template['replace_word_from'] ?? null,
            $template['replace_word_to'] ?? null,
            $template['remark'] ?? null,
            $created['created_by'],
            $created['created_by_type'],
            $created['created_by_owner_id'],
            date('Y-m-d H:i:s'),
            $companyId,
            isset($template['id']) ? (int) $template['id'] : null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        if ($newId <= 0) {
            $pdo->rollBack();
            dcSetGroupProcessEnsureError('Cannot create process for group scope');
            return null;
        }

        $dayIds = [];
        if (!empty($template['id'])) {
            $dayIds = dcDayIdsForProcess($pdo, (int) $template['id']);
        }
        if (empty($dayIds)) {
            $dayIds = dcAllDayIds($pdo);
        }
        dcInsertProcessDays($pdo, $newId, $dayIds);

        if (!empty($template['id'])) {
            dcCopyTemplatesToProcess($pdo, $companyId, $newId, (int) $template['id'], $code);
        }

        $pdo->commit();
        return $newId;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('dcCreateGroupProcessByCode: ' . $e->getMessage());
        dcSetGroupProcessEnsureError('Cannot create process for group scope');
        return null;
    }
}

/**
 * Find or create description row for company (process.description_id is NOT NULL + FK).
 */
function dcEnsureDescriptionIdForCompany(PDO $pdo, int $companyId, string $name): ?int
{
    $label = trim($name);
    if ($companyId <= 0 || $label === '') {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT id FROM description
        WHERE company_id = ? AND UPPER(TRIM(name)) = UPPER(TRIM(?))
        ORDER BY id ASC
        LIMIT 1
    ');
    $stmt->execute([$companyId, $label]);
    $existing = (int) ($stmt->fetchColumn() ?: 0);
    if ($existing > 0) {
        return $existing;
    }
    $ins = $pdo->prepare('INSERT INTO description (name, company_id) VALUES (?, ?)');
    $ins->execute([$label, $companyId]);
    $newId = (int) $pdo->lastInsertId();
    return $newId > 0 ? $newId : null;
}

/**
 * Use template description only when it exists for this company; otherwise create by process code.
 */
function dcResolveProcessDescriptionId(
    PDO $pdo,
    int $companyId,
    string $processCode,
    ?int $templateDescriptionId
): ?int {
    $code = strtoupper(trim($processCode));
    if ($companyId <= 0 || $code === '') {
        return null;
    }
    if (dcIsGroupPayrollProcessCode($code)) {
        return dcEnsureDescriptionIdForCompany($pdo, $companyId, $code);
    }
    if ($templateDescriptionId !== null && $templateDescriptionId > 0) {
        $chk = $pdo->prepare('SELECT id FROM description WHERE id = ? AND company_id = ? LIMIT 1');
        $chk->execute([$templateDescriptionId, $companyId]);
        if ((int) ($chk->fetchColumn() ?: 0) > 0) {
            return $templateDescriptionId;
        }
    }
    return dcEnsureDescriptionIdForCompany($pdo, $companyId, $code);
}

/**
 * Point group SALARY/BONUS at a description named like the process code (not shared "SALARY" on BONUS).
 */
function dcFixGroupPayrollProcessDescription(PDO $pdo, int $processId): void
{
    if ($processId <= 0) {
        return;
    }
    $stmt = $pdo->prepare('
        SELECT p.company_id,
               UPPER(TRIM(p.process_id)) AS process_code,
               UPPER(TRIM(COALESCE(d.name, ""))) AS description_name
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        WHERE p.id = ?
        LIMIT 1
    ');
    $stmt->execute([$processId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $companyId = (int) ($row['company_id'] ?? 0);
    $code = strtoupper(trim((string) ($row['process_code'] ?? '')));
    if ($companyId <= 0 || !dcIsGroupPayrollProcessCode($code)) {
        return;
    }
    $descName = strtoupper(trim((string) ($row['description_name'] ?? '')));
    if ($descName === $code) {
        return;
    }
    $newDescId = dcEnsureDescriptionIdForCompany($pdo, $companyId, $code);
    if ($newDescId === null || $newDescId <= 0) {
        return;
    }
    $modifier = resolveProcessModifierFromSession($pdo);
    $upd = $pdo->prepare(
        'UPDATE process SET description_id = ?'
        . processModifiedBySqlSuffix()
        . ' WHERE id = ?'
    );
    $upd->execute(array_merge(
        [$newDescId],
        processModifiedByBindParams($modifier),
        [$processId]
    ));
}

/**
 * Resolve process.id; auto-create group payroll codes when missing on group ledger or
 * subsidiary company scope (C168 / bank-only e.g. CX).
 */
function dcEnsureProcessIdByCode(
    PDO $pdo,
    int $companyId,
    string $processCode,
    bool $groupScope,
    ?string $groupId = null,
    ?int $preferredCurrencyId = null
): ?int {
    dcSetGroupProcessEnsureError('');

    $existing = dcResolveProcessIdByCode($pdo, $companyId, $processCode, $groupScope);
    if ($existing !== null) {
        dcFixGroupPayrollProcessDescription($pdo, $existing);
        return $existing;
    }

    $code = strtoupper(trim($processCode));
    $mayAutoCreate = $groupScope
        || (dcIsGroupPayrollProcessCode($code) && dcCompanyScopeAllowsSalaryBonusProcess($pdo, $companyId));
    if (!$mayAutoCreate) {
        dcSetGroupProcessEnsureError('Process not found for scope');
        return null;
    }

    return dcCreateGroupProcessByCode($pdo, $companyId, $processCode, $groupId, $preferredCurrencyId);
}

/**
 * Ensure process.id belongs to company scope (group = SALARY/BONUS only).
 */
/**
 * Verify user may access company_id (including group entity when mapped to a subsidiary in that group).
 *
 * @throws Exception
 */
function dcAssertUserCanAccessCompany(PDO $pdo, int $companyId, ?string $viewGroup = null): void
{
    if ($companyId <= 0) {
        throw new Exception('缺少公司信息');
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new Exception('用户未登录');
    }

    $vg = dcNormalizeGroupId($viewGroup ?? '');
    $sessionCompanyId = isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : 0;
    if ($sessionCompanyId > 0 && $sessionCompanyId === $companyId) {
        return;
    }

    if (gc_is_group_login()) {
        if (gc_session_can_access_company_id($pdo, $companyId, $vg !== '' ? $vg : null)) {
            return;
        }
        throw new Exception('无权限访问该公司');
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
        throw new Exception('无权限访问该公司');
    }

    if ($userType === 'member') {
        $memberId = function_exists('member_session_canonical_account_id')
            ? member_session_canonical_account_id()
            : $userId;
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM account_company ac
            WHERE ac.account_id = ? AND ac.company_id = ?
        ');
        $stmt->execute([$memberId, $companyId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        if ($vg !== '' && gc_session_can_access_company_id($pdo, $companyId, $vg)) {
            return;
        }
        throw new Exception('无权限访问该公司');
    }

    $mapStmt = $pdo->prepare('SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?');
    $mapStmt->execute([$userId, $companyId]);
    if ((int) $mapStmt->fetchColumn() > 0) {
        return;
    }

    if ($vg !== '') {
        $entityId = tx_resolve_group_entity_company_id($pdo, $vg);
        if ($entityId > 0 && $companyId === $entityId) {
            $grpStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM user_company_map ucm
                INNER JOIN company c ON c.id = ucm.company_id
                WHERE ucm.user_id = ?
                  AND UPPER(TRIM(COALESCE(c.group_id, ''))) = ?
            ");
            $grpStmt->execute([$userId, $vg]);
            if ((int) $grpStmt->fetchColumn() > 0) {
                return;
            }
        }
        if (gc_session_can_access_company_id($pdo, $companyId, $vg)) {
            return;
        }
    }

    $ownerFallback = $pdo->prepare('SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?');
    $ownerFallback->execute([$companyId, $userId]);
    if ((int) $ownerFallback->fetchColumn() > 0) {
        return;
    }

    throw new Exception('无权限访问该公司');
}

function dcAssertProcessIdInCaptureScope(PDO $pdo, int $processId, int $companyId, bool $groupScope): void
{
    if ($processId <= 0 || $companyId <= 0) {
        throw new Exception('Invalid process for scope');
    }
    $stmt = $pdo->prepare('SELECT UPPER(TRIM(process_id)) FROM process WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$processId, $companyId]);
    $code = strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
    if ($code === '') {
        throw new Exception('Process not found for scope');
    }
    if ($groupScope && !dcIsGroupPayrollProcessCode($code)) {
        throw new Exception('Invalid process for group scope');
    }
    if (
        !$groupScope
        && dcIsGroupPayrollProcessCode($code)
        && !dcCompanyScopeAllowsSalaryBonusProcess($pdo, $companyId)
    ) {
        throw new Exception('Invalid process for company scope');
    }
}

/**
 * Numeric company.id used for group capture / maintenance when SALARY/BONUS apply.
 * Prefer legacy entity row (company_id = group code); else first subsidiary anchor (e.g. C168 under AP).
 */
function dcResolveGroupCaptureCompanyId(PDO $pdo, string $groupCode): int
{
    $g = dcNormalizeGroupId($groupCode);
    if ($g === '') {
        return 0;
    }
    $entityId = tx_resolve_group_entity_company_id($pdo, $g);
    if ($entityId > 0) {
        return $entityId;
    }

    return gc_resolve_group_anchor_company_id($pdo, $g);
}

/** True when numeric company row is a group entity (AP/IG). */
function dcCompanyIdIsGroupEntity(PDO $pdo, int $companyId): bool
{
    if ($companyId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT 1
        FROM company c
        WHERE c.id = ?
          AND TRIM(COALESCE(c.company_id, '')) <> ''
          AND UPPER(TRIM(c.company_id)) = UPPER(TRIM(COALESCE(c.group_id, '')))
        LIMIT 1
    ");
    $stmt->execute([$companyId]);
    if ((bool) $stmt->fetchColumn()) {
        return true;
    }

    $grpStmt = $pdo->prepare('SELECT UPPER(TRIM(COALESCE(group_id, ""))) FROM company WHERE id = ? LIMIT 1');
    $grpStmt->execute([$companyId]);
    $groupId = dcNormalizeGroupId((string) ($grpStmt->fetchColumn() ?: ''));
    if ($groupId === '') {
        return false;
    }

    return tx_resolve_group_entity_company_id($pdo, $groupId) === $companyId;
}

/** SQL: capture rows on group-entity company only. */
function dcSqlCaptureOnGroupEntityCompany(string $dcAlias = 'dc'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $dcAlias) ?: 'dc';
    return " AND EXISTS (
        SELECT 1
        FROM company c_ge
        WHERE c_ge.id = {$a}.company_id
          AND TRIM(COALESCE(c_ge.company_id, '')) <> ''
          AND UPPER(TRIM(c_ge.company_id)) = UPPER(TRIM(COALESCE(c_ge.group_id, '')))
    ) ";
}

/** SQL: capture rows on subsidiary companies only (exclude group-entity rows). */
function dcSqlCaptureOnSubsidiaryCompany(string $dcAlias = 'dc'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $dcAlias) ?: 'dc';
    return " AND NOT EXISTS (
        SELECT 1
        FROM company c_ge
        WHERE c_ge.id = {$a}.company_id
          AND TRIM(COALESCE(c_ge.company_id, '')) <> ''
          AND UPPER(TRIM(c_ge.company_id)) = UPPER(TRIM(COALESCE(c_ge.group_id, '')))
    ) ";
}

/**
 * Capture Maintenance: group scope → entity company + SALARY/BONUS; company → subsidiaries only.
 *
 * @param array<string, mixed> $params
 * @return array{company_id: int, is_group_scope: bool, scope_process_sql: string, scope_company_sql: string}
 */
function dcFinalizeCaptureMaintenanceScope(PDO $pdo, array $scopeResolved, array $params): array
{
    return dcFinalizeDualTenantCaptureScope($pdo, $scopeResolved, $params);
}

/** SQL: subsidiary company ledger only (exclude group-scope account_company rows). */
function dcSqlAccountCompanySubsidiaryOnly(string $acAlias = 'ac'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $acAlias) ?: 'ac';
    global $pdo;
    if (!isset($pdo)) {
        return '';
    }
    try {
        if ($pdo->query("SHOW COLUMNS FROM account_company LIKE 'scope_type'")->rowCount() > 0) {
            return " AND (COALESCE({$a}.scope_type, '') = '' OR {$a}.scope_type = 'company')";
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    return '';
}

/** SQL: company subsidiary currencies only (exclude scope_type=group rows). */
function dcSqlCurrencyCompanyLedgerOnly(string $cAlias = 'c'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $cAlias) ?: 'c';
    global $pdo;
    if (!isset($pdo)) {
        return '';
    }
    try {
        if ($pdo->query("SHOW COLUMNS FROM currency LIKE 'scope_type'")->rowCount() > 0) {
            return " AND (COALESCE({$a}.scope_type, '') = '' OR {$a}.scope_type = 'company')";
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    return '';
}

/**
 * Summary Edit Formula: active accounts for group ledger (scope_type=group).
 *
 * @return list<array<string, mixed>>
 */
function dcSummaryLoadAccountsForGroup(PDO $pdo, string $groupCode): array
{
    $g = dcNormalizeGroupId($groupCode);
    if ($g === '') {
        return [];
    }
    require_once __DIR__ . '/../../includes/group_company_access.php';

    $groupPk = gc_resolve_group_pk_by_code($pdo, $g);
    if ($groupPk <= 0) {
        return [];
    }

    $accountIds = [];
    try {
        $hasScopeCol = $pdo->query("SHOW COLUMNS FROM account_company LIKE 'scope_type'")->rowCount() > 0;
    } catch (Throwable $e) {
        $hasScopeCol = false;
    }
    if ($hasScopeCol) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT ac.account_id
            FROM account_company ac
            WHERE ac.scope_type = 'group' AND ac.scope_id = ?
        ");
        $stmt->execute([$groupPk]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $aid = (int) $id;
            if ($aid > 0) {
                $accountIds[$aid] = true;
            }
        }
    }
    try {
        $hasMap = $pdo->query("SHOW TABLES LIKE 'account_group_map'")->rowCount() > 0;
    } catch (Throwable $e) {
        $hasMap = false;
    }
    if ($hasMap) {
        $stmt = $pdo->prepare('SELECT DISTINCT account_id FROM account_group_map WHERE group_id = ?');
        $stmt->execute([$groupPk]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $aid = (int) $id;
            if ($aid > 0) {
                $accountIds[$aid] = true;
            }
        }
    }

    $ids = array_values(array_keys($accountIds));
    if ($ids === []) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.id, a.account_id, a.role, a.name
        FROM account a
        WHERE a.id IN ($ph)
          AND a.status = 'active'
        ORDER BY a.account_id
    ");
    $stmt->execute($ids);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Summary Edit Formula: active accounts for subsidiary company scope.
 *
 * @return list<array<string, mixed>>
 */
function dcSummaryLoadAccountsForCompany(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }
    $scopeSql = dcSqlAccountCompanySubsidiaryOnly('ac');
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.id, a.account_id, a.role, a.name
        FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE ac.company_id = ?
        {$scopeSql}
          AND a.status = 'active'
        ORDER BY a.account_id
    ");
    $stmt->execute([$companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Summary form catalog accounts (group ledger vs subsidiary company).
 *
 * @return list<array<string, mixed>>
 */
function dcSummaryLoadFormAccounts(PDO $pdo, bool $isGroupScope, int $companyId, string $groupCode): array
{
    if ($isGroupScope) {
        $code = dcNormalizeGroupId($groupCode);
        if ($code === '') {
            $code = dcCompanyGroupId($pdo, $companyId);
        }
        return dcSummaryLoadAccountsForGroup($pdo, $code);
    }

    return dcSummaryLoadAccountsForCompany($pdo, $companyId);
}

/**
 * Summary form catalog currencies for group scope (group Currency Setting / ledger).
 *
 * @return list<array{id: int, code: string}>
 */
function dcSummaryLoadCurrenciesForGroup(PDO $pdo, string $groupCode): array
{
    $g = dcNormalizeGroupId($groupCode);
    if ($g === '') {
        return [];
    }
    if (!defined('DASHBOARD_API_SKIP_MAIN')) {
        define('DASHBOARD_API_SKIP_MAIN', true);
    }
    require_once __DIR__ . '/../transactions/transaction_scope.php';
    require_once __DIR__ . '/../transactions/dashboard_api.php';

    $map = dashboardResolveGroupScopeCurrencyMap($pdo, $g);
    $rows = [];
    foreach ($map as $id => $code) {
        $rows[] = ['id' => (int) $id, 'code' => strtoupper(trim((string) $code))];
    }
    usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

    return $rows;
}

/**
 * Summary form catalog currencies for company scope.
 *
 * @return list<array{id: int, code: string}>
 */
function dcSummaryLoadCurrenciesForCompany(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }
    $scopeSql = dcSqlCurrencyCompanyLedgerOnly('c');
    $stmt = $pdo->prepare("
        SELECT id, code
        FROM currency c
        WHERE c.company_id = ?
        {$scopeSql}
        ORDER BY c.code
    ");
    $stmt->execute([$companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array{id: int, code: string}>
 */
function dcSummaryLoadFormCurrencies(PDO $pdo, bool $isGroupScope, int $companyId, string $groupCode): array
{
    if ($isGroupScope) {
        $code = dcNormalizeGroupId($groupCode);
        if ($code === '') {
            $code = dcCompanyGroupId($pdo, $companyId);
        }
        return dcSummaryLoadCurrenciesForGroup($pdo, $code);
    }

    return dcSummaryLoadCurrenciesForCompany($pdo, $companyId);
}

/**
 * Resolve currency id for Summary submit (group ledger vs subsidiary company).
 */
function dcResolveCaptureCurrencyId(
    PDO $pdo,
    bool $isGroupScope,
    int $companyId,
    string $groupCode,
    $currencyId = null,
    ?string $currencyCode = null
): ?int {
    if ($isGroupScope) {
        $g = dcNormalizeGroupId($groupCode);
        if ($g === '' && $companyId > 0) {
            $g = dcCompanyGroupId($pdo, $companyId);
        }
        $cid = $currencyId !== null && $currencyId !== '' ? (int) $currencyId : 0;
        if ($cid > 0 && dcValidatePreferredCurrencyId($pdo, $cid, $companyId, $g)) {
            return $cid;
        }
        if ($currencyCode) {
            require_once __DIR__ . '/../transactions/transaction_scope.php';
            $groupPk = gc_resolve_group_pk_by_code($pdo, $g);
            if ($groupPk > 0) {
                try {
                    $scope = [
                        'mode' => 'group',
                        'company_id' => 0,
                        'group_scope_id' => $groupPk,
                        'group_code' => $g,
                    ];
                    return tx_resolve_currency_id_for_scope($pdo, (string) $currencyCode, $scope);
                } catch (Throwable $e) {
                    return null;
                }
            }
        }
        return null;
    }

    return resolveCompanyCurrencyId($pdo, $companyId, $currencyId, $currencyCode);
}

/** Whether account_id is valid for the active capture scope. */
function dcAccountBelongsToCaptureScope(
    PDO $pdo,
    int $accountId,
    bool $isGroupScope,
    int $companyId,
    string $groupCode
): bool {
    if ($accountId <= 0) {
        return false;
    }
    if ($isGroupScope) {
        $g = dcNormalizeGroupId($groupCode);
        if ($g === '') {
            $g = dcCompanyGroupId($pdo, $companyId);
        }
        require_once __DIR__ . '/../../includes/group_company_access.php';
        $groupPk = gc_resolve_group_pk_by_code($pdo, $g);
        if ($groupPk <= 0) {
            return false;
        }
        try {
            if ($pdo->query("SHOW COLUMNS FROM account_company LIKE 'scope_type'")->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT 1 FROM account_company
                    WHERE account_id = ? AND scope_type = 'group' AND scope_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$accountId, $groupPk]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            }
            if ($pdo->query("SHOW TABLES LIKE 'account_group_map'")->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT 1 FROM account_group_map
                    WHERE account_id = ? AND group_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$accountId, $groupPk]);
                if ($stmt->fetchColumn()) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    if ($companyId <= 0) {
        return false;
    }
    $scopeSql = dcSqlAccountCompanySubsidiaryOnly('ac');
    $stmt = $pdo->prepare("
        SELECT 1 FROM account_company ac
        WHERE ac.account_id = ? AND ac.company_id = ?
        {$scopeSql}
        LIMIT 1
    ");
    $stmt->execute([$accountId, $companyId]);

    return (bool) $stmt->fetchColumn();
}

function dcAssertAccountIdInCaptureScope(
    PDO $pdo,
    int $accountId,
    bool $isGroupScope,
    int $companyId,
    string $groupCode
): void {
    if (!dcAccountBelongsToCaptureScope($pdo, $accountId, $isGroupScope, $companyId, $groupCode)) {
        throw new Exception($isGroupScope
            ? 'Account not valid for group capture scope'
            : 'Account not valid for company capture scope');
    }
}
