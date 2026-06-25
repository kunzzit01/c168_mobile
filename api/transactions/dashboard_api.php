<?php
/**
 * Transaction Dashboard API
 * 用于获取 Capital、Expenses 和 Profit 的汇总数据
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';

if (!$pdo instanceof PDO) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'data' => null,
        'error' => 'Database connection failed',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/transaction_scope.php';
require_once __DIR__ . '/../reports/report_scope_common.php';
require_once __DIR__ . '/dcd_processed_quant.php';

function dashboard_ensure_tenant_scope_loaded(): void
{
    if (!function_exists('tenant_sql_currency_subsidiary_only')) {
        require_once __DIR__ . '/../../includes/tenant_scope.php';
    }
}

/** Reuse scope lookups across multi-currency bootstrap captures in one HTTP request. */
function dashboard_api_begin_bootstrap_batch(): void
{
    $GLOBALS['DASHBOARD_BOOTSTRAP_BATCH'] = true;
    $GLOBALS['DASHBOARD_BOOTSTRAP_CTX'] = ['cache' => []];
}

function dashboard_api_end_bootstrap_batch(): void
{
    unset($GLOBALS['DASHBOARD_BOOTSTRAP_BATCH'], $GLOBALS['DASHBOARD_BOOTSTRAP_CTX']);
}

/**
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function dashboard_bootstrap_cache_remember(string $key, callable $fn)
{
    if (empty($GLOBALS['DASHBOARD_BOOTSTRAP_BATCH'])) {
        return $fn();
    }
    if (!isset($GLOBALS['DASHBOARD_BOOTSTRAP_CTX']['cache'])) {
        $GLOBALS['DASHBOARD_BOOTSTRAP_CTX'] = ['cache' => []];
    }
    if (array_key_exists($key, $GLOBALS['DASHBOARD_BOOTSTRAP_CTX']['cache'])) {
        return $GLOBALS['DASHBOARD_BOOTSTRAP_CTX']['cache'][$key];
    }
    $value = $fn();
    $GLOBALS['DASHBOARD_BOOTSTRAP_CTX']['cache'][$key] = $value;

    return $value;
}

/** Skip daily GROUP BY aggregation; KPI cards only need period_total / balances. */
function dashboard_api_kpi_only(): bool
{
    return isset($_GET['kpi_only']) && (string) $_GET['kpi_only'] === '1';
}

/** Multi-currency earnings panel: skip CAPITAL + ownership (frontend merges from primary KPI). */
function dashboard_api_earnings_only(): bool
{
    return isset($_GET['earnings_only']) && (string) $_GET['earnings_only'] === '1';
}

/** SQL AND: subsidiary currency rows only (exclude group ledger on shared anchor company_id). */
function dashboard_sql_currency_subsidiary_only(PDO $pdo, string $alias = 'c'): string
{
    dashboard_ensure_tenant_scope_loaded();

    return tenant_sql_currency_subsidiary_only($pdo, $alias);
}

/** SQL AND: subsidiary account_company rows only. */
function dashboard_sql_account_company_subsidiary_only(PDO $pdo, string $alias = 'ac'): string
{
    dashboard_ensure_tenant_scope_loaded();

    return tenant_sql_account_company_subsidiary_only($pdo, $alias);
}

/** SQL AND: subsidiary transaction ledger only (reads DASHBOARD_SUBSIDIARY_LEDGER global). */
function dashboard_sql_txn_subsidiary_only(PDO $pdo, string $alias = 't'): string
{
    if (empty($GLOBALS['DASHBOARD_SUBSIDIARY_LEDGER'])) {
        return '';
    }
    if (!tx_table_has_scope_column($pdo, 'transactions')) {
        return '';
    }

    return tx_sql_transaction_company_ledger_only($alias);
}

/**
 * Contra 审批：过滤未批准的 CONTRA（向后兼容：若无字段则不过滤）
 */
function dashboardHasContraApprovalColumns(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null)
        return $has;
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'approval_status'");
    $has = $stmt->rowCount() > 0;
    return $has;
}

/**
 * 检查 transactions.currency_id 字段是否存在（static 缓存，每次请求只查一次）
 */
function dashboardHasTransactionCurrency(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null)
        return $has;
    try {
        $check = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $has = $check && $check->rowCount() > 0;
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * 检查 transaction_entry 表是否存在（static 缓存，每次请求只查一次）
 */
function dashboardHasTransactionEntry(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null)
        return $has;
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'transaction_entry'");
        $has = $check && $check->rowCount() > 0;
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * 检查 company_ownership 表及 owner_type 列是否存在（static 缓存）
 * 返回 ['table' => bool, 'owner_type_col' => bool]
 */
function dashboardCompanyOwnershipSchema(PDO $pdo): array
{
    static $schema = null;
    if ($schema !== null)
        return $schema;
    try {
        $hasTable = $pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() > 0;
        $hasCol = $hasTable && $pdo->query("SHOW COLUMNS FROM company_ownership LIKE 'owner_type'")->rowCount() > 0;
    } catch (Throwable $e) {
        $hasTable = false;
        $hasCol = false;
    }
    $schema = ['table' => $hasTable, 'owner_type_col' => $hasCol];
    return $schema;
}

/**
 * Resolve ownership snapshot month from dashboard date range end (same rule as subsidiary earnings).
 *
 * @return array{month_key:string,effective_month:string,use_history:bool}
 */
function dashboardResolveOwnershipMonthFromDate(string $dateToDisplay): array
{
    require_once __DIR__ . '/../includes/ownership_history.php';
    $monthKey = date('Y-m', strtotime($dateToDisplay));
    $parsedMonth = ownership_history_parse_month_param($monthKey);
    $useHistory = $parsedMonth !== null && ownership_history_is_past_month($parsedMonth['month_key']);

    return [
        'month_key' => $parsedMonth['month_key'] ?? ownership_history_current_month_key(),
        'effective_month' => $parsedMonth['effective_month'] ?? ownership_history_effective_month_from_now(),
        'use_history' => $useHistory,
    ];
}

/**
 * 多段 Group 链：从筛选的 view_group 反向经 group_ownership (owner_type=group) 再接到
 * company_ownership (owner_type=group)，得到进入当前 view 前的连乘比例 (0~1)。
 * 例：TT 10%→SS × SS 20%→AA = 0.02。无法解析时返回 null（改走原两段式逻辑）。
 */
function dashboardResolveEarningsPathProduct(
    PDO $pdo,
    int $companyId,
    string $viewGroupTrim,
    string $effectiveMonth = '',
    bool $useHistory = false
): ?float {
    $viewG = strtoupper(trim($viewGroupTrim));
    if ($viewG === '') {
        return null;
    }
    try {
        if ($useHistory) {
            require_once __DIR__ . '/../includes/ownership_history.php';
            ownership_history_ensure_tables($pdo);
            if ($pdo->query("SHOW TABLES LIKE 'group_ownership_history'")->rowCount() < 1) {
                return null;
            }
            if ($pdo->query("SHOW TABLES LIKE 'company_ownership_history'")->rowCount() < 1) {
                return null;
            }
        } else {
            if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() < 1) {
                return null;
            }
            if ($pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() < 1) {
                return null;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    $groupTable = $useHistory ? 'group_ownership_history' : 'group_ownership';
    $companyTable = $useHistory ? 'company_ownership_history' : 'company_ownership';
    $monthSql = $useHistory ? ' AND effective_month = ?' : '';

    $g = $viewG;
    $path = 1.0;
    $maxHops = 32;
    while ($maxHops-- > 0) {
        $stmt = $pdo->prepare("
            SELECT group_id, percentage
            FROM {$groupTable}
            WHERE owner_type = 'group'
              AND percentage > 0
              AND partner_group_id IS NOT NULL
              AND TRIM(partner_group_id) <> ''
              AND UPPER(TRIM(partner_group_id)) = UPPER(TRIM(?))
              {$monthSql}
            LIMIT 1
        ");
        $stmt->execute($useHistory ? [$g, $effectiveMonth] : [$g]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            break;
        }
        $pct = (float) $row['percentage'];
        if ($pct <= 0) {
            break;
        }
        $path *= ($pct / 100.0);
        $g = strtoupper(trim((string) $row['group_id']));
    }

    $stmtCo = $pdo->prepare("
        SELECT percentage
        FROM {$companyTable}
        WHERE company_id = ?
          AND owner_type = 'group'
          AND percentage > 0
          AND partner_group_id IS NOT NULL
          AND TRIM(partner_group_id) <> ''
          AND UPPER(TRIM(partner_group_id)) = UPPER(TRIM(?))
          {$monthSql}
        LIMIT 1
    ");
    $stmtCo->execute($useHistory ? [$companyId, $g, $effectiveMonth] : [$companyId, $g]);
    $coPct = $stmtCo->fetchColumn();
    if ($coPct !== false) {
        $path *= ((float) $coPct) / 100.0;
        return $path;
    }

    $stmtHasGr = $pdo->prepare("SELECT 1 FROM {$companyTable} WHERE company_id = ? AND owner_type = 'group'{$monthSql} LIMIT 1");
    $stmtHasGr->execute($useHistory ? [$companyId, $effectiveMonth] : [$companyId]);
    if ($stmtHasGr->fetchColumn()) {
        return null;
    }

    $stmtNat = $pdo->prepare("SELECT UPPER(TRIM(group_id)) FROM company WHERE id = ?");
    $stmtNat->execute([$companyId]);
    $nat = $stmtNat->fetchColumn();
    if ($nat && strtoupper(trim((string) $nat)) === $g) {
        return $path;
    }

    return null;
}

function dashboardContraApprovedWhere(PDO $pdo, string $alias = 't'): string
{
    if (!dashboardHasContraApprovalColumns($pdo)) {
        return '';
    }
    $a = $alias !== '' ? $alias . '.' : '';
    return " AND ((
                {$a}transaction_type IN ('CONTRA','PAYMENT','RECEIVE','CLAIM','CLEAR','ADJUSTMENT','WIN','LOSE','PROFIT')
                AND {$a}approval_status = 'APPROVED'
            ) OR {$a}transaction_type NOT IN ('CONTRA','PAYMENT','RECEIVE','CLAIM','CLEAR','ADJUSTMENT','WIN','LOSE','PROFIT'))";
}

/**
 * 是否在仪表板统计中排除 CLEAR：
 * - 对 CAPITAL：不排除（CLEAR 与 CONTRA 行为一致）
 * - 对 EXPENSES/PROFIT：排除 CLEAR（无论是 To 还是 From）
 */
function dashboardShouldExcludeClearForRole(?string $role): bool
{
    if ($role === null) {
        return false;
    }
    $role = strtoupper(trim((string) $role));
    // Dashboard：PROFIT / EXPENSES 的 CLEAR 不计入 KPI（Transaction 页仍正常展示/提交）
    return $role === 'PROFIT' || $role === 'EXPENSES';
}

/**
 * 手动 PROFIT（Transaction Payment → WIN/LOSE，非 Bank Process / 赔款）描述条件。
 * 与 search_api.php txn_win_lose bulk 一致。
 */
function dashboardManualProfitDescSql(string $alias = 't'): string
{
    $d = $alias !== '' ? $alias . '.' : '';
    return "(({$d}description NOT LIKE 'Process: %' AND {$d}description NOT LIKE 'Inactive Compensation %' AND {$d}description NOT LIKE 'Compensation %') OR {$d}description IS NULL)";
}

function dashboardMoneyZero(): string
{
    return '0.00000000';
}

function dashboardMoneyAdd($a, $b, int $scale = MONEY_SCALE): string
{
    return money_add($a ?? '0', $b ?? '0', $scale);
}

function dashboardMoneySub($a, $b, int $scale = MONEY_SCALE): string
{
    return money_sub($a ?? '0', $b ?? '0', $scale);
}

function dashboardAddDailyAmount(array &$daily, string $date, $amount): void
{
    if ($date === '') {
        return;
    }
    $daily[$date] = dashboardMoneyAdd($daily[$date] ?? '0', $amount);
}

function dashboardSumDailyAmounts(array $daily): string
{
    $total = dashboardMoneyZero();
    foreach ($daily as $amount) {
        $total = dashboardMoneyAdd($total, $amount);
    }
    return $total;
}

function dashboardOut($value): string
{
    return money_out($value ?? '0');
}

function dashboardOutMap(array $daily): array
{
    foreach ($daily as $date => $amount) {
        $daily[$date] = dashboardOut($amount);
    }
    return $daily;
}

function dashboardEnsureGroupRowForCode(PDO $pdo, string $groupCode): void
{
    $g = strtoupper(trim($groupCode));
    if ($g === '') {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `groups` (`group_code`, `group_name`, `owner_id`)
            SELECT DISTINCT
                UPPER(TRIM(c.group_id)),
                UPPER(TRIM(c.group_id)),
                c.owner_id
            FROM company c
            WHERE UPPER(TRIM(c.group_id)) = ?
              AND TRIM(COALESCE(c.group_id, '')) <> ''
            LIMIT 1
            ON DUPLICATE KEY UPDATE
                `owner_id` = COALESCE(`groups`.`owner_id`, VALUES(`owner_id`))
        ");
        $stmt->execute([$g]);
    } catch (Throwable $e) {
        error_log('dashboardEnsureGroupRowForCode(' . $g . '): ' . $e->getMessage());
    }
}

function dashboardResolveGroupScopeIdByCode(PDO $pdo, string $groupCode): int
{
    $g = strtoupper(trim($groupCode));
    if ($g === '') {
        return 0;
    }
    $lookup = static function (PDO $pdo, string $code): int {
        $stmt = $pdo->prepare('SELECT id FROM `groups` WHERE group_code = ? LIMIT 1');
        $stmt->execute([$code]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM `groups` WHERE UPPER(TRIM(group_code)) = UPPER(TRIM(?)) LIMIT 1'
        );
        $stmt->execute([$code]);

        return (int) ($stmt->fetchColumn() ?: 0);
    };
    try {
        $id = $lookup($pdo, $g);
        if ($id > 0) {
            return $id;
        }
        dashboardEnsureGroupRowForCode($pdo, $g);
        $id = $lookup($pdo, $g);
        if ($id > 0) {
            return $id;
        }

        $mapStmt = $pdo->prepare('
            SELECT g.id
            FROM `groups` g
            INNER JOIN group_company_map m ON m.group_id = g.id
            INNER JOIN company c ON c.id = m.company_id
            WHERE UPPER(TRIM(c.group_id)) = ?
            LIMIT 1
        ');
        $mapStmt->execute([$g]);

        return (int) ($mapStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('dashboardResolveGroupScopeIdByCode(' . $g . '): ' . $e->getMessage());

        return 0;
    }
}

function dashboardResolveGroupScopeId(PDO $pdo, ?string $viewGroup = null): int
{
    $fromParam = $viewGroup !== null ? strtoupper(trim($viewGroup)) : '';
    if ($fromParam !== '') {
        return dashboardResolveGroupScopeIdByCode($pdo, $fromParam);
    }
    if (!gc_is_group_login()) {
        return 0;
    }
    $identifier = gc_session_login_identifier();
    if ($identifier === null || $identifier === '') {
        return 0;
    }
    return dashboardResolveGroupScopeIdByCode($pdo, $identifier);
}

function dashboardResolveGroupCodeFromScopeId(PDO $pdo, int $groupScopeId): string
{
    if ($groupScopeId <= 0) {
        return '';
    }
    try {
        $stmt = $pdo->prepare('SELECT UPPER(TRIM(group_code)) FROM `groups` WHERE id = ? LIMIT 1');
        $stmt->execute([$groupScopeId]);
        return strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Group login and explicit group_only must use groups.id ledger — never legacy company_id=AP/IG rows.
 */
function dashboard_should_force_pure_group_ledger(PDO $pdo): bool
{
    dashboard_ensure_tenant_scope_loaded();

    if (function_exists('gc_is_group_login') && gc_is_group_login()) {
        return true;
    }

    $explicitGroupOnly = !empty($_GET['group_only'])
        && filter_var($_GET['group_only'], FILTER_VALIDATE_BOOLEAN);
    if ($explicitGroupOnly) {
        return true;
    }

    return function_exists('tenant_dual_tenant_enabled') && tenant_dual_tenant_enabled($pdo);
}

function dashboardAssertGroupLedgerAccess(PDO $pdo, string $groupCode, int $groupScopeId): void
{
    $g = reportNormalizeGroupId($groupCode);
    if ($g === '') {
        throw new Exception('无效的集团');
    }

    // Dual-tenant group ledger (groups.id scope): skip legacy company_id=AP/IG entity checks.
    if ($groupScopeId > 0 && gc_session_can_access_group_ledger($pdo, $g)) {
        return;
    }

    $entityId = tx_resolve_group_entity_company_id($pdo, $g);
    if ($entityId <= 0) {
        throw new Exception('无效的集团');
    }
    assertGroupEntityAccess($pdo, $g, $entityId);
}

/**
 * Group ledger DCD WHERE（与 search_api searchApiDcdBulkLedgerWhere 对齐）。
 *
 * @return array{sql: string, params: array<int|string>}
 */
function dashboardGroupDcdLedgerWhere(PDO $pdo, int $groupScopeId): array
{
    $groupCode = dashboardResolveGroupCodeFromScopeId($pdo, $groupScopeId);
    $anchorId = $groupCode !== '' ? tx_resolve_group_anchor_company_id($pdo, $groupCode) : 0;
    $sql = 'dcd.company_id = ? AND dc.company_id = ?';
    $params = [$anchorId, $anchorId];

    if (tenant_table_has_scope_columns($pdo, 'data_captures')) {
        if ($groupScopeId > 0) {
            $sql .= ' AND dc.scope_type = ? AND dc.scope_id = ?'
                 . ' AND dcd.scope_type = ? AND dcd.scope_id = ?';
            $params[] = 'group';
            $params[] = $groupScopeId;
            $params[] = 'group';
            $params[] = $groupScopeId;
        }
    } elseif ($anchorId > 0) {
        require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';
        if (dcCompanyIdIsGroupEntity($pdo, $anchorId)) {
            $sql .= dcSqlCaptureOnGroupEntityCompany('dc');
        }
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Group ledger 币别过滤（transactions / transaction_entry.currency_id）。
 *
 * @param array<int, string> $currencyMap
 * @return array{0: string, 1: array<int, int>}
 */
function dashboardGroupLedgerCurrencyFilterSql(?string $filterCurrencyCode, array $currencyMap, string $alias = 't'): array
{
    if ($filterCurrencyCode === null || trim($filterCurrencyCode) === '') {
        return ['', []];
    }
    $currId = array_search(strtoupper(trim($filterCurrencyCode)), $currencyMap, true);
    if ($currId === false) {
        return [' AND 1=0', []];
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 't';

    return [" AND {$a}.currency_id = ?", [(int) $currId]];
}

/**
 * Group-only process（SALARY / BONUS）的 Data Capture Win/Loss。
 *
 * @param array<int, array<string, mixed>> $accounts
 * @return array{capture_bf: string, daily: array<string, string>}
 */
function dashboardGroupSalaryBonusCaptureBundle(
    PDO $pdo,
    int $groupScopeId,
    array $accounts,
    string $dateFrom,
    string $dateTo,
    ?string $filterCurrencyCode
): array {
    if ($accounts === []) {
        return ['capture_bf' => dashboardMoneyZero(), 'daily' => [], 'capture_period' => dashboardMoneyZero()];
    }

    require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';

    list($acctFilter, $acctParams) = dashboardDcdAccountMatchFilterSql($accounts);
    list($currencyFilter, $currencyParams) = dashboardCaptureCurrencyFilterSql($filterCurrencyCode);
    $ledger = dashboardGroupDcdLedgerWhere($pdo, $groupScopeId);
    $processFilter = dcSqlGroupProcessFilter('p');
    $dcdQ = dcd_processed_amount_sql_quant2('dcd.processed_amount');

    $sql = "SELECT COALESCE(SUM({$dcdQ}), 0)
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN process p ON dc.process_id = p.id
            WHERE {$ledger['sql']}
              AND dcd.currency_id IS NOT NULL
              AND dc.capture_date < ?
              {$processFilter}
              {$acctFilter}
              {$currencyFilter}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ledger['params'], [$dateFrom], $acctParams, $currencyParams));
    $captureBf = (string) ($stmt->fetchColumn() ?? '0');

    $kpiOnly = !empty($GLOBALS['DASHBOARD_KPI_ONLY']);
    if ($kpiOnly) {
        $sql = "SELECT COALESCE(SUM({$dcdQ}), 0)
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                INNER JOIN process p ON dc.process_id = p.id
                WHERE {$ledger['sql']}
                  AND dcd.currency_id IS NOT NULL
                  AND dc.capture_date BETWEEN ? AND ?
                  {$processFilter}
                  {$acctFilter}
                  {$currencyFilter}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($ledger['params'], [$dateFrom, $dateTo], $acctParams, $currencyParams));
        return [
            'capture_bf' => $captureBf,
            'daily' => [],
            'capture_period' => (string) ($stmt->fetchColumn() ?? '0'),
        ];
    }

    $sql = "SELECT DATE(dc.capture_date) AS d, COALESCE(SUM({$dcdQ}), 0) AS wl
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN process p ON dc.process_id = p.id
            WHERE {$ledger['sql']}
              AND dcd.currency_id IS NOT NULL
              AND dc.capture_date BETWEEN ? AND ?
              {$processFilter}
              {$acctFilter}
              {$currencyFilter}
            GROUP BY DATE(dc.capture_date)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ledger['params'], [$dateFrom, $dateTo], $acctParams, $currencyParams));
    $daily = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $daily[(string) ($row['d'] ?? '')] = (string) ($row['wl'] ?? '0');
    }

    return ['capture_bf' => $captureBf, 'daily' => $daily, 'capture_period' => dashboardSumDailyAmounts($daily)];
}

/**
 * Group PROFIT：与 company 同类交易口径（WIN/LOSE/ADJUSTMENT/RATE 等），数据源为 group ledger。
 * Data Capture 仅含 group SALARY/BONUS；不计入 Domain Share/Net Profit/List Fee 等费用。
 *
 * @param array<int, array<string, mixed>> $accountRows
 * @param array<int, string> $currencyMap
 * @return array{role: string, total_balance: string, initial_balance: string, period_total: string, daily_data: array<string, string>}
 */
function dashboardBuildGroupProfitBucket(
    PDO $pdo,
    int $groupScopeId,
    array $accountRows,
    string $dateFrom,
    string $dateTo,
    ?string $filterCurrencyCode,
    array $currencyMap,
    bool $hasTransactionCurrency
): array {
    $accountIds = array_values(array_unique(array_filter(
        array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $accountRows),
        static fn (int $id): bool => $id > 0
    )));
    if ($accountIds === []) {
        return dashboardEmptyRoleBucket('PROFIT');
    }

    $totalBf = dashboardMoneyZero();
    $dailyData = [];
    $excludeClear = dashboardShouldExcludeClearForRole('PROFIT');
    $clearFilter = $excludeClear ? " AND t.transaction_type <> 'CLEAR'" : '';
    $contraApproval = dashboardContraApprovedWhere($pdo, 't');
    $idsPlaceholder = implode(',', array_fill(0, count($accountIds), '?'));
    $groupTxnWhere = 't.scope_type = \'group\' AND t.scope_id = ?';
    $groupHeaderWhere = 'h.scope_type = \'group\' AND h.scope_id = ?';

    list($currencyFilterT, $currencyParamsT) = dashboardGroupLedgerCurrencyFilterSql($filterCurrencyCode, $currencyMap, 't');
    list($currencyFilterE, $currencyParamsE) = dashboardGroupLedgerCurrencyFilterSql($filterCurrencyCode, $currencyMap, 'e');
    $excludeDomainSql = "
              AND COALESCE(t.sms, '') NOT LIKE '[DOMAIN_SHARE_COMMISSION|%'
              AND COALESCE(t.sms, '') NOT LIKE '[DOMAIN_NET_PROFIT|%'
              AND COALESCE(t.sms, '') NOT LIKE '[DOMAIN_LIST_FEE|%'
              AND UPPER(TRIM(COALESCE(t.description, ''))) NOT LIKE 'DOMAIN LIST FEE FROM %'";

    $kpiOnly = !empty($GLOBALS['DASHBOARD_KPI_ONLY']);
    $captureBundle = dashboardGroupSalaryBonusCaptureBundle(
        $pdo,
        $groupScopeId,
        $accountRows,
        $dateFrom,
        $dateTo,
        $filterCurrencyCode
    );
    $totalBf = dashboardMoneyAdd($totalBf, $captureBundle['capture_bf']);
    $capturePeriod = (string) ($captureBundle['capture_period'] ?? '0');
    if (!$kpiOnly) {
        foreach ($captureBundle['daily'] as $date => $amount) {
            dashboardAddDailyAmount($dailyData, $date, $amount);
        }
    }

    $bfTxnTypes = "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE', 'ADJUSTMENT')";
    $dailyTxnTypes = $bfTxnTypes;
    $dailyFromTxnTypes = "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE')";

    if ($hasTransactionCurrency) {
        $sql = "SELECT COALESCE(SUM(CASE
                    WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -amount
                    WHEN transaction_type = 'CONTRA' THEN -amount
                    WHEN transaction_type = 'CLEAR' THEN -amount
                    WHEN transaction_type = 'PAYMENT' THEN -amount
                    WHEN transaction_type = 'WIN' AND (description LIKE 'Process: %') THEN amount
                    WHEN transaction_type = 'LOSE' AND (description LIKE 'Process: %') THEN -amount
                    WHEN transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN -amount
                    WHEN transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN amount
                    WHEN transaction_type = 'ADJUSTMENT' THEN amount
                    ELSE 0
                END), 0)
                FROM transactions t
                WHERE {$groupTxnWhere}
                  AND t.account_id IN ($idsPlaceholder)
                  AND t.transaction_date < ?
                  AND t.transaction_type IN $bfTxnTypes" . $currencyFilterT . $clearFilter . $contraApproval . $excludeDomainSql;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom], $currencyParamsT));
        $totalBf = dashboardMoneyAdd($totalBf, $stmt->fetchColumn());

        $sql = "SELECT COALESCE(SUM(CASE
                    WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'CLEAR') THEN amount
                    WHEN transaction_type = 'CONTRA' THEN amount
                    WHEN transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN amount
                    WHEN transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN -amount
                    ELSE 0
                END), 0)
                FROM transactions t
                WHERE {$groupTxnWhere}
                  AND t.from_account_id IN ($idsPlaceholder)
                  AND t.transaction_date < ?
                  AND t.transaction_type IN $bfTxnTypes" . $currencyFilterT . $clearFilter . $contraApproval . $excludeDomainSql;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom], $currencyParamsT));
        $totalBf = dashboardMoneyAdd($totalBf, $stmt->fetchColumn());

        try {
            if (dashboardHasTransactionEntry($pdo)) {
                $sql = "SELECT COALESCE(SUM(CASE
                            WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                            WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                            WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                            ELSE e.amount
                        END), 0)
                        FROM transaction_entry e
                        JOIN transactions h ON e.header_id = h.id
                        WHERE {$groupHeaderWhere}
                          AND e.account_id IN ($idsPlaceholder)
                          AND h.transaction_date < ?" . $currencyFilterE;
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom], $currencyParamsE));
                $totalBf = dashboardMoneyAdd($totalBf, $stmt->fetchColumn());
            }
        } catch (Throwable $e) {
        }

        if ($kpiOnly) {
            $sql = "SELECT COALESCE(SUM(CASE
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -t.amount
                        WHEN transaction_type = 'CONTRA' THEN -t.amount
                        WHEN transaction_type = 'CLEAR' THEN -t.amount
                        WHEN transaction_type = 'PAYMENT' THEN -t.amount
                        WHEN t.transaction_type = 'WIN' AND (t.description LIKE 'Process: %') THEN t.amount
                        WHEN t.transaction_type = 'LOSE' AND (t.description LIKE 'Process: %') THEN -t.amount
                        WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                        WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                        WHEN t.transaction_type = 'ADJUSTMENT' THEN t.amount
                        ELSE 0
                    END), 0)
                FROM transactions t
                WHERE {$groupTxnWhere}
                  AND t.account_id IN ($idsPlaceholder)
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN $dailyTxnTypes" . $currencyFilterT . $clearFilter . $contraApproval . $excludeDomainSql;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom, $dateTo], $currencyParamsT));
            $period = dashboardMoneyAdd($capturePeriod, $stmt->fetchColumn());

            $sql = "SELECT COALESCE(SUM(CASE
                        WHEN transaction_type = 'CONTRA' THEN t.amount
                        WHEN transaction_type = 'CLEAR' THEN t.amount
                        WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN t.amount
                        WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                        WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                        ELSE 0
                    END), 0)
                FROM transactions t
                WHERE {$groupTxnWhere}
                  AND t.from_account_id IN ($idsPlaceholder)
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN $dailyFromTxnTypes" . $currencyFilterT . $clearFilter . $contraApproval . $excludeDomainSql;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom, $dateTo], $currencyParamsT));
            $period = dashboardMoneyAdd($period, $stmt->fetchColumn());

            try {
                if (dashboardHasTransactionEntry($pdo)) {
                    $sql = "SELECT COALESCE(SUM(CASE
                                WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                                ELSE e.amount
                            END), 0)
                        FROM transaction_entry e
                        JOIN transactions h ON e.header_id = h.id
                        WHERE {$groupHeaderWhere}
                          AND e.account_id IN ($idsPlaceholder)
                          AND h.transaction_date BETWEEN ? AND ?" . $currencyFilterE;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom, $dateTo], $currencyParamsE));
                    $period = dashboardMoneyAdd($period, $stmt->fetchColumn());
                }
            } catch (Throwable $e) {
            }

            $total = dashboardMoneyAdd($totalBf, $period);
            return [
                'role' => 'PROFIT',
                'total_balance' => dashboardOut($total),
                'initial_balance' => dashboardOut($totalBf),
                'period_total' => dashboardOut($period),
                'daily_data' => [],
            ];
        }

        $sql = "SELECT DATE(t.transaction_date) AS date,
                       COALESCE(SUM(CASE
                           WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -t.amount
                           WHEN transaction_type = 'CONTRA' THEN -t.amount
                           WHEN transaction_type = 'CLEAR' THEN -t.amount
                           WHEN transaction_type = 'PAYMENT' THEN -t.amount
                           WHEN t.transaction_type = 'WIN' AND (t.description LIKE 'Process: %') THEN t.amount
                           WHEN t.transaction_type = 'LOSE' AND (t.description LIKE 'Process: %') THEN -t.amount
                           WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                           WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                           WHEN t.transaction_type = 'ADJUSTMENT' THEN t.amount
                           ELSE 0
                       END), 0) AS delta
                FROM transactions t
                WHERE {$groupTxnWhere}
                  AND t.account_id IN ($idsPlaceholder)
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN $dailyTxnTypes" . $currencyFilterT . $clearFilter . $contraApproval . $excludeDomainSql . "
                GROUP BY DATE(t.transaction_date)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom, $dateTo], $currencyParamsT));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            dashboardAddDailyAmount($dailyData, (string) ($row['date'] ?? ''), $row['delta'] ?? '0');
        }

        $sql = "SELECT DATE(t.transaction_date) AS date,
                       COALESCE(SUM(CASE
                           WHEN transaction_type = 'CONTRA' THEN t.amount
                           WHEN transaction_type = 'CLEAR' THEN t.amount
                           WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN t.amount
                           WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                           WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                           ELSE 0
                       END), 0) AS delta
                FROM transactions t
                WHERE {$groupTxnWhere}
                  AND t.from_account_id IN ($idsPlaceholder)
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN $dailyFromTxnTypes" . $currencyFilterT . $clearFilter . $contraApproval . $excludeDomainSql . "
                GROUP BY DATE(t.transaction_date)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom, $dateTo], $currencyParamsT));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            dashboardAddDailyAmount($dailyData, (string) ($row['date'] ?? ''), $row['delta'] ?? '0');
        }

        try {
            if (dashboardHasTransactionEntry($pdo)) {
                $sql = "SELECT DATE(h.transaction_date) AS date,
                               COALESCE(SUM(CASE
                                   WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                   WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                   WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                                   ELSE e.amount
                               END), 0) AS rate_delta
                        FROM transaction_entry e
                        JOIN transactions h ON e.header_id = h.id
                        WHERE {$groupHeaderWhere}
                          AND e.account_id IN ($idsPlaceholder)
                          AND h.transaction_date BETWEEN ? AND ?" . $currencyFilterE . "
                        GROUP BY DATE(h.transaction_date)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom, $dateTo], $currencyParamsE));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    dashboardAddDailyAmount($dailyData, (string) ($row['date'] ?? ''), $row['rate_delta'] ?? '0');
                }
            }
        } catch (Throwable $e) {
        }
    }

    $period = dashboardSumDailyAmounts($dailyData);
    $total = dashboardMoneyAdd($totalBf, $period);

    return [
        'role' => 'PROFIT',
        'total_balance' => dashboardOut($total),
        'initial_balance' => dashboardOut($totalBf),
        'period_total' => dashboardOut($period),
        'daily_data' => dashboardOutMap($dailyData),
    ];
}

/**
 * Group ledger：全 scope RATE_MIDDLEMAN 并入 Profit（与 company dashboard 一致）。
 *
 * @param array<string, mixed> $result
 * @param array<int, string> $currencyMap
 */
function dashboardMergeGroupRateMiddlemanIntoProfit(
    PDO $pdo,
    array &$result,
    int $groupScopeId,
    string $dateFrom,
    string $dateTo,
    ?string $filterCurrencyCode,
    array $currencyMap
): void {
    if (!dashboardHasTransactionEntry($pdo) || empty($result['profit'])) {
        return;
    }

    $kpiOnly = !empty($GLOBALS['DASHBOARD_KPI_ONLY']);

    try {
        list($currencyFilterE, $currencyParamsE) = dashboardGroupLedgerCurrencyFilterSql($filterCurrencyCode, $currencyMap, 'e');
        if ($currencyFilterE === ' AND 1=0') {
            return;
        }

        if ($kpiOnly) {
            $rateMMSql = "
                SELECT COALESCE(SUM(e.amount), 0) AS total
                FROM transaction_entry e
                JOIN transactions h ON e.header_id = h.id
                WHERE h.scope_type = 'group' AND h.scope_id = ?
                  AND e.entry_type = 'RATE_MIDDLEMAN'
                  AND h.transaction_date BETWEEN ? AND ?" . $currencyFilterE;
            $rateMMParams = array_merge([$groupScopeId, $dateFrom, $dateTo], $currencyParamsE);
            $rateMMStmt = $pdo->prepare($rateMMSql);
            $rateMMStmt->execute($rateMMParams);
            $rateMMPeriodTotal = (string) ($rateMMStmt->fetchColumn() ?? '0');
            if (money_cmp($rateMMPeriodTotal, '0') === 0) {
                return;
            }
            $result['profit']['period_total'] = dashboardOut(
                dashboardMoneyAdd($result['profit']['period_total'] ?? '0', $rateMMPeriodTotal)
            );
            $result['profit']['total_balance'] = dashboardOut(
                dashboardMoneyAdd($result['profit']['total_balance'] ?? '0', $rateMMPeriodTotal)
            );
            return;
        }

        $rateMMSql = "
            SELECT DATE(h.transaction_date) AS date, COALESCE(SUM(e.amount), 0) AS total
            FROM transaction_entry e
            JOIN transactions h ON e.header_id = h.id
            WHERE h.scope_type = 'group' AND h.scope_id = ?
              AND e.entry_type = 'RATE_MIDDLEMAN'
              AND h.transaction_date BETWEEN ? AND ?" . $currencyFilterE;
        $rateMMParams = array_merge([$groupScopeId, $dateFrom, $dateTo], $currencyParamsE);
        $rateMMSql .= ' GROUP BY DATE(h.transaction_date)';

        $rateMMDaily = [];
        $rateMMPeriodTotal = dashboardMoneyZero();
        $rateMMStmt = $pdo->prepare($rateMMSql);
        $rateMMStmt->execute($rateMMParams);
        while ($rateRow = $rateMMStmt->fetch(PDO::FETCH_ASSOC)) {
            $d = (string) ($rateRow['date'] ?? '');
            $v = $rateRow['total'] ?? '0';
            dashboardAddDailyAmount($rateMMDaily, $d, $v);
            $rateMMPeriodTotal = dashboardMoneyAdd($rateMMPeriodTotal, $v);
        }

        if ($rateMMDaily === []) {
            return;
        }

        foreach ($rateMMDaily as $d => $v) {
            dashboardAddDailyAmount($result['profit']['daily_data'], $d, $v);
        }
        $result['profit']['period_total'] = dashboardOut(dashboardMoneyAdd($result['profit']['period_total'] ?? '0', $rateMMPeriodTotal));
        $result['profit']['total_balance'] = dashboardOut(dashboardMoneyAdd($result['profit']['total_balance'] ?? '0', $rateMMPeriodTotal));
        $result['profit']['daily_data'] = dashboardOutMap($result['profit']['daily_data']);
    } catch (Throwable $e) {
    }
}

/**
 * Native subsidiaries under a group tab (excludes group-entity placeholder rows).
 *
 * @return list<int>
 */
function dashboardListGroupSubsidiaryCompanyIds(PDO $pdo, string $groupCode): array
{
    $g = reportNormalizeGroupId($groupCode);
    if ($g === '') {
        return [];
    }

    require_once __DIR__ . '/../get_companies_helper.php';

    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($role === 'owner') {
        $ownerId = (int) ($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);
        $companies = $ownerId > 0 ? getCompaniesByOwner($pdo, $ownerId, true) : [];
    } else {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $companies = $userId > 0 ? getCompaniesByUser($pdo, $userId, true) : [];
    }

    $ids = [];
    foreach ($companies as $row) {
        $nativeG = strtoupper(trim((string) ($row['native_group_id'] ?? $row['group_id'] ?? '')));
        $code = strtoupper(trim((string) ($row['company_id'] ?? '')));
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $nativeG !== $g || $code === '' || $code === $g) {
            continue;
        }
        $ids[$id] = $id;
    }

    return array_values($ids);
}

/**
 * company_ownership (owner_type=group) → partner group percentage, month-aware.
 *
 * @param list<int> $companyIds
 * @return array<int, string> company_id => percentage (money string)
 */
function dashboardLoadCompanyEquityToGroup(
    PDO $pdo,
    array $companyIds,
    string $targetGroupCode,
    string $effectiveMonth,
    bool $useHistory
): array {
    if ($companyIds === []) {
        return [];
    }

    $g = reportNormalizeGroupId($targetGroupCode);
    if ($g === '') {
        return [];
    }

    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $rows = [];

    if ($useHistory) {
        require_once __DIR__ . '/../includes/ownership_history.php';
        ownership_history_ensure_tables($pdo);
        if ($pdo->query("SHOW TABLES LIKE 'company_ownership_history'")->rowCount() < 1) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT company_id, percentage
            FROM company_ownership_history
            WHERE company_id IN ($in)
              AND owner_type = 'group'
              AND percentage > 0
              AND partner_group_id IS NOT NULL
              AND TRIM(partner_group_id) <> ''
              AND UPPER(TRIM(partner_group_id)) = UPPER(TRIM(?))
              AND effective_month = ?
        ");
        $stmt->execute(array_merge($companyIds, [$g, $effectiveMonth]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $schema = dashboardCompanyOwnershipSchema($pdo);
        if (!$schema['table']) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT company_id, percentage
            FROM company_ownership
            WHERE company_id IN ($in)
              AND owner_type = 'group'
              AND percentage > 0
              AND partner_group_id IS NOT NULL
              AND TRIM(partner_group_id) <> ''
              AND UPPER(TRIM(partner_group_id)) = UPPER(TRIM(?))
        ");
        $stmt->execute(array_merge($companyIds, [$g]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $map = [];
    foreach ($rows as $row) {
        $cid = (int) ($row['company_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $map[$cid] = money_out($row['percentage'] ?? '0', 2);
    }

    return $map;
}

/** Align with frontend computeKpiMetrics net profit (period profit + signed expenses). */
function dashboardCompanyPeriodNetProfitFromPayload(array $data): string
{
    $profit = (string) ($data['period_total']['profit'] ?? $data['profit'] ?? '0');
    $expenses = (string) ($data['period_total']['expenses'] ?? '0');
    $expSigned = money_cmp($expenses, '0') > 0 ? dashboardMoneySub('0', $expenses) : $expenses;

    return dashboardMoneyAdd($profit, $expSigned);
}

/**
 * @param array<string, mixed> $dailyData
 * @return array<string, string>
 */
function dashboardCompanyNetProfitDailyFromPayload(array $dailyData): array
{
    $profitDaily = is_array($dailyData['profit'] ?? null) ? $dailyData['profit'] : [];
    $expensesDaily = is_array($dailyData['expenses'] ?? null) ? $dailyData['expenses'] : [];
    $dates = array_unique(array_merge(array_keys($profitDaily), array_keys($expensesDaily)));
    $out = [];
    foreach ($dates as $date) {
        $d = (string) $date;
        if ($d === '') {
            continue;
        }
        $profit = (string) ($profitDaily[$d] ?? '0');
        $expenses = (string) ($expensesDaily[$d] ?? '0');
        $expSigned = money_cmp($expenses, '0') > 0 ? dashboardMoneySub('0', $expenses) : $expenses;
        $out[$d] = dashboardMoneyAdd($profit, $expSigned);
    }

    return $out;
}

/** @return array{user_id:int, owner_type:string} */
function dashboardResolveViewerOwnerType(): array
{
    $userType = strtolower((string) ($_SESSION['user_type'] ?? ''));
    $role = strtolower((string) ($_SESSION['role'] ?? ''));
    $ownerTypeStr = 'account';
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userType === 'owner' || $role === 'owner') {
        $ownerTypeStr = 'owner';
        $userId = (int) ($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $userId);
    } elseif ($userType === 'user') {
        $ownerTypeStr = 'user';
    }

    return ['user_id' => $userId, 'owner_type' => $ownerTypeStr];
}

/** Viewer's allocation % in a group ledger (group_ownership), month-aware for past months. */
function dashboardLoadViewerGroupAccountPercentage(
    PDO $pdo,
    string $groupLedgerCode,
    string $effectiveMonth = '',
    bool $useHistory = false
): array {
    $out = ['percentage' => 0.0, 'has' => false];
    $g = reportNormalizeGroupId($groupLedgerCode);
    if ($g === '') {
        return $out;
    }
    try {
        if ($useHistory) {
            require_once __DIR__ . '/../includes/ownership_history.php';
            ownership_history_ensure_tables($pdo);
            if ($pdo->query("SHOW TABLES LIKE 'group_ownership_history'")->rowCount() < 1) {
                return $out;
            }
        } elseif ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() < 1) {
            return $out;
        }
    } catch (Throwable $e) {
        return $out;
    }

    $viewer = dashboardResolveViewerOwnerType();
    if ($viewer['user_id'] <= 0) {
        return $out;
    }

    $groupTable = $useHistory ? 'group_ownership_history' : 'group_ownership';
    $monthSql = $useHistory ? ' AND effective_month = ?' : '';
    $stmt = $pdo->prepare("
        SELECT percentage FROM {$groupTable}
        WHERE UPPER(TRIM(group_id)) = UPPER(TRIM(?))
          AND account_id = ?
          AND owner_type = ?
          {$monthSql}
        LIMIT 1
    ");
    $stmt->execute(
        $useHistory
            ? [$g, $viewer['user_id'], $viewer['owner_type'], $effectiveMonth]
            : [$g, $viewer['user_id'], $viewer['owner_type']]
    );
    $pct = $stmt->fetchColumn();
    if ($pct !== false) {
        $out['percentage'] = (float) $pct;
        $out['has'] = true;
    }

    return $out;
}

/**
 * Load dashboard ownership multipliers for a company viewer (direct + group chain).
 *
 * @return array{
 *   ownership_percentage:float,
 *   has_ownership_setup:bool,
 *   group_equity_percentage:float,
 *   group_account_percentage:float,
 *   has_group_ownership:bool
 * }
 */
function dashboardLoadCompanyDashboardOwnership(
    PDO $pdo,
    int $companyId,
    string $dateToDisplay,
    string $viewGroup = ''
): array {
    $result = [
        'ownership_percentage' => 0.0,
        'has_ownership_setup' => false,
        'group_equity_percentage' => 0.0,
        'group_account_percentage' => 0.0,
        'has_group_ownership' => false,
    ];

    $monthCtx = dashboardResolveOwnershipMonthFromDate($dateToDisplay);
    $effectiveMonth = $monthCtx['effective_month'];
    $useHistory = $monthCtx['use_history'];
    $companyTable = $useHistory ? 'company_ownership_history' : 'company_ownership';
    $groupTable = $useHistory ? 'group_ownership_history' : 'group_ownership';
    $monthSql = $useHistory ? ' AND effective_month = ?' : '';

    try {
        if ($useHistory) {
            require_once __DIR__ . '/../includes/ownership_history.php';
            ownership_history_ensure_tables($pdo);
            if ($pdo->query("SHOW TABLES LIKE 'company_ownership_history'")->rowCount() < 1) {
                return $result;
            }
        } else {
            $ownershipSchema = dashboardCompanyOwnershipSchema($pdo);
            if (!$ownershipSchema['table']) {
                return $result;
            }
        }
    } catch (Throwable $e) {
        return $result;
    }

    try {
        $stmtSetup = $pdo->prepare("SELECT 1 FROM {$companyTable} WHERE company_id = ?{$monthSql} LIMIT 1");
        $stmtSetup->execute($useHistory ? [$companyId, $effectiveMonth] : [$companyId]);
        if ($stmtSetup->fetchColumn() !== false) {
            $result['has_ownership_setup'] = true;
        }

        $hasOwnerType = true;
        if (!$useHistory) {
            $hasOwnerType = dashboardCompanyOwnershipSchema($pdo)['owner_type_col'];
        }

        $viewer = dashboardResolveViewerOwnerType();
        $userId = $viewer['user_id'];
        $ownerTypeStr = $viewer['owner_type'];
        $userType = strtolower((string) ($_SESSION['user_type'] ?? ''));

        if ($hasOwnerType) {
            $stmtPct = $pdo->prepare("
                SELECT percentage FROM {$companyTable}
                WHERE company_id = ? AND account_id = ? AND owner_type = ?{$monthSql}
                LIMIT 1
            ");
            $stmtPct->execute(
                $useHistory
                    ? [$companyId, $userId, $ownerTypeStr, $effectiveMonth]
                    : [$companyId, $userId, $ownerTypeStr]
            );
            $pct = $stmtPct->fetchColumn();
            if ($pct === false && $ownerTypeStr === 'owner' && $userId > 0) {
                $stmtPct->execute(
                    $useHistory
                        ? [$companyId, $userId, 'user', $effectiveMonth]
                        : [$companyId, $userId, 'user']
                );
                $pct = $stmtPct->fetchColumn();
            }
            if ($pct !== false) {
                $result['ownership_percentage'] = (float) $pct;
            }
        } elseif ($userType === 'member') {
            $stmtPct = $pdo->prepare("
                SELECT percentage FROM {$companyTable}
                WHERE company_id = ? AND account_id = ?{$monthSql}
                LIMIT 1
            ");
            $stmtPct->execute($useHistory ? [$companyId, $userId, $effectiveMonth] : [$companyId, $userId]);
            $pct = $stmtPct->fetchColumn();
            if ($pct !== false) {
                $result['ownership_percentage'] = (float) $pct;
            }
        }

        if ($hasOwnerType) {
            $ownerTypeStr = $ownerTypeStr ?? 'owner';
            $skipGroupChain = ((float) $result['ownership_percentage']) > 0.0;
            $grpEquityRow = null;
            $multiGroupPathResolved = false;

            if (!$skipGroupChain && $viewGroup !== '') {
                $pathDec = dashboardResolveEarningsPathProduct(
                    $pdo,
                    $companyId,
                    $viewGroup,
                    $effectiveMonth,
                    $useHistory
                );
                if ($pathDec !== null) {
                    $multiGroupPathResolved = true;
                    $result['group_equity_percentage'] = $pathDec * 100.0;
                    try {
                        $hasGroupTable = $useHistory
                            ? $pdo->query("SHOW TABLES LIKE 'group_ownership_history'")->rowCount() > 0
                            : $pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0;
                        if ($hasGroupTable) {
                            $stmtAccShare = $pdo->prepare("
                                SELECT percentage FROM {$groupTable}
                                WHERE UPPER(TRIM(group_id)) = UPPER(TRIM(?))
                                  AND account_id = ?
                                  AND owner_type = ?
                                  {$monthSql}
                                LIMIT 1
                            ");
                            $stmtAccShare->execute(
                                $useHistory
                                    ? [$viewGroup, $userId, $ownerTypeStr, $effectiveMonth]
                                    : [$viewGroup, $userId, $ownerTypeStr]
                            );
                            $accSharePct = $stmtAccShare->fetchColumn();
                            if ($accSharePct !== false) {
                                $result['group_account_percentage'] = (float) $accSharePct;
                                $result['has_group_ownership'] = true;
                            } else {
                                $result['group_equity_percentage'] = 0.0;
                                $result['group_account_percentage'] = 0.0;
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
            }

            if (!$result['has_group_ownership'] && !$multiGroupPathResolved) {
                if ($viewGroup !== '') {
                    $stmtGrpEquity = $pdo->prepare("
                        SELECT partner_group_id, percentage
                        FROM {$companyTable}
                        WHERE company_id = ? AND owner_type = 'group'
                          AND UPPER(TRIM(partner_group_id)) = UPPER(TRIM(?))
                          {$monthSql}
                        LIMIT 1
                    ");
                    $stmtGrpEquity->execute(
                        $useHistory
                            ? [$companyId, $viewGroup, $effectiveMonth]
                            : [$companyId, $viewGroup]
                    );
                    $grpEquityRow = $stmtGrpEquity->fetch(PDO::FETCH_ASSOC);
                    if (!$grpEquityRow) {
                        $stmtGrpEquity = $pdo->prepare("
                            SELECT partner_group_id, percentage
                            FROM {$companyTable}
                            WHERE company_id = ? AND owner_type = 'group'
                            {$monthSql}
                            LIMIT 1
                        ");
                        $stmtGrpEquity->execute($useHistory ? [$companyId, $effectiveMonth] : [$companyId]);
                        $grpEquityRow = $stmtGrpEquity->fetch(PDO::FETCH_ASSOC);
                    }
                } else {
                    $stmtGrpEquity = $pdo->prepare("
                        SELECT partner_group_id, percentage
                        FROM {$companyTable}
                        WHERE company_id = ? AND owner_type = 'group'
                        {$monthSql}
                        LIMIT 1
                    ");
                    $stmtGrpEquity->execute($useHistory ? [$companyId, $effectiveMonth] : [$companyId]);
                    $grpEquityRow = $stmtGrpEquity->fetch(PDO::FETCH_ASSOC);
                }

                if ($grpEquityRow && $grpEquityRow['partner_group_id']) {
                    $companyGroupId = $grpEquityRow['partner_group_id'];
                    $result['group_equity_percentage'] = (float) $grpEquityRow['percentage'];

                    try {
                        $hasGroupTable = $useHistory
                            ? $pdo->query("SHOW TABLES LIKE 'group_ownership_history'")->rowCount() > 0
                            : $pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0;
                        if ($hasGroupTable) {
                            $stmtAccShare = $pdo->prepare("
                                SELECT percentage FROM {$groupTable}
                                WHERE group_id = ? AND account_id = ? AND owner_type = ?
                                {$monthSql}
                                LIMIT 1
                            ");
                            $stmtAccShare->execute(
                                $useHistory
                                    ? [$companyGroupId, $userId, $ownerTypeStr, $effectiveMonth]
                                    : [$companyGroupId, $userId, $ownerTypeStr]
                            );
                            $accSharePct = $stmtAccShare->fetchColumn();
                            if ($accSharePct !== false) {
                                $result['group_account_percentage'] = (float) $accSharePct;
                                $result['has_group_ownership'] = true;
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // ignore — tables may not exist yet
    }

    return $result;
}

/** Group ledger period net profit (profit role + signed expenses), before subsidiary merge. */
function dashboardGroupPeriodNetProfitFromSummary(array $groupResult): string
{
    $profit = (string) ($groupResult['profit']['period_total'] ?? '0');
    $expenses = (string) ($groupResult['expenses']['period_total'] ?? '0');
    $expSigned = money_cmp($expenses, '0') > 0 ? dashboardMoneySub('0', $expenses) : $expenses;

    return dashboardMoneyAdd($profit, $expSigned);
}

/**
 * Display codes for company primary keys (subsidiary profit chart).
 *
 * @param list<int> $companyIds
 * @return array<int, string>
 */
function dashboardLoadCompanyDisplayCodes(PDO $pdo, array $companyIds): array
{
    if ($companyIds === []) {
        return [];
    }

    $in = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE id IN ($in)");
    $stmt->execute($companyIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = (string) ($row['company_id'] ?? '');
        }
    }

    return $map;
}

/**
 * Sum subsidiary net-profit × company equity % to the group.
 *
 * @return array{
 *   period_total:string,
 *   daily:array<string,string>,
 *   has_equity:bool,
 *   by_company:list<array<string, mixed>>
 * }
 */
function dashboardComputeSubsidiaryEarningsTotal(
    PDO $pdo,
    string $groupLedgerCode,
    string $dateFromDisplay,
    string $dateToDisplay,
    ?string $filterCurrencyCode,
    bool $kpiOnly,
    float $accountPct = 0.0
): array {
    $empty = [
        'period_total' => dashboardMoneyZero(),
        'daily' => [],
        'has_equity' => false,
        'by_company' => [],
    ];

    require_once __DIR__ . '/../includes/ownership_history.php';

    $companyIds = dashboardListGroupSubsidiaryCompanyIds($pdo, $groupLedgerCode);
    if ($companyIds === []) {
        return $empty;
    }

    $monthCtx = dashboardResolveOwnershipMonthFromDate($dateToDisplay);
    $useHistory = $monthCtx['use_history'];
    $effectiveMonth = $monthCtx['effective_month'];
    $equityMap = dashboardLoadCompanyEquityToGroup(
        $pdo,
        $companyIds,
        $groupLedgerCode,
        $effectiveMonth,
        $useHistory
    );
    if ($equityMap === []) {
        return $empty;
    }

    $periodShareTotal = dashboardMoneyZero();
    $dailyShare = [];
    $byCompany = [];
    $companyCodes = dashboardLoadCompanyDisplayCodes($pdo, array_keys($equityMap));
    $accountMul = $accountPct > 0 ? money_div((string) $accountPct, '100', MONEY_SCALE) : '1';
    $gNorm = reportNormalizeGroupId($groupLedgerCode);

    dashboard_api_begin_bootstrap_batch();
    try {
        foreach ($equityMap as $companyId => $pctStr) {
            if (money_cmp($pctStr, '0') <= 0) {
                continue;
            }

            $captureParams = [
                'company_id' => (string) $companyId,
                'view_group' => $groupLedgerCode,
                'date_from' => $dateFromDisplay,
                'date_to' => $dateToDisplay,
            ];
            if ($filterCurrencyCode !== null && trim($filterCurrencyCode) !== '') {
                $captureParams['currency'] = $filterCurrencyCode;
            }
            if ($kpiOnly) {
                $captureParams['kpi_only'] = '1';
            }

            $cap = dashboard_api_capture($captureParams);
            if (empty($cap['success']) || !is_array($cap['data'] ?? null)) {
                continue;
            }

            $data = $cap['data'];
            $netProfit = dashboardCompanyPeriodNetProfitFromPayload($data);
            $share = money_mul($netProfit, money_div($pctStr, '100', MONEY_SCALE), MONEY_SCALE);
            $myEarning = money_mul($share, $accountMul, MONEY_SCALE);
            $periodShareTotal = dashboardMoneyAdd($periodShareTotal, $share);

            $displayCode = $companyCodes[$companyId] ?? (string) $companyId;
            $byCompany[] = [
                'company_pk' => $companyId,
                'company_id' => $displayCode,
                'group_id' => $gNorm,
                'net_profit' => dashboardOut($netProfit),
                'group_equity_pct' => dashboardOut($pctStr, 2),
                'account_pct' => dashboardOut((string) $accountPct, 2),
                'group_share' => dashboardOut($share),
                'my_earning' => dashboardOut($myEarning),
            ];

            if (!$kpiOnly) {
                $netDaily = dashboardCompanyNetProfitDailyFromPayload($data['daily_data'] ?? []);
                foreach ($netDaily as $d => $net) {
                    $dayShare = money_mul($net, money_div($pctStr, '100', MONEY_SCALE), MONEY_SCALE);
                    dashboardAddDailyAmount($dailyShare, $d, $dayShare);
                }
            }
        }
    } finally {
        dashboard_api_end_bootstrap_batch();
    }

    usort($byCompany, static function (array $a, array $b): int {
        return money_cmp((string) ($b['my_earning'] ?? '0'), (string) ($a['my_earning'] ?? '0'));
    });

    return [
        'period_total' => $periodShareTotal,
        'daily' => $dailyShare,
        'has_equity' => money_cmp($periodShareTotal, '0') !== 0 || $dailyShare !== [] || $byCompany !== [],
        'by_company' => $byCompany,
    ];
}

/**
 * Single-subsidiary group: fold group-ledger earnings into that row's my_earning so the
 * earnings tab total matches the KPI group-aggregate card. Multi-subsidiary groups unchanged.
 *
 * @param array{by_company:list<array<string,mixed>>} $subsidiaryEarnings
 */
function dashboardApplySingleSubsidiaryGroupLedgerEarnings(
    PDO $pdo,
    array &$subsidiaryEarnings,
    string $groupLedgerCode,
    string $groupLedgerNetProfit,
    float $accountPct
): void {
    $subsidiaryIds = dashboardListGroupSubsidiaryCompanyIds($pdo, $groupLedgerCode);
    if (count($subsidiaryIds) !== 1) {
        return;
    }

    $byCompany = $subsidiaryEarnings['by_company'] ?? [];
    if (count($byCompany) !== 1) {
        return;
    }

    if (money_cmp($groupLedgerNetProfit, '0') === 0) {
        return;
    }

    $accountMul = $accountPct > 0
        ? money_div((string) $accountPct, '100', MONEY_SCALE)
        : '1';
    $ledgerMyEarning = money_mul($groupLedgerNetProfit, $accountMul, MONEY_SCALE);
    $current = (string) ($byCompany[0]['my_earning'] ?? '0');
    $subsidiaryEarnings['by_company'][0]['my_earning'] = dashboardOut(
        dashboardMoneyAdd($current, $ledgerMyEarning)
    );
}

/**
 * Add subsidiary net-profit × ownership% into group profit (PROFIT role ledger flow unchanged).
 *
 * @param array<string, mixed> $groupResult
 * @param array{period_total:string,daily:array<string,string>,has_equity:bool}|null $precomputed
 */
function dashboardMergeGroupOwnershipProfitShare(
    PDO $pdo,
    array &$groupResult,
    string $groupLedgerCode,
    string $dateFromDisplay,
    string $dateToDisplay,
    ?string $filterCurrencyCode,
    bool $kpiOnly,
    ?array $precomputed = null
): bool {
    if (empty($groupResult['profit'])) {
        return false;
    }

    $computed = $precomputed ?? dashboardComputeSubsidiaryEarningsTotal(
        $pdo,
        $groupLedgerCode,
        $dateFromDisplay,
        $dateToDisplay,
        $filterCurrencyCode,
        $kpiOnly
    );
    $periodShareTotal = $computed['period_total'];
    $dailyShare = $computed['daily'];

    if (!$computed['has_equity']) {
        return false;
    }

    if (money_cmp(money_abs($periodShareTotal), '0.0000001') <= 0 && $dailyShare === []) {
        return true;
    }

    // NOTE: Ownership net-profit share is merged into group profit daily_data for the trend chart.
    // Product may drop per-day ownership allocation later; keep period_total merge either way.
    $groupResult['profit']['period_total'] = dashboardOut(
        dashboardMoneyAdd($groupResult['profit']['period_total'] ?? '0', $periodShareTotal)
    );
    $groupResult['profit']['total_balance'] = dashboardOut(
        dashboardMoneyAdd($groupResult['profit']['total_balance'] ?? '0', $periodShareTotal)
    );
    if (!$kpiOnly && $dailyShare !== []) {
        foreach ($dailyShare as $d => $amt) {
            dashboardAddDailyAmount($groupResult['profit']['daily_data'], $d, $amt);
        }
        $groupResult['profit']['daily_data'] = dashboardOutMap($groupResult['profit']['daily_data']);
    }

    return true;
}

function dashboardBuildGroupScopedSummary(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $groupScopeId,
    ?string $filterCurrencyCode = null
): array {
    $roles = ['CAPITAL', 'EXPENSES', 'PROFIT'];
    $result = [];
    $kpiOnly = !empty($GLOBALS['DASHBOARD_KPI_ONLY']);
    $hasTransactionCurrency = dashboardHasTransactionCurrency($pdo);
    $currencyMap = dashboardResolveFilterCurrencyMap($pdo, 0, null, $groupScopeId);
    $currencyFilterSql = '';
    $currencyFilterParams = [];
    if ($filterCurrencyCode !== null && $hasTransactionCurrency) {
        $currId = array_search($filterCurrencyCode, $currencyMap, true);
        if ($currId === false) {
            foreach ($roles as $role) {
                $result[strtolower($role)] = [
                    'role' => $role,
                    'total_balance' => dashboardMoneyZero(),
                    'initial_balance' => dashboardMoneyZero(),
                    'period_total' => dashboardMoneyZero(),
                    'daily_data' => [],
                ];
            }
            return $result;
        }
        $currencyFilterSql = ' AND t.currency_id = ?';
        $currencyFilterParams = [(int) $currId];
    }
    $contraApproval = dashboardContraApprovedWhere($pdo, 't');

    foreach ($roles as $role) {
        $excludeClear = dashboardShouldExcludeClearForRole($role);
        $clearFilter = $excludeClear ? " AND t.transaction_type <> 'CLEAR'" : '';
        list($roleFilterSql, $roleFilterParams) = dashboardRoleFilterSql($role, 'a');
        $accStmt = $pdo->prepare("
            SELECT DISTINCT a.id, a.account_id, a.name, a.role
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.scope_type = 'group'
              AND ac.scope_id = ?
              AND {$roleFilterSql}
        ");
        $accStmt->execute(array_merge([$groupScopeId], $roleFilterParams));
        $accountRows = $accStmt->fetchAll(PDO::FETCH_ASSOC);
        $accountIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $accountRows
        )));
        $accountIds = array_values(array_filter($accountIds, static fn (int $id): bool => $id > 0));

        if (empty($accountIds)) {
            $result[strtolower($role)] = [
                'role' => $role,
                'total_balance' => dashboardMoneyZero(),
                'initial_balance' => dashboardMoneyZero(),
                'period_total' => dashboardMoneyZero(),
                'daily_data' => []
            ];
            continue;
        }

        if ($role === 'PROFIT') {
            $result['profit'] = dashboardBuildGroupProfitBucket(
                $pdo,
                $groupScopeId,
                $accountRows,
                $dateFrom,
                $dateTo,
                $filterCurrencyCode,
                $currencyMap,
                $hasTransactionCurrency
            );
            continue;
        }

        $in = implode(',', array_fill(0, count($accountIds), '?'));

        $bfToSql = "
            SELECT COALESCE(SUM(CASE
                WHEN t.transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                WHEN t.transaction_type IN ('CONTRA', 'CLEAR') THEN -t.amount
                WHEN t.transaction_type = 'PAYMENT' THEN -t.amount
                WHEN t.transaction_type = 'WIN' THEN -t.amount
                WHEN t.transaction_type = 'LOSE' THEN t.amount
                WHEN t.transaction_type = 'ADJUSTMENT' THEN t.amount
                ELSE 0
            END), 0)
            FROM transactions t
            WHERE t.scope_type = 'group'
              AND t.scope_id = ?
              AND t.account_id IN ($in)
              AND t.transaction_date < ?" . $currencyFilterSql . $clearFilter . $contraApproval;
        $bfToStmt = $pdo->prepare($bfToSql);
        $bfToStmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom], $currencyFilterParams));
        $bfTo = (string) ($bfToStmt->fetchColumn() ?? '0');

        $bfFromSql = "
            SELECT COALESCE(SUM(CASE
                WHEN t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'CONTRA', 'CLEAR') THEN t.amount
                WHEN t.transaction_type = 'WIN' THEN t.amount
                WHEN t.transaction_type = 'LOSE' THEN -t.amount
                ELSE 0
            END), 0)
            FROM transactions t
            WHERE t.scope_type = 'group'
              AND t.scope_id = ?
              AND t.from_account_id IN ($in)
              AND t.transaction_date < ?" . $currencyFilterSql . $clearFilter . $contraApproval;
        $bfFromStmt = $pdo->prepare($bfFromSql);
        $bfFromStmt->execute(array_merge([$groupScopeId], $accountIds, [$dateFrom], $currencyFilterParams));
        $bfFrom = (string) ($bfFromStmt->fetchColumn() ?? '0');

        $initial = dashboardMoneyAdd($bfTo, $bfFrom);

        if ($kpiOnly) {
            $periodSql = "
                SELECT COALESCE(SUM(CASE
                    WHEN t.account_id IN ($in) THEN
                        CASE
                            WHEN t.transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                            WHEN t.transaction_type IN ('CONTRA', 'CLEAR') THEN -t.amount
                            WHEN t.transaction_type = 'PAYMENT' THEN -t.amount
                            WHEN t.transaction_type = 'WIN' THEN -t.amount
                            WHEN t.transaction_type = 'LOSE' THEN t.amount
                            WHEN t.transaction_type = 'ADJUSTMENT' THEN t.amount
                            ELSE 0
                        END
                    WHEN t.from_account_id IN ($in) THEN
                        CASE
                            WHEN t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'CONTRA', 'CLEAR') THEN t.amount
                            WHEN t.transaction_type = 'WIN' THEN t.amount
                            WHEN t.transaction_type = 'LOSE' THEN -t.amount
                            ELSE 0
                        END
                    ELSE 0
                END), 0) AS delta
                FROM transactions t
                WHERE t.scope_type = 'group'
                  AND t.scope_id = ?
                  AND t.transaction_date BETWEEN ? AND ?
                  AND (t.account_id IN ($in) OR t.from_account_id IN ($in))" . $currencyFilterSql . $clearFilter . $contraApproval;
            $periodStmt = $pdo->prepare($periodSql);
            $periodParams = array_merge(
                $accountIds,
                $accountIds,
                [$groupScopeId, $dateFrom, $dateTo],
                $accountIds,
                $currencyFilterParams
            );
            $periodStmt->execute($periodParams);
            $period = (string) ($periodStmt->fetchColumn() ?? '0');

            if ($role === 'EXPENSES') {
                $captureBundle = dashboardGroupSalaryBonusCaptureBundle(
                    $pdo,
                    $groupScopeId,
                    $accountRows,
                    $dateFrom,
                    $dateTo,
                    $filterCurrencyCode
                );
                $initial = dashboardMoneyAdd($initial, $captureBundle['capture_bf']);
                $period = dashboardMoneyAdd($period, $captureBundle['capture_period'] ?? '0');
            }

            $total = dashboardMoneyAdd($initial, $period);
            $result[strtolower($role)] = [
                'role' => $role,
                'total_balance' => dashboardOut($total),
                'initial_balance' => dashboardOut($initial),
                'period_total' => dashboardOut($period),
                'daily_data' => [],
            ];
            continue;
        }

        $dailySql = "
            SELECT DATE(t.transaction_date) AS d, COALESCE(SUM(CASE
                WHEN t.account_id IN ($in) THEN
                    CASE
                        WHEN t.transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                        WHEN t.transaction_type IN ('CONTRA', 'CLEAR') THEN -t.amount
                        WHEN t.transaction_type = 'PAYMENT' THEN -t.amount
                        WHEN t.transaction_type = 'WIN' THEN -t.amount
                        WHEN t.transaction_type = 'LOSE' THEN t.amount
                        WHEN t.transaction_type = 'ADJUSTMENT' THEN t.amount
                        ELSE 0
                    END
                WHEN t.from_account_id IN ($in) THEN
                    CASE
                        WHEN t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'CONTRA', 'CLEAR') THEN t.amount
                        WHEN t.transaction_type = 'WIN' THEN t.amount
                        WHEN t.transaction_type = 'LOSE' THEN -t.amount
                        ELSE 0
                    END
                ELSE 0
            END), 0) AS delta
            FROM transactions t
            WHERE t.scope_type = 'group'
              AND t.scope_id = ?
              AND t.transaction_date BETWEEN ? AND ?
              AND (t.account_id IN ($in) OR t.from_account_id IN ($in))" . $currencyFilterSql . $clearFilter . $contraApproval . "
            GROUP BY DATE(t.transaction_date)
            ORDER BY DATE(t.transaction_date)
        ";
        $dailyStmt = $pdo->prepare($dailySql);
        $dailyParams = array_merge(
            $accountIds,
            $accountIds,
            [$groupScopeId, $dateFrom, $dateTo],
            $accountIds,
            $accountIds,
            $currencyFilterParams
        );
        $dailyStmt->execute($dailyParams);
        $dailyData = [];
        while ($r = $dailyStmt->fetch(PDO::FETCH_ASSOC)) {
            $dailyData[(string) $r['d']] = (string) ($r['delta'] ?? '0');
        }

        if ($role === 'EXPENSES') {
            $captureBundle = dashboardGroupSalaryBonusCaptureBundle(
                $pdo,
                $groupScopeId,
                $accountRows,
                $dateFrom,
                $dateTo,
                $filterCurrencyCode
            );
            $initial = dashboardMoneyAdd($initial, $captureBundle['capture_bf']);
            foreach ($captureBundle['daily'] as $date => $amount) {
                dashboardAddDailyAmount($dailyData, $date, $amount);
            }
        }

        $period = dashboardSumDailyAmounts($dailyData);
        $total = dashboardMoneyAdd($initial, $period);

        $result[strtolower($role)] = [
            'role' => $role,
            'total_balance' => dashboardOut($total),
            'initial_balance' => dashboardOut($initial),
            'period_total' => dashboardOut($period),
            'daily_data' => dashboardOutMap($dailyData)
        ];
    }

    dashboardMergeGroupRateMiddlemanIntoProfit(
        $pdo,
        $result,
        $groupScopeId,
        $dateFrom,
        $dateTo,
        $filterCurrencyCode,
        $currencyMap
    );

    return $result;
}

/**
 * Dashboard 交易币别过滤（与 search_api 对齐）：
 * - 优先使用 transactions.currency_id
 * - 若 currency_id 为空，则用 data_capture_details 的 account + currency 映射兜底
 */
function dashboardTxnCurrencyFilter(string $accountColumn): string
{
    if ($accountColumn !== 'account_id' && $accountColumn !== 'from_account_id') {
        $accountColumn = 'account_id';
    }
    return " AND (
        t.currency_id = ?
        OR (
            t.currency_id IS NULL
            AND EXISTS (
                SELECT 1
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                WHERE dcd.company_id = ? AND dc.company_id = ?
                  AND CAST(dcd.account_id AS CHAR) = CAST(t.`{$accountColumn}` AS CHAR)
                  AND dcd.currency_id = ?
            )
        )
    )";
}

/** @return array<int, string> */
function dashboardLoadCurrencyMap(PDO $pdo, int $companyId, bool $subsidiaryOnly = false): array
{
    $cacheKey = 'currency_map:' . $companyId . ':' . ($subsidiaryOnly ? '1' : '0');

    return dashboard_bootstrap_cache_remember($cacheKey, static function () use ($pdo, $companyId, $subsidiaryOnly): array {
        $currency_map = [];
        $scopeSql = $subsidiaryOnly ? dashboard_sql_currency_subsidiary_only($pdo, 'c') : '';
        $currency_stmt = $pdo->prepare("SELECT id, UPPER(code) AS code FROM currency c WHERE c.company_id = ?{$scopeSql}");
        $currency_stmt->execute([$companyId]);
        foreach ($currency_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency_map[$row['id']] = strtoupper($row['code']);
        }

        return $currency_map;
    });
}

function dashboardHasAccountCurrencyTable(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = $pdo->query("SHOW TABLES LIKE 'account_currency'")->rowCount() > 0;
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function dashboardAccountHasCurrencyIdColumn(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency_id'")->rowCount() > 0;
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * Account ids used for dashboard KPI (company and/or group ledger scope).
 *
 * @return int[]
 */
function dashboardCollectScopeAccountIds(
    PDO $pdo,
    int $companyId,
    ?string $viewGroup,
    int $groupScopeId = 0,
    bool $subsidiaryOnly = false
): array {
    $roles = ['CAPITAL', 'EXPENSES', 'PROFIT'];
    $ids = [];

    if ($groupScopeId > 0) {
        foreach ($roles as $role) {
            list($roleFilterSql, $roleFilterParams) = dashboardRoleFilterSql($role, 'a');
            $accStmt = $pdo->prepare("
                SELECT DISTINCT a.id
                FROM account a
                INNER JOIN account_company ac ON ac.account_id = a.id
                WHERE ac.scope_type = 'group'
                  AND ac.scope_id = ?
                  AND {$roleFilterSql}
            ");
            $accStmt->execute(array_merge([$groupScopeId], $roleFilterParams));
            foreach ($accStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[(int) $id] = true;
            }
        }
        return array_keys($ids);
    }

    foreach ($roles as $role) {
        $scopeCompanyIds = $companyId > 0
            ? dashboardResolveRoleScopeCompanyIds($pdo, $companyId, $role, $viewGroup, $subsidiaryOnly)
            : [];
        foreach ($scopeCompanyIds as $scopeCompanyId) {
            list($roleFilterSql, $roleFilterParams) = dashboardRoleFilterSql($role, 'a');
            $acSubSql = $subsidiaryOnly ? dashboard_sql_account_company_subsidiary_only($pdo, 'ac') : '';
            $accStmt = $pdo->prepare("
                SELECT DISTINCT a.id
                FROM account a
                INNER JOIN account_company ac ON ac.account_id = a.id
                WHERE ac.company_id = ?
                  {$acSubSql}
                  AND {$roleFilterSql}
            ");
            $accStmt->execute(array_merge([$scopeCompanyId], $roleFilterParams));
            foreach ($accStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[(int) $id] = true;
            }
        }
    }

    return array_keys($ids);
}

/**
 * Group-only scope: entity company accounts + group-ledger accounts (always merged).
 *
 * @return int[]
 */
function dashboardCollectGroupOnlyAccountIds(PDO $pdo, string $viewGroup): array
{
    $viewGroup = reportNormalizeGroupId($viewGroup);
    if ($viewGroup === '') {
        return [];
    }

    $accountIds = [];
    $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroup);
    if ($entityId > 0) {
        $accountIds = array_merge(
            $accountIds,
            dashboardCollectScopeAccountIds($pdo, $entityId, null, 0)
        );
    }

    $groupScopeId = dashboardResolveGroupScopeId($pdo, $viewGroup);
    if ($groupScopeId > 0 && gc_session_can_access_group_ledger($pdo, $viewGroup)) {
        $accountIds = array_merge(
            $accountIds,
            dashboardCollectScopeAccountIds($pdo, 0, null, $groupScopeId)
        );
    }

    return array_values(array_unique($accountIds));
}

/**
 * Currency codes enabled in Account → Currency Setting (company currency table).
 *
 * @param int[] $companyIds
 * @return array<string, true> uppercase code => true
 */
function dashboardAllowedCurrencyCodesForCompanies(PDO $pdo, array $companyIds, bool $subsidiaryOnly = false): array
{
    $allowed = [];
    foreach ($companyIds as $companyId) {
        foreach (dashboardLoadCurrencyMap($pdo, (int) $companyId, $subsidiaryOnly) as $code) {
            $allowed[strtoupper((string) $code)] = true;
        }
    }
    return $allowed;
}

/**
 * Currency codes from Currency Setting on the group tenant (scope_type=group), if any rows exist.
 *
 * @return array<string, true>
 */
function dashboardAllowedCurrencyCodesForGroupTenant(PDO $pdo, string $groupCode): array
{
    $g = reportNormalizeGroupId($groupCode);
    if ($g === '') {
        return [];
    }
    $pk = gc_resolve_group_pk_by_code($pdo, $g);
    if ($pk <= 0) {
        return [];
    }
    $allowed = [];
    try {
        $stmt = $pdo->prepare("
            SELECT UPPER(TRIM(code)) AS code
            FROM currency
            WHERE scope_type = 'group' AND scope_id = ?
        ");
        $stmt->execute([$pk]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $up = strtoupper(trim((string) $code));
            if ($up !== '') {
                $allowed[$up] = true;
            }
        }
    } catch (Throwable $e) {
        return [];
    }
    return $allowed;
}

/**
 * Restrict account currency map to group Currency Setting (group scope rows and/or anchor company table).
 *
 * @param array<int, string> $map
 * @return array<int, string>
 */
function dashboardRestrictCurrencyMapToGroupTenant(PDO $pdo, string $groupCode, array $map): array
{
    if ($map === []) {
        return [];
    }

    $groupAllowed = dashboardAllowedCurrencyCodesForGroupTenant($pdo, $groupCode);
    $entityId = tx_resolve_group_entity_company_id($pdo, $groupCode);
    $companyAllowed = $entityId > 0
        ? dashboardAllowedCurrencyCodesForCompanies($pdo, [$entityId])
        : [];

    $allowed = $groupAllowed !== [] ? $groupAllowed : $companyAllowed;
    if ($allowed === []) {
        return $map;
    }

    $out = [];
    foreach ($map as $id => $code) {
        $up = strtoupper((string) $code);
        if (isset($allowed[$up])) {
            $out[(int) $id] = $up;
        }
    }

    return $out;
}

/**
 * Group tab / group login: currencies from group-ledger account_currency only.
 * Does not list subsidiary-synced Currency Setting rows — a code appears only when
 * a group-scoped account has that currency enabled (e.g. after a group-ledger payment).
 *
 * @return array<int, string>
 */
function dashboardResolveGroupScopeCurrencyMap(PDO $pdo, string $viewGroup): array
{
    $viewGroup = reportNormalizeGroupId($viewGroup);
    if ($viewGroup === '') {
        return [];
    }

    $accountIds = dashboardCollectGroupOnlyAccountIds($pdo, $viewGroup);
    if ($accountIds === []) {
        return [];
    }

    $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroup);
    $companyIds = $entityId > 0 ? [$entityId] : [];

    $map = dashboardLoadAccountCurrencyMap($pdo, $accountIds, $companyIds, true);

    return dashboardRestrictCurrencyMapToGroupTenant($pdo, $viewGroup, $map);
}

/**
 * Keep only account_currency rows whose code exists on the scoped company currency table.
 *
 * @param array<int, string> $map
 * @return array<int, string>
 */
function dashboardIntersectAccountCurrencyWithCompanyTable(
    PDO $pdo,
    array $map,
    array $companyIds,
    bool $subsidiaryOnly = false
): array {
    if ($map === []) {
        return [];
    }
    $allowed = dashboardAllowedCurrencyCodesForCompanies($pdo, $companyIds, $subsidiaryOnly);
    if ($allowed === []) {
        return $map;
    }
    $out = [];
    foreach ($map as $id => $code) {
        $up = strtoupper((string) $code);
        if (isset($allowed[$up])) {
            $out[(int) $id] = $up;
        }
    }
    return $out;
}

/**
 * Filter / display currencies from account_currency (Edit Account active currencies).
 *
 * @param int[] $accountIds
 * @param int[] $companyIds currency.company_id rows to join (subsidiary + group entity)
 * @param bool $accountCurrencyOnly group-only: acc active currencies only, never Currency Setting list
 * @return array<int, string>
 */
function dashboardLoadAccountCurrencyMap(
    PDO $pdo,
    array $accountIds,
    array $companyIds,
    bool $accountCurrencyOnly = false,
    bool $subsidiaryOnly = false
): array {
    $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
    $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
    $companyIds = array_values(array_filter($companyIds, static fn(int $id): bool => $id > 0));
    if ($accountIds === []) {
        return [];
    }

    $map = [];
    $currencyScopeSql = $subsidiaryOnly ? dashboard_sql_currency_subsidiary_only($pdo, 'c') : '';
    if (dashboardHasAccountCurrencyTable($pdo)) {
        $ph = implode(',', array_fill(0, count($accountIds), '?'));
        if ($accountCurrencyOnly) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, UPPER(c.code) AS code
                FROM account_currency ac
                INNER JOIN currency c ON c.id = ac.currency_id
                WHERE ac.account_id IN ($ph){$currencyScopeSql}
            ");
            $stmt->execute($accountIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int) $row['id']] = strtoupper((string) $row['code']);
            }
        } elseif ($companyIds !== []) {
            foreach ($companyIds as $companyId) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, UPPER(c.code) AS code
                    FROM account_currency ac
                    INNER JOIN currency c ON c.id = ac.currency_id AND c.company_id = ?{$currencyScopeSql}
                    WHERE ac.account_id IN ($ph)
                ");
                $stmt->execute(array_merge([$companyId], $accountIds));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $map[(int) $row['id']] = strtoupper((string) $row['code']);
                }
            }
        }
    }

    if ($map === [] && dashboardAccountHasCurrencyIdColumn($pdo)) {
        $ph = implode(',', array_fill(0, count($accountIds), '?'));
        if ($accountCurrencyOnly) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, UPPER(c.code) AS code
                FROM account a
                INNER JOIN currency c ON c.id = a.currency_id
                WHERE a.id IN ($ph)
                  AND a.currency_id IS NOT NULL{$currencyScopeSql}
            ");
            $stmt->execute($accountIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int) $row['id']] = strtoupper((string) $row['code']);
            }
        } elseif ($companyIds !== []) {
            foreach ($companyIds as $companyId) {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, UPPER(c.code) AS code
                    FROM account a
                    INNER JOIN currency c ON c.id = a.currency_id AND c.company_id = ?{$currencyScopeSql}
                    WHERE a.id IN ($ph)
                      AND a.currency_id IS NOT NULL
                ");
                $stmt->execute(array_merge([$companyId], $accountIds));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $map[(int) $row['id']] = strtoupper((string) $row['code']);
                }
            }
        }
    }

    if ($accountCurrencyOnly) {
        return $map;
    }

    return dashboardIntersectAccountCurrencyWithCompanyTable($pdo, $map, $companyIds, $subsidiaryOnly);
}

/**
 * Dashboard currency bar from scoped accounts.
 *
 * @param int[] $accountIds
 * @param int[] $currencyCompanyIds
 * @param bool $accountCurrencyOnly never fall back to company currency table
 * @return array<int, string>
 */
function dashboardFinalizeScopeCurrencyMap(
    PDO $pdo,
    array $accountIds,
    array $currencyCompanyIds,
    bool $accountCurrencyOnly = false
): array {
    $currencyCompanyIds = array_values(array_unique(array_filter(array_map('intval', $currencyCompanyIds))));
    $accountIds = array_values(array_unique(array_map('intval', $accountIds)));

    if ($accountIds === []) {
        return [];
    }

    return dashboardLoadAccountCurrencyMap($pdo, $accountIds, $currencyCompanyIds, $accountCurrencyOnly);
}

/**
 * Group / group-tab dashboard: currency filter map from scoped accounts, not full company list.
 *
 * @return array<int, string>
 */
function dashboardResolveFilterCurrencyMap(
    PDO $pdo,
    int $companyId,
    ?string $viewGroup,
    int $groupScopeId = 0,
    bool $subsidiaryOnly = false
): array {
    $viewGroupNorm = $subsidiaryOnly ? '' : reportNormalizeGroupId($viewGroup ?? '');
    $companyIds = [];

    if ($groupScopeId > 0) {
        $groupCode = dashboardResolveGroupCodeFromScopeId($pdo, $groupScopeId);
        if ($groupCode !== '') {
            return dashboardResolveGroupScopeCurrencyMap($pdo, $groupCode);
        }
        return [];
    }
    if ($viewGroupNorm !== '' && $companyId <= 0) {
        return dashboardResolveGroupScopeCurrencyMap($pdo, $viewGroupNorm);
    }

    $accountIds = dashboardCollectScopeAccountIds(
        $pdo,
        $companyId,
        $viewGroupNorm !== '' ? $viewGroupNorm : null,
        0,
        $subsidiaryOnly
    );
    if ($companyId > 0) {
        $companyIds[] = $companyId;
        if ($viewGroupNorm !== '' && !$subsidiaryOnly) {
            $entityId = tx_resolve_group_entity_company_id($pdo, $viewGroupNorm);
            if ($entityId > 0) {
                $companyIds[] = $entityId;
            }
        }
    }

    $companyIds = array_values(array_unique($companyIds));

    return dashboardFinalizeScopeCurrencyMap($pdo, $accountIds, $companyIds, false);
}

/**
 * Under a group tab, EXPENSES accounts often sit on the group-entity company (e.g. IG)
 * while PROFIT / data capture stays on the subsidiary (e.g. 95).
 *
 * @return int[]
 */
function dashboardResolveRoleScopeCompanyIds(
    PDO $pdo,
    int $companyId,
    string $role,
    ?string $viewGroup,
    bool $subsidiaryOnly = false
): array {
    $scopes = [$companyId];
    if ($role !== 'EXPENSES') {
        return $scopes;
    }

    $groupCodes = [];
    $fromParam = reportNormalizeGroupId($viewGroup ?? '');
    if ($fromParam !== '') {
        $groupCodes[$fromParam] = true;
    }

    $nativeStmt = $pdo->prepare('SELECT UPPER(TRIM(COALESCE(group_id, ""))) FROM company WHERE id = ? LIMIT 1');
    $nativeStmt->execute([$companyId]);
    $nativeGroup = reportNormalizeGroupId($nativeStmt->fetchColumn() ?: '');
    if ($nativeGroup !== '') {
        $groupCodes[$nativeGroup] = true;
    }

    foreach (array_keys($groupCodes) as $groupCode) {
        $entityId = tx_resolve_group_entity_company_id($pdo, $groupCode);
        if ($entityId > 0 && $entityId !== $companyId) {
            $scopes[] = $entityId;
        }
    }

    return array_values(array_unique($scopes));
}

function dashboardEmptyRoleBucket(string $role): array
{
    return [
        'role' => $role,
        'total_balance' => dashboardMoneyZero(),
        'initial_balance' => dashboardMoneyZero(),
        'period_total' => dashboardMoneyZero(),
        'daily_data' => [],
    ];
}

/**
 * Role filter aligned with legacy dashboard + Transaction List (EXPENSES / EXPENSE).
 *
 * @return array{0: string, 1: array<int, string>}
 */
function dashboardRoleFilterSql(string $role, string $alias = 'a'): array
{
    $col = ($alias !== '' ? $alias . '.' : '') . 'role';
    $roleUp = strtoupper(trim($role));
    if ($roleUp === 'EXPENSES') {
        return [
            "UPPER(TRIM(COALESCE({$col}, ''))) IN ('EXPENSES', 'EXPENSE')",
            [],
        ];
    }

    return [
        "UPPER(TRIM(COALESCE({$col}, ''))) = ?",
        [$roleUp],
    ];
}

/** Avoid utf8mb4_general_ci vs utf8mb4_unicode_ci mix on string compares (Hostinger default). */
function dashboardSqlUnicodeCi(string $expr): string
{
    return "CONVERT(($expr) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

function dashboardEnsureConnectionCollation(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {
    }
    $done = true;
}

/** Match data_capture_details.account_id to transactions account column (id or account code). */
function dashboardDcdToTxnAccountMatchSql(string $dcdAlias, string $accountColumn): string
{
    if ($accountColumn !== 'from_account_id') {
        $accountColumn = 'account_id';
    }
    $tCol = 't.`' . $accountColumn . '`';
    $dcdCast = dashboardSqlUnicodeCi("CAST({$dcdAlias}.account_id AS CHAR)");
    $txnCast = dashboardSqlUnicodeCi("CAST({$tCol} AS CHAR)");
    $dcdTrim = dashboardSqlUnicodeCi("TRIM(COALESCE({$dcdAlias}.account_id, ''))");
    $txnTrim = dashboardSqlUnicodeCi("TRIM(CAST({$tCol} AS CHAR))");

    return "({$dcdCast} = {$txnCast} OR {$dcdTrim} = {$txnTrim})";
}

/**
 * Filter capture rows by currency code (avoids mixing currency_id across companies).
 *
 * @return array{0: string, 1: array<int, string>}
 */
function dashboardCaptureCurrencyFilterSql(?string $filterCurrencyCode, string $dcdAlias = 'dcd'): array
{
    if ($filterCurrencyCode === null || trim($filterCurrencyCode) === '') {
        return ['', []];
    }
    $code = strtoupper(trim($filterCurrencyCode));
    $codeExpr = dashboardSqlUnicodeCi('UPPER(TRIM(cur.code))');

    return [
        " AND EXISTS (
            SELECT 1 FROM currency cur
            WHERE cur.id = {$dcdAlias}.currency_id
              AND {$codeExpr} = ?
        )",
        [$code],
    ];
}

/**
 * Filter transactions by currency code (aligned with search_api).
 *
 * @return array{0: string, 1: array<int, string>}
 */
function dashboardTransactionCurrencyFilterSql(?string $filterCurrencyCode, string $accountColumn = 'account_id'): array
{
    if ($filterCurrencyCode === null || trim($filterCurrencyCode) === '') {
        return ['', []];
    }
    $code = strtoupper(trim($filterCurrencyCode));
    if ($accountColumn !== 'from_account_id') {
        $accountColumn = 'account_id';
    }
    $codeExpr = dashboardSqlUnicodeCi('UPPER(TRIM(cur.code))');
    $dcdMatch = dashboardDcdToTxnAccountMatchSql('dcd', $accountColumn);

    return [
        " AND (
            EXISTS (
                SELECT 1 FROM currency cur
                WHERE cur.id = t.currency_id
                  AND {$codeExpr} = ?
            )
            OR (
                t.currency_id IS NULL
                AND EXISTS (
                    SELECT 1
                    FROM data_capture_details dcd
                    JOIN data_captures dc ON dcd.capture_id = dc.id
                    JOIN currency cur ON cur.id = dcd.currency_id
                    WHERE dcd.company_id = t.company_id
                      AND dc.company_id = t.company_id
                      AND {$dcdMatch}
                      AND {$codeExpr} = ?
                )
            )
        )",
        [$code, $code],
    ];
}

/**
 * @return array{0: string, 1: array<int, string>}
 */
function dashboardEntryCurrencyFilterSql(?string $filterCurrencyCode): array
{
    if ($filterCurrencyCode === null || trim($filterCurrencyCode) === '') {
        return ['', []];
    }
    $code = strtoupper(trim($filterCurrencyCode));

    $codeExpr = dashboardSqlUnicodeCi('UPPER(TRIM(cur.code))');

    return [
        " AND EXISTS (
            SELECT 1 FROM currency cur
            WHERE cur.id = e.currency_id
              AND {$codeExpr} = ?
        )",
        [$code],
    ];
}

/** Strict account.role match for Dashboard EXPENSES (aligned with Transaction List category=EXPENSES). */
function dashboardSqlExpensesRoleMatch(string $alias = 'a'): string
{
    $roleExpr = dashboardSqlUnicodeCi(
        'UPPER(TRIM(COALESCE(' . ($alias !== '' ? $alias . '.' : '') . "role, '')))"
    );

    return "{$roleExpr} IN ('EXPENSES', 'EXPENSE')";
}

/** @param array<string, mixed> $row */
function dashboardAccountRowIsExpensesRole(array $row): bool
{
    $role = strtoupper(trim((string) ($row['role'] ?? '')));

    return $role === 'EXPENSES' || $role === 'EXPENSE';
}

/**
 * Discover EXPENSES pool accounts (aligned with Transaction List category=EXPENSES).
 * Accounts may live on group-entity company while transactions post on subsidiary ledger.
 *
 * @return array<int, array<string, mixed>>
 */
function dashboardDiscoverExpenseAccounts(
    PDO $pdo,
    int $scopeCompanyId,
    int $ledgerCompanyId,
    ?string $dateToDb = null,
    bool $subsidiaryOnly = false
): array {
    $dateCap = $dateToDb !== null && trim($dateToDb) !== '' ? trim($dateToDb) : date('Y-m-d');
    $cacheKey = 'expense_accounts:'
        . $scopeCompanyId . ':'
        . $ledgerCompanyId . ':'
        . $dateCap . ':'
        . ($subsidiaryOnly ? '1' : '0');

    return dashboard_bootstrap_cache_remember($cacheKey, static function () use (
        $pdo,
        $scopeCompanyId,
        $ledgerCompanyId,
        $dateCap,
        $subsidiaryOnly
    ): array {
    $byId = [];
    $roleMatchSql = dashboardSqlExpensesRoleMatch('a');
    $acSubSql = $subsidiaryOnly ? dashboard_sql_account_company_subsidiary_only($pdo, 'ac') : '';
    $txnSubSql = $subsidiaryOnly ? dashboard_sql_txn_subsidiary_only($pdo, 't') : '';
    if ($subsidiaryOnly && $txnSubSql === '' && tx_table_has_scope_column($pdo, 'transactions')) {
        $txnSubSql = tx_sql_transaction_company_ledger_only('t');
    }

    $sql = "SELECT DISTINCT a.id, a.account_id, a.name, a.role
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ?
              {$acSubSql}
              AND {$roleMatchSql}
            ORDER BY a.account_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$scopeCompanyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!dashboardAccountRowIsExpensesRole($row)) {
            continue;
        }
        $byId[(int) $row['id']] = $row;
    }

    // search_api: from_account on subsidiary ledger may reference pool accounts not in account_company here.
    $contra = dashboardContraApprovedWhere($pdo, 't');
    $txnSql = "SELECT DISTINCT a.id, a.account_id, a.name, a.role
               FROM account a
               WHERE {$roleMatchSql}
                 AND a.id IN (
                   SELECT DISTINCT t.from_account_id
                   FROM transactions t
                   WHERE t.company_id = ?
                     AND t.from_account_id IS NOT NULL
                     AND t.transaction_date <= ?
                     AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'WIN', 'LOSE', 'ADJUSTMENT')
                     $contra{$txnSubSql}
                   UNION
                   SELECT DISTINCT t.account_id
                   FROM transactions t
                   WHERE t.company_id = ?
                     AND t.transaction_date <= ?
                     AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'WIN', 'LOSE', 'ADJUSTMENT')
                     $contra{$txnSubSql}
                 )
               ORDER BY a.account_id";
    $txnStmt = $pdo->prepare($txnSql);
    $txnStmt->execute([$ledgerCompanyId, $dateCap, $ledgerCompanyId, $dateCap]);
    foreach ($txnStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!dashboardAccountRowIsExpensesRole($row)) {
            continue;
        }
        $byId[(int) $row['id']] = $row;
    }

    return array_values($byId);
    });
}

/** EXPENSES pool: accounts on group entity, transactions on subsidiary (same as Transaction List). */
function dashboardLedgerCompanyIdForRole(string $role, int $primaryCompanyId, int $scopeCompanyId): int
{
    return $role === 'EXPENSES' ? $primaryCompanyId : $scopeCompanyId;
}

function dashboardRoleUsesProfitTransactionRules(string $role): bool
{
    return strtoupper(trim($role)) === 'PROFIT';
}

/** EXPENSES uses the same WIN/LOSE / ADJUSTMENT / RATE txn types as Transaction List (not PROFIT-only domain rules). */
function dashboardRoleUsesFullTransactionTypes(string $role): bool
{
    $roleUp = strtoupper(trim($role));

    return $roleUp === 'PROFIT' || $roleUp === 'EXPENSES';
}

/**
 * EXPENSES pool accounts may capture on group entity while transactions post on subsidiary ledger.
 *
 * @param int[] $scopeCompanyIds
 * @return int[]
 */
function dashboardCaptureCompanyIdsForRole(string $role, int $ledgerCompanyId, array $scopeCompanyIds): array
{
    // Capture rows always scoped to the ledger company (matches search_api company_id filter).
    return [$ledgerCompanyId];
}

function dashboardNormalizeSearchRange(string $dateFrom, string $dateTo): array
{
    $from = trim($dateFrom);
    $to = trim($dateTo);
    if (strlen($from) <= 10) {
        $from .= ' 00:00:00';
    }
    if (strlen($to) <= 10) {
        $to .= ' 23:59:59';
    }

    return [$from, $to];
}

/**
 * Match data_capture_details.account_id to account rows (numeric id and/or account code).
 * Aligned with search_api bulk keys: CAST(dcd.account_id) = '4530' OR TRIM(dcd.account_id) = 'EXPENSE'.
 *
 * @param array<int, array<string, mixed>> $accounts
 * @return array{0: string, 1: array<int, int|string>}
 */
function dashboardDcdAccountMatchFilterSql(array $accounts, string $dcdAlias = 'dcd'): array
{
    if ($accounts === []) {
        return [' AND 1=0', []];
    }

    $idKeys = [];
    $codeKeys = [];
    foreach ($accounts as $acc) {
        $id = (int) ($acc['id'] ?? 0);
        if ($id > 0) {
            $idKeys[(string) $id] = true;
        }
        $code = trim((string) ($acc['account_id'] ?? ''));
        if ($code !== '' && $code !== (string) $id) {
            $codeKeys[$code] = true;
        }
    }

    $clauses = [];
    $params = [];
    if ($idKeys !== []) {
        $ph = implode(',', array_fill(0, count($idKeys), '?'));
        $dcdCast = dashboardSqlUnicodeCi("CAST({$dcdAlias}.account_id AS CHAR)");
        $clauses[] = "{$dcdCast} IN ($ph)";
        foreach (array_keys($idKeys) as $key) {
            $params[] = $key;
        }
    }
    if ($codeKeys !== []) {
        $ph = implode(',', array_fill(0, count($codeKeys), '?'));
        $dcdTrim = dashboardSqlUnicodeCi("TRIM(COALESCE({$dcdAlias}.account_id, ''))");
        $clauses[] = "{$dcdTrim} IN ($ph)";
        foreach (array_keys($codeKeys) as $key) {
            $params[] = $key;
        }
    }

    if ($clauses === []) {
        return [' AND 1=0', []];
    }

    return [' AND (' . implode(' OR ', $clauses) . ')', $params];
}

/** @param int[] $accountIds */
function dashboardExpenseAccountDcdFilterSql(array $accountIds, string $dcdAlias = 'dcd'): array
{
    $accounts = [];
    foreach ($accountIds as $id) {
        $accounts[] = ['id' => (int) $id, 'account_id' => ''];
    }

    return dashboardDcdAccountMatchFilterSql($accounts, $dcdAlias);
}

function dashboardResolveCurrencyIdFromMap(?string $currencyCode, array $currencyMap): ?int
{
    if ($currencyCode === null || trim($currencyCode) === '') {
        return null;
    }
    $want = strtoupper(trim($currencyCode));
    foreach ($currencyMap as $id => $code) {
        if (strtoupper((string) $code) === $want) {
            return (int) $id;
        }
    }

    return null;
}

function dashboardWlTxnAmountSqlQuant2(string $signedContributionExpr): string
{
    return dcd_processed_amount_sql_quant2('(' . $signedContributionExpr . ')');
}

/**
 * EXPENSES Win/Loss series aligned with search_api calculateWinLossByCurrency (capture + WIN/LOSE + RATE_MM).
 *
 * @param array<int, array<string, mixed>> $accounts
 * @return array{daily: array<string, string>, capture_bf: string, period_wl: string}
 */
function dashboardExpensesBuildWinLossBundle(
    PDO $pdo,
    int $companyId,
    array $accounts,
    array $currencyMap,
    string $dateFromDb,
    string $dateToDb,
    ?string $currencyCode
): array {
    $currencyId = dashboardResolveCurrencyIdFromMap($currencyCode, $currencyMap);
    if ($currencyId === null || $accounts === []) {
        return [
            'daily' => [],
            'capture_bf' => dashboardMoneyZero(),
            'period_wl' => dashboardMoneyZero(),
        ];
    }

    $accountIds = array_values(array_unique(array_filter(
        array_map(static fn (array $acc): int => (int) ($acc['id'] ?? 0), $accounts),
        static fn (int $id): bool => $id > 0
    )));
    if ($accountIds === []) {
        return [
            'daily' => [],
            'capture_bf' => dashboardMoneyZero(),
            'period_wl' => dashboardMoneyZero(),
        ];
    }

    $daily = [];
    $captureBf = dashboardMoneyZero();
    $dcdQ = dcd_processed_amount_sql_quant2('dcd.processed_amount');
    list($acctFilterDcd, $acctParamsDcd) = dashboardDcdAccountMatchFilterSql($accounts);
    $dcdBaseWhere = "dcd.company_id = ? AND dc.company_id = ? AND dcd.currency_id = ?{$acctFilterDcd}";
    $dcdBindBase = [$companyId, $companyId, $currencyId, ...$acctParamsDcd];

    $sql = "SELECT COALESCE(SUM({$dcdQ}), 0)
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE {$dcdBaseWhere} AND dc.capture_date < ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($dcdBindBase, [$dateFromDb]));
    $captureBf = dashboardMoneyAdd($captureBf, $stmt->fetchColumn());

    $kpiOnlyRequest = !empty($GLOBALS['DASHBOARD_KPI_ONLY']);
    if ($kpiOnlyRequest) {
        $periodWl = dashboardMoneyZero();
        $sql = "SELECT COALESCE(SUM({$dcdQ}), 0)
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                WHERE {$dcdBaseWhere} AND dc.capture_date BETWEEN ? AND ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($dcdBindBase, [$dateFromDb, $dateToDb]));
        $periodWl = dashboardMoneyAdd($periodWl, $stmt->fetchColumn());

        if (!dashboardHasTransactionCurrency($pdo)) {
            return [
                'daily' => [],
                'capture_bf' => $captureBf,
                'period_wl' => $periodWl,
            ];
        }

        $idsPlaceholder = implode(',', array_fill(0, count($accountIds), '?'));
        $contra = dashboardContraApprovedWhere($pdo, 't');
        $txnSubSql = dashboard_sql_txn_subsidiary_only($pdo, 't');
        $txnSubSqlH = dashboard_sql_txn_subsidiary_only($pdo, 'h');
        $processDesc = "(t.description LIKE 'Process: %' OR t.description LIKE 'Inactive Compensation %' OR t.description LIKE 'Compensation %')";
        $manualDesc = "((t.description NOT LIKE 'Process: %' AND t.description NOT LIKE 'Inactive Compensation %' AND t.description NOT LIKE 'Compensation %') OR t.description IS NULL)";
        $bankWin = dashboardWlTxnAmountSqlQuant2('t.amount');
        $bankLose = dashboardWlTxnAmountSqlQuant2('-t.amount');

        $sql = "SELECT COALESCE(SUM(CASE
                    WHEN t.transaction_type = 'WIN' AND {$processDesc} THEN {$bankWin}
                    WHEN t.transaction_type = 'LOSE' AND {$processDesc} THEN {$bankLose}
                    ELSE 0 END), 0) AS wl
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.account_id IN ($idsPlaceholder)
                  AND t.currency_id = ?
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN ('WIN', 'LOSE')
                  AND {$processDesc}
                  {$contra}{$txnSubSql}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
        $periodWl = dashboardMoneyAdd($periodWl, $stmt->fetchColumn());

        $manualWinTo = dashboardWlTxnAmountSqlQuant2('-t.amount');
        $manualLoseTo = dashboardWlTxnAmountSqlQuant2('t.amount');
        $manualAdj = dashboardWlTxnAmountSqlQuant2('t.amount');
        $sql = "SELECT COALESCE(SUM(CASE WHEN t.transaction_type = 'WIN' THEN {$manualWinTo}
                    WHEN t.transaction_type = 'LOSE' THEN {$manualLoseTo}
                    WHEN t.transaction_type = 'ADJUSTMENT' THEN {$manualAdj}
                    ELSE 0 END), 0) AS wl
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.account_id IN ($idsPlaceholder)
                  AND t.currency_id = ?
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN ('WIN', 'LOSE', 'ADJUSTMENT')
                  AND {$manualDesc}
                  {$contra}{$txnSubSql}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
        $periodWl = dashboardMoneyAdd($periodWl, $stmt->fetchColumn());

        $manualWinFrom = dashboardWlTxnAmountSqlQuant2('t.amount');
        $manualLoseFrom = dashboardWlTxnAmountSqlQuant2('-t.amount');
        $sql = "SELECT COALESCE(SUM(CASE WHEN t.transaction_type = 'WIN' THEN {$manualWinFrom}
                    WHEN t.transaction_type = 'LOSE' THEN {$manualLoseFrom}
                    ELSE 0 END), 0) AS wl
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id IN ($idsPlaceholder)
                  AND t.currency_id = ?
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN ('WIN', 'LOSE')
                  AND {$manualDesc}
                  {$contra}{$txnSubSql}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
        $periodWl = dashboardMoneyAdd($periodWl, $stmt->fetchColumn());

        if (dashboardHasTransactionEntry($pdo)) {
            $mmQ = dashboardWlTxnAmountSqlQuant2('e.amount');
            $sql = "SELECT COALESCE(SUM({$mmQ}), 0) AS wl
                    FROM transaction_entry e
                    JOIN transactions h ON e.header_id = h.id
                    WHERE h.company_id = ?
                      AND e.company_id = ?
                      AND e.account_id IN ($idsPlaceholder)
                      AND e.currency_id = ?
                      AND e.entry_type = 'RATE_MIDDLEMAN'
                      AND h.transaction_date BETWEEN ? AND ?{$txnSubSqlH}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$companyId, $companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
            $periodWl = dashboardMoneyAdd($periodWl, $stmt->fetchColumn());
        }

        return [
            'daily' => [],
            'capture_bf' => $captureBf,
            'period_wl' => $periodWl,
        ];
    }

    $sql = "SELECT DATE(dc.capture_date) AS date, COALESCE(SUM({$dcdQ}), 0) AS wl
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE {$dcdBaseWhere} AND dc.capture_date BETWEEN ? AND ?
            GROUP BY DATE(dc.capture_date)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($dcdBindBase, [$dateFromDb, $dateToDb]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboardAddDailyAmount($daily, (string) $row['date'], $row['wl'] ?? '0');
    }

    if (!dashboardHasTransactionCurrency($pdo)) {
        return [
            'daily' => $daily,
            'capture_bf' => $captureBf,
            'period_wl' => dashboardSumDailyAmounts($daily),
        ];
    }

    $idsPlaceholder = implode(',', array_fill(0, count($accountIds), '?'));
    $contra = dashboardContraApprovedWhere($pdo, 't');
    $txnSubSql = dashboard_sql_txn_subsidiary_only($pdo, 't');
    $txnSubSqlH = dashboard_sql_txn_subsidiary_only($pdo, 'h');
    $processDesc = "(t.description LIKE 'Process: %' OR t.description LIKE 'Inactive Compensation %' OR t.description LIKE 'Compensation %')";
    $manualDesc = "((t.description NOT LIKE 'Process: %' AND t.description NOT LIKE 'Inactive Compensation %' AND t.description NOT LIKE 'Compensation %') OR t.description IS NULL)";

    $bankWin = dashboardWlTxnAmountSqlQuant2('t.amount');
    $bankLose = dashboardWlTxnAmountSqlQuant2('-t.amount');
    $sql = "SELECT DATE(t.transaction_date) AS date,
                   COALESCE(SUM(CASE
                       WHEN t.transaction_type = 'WIN' AND {$processDesc} THEN {$bankWin}
                       WHEN t.transaction_type = 'LOSE' AND {$processDesc} THEN {$bankLose}
                       ELSE 0 END), 0) AS wl
            FROM transactions t
            WHERE t.company_id = ?
              AND t.account_id IN ($idsPlaceholder)
              AND t.currency_id = ?
              AND t.transaction_date BETWEEN ? AND ?
              AND t.transaction_type IN ('WIN', 'LOSE')
              AND {$processDesc}
              {$contra}{$txnSubSql}
            GROUP BY DATE(t.transaction_date)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboardAddDailyAmount($daily, (string) $row['date'], $row['wl'] ?? '0');
    }

    $manualWinTo = dashboardWlTxnAmountSqlQuant2('-t.amount');
    $manualLoseTo = dashboardWlTxnAmountSqlQuant2('t.amount');
    $manualAdj = dashboardWlTxnAmountSqlQuant2('t.amount');
    $sql = "SELECT DATE(t.transaction_date) AS date,
                   COALESCE(SUM(CASE WHEN t.transaction_type = 'WIN' THEN {$manualWinTo}
                       WHEN t.transaction_type = 'LOSE' THEN {$manualLoseTo}
                       WHEN t.transaction_type = 'ADJUSTMENT' THEN {$manualAdj}
                       ELSE 0 END), 0) AS wl
            FROM transactions t
            WHERE t.company_id = ?
              AND t.account_id IN ($idsPlaceholder)
              AND t.currency_id = ?
              AND t.transaction_date BETWEEN ? AND ?
              AND t.transaction_type IN ('WIN', 'LOSE', 'ADJUSTMENT')
              AND {$manualDesc}
              {$contra}{$txnSubSql}
            GROUP BY DATE(t.transaction_date)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboardAddDailyAmount($daily, (string) $row['date'], $row['wl'] ?? '0');
    }

    $manualWinFrom = dashboardWlTxnAmountSqlQuant2('t.amount');
    $manualLoseFrom = dashboardWlTxnAmountSqlQuant2('-t.amount');
    $sql = "SELECT DATE(t.transaction_date) AS date,
                   COALESCE(SUM(CASE WHEN t.transaction_type = 'WIN' THEN {$manualWinFrom}
                       WHEN t.transaction_type = 'LOSE' THEN {$manualLoseFrom}
                       ELSE 0 END), 0) AS wl
            FROM transactions t
            WHERE t.company_id = ?
              AND t.from_account_id IN ($idsPlaceholder)
              AND t.currency_id = ?
              AND t.transaction_date BETWEEN ? AND ?
              AND t.transaction_type IN ('WIN', 'LOSE')
              AND {$manualDesc}
              {$contra}{$txnSubSql}
            GROUP BY DATE(t.transaction_date)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboardAddDailyAmount($daily, (string) $row['date'], $row['wl'] ?? '0');
    }

    if (dashboardHasTransactionEntry($pdo)) {
        $mmQ = dashboardWlTxnAmountSqlQuant2('e.amount');
        $sql = "SELECT DATE(h.transaction_date) AS date, COALESCE(SUM({$mmQ}), 0) AS wl
                FROM transaction_entry e
                JOIN transactions h ON e.header_id = h.id
                WHERE h.company_id = ?
                  AND e.company_id = ?
                  AND e.account_id IN ($idsPlaceholder)
                  AND e.currency_id = ?
                  AND e.entry_type = 'RATE_MIDDLEMAN'
                  AND h.transaction_date BETWEEN ? AND ?{$txnSubSqlH}
                GROUP BY DATE(h.transaction_date)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$companyId, $companyId], $accountIds, [$currencyId, $dateFromDb, $dateToDb]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            dashboardAddDailyAmount($daily, (string) $row['date'], $row['wl'] ?? '0');
        }
    }

    return [
        'daily' => $daily,
        'capture_bf' => $captureBf,
        'period_wl' => dashboardSumDailyAmounts($daily),
    ];
}

/**
 * EXPENSES Cr/Dr series aligned with search_api calculateCrDrByCurrency (PAYMENT/RECEIVE/CONTRA/CLAIM; excludes CLEAR).
 *
 * @param array<int, array<string, mixed>> $accounts
 * @return array{daily: array<string, string>, period_cr_dr: string}
 */
function dashboardExpensesBuildCrDrBundle(
    PDO $pdo,
    int $companyId,
    array $accounts,
    array $currencyMap,
    string $dateFromDb,
    string $dateToDb,
    ?string $currencyCode
): array {
    $currencyId = dashboardResolveCurrencyIdFromMap($currencyCode, $currencyMap);
    $accountIds = array_values(array_unique(array_filter(
        array_map(static fn (array $acc): int => (int) ($acc['id'] ?? 0), $accounts),
        static fn (int $id): bool => $id > 0
    )));
    if ($currencyId === null || $accountIds === [] || !dashboardHasTransactionCurrency($pdo)) {
        return [
            'daily' => [],
            'period_cr_dr' => dashboardMoneyZero(),
        ];
    }

    $kpiOnlyRequest = !empty($GLOBALS['DASHBOARD_KPI_ONLY']);
    if ($kpiOnlyRequest) {
        $idsPlaceholder = implode(',', array_fill(0, count($accountIds), '?'));
        $clearFilter = dashboardShouldExcludeClearForRole('EXPENSES') ? " AND t.transaction_type <> 'CLEAR'" : '';
        $contra = dashboardContraApprovedWhere($pdo, 't');
        $crDrTypes = "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM')";
        $periodCrDr = dashboardMoneyZero();

        $toSql = "SELECT COALESCE(SUM(CASE
                         WHEN t.transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                         WHEN t.transaction_type = 'CONTRA' THEN -t.amount
                         WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN t.amount
                         WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                         WHEN t.transaction_type = 'PAYMENT'
                              AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN t.amount
                         WHEN t.transaction_type = 'PAYMENT' THEN -t.amount
                         ELSE 0
                     END), 0) AS cr_dr
                  FROM transactions t
                  WHERE t.company_id = ?
                    AND t.account_id IN ($idsPlaceholder)
                    AND t.transaction_date BETWEEN ? AND ?
                    AND t.transaction_type IN $crDrTypes
                    AND t.currency_id = ?"
            . $clearFilter . $contra . dashboard_sql_txn_subsidiary_only($pdo, 't');
        $toStmt = $pdo->prepare($toSql);
        $toStmt->execute(array_merge([$companyId], $accountIds, [$dateFromDb, $dateToDb, $currencyId]));
        $periodCrDr = dashboardMoneyAdd($periodCrDr, $toStmt->fetchColumn());

        $fromSql = "SELECT COALESCE(SUM(CASE
                           WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN 0
                           WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                           WHEN t.transaction_type = 'PAYMENT'
                                AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN -t.amount
                           WHEN t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM') THEN t.amount
                           WHEN t.transaction_type = 'CONTRA' THEN t.amount
                           ELSE 0
                       END), 0) AS cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id IN ($idsPlaceholder)
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN $crDrTypes
                  AND t.currency_id = ?"
            . $clearFilter . $contra . dashboard_sql_txn_subsidiary_only($pdo, 't');
        $fromStmt = $pdo->prepare($fromSql);
        $fromStmt->execute(array_merge([$companyId], $accountIds, [$dateFromDb, $dateToDb, $currencyId]));
        $periodCrDr = dashboardMoneyAdd($periodCrDr, $fromStmt->fetchColumn());

        if (dashboardHasTransactionEntry($pdo)) {
            try {
                $rateSql = "SELECT COALESCE(SUM(CASE
                                   WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                   WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                   ELSE e.amount
                               END), 0) AS cr_dr
                            FROM transaction_entry e
                            JOIN transactions h ON e.header_id = h.id
                            WHERE h.company_id = ?
                              AND e.company_id = ?
                              AND e.account_id IN ($idsPlaceholder)
                              AND e.currency_id = ?
                              AND h.transaction_type = 'RATE'
                              AND h.transaction_date BETWEEN ? AND ?
                              AND e.entry_type <> 'RATE_MIDDLEMAN'" . dashboard_sql_txn_subsidiary_only($pdo, 'h');
                $rateStmt = $pdo->prepare($rateSql);
                $rateStmt->execute(array_merge(
                    [$companyId, $companyId],
                    $accountIds,
                    [$currencyId, $dateFromDb, $dateToDb]
                ));
                $periodCrDr = dashboardMoneyAdd($periodCrDr, $rateStmt->fetchColumn());
            } catch (Throwable $e) {
            }
        }

        return [
            'daily' => [],
            'period_cr_dr' => $periodCrDr,
        ];
    }

    $daily = [];
    $idsPlaceholder = implode(',', array_fill(0, count($accountIds), '?'));
    $clearFilter = dashboardShouldExcludeClearForRole('EXPENSES') ? " AND t.transaction_type <> 'CLEAR'" : '';
    $contra = dashboardContraApprovedWhere($pdo, 't');
    $crDrTypes = "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM')";

    $toSql = "SELECT DATE(t.transaction_date) AS date,
                     COALESCE(SUM(CASE
                         WHEN t.transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                         WHEN t.transaction_type = 'CONTRA' THEN -t.amount
                         WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN t.amount
                         WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                         WHEN t.transaction_type = 'PAYMENT'
                              AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN t.amount
                         WHEN t.transaction_type = 'PAYMENT' THEN -t.amount
                         ELSE 0
                     END), 0) AS cr_dr
              FROM transactions t
              WHERE t.company_id = ?
                AND t.account_id IN ($idsPlaceholder)
                AND t.transaction_date BETWEEN ? AND ?
                AND t.transaction_type IN $crDrTypes
                AND t.currency_id = ?"
        . $clearFilter . $contra . dashboard_sql_txn_subsidiary_only($pdo, 't') . '
              GROUP BY DATE(t.transaction_date)';
    $toStmt = $pdo->prepare($toSql);
    $toStmt->execute(array_merge([$companyId], $accountIds, [$dateFromDb, $dateToDb, $currencyId]));
    foreach ($toStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboardAddDailyAmount($daily, (string) $row['date'], $row['cr_dr'] ?? '0');
    }

    $fromSql = "SELECT DATE(t.transaction_date) AS date,
                       COALESCE(SUM(CASE
                           WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN 0
                           WHEN t.transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                           WHEN t.transaction_type = 'PAYMENT'
                                AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN -t.amount
                           WHEN t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM') THEN t.amount
                           WHEN t.transaction_type = 'CONTRA' THEN t.amount
                           ELSE 0
                       END), 0) AS cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id IN ($idsPlaceholder)
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN $crDrTypes
                  AND t.currency_id = ?"
        . $clearFilter . $contra . dashboard_sql_txn_subsidiary_only($pdo, 't') . '
                GROUP BY DATE(t.transaction_date)';
    $fromStmt = $pdo->prepare($fromSql);
    $fromStmt->execute(array_merge([$companyId], $accountIds, [$dateFromDb, $dateToDb, $currencyId]));
    foreach ($fromStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        dashboardAddDailyAmount($daily, (string) $row['date'], $row['cr_dr'] ?? '0');
    }

    if (dashboardHasTransactionEntry($pdo)) {
        try {
            $rateSql = "SELECT DATE(h.transaction_date) AS date,
                               COALESCE(SUM(CASE
                                   WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                   WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                   ELSE e.amount
                               END), 0) AS cr_dr
                        FROM transaction_entry e
                        JOIN transactions h ON e.header_id = h.id
                        WHERE h.company_id = ?
                          AND e.company_id = ?
                          AND e.account_id IN ($idsPlaceholder)
                          AND e.currency_id = ?
                          AND h.transaction_type = 'RATE'
                          AND h.transaction_date BETWEEN ? AND ?
                          AND e.entry_type <> 'RATE_MIDDLEMAN'" . dashboard_sql_txn_subsidiary_only($pdo, 'h') . "
                        GROUP BY DATE(h.transaction_date)";
            $rateStmt = $pdo->prepare($rateSql);
            $rateStmt->execute(array_merge(
                [$companyId, $companyId],
                $accountIds,
                [$currencyId, $dateFromDb, $dateToDb]
            ));
            foreach ($rateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                dashboardAddDailyAmount($daily, (string) $row['date'], $row['cr_dr'] ?? '0');
            }
        } catch (Throwable $e) {
        }
    }

    return [
        'daily' => $daily,
        'period_cr_dr' => dashboardSumDailyAmounts($daily),
    ];
}

function dashboard_api_main(): void
{
    global $pdo;
    if (!$pdo instanceof PDO) {
        throw new Exception('Database connection failed');
    }
    dashboardEnsureConnectionCollation($pdo);

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    if (!user_can_access_dashboard($pdo)) {
        throw new Exception('无权访问 Dashboard');
    }

    // 获取搜索参数
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;

    // 获取 company_id：优先使用参数，否则使用 session
    $company_id = null;
    $requestedCompanyId = isset($_GET['company_id']) && $_GET['company_id'] !== ''
        ? (int) $_GET['company_id']
        : 0;
    $subsidiaryAccountsOnly = isset($_GET['subsidiary_accounts_only'])
        && (string) $_GET['subsidiary_accounts_only'] === '1';

    $viewGroupForAccess = isset($_GET['view_group']) ? trim((string) $_GET['view_group']) : null;

    if ($requestedCompanyId > 0) {
        if (gc_is_group_login()) {
            if (!gc_session_can_access_company_id($pdo, $requestedCompanyId, $viewGroupForAccess)) {
                throw new Exception('无权访问该公司');
            }
            $company_id = $requestedCompanyId;
        } else {
            $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
            if ($userRole === 'owner') {
                $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
                $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
                $stmt->execute([$requestedCompanyId, $owner_id]);
                if ($stmt->fetchColumn()) {
                    $company_id = $requestedCompanyId;
                } elseif (
                    $viewGroupForAccess !== null
                    && $viewGroupForAccess !== ''
                    && gc_session_can_access_subsidiary_under_view_group(
                        $pdo,
                        $requestedCompanyId,
                        $viewGroupForAccess
                    )
                ) {
                    $company_id = $requestedCompanyId;
                } else {
                    throw new Exception('无权访问该公司');
                }
            } else {
                if (isset($_SESSION['company_id']) && (int) $_SESSION['company_id'] === $requestedCompanyId) {
                    $company_id = $requestedCompanyId;
                } else {
                    $ucm_stmt = $pdo->prepare("SELECT 1 FROM user_company_map WHERE user_id = ? AND company_id = ? LIMIT 1");
                    $ucm_stmt->execute([$_SESSION['user_id'], $requestedCompanyId]);
                    if ($ucm_stmt->fetchColumn()) {
                        $company_id = $requestedCompanyId;
                    } elseif (
                        $viewGroupForAccess !== null
                        && $viewGroupForAccess !== ''
                        && gc_session_can_access_subsidiary_under_view_group(
                            $pdo,
                            $requestedCompanyId,
                            $viewGroupForAccess
                        )
                    ) {
                        $company_id = $requestedCompanyId;
                    } else {
                        throw new Exception('无权访问该公司');
                    }
                }
            }
        }
    } else {
        // Group-only request (no company_id): handled below. Do not fall back to session company.
        $groupOnlyParam = reportNormalizeGroupId($_GET['group_id'] ?? '');
        $viewGroupOnlyParam = reportNormalizeGroupId($_GET['view_group'] ?? '');
        $hasGroupOnlyRequest = $groupOnlyParam !== '' || $viewGroupOnlyParam !== '';
        if (!gc_is_group_login() && !$hasGroupOnlyRequest) {
            if (!isset($_SESSION['company_id'])) {
                throw new Exception('用户未登录或缺少公司信息');
            }
            $company_id = (int) $_SESSION['company_id'];
        }
    }

    // 如果没有提供日期范围，默认使用当月
    if (!$date_from || !$date_to) {
        $currentYear = date('Y');
        $currentMonth = date('m');
        $date_from = "$currentYear-$currentMonth-01";
        $date_to = date('Y-m-t'); // 当月最后一天
    }

    list($date_from_db, $date_to_db) = dashboardNormalizeSearchRange($date_from, $date_to);

    // 可选：按币别筛选（传 currency 为 code，如 MYR、USD）
    $filter_currency_code = null;
    if (isset($_GET['currency']) && trim((string) $_GET['currency']) !== '') {
        $filter_currency_code = strtoupper(trim((string) $_GET['currency']));
    }
    $kpiOnly = dashboard_api_kpi_only();
    $earningsOnly = dashboard_api_earnings_only();
    $GLOBALS['DASHBOARD_KPI_ONLY'] = $kpiOnly;
    $GLOBALS['DASHBOARD_EARNINGS_ONLY'] = $earningsOnly;

    // No company_id: group ledger only (scope_type=group). Distinct from company_id-scoped rows.
    $groupLedgerCode = reportNormalizeGroupId($_GET['view_group'] ?? '');
    if ($groupLedgerCode === '') {
        $groupLedgerCode = reportNormalizeGroupId($_GET['group_id'] ?? '');
    }
    if ($groupLedgerCode === '' && gc_is_group_login()) {
        $groupLedgerCode = (string) (gc_session_login_identifier() ?? '');
    }
    $useGroupLedger = $requestedCompanyId <= 0 && $groupLedgerCode !== '';
    $groupScopeId = 0;

    if ($useGroupLedger) {
        // Company login may still use legacy group-entity row; group login always uses group ledger.
        if (!dashboard_should_force_pure_group_ledger($pdo)) {
            $groupEntityCompanyId = tx_resolve_group_entity_company_id($pdo, $groupLedgerCode);
            if ($groupEntityCompanyId > 0) {
                assertGroupEntityAccess($pdo, $groupLedgerCode, $groupEntityCompanyId);
                $company_id = $groupEntityCompanyId;
                $useGroupLedger = false;
            }
        }
        if ($useGroupLedger) {
            $groupScopeId = dashboardResolveGroupScopeId($pdo, $groupLedgerCode);
            if ($groupScopeId <= 0) {
                $dbName = '';
                try {
                    $dbName = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
                } catch (Throwable $ignored) {
                    $dbName = '';
                }
                throw new Exception(
                    'Group scope is invalid or not initialized (group_code='
                    . $groupLedgerCode
                    . ($dbName !== '' ? ', database=' . $dbName : '')
                    . '). Confirm migration 20260528_dual_tenant_company_group.sql on this database.'
                );
            }
            if (!gc_session_can_access_group_ledger($pdo, $groupLedgerCode)) {
                throw new Exception('无权访问该 Group Ledger');
            }
            dashboardAssertGroupLedgerAccess($pdo, $groupLedgerCode, $groupScopeId);
        }
    }

    // Pure group ledger (no group-entity company row such as company_id=AP).
    if ($useGroupLedger) {
        $groupResult = dashboardBuildGroupScopedSummary(
            $pdo,
            $date_from_db,
            $date_to_db,
            $groupScopeId,
            $filter_currency_code
        );
        $groupLedgerNetProfit = dashboardGroupPeriodNetProfitFromSummary($groupResult);
        $ownershipMonth = dashboardResolveOwnershipMonthFromDate((string) $date_to);
        $viewerGroupShare = dashboardLoadViewerGroupAccountPercentage(
            $pdo,
            $groupLedgerCode,
            $ownershipMonth['effective_month'],
            $ownershipMonth['use_history']
        );
        $groupAccountPctForSubsidiaries = (float) ($viewerGroupShare['percentage'] ?? 0);
        $subsidiaryEarnings = dashboardComputeSubsidiaryEarningsTotal(
            $pdo,
            $groupLedgerCode,
            (string) $date_from,
            (string) $date_to,
            $filter_currency_code,
            $kpiOnly,
            $groupAccountPctForSubsidiaries
        );
        dashboardApplySingleSubsidiaryGroupLedgerEarnings(
            $pdo,
            $subsidiaryEarnings,
            $groupLedgerCode,
            $groupLedgerNetProfit,
            $groupAccountPctForSubsidiaries
        );
        $hasGroupOwnershipProfit = dashboardMergeGroupOwnershipProfitShare(
            $pdo,
            $groupResult,
            $groupLedgerCode,
            (string) $date_from,
            (string) $date_to,
            $filter_currency_code,
            $kpiOnly,
            $subsidiaryEarnings
        );
        $groupAccountPct = (float) ($viewerGroupShare['percentage'] ?? 0);
        $hasGroupAccountOwnership = !empty($viewerGroupShare['has']);
        echo json_encode([
            'success' => true,
            'data' => [
                'capital' => $groupResult['capital']['total_balance'],
                'expenses' => $groupResult['expenses']['period_total'],
                'profit' => $groupResult['profit']['total_balance'],
                'ownership_percentage' => 0,
                'has_ownership_setup' => $hasGroupOwnershipProfit || $hasGroupAccountOwnership
                    || money_cmp($subsidiaryEarnings['period_total'], '0') !== 0,
                'group_equity_percentage' => 0,
                'group_account_percentage' => $groupAccountPct,
                'has_group_ownership' => $hasGroupAccountOwnership,
                'group_ledger_net_profit' => dashboardOut($groupLedgerNetProfit),
                'subsidiary_earnings_total' => dashboardOut($subsidiaryEarnings['period_total']),
                'subsidiary_earnings_by_company' => $subsidiaryEarnings['by_company'] ?? [],
                '_group_aggregate_earnings' => true,
                'period_total' => [
                    'capital' => $groupResult['capital']['period_total'],
                    'expenses' => $groupResult['expenses']['period_total'],
                    'profit' => $groupResult['profit']['period_total']
                ],
                'initial_balance' => [
                    'capital' => $groupResult['capital']['initial_balance'],
                    'expenses' => $groupResult['expenses']['initial_balance'],
                    'profit' => $groupResult['profit']['initial_balance']
                ],
                'daily_data' => [
                    'capital' => $groupResult['capital']['daily_data'],
                    'expenses' => $groupResult['expenses']['daily_data'],
                    'profit' => $groupResult['profit']['daily_data'],
                    'profit_payment_flow_daily' => []
                ],
                'date_range' => [
                    'from' => $date_from,
                    'to' => $date_to
                ]
            ]
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Explicit company_id: standard company dashboard (company_id rows).

    // 使用 static 缓存函数，整个请求中只查一次 schema
    $hasTransactionCurrency = dashboardHasTransactionCurrency($pdo);

    $viewGroupCodeForScope = reportNormalizeGroupId($_GET['view_group'] ?? '');
    if (!$subsidiaryAccountsOnly && $viewGroupCodeForScope === '' && $company_id > 0) {
        $vgStmt = $pdo->prepare('SELECT UPPER(TRIM(COALESCE(group_id, ""))) FROM company WHERE id = ? LIMIT 1');
        $vgStmt->execute([$company_id]);
        $viewGroupCodeForScope = reportNormalizeGroupId($vgStmt->fetchColumn() ?: '');
    }

    // Group tab: currencies from account_currency on scoped accounts (not full company currency list).
    // Subsidiary drill-down (e.g. C168 under AP): company Currency Setting only — never group SGD.
    $GLOBALS['DASHBOARD_SUBSIDIARY_LEDGER'] = $subsidiaryAccountsOnly;
    if ($subsidiaryAccountsOnly) {
        $currency_map = dashboardLoadCurrencyMap($pdo, $company_id, true);
    } elseif ($viewGroupCodeForScope !== '') {
        $currency_map = dashboardResolveFilterCurrencyMap(
            $pdo,
            $company_id,
            $viewGroupCodeForScope,
            0,
            false
        );
    } else {
        $currency_map = dashboardLoadCurrencyMap($pdo, $company_id);
    }

    $scopeViewGroup = $subsidiaryAccountsOnly ? null : $viewGroupCodeForScope;
    $dashAcSubSql = $subsidiaryAccountsOnly ? dashboard_sql_account_company_subsidiary_only($pdo, 'ac') : '';
    $dashTxnSubSql = dashboard_sql_txn_subsidiary_only($pdo, 't');
    $dashTxnSubSqlH = dashboard_sql_txn_subsidiary_only($pdo, 'h');

    // 定义要查询的角色（earnings_only 只需 EXPENSES + PROFIT）
    $roles = $earningsOnly ? ['EXPENSES', 'PROFIT'] : ['CAPITAL', 'EXPENSES', 'PROFIT'];
    $result = [];

    foreach ($roles as $role) {
        $excludeClear = dashboardShouldExcludeClearForRole($role);
        $scopeCompanyIds = dashboardResolveRoleScopeCompanyIds(
            $pdo,
            $company_id,
            $role,
            $scopeViewGroup,
            $subsidiaryAccountsOnly
        );

        $total_balance = dashboardMoneyZero();
        $total_bf = dashboardMoneyZero();
        $daily_data = [];
        $daily_win_loss = [];
        $daily_cr_dr = [];
        $isExpensesRole = ($role === 'EXPENSES');
        $expenseAccountRowsById = [];
        $expensesPeriodWlFromBundle = null;
        $expensesPeriodCrDrFromBundle = null;
        $primaryAccountIds = [];
        $hadAccounts = false;
        $seenExpenseAccountIds = [];
        $rolePeriodDelta = dashboardMoneyZero();

        foreach ($scopeCompanyIds as $scopeCompanyId) {
            if ($role === 'EXPENSES') {
                $accounts = dashboardDiscoverExpenseAccounts($pdo, $scopeCompanyId, $company_id, $date_to_db, $subsidiaryAccountsOnly);
            } else {
                $roleAcctCacheKey = 'role_accounts:'
                    . $scopeCompanyId . ':'
                    . $role . ':'
                    . ($subsidiaryAccountsOnly ? '1' : '0');
                $accounts = dashboard_bootstrap_cache_remember($roleAcctCacheKey, static function () use (
                    $pdo,
                    $scopeCompanyId,
                    $role,
                    $subsidiaryAccountsOnly,
                    $dashAcSubSql
                ): array {
                list($roleFilterSql, $roleFilterParams) = dashboardRoleFilterSql($role, 'a');
                $sql = "SELECT DISTINCT a.id, a.account_id, a.name, a.role
                        FROM account a
                        INNER JOIN account_company ac ON a.id = ac.account_id
                        WHERE ac.company_id = ?
                          {$dashAcSubSql}
                          AND {$roleFilterSql}";

                $params = [];
                list($sql, $params) = filterAccountsByPermissions($pdo, $sql, [], $scopeCompanyId);
                $sql = preg_replace('/\bAND id IN\b/i', 'AND a.id IN', $sql);
                $sql = preg_replace('/\bWHERE id IN\b/i', 'WHERE a.id IN', $sql);

                $params = array_merge([$scopeCompanyId], $roleFilterParams, $params);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                return $stmt->fetchAll(PDO::FETCH_ASSOC);
                });
            }

            // EXPENSES 池账户：Dashboard 不按 account_permissions 白名单过滤

            $account_ids = array_values(array_unique(array_map('intval', array_column($accounts, 'id'))));
            if ($role === 'EXPENSES') {
                $account_ids = array_values(array_filter(
                    $account_ids,
                    static function (int $id) use (&$seenExpenseAccountIds): bool {
                        if ($id <= 0 || isset($seenExpenseAccountIds[$id])) {
                            return false;
                        }
                        $seenExpenseAccountIds[$id] = true;

                        return true;
                    }
                ));
            }
            if (empty($account_ids)) {
                continue;
            }
            $hadAccounts = true;
            if ($isExpensesRole) {
                foreach ($accounts as $accRow) {
                    if (!dashboardAccountRowIsExpensesRole($accRow)) {
                        continue;
                    }
                    $expenseAccountRowsById[(int) ($accRow['id'] ?? 0)] = $accRow;
                }
            }
            if ($scopeCompanyId === $company_id) {
                $primaryAccountIds = $account_ids;
            }

            // EXPENSES: pool accounts on group entity, ledger/transactions on subsidiary (see search_api).
            $ledgerCompanyId = dashboardLedgerCompanyIdForRole($role, $company_id, $scopeCompanyId);
            $captureCompanyIds = dashboardCaptureCompanyIdsForRole($role, $ledgerCompanyId, $scopeCompanyIds);
            $capture_company_placeholder = implode(',', array_fill(0, count($captureCompanyIds), '?'));
            $useProfitTxnRules = dashboardRoleUsesProfitTransactionRules($role);
            $useFullTxnTypes = dashboardRoleUsesFullTransactionTypes($role);

            $ids_placeholder = implode(',', array_fill(0, count($account_ids), '?'));
            list($currency_filter_dcd, $currency_params_dcd) = dashboardCaptureCurrencyFilterSql($filter_currency_code);
            list($currency_filter_t_to, $currency_params_t_to) = dashboardTransactionCurrencyFilterSql(
                $filter_currency_code,
                'account_id'
            );
            list($currency_filter_t_from, $currency_params_t_from) = dashboardTransactionCurrencyFilterSql(
                $filter_currency_code,
                'from_account_id'
            );
            list($currency_filter_e, $currency_params_e) = dashboardEntryCurrencyFilterSql($filter_currency_code);
            list($acct_filter_dcd, $acct_params_dcd) = dashboardDcdAccountMatchFilterSql($accounts);
            $dcdAmountSql = dcd_processed_amount_sql_quant2('dcd.processed_amount');

            // --- 1. 计算 B/F (Balance Forward) ---
            // A. Data Capture B/F (EXPENSES uses search_api-aligned bundle after scope loop)
            if (!$isExpensesRole) {
            $sql = "SELECT COALESCE(SUM({$dcdAmountSql}), 0)
                    FROM data_capture_details dcd
                    JOIN data_captures dc ON dcd.capture_id = dc.id
                    WHERE dc.company_id IN ($capture_company_placeholder)
                      AND dcd.company_id IN ($capture_company_placeholder)
                      AND dcd.currency_id IS NOT NULL
                      AND dc.capture_date < ?" . $acct_filter_dcd . $currency_filter_dcd;
            $bf_stmt = $pdo->prepare($sql);
            $bf_stmt->execute(array_merge(
                $captureCompanyIds,
                $captureCompanyIds,
                [$date_from_db],
                $acct_params_dcd,
                $currency_params_dcd
            ));
            $total_bf = dashboardMoneyAdd($total_bf, $bf_stmt->fetchColumn());
            }

        // B. Transactions B/F (To/From) — EXPENSES period uses search_api-aligned wl bundle only
        if ($hasTransactionCurrency && !$isExpensesRole) {
            $clearFilter = $excludeClear ? " AND t.transaction_type <> 'CLEAR'" : "";
            $contraApproval = dashboardContraApprovedWhere($pdo, 't');
            $fromDomainFilter = $useProfitTxnRules ? '' : "
                      AND COALESCE(t.sms, '') NOT LIKE '[DOMAIN_SHARE_COMMISSION|%'
                      AND COALESCE(t.sms, '') NOT LIKE '[DOMAIN_NET_PROFIT|%'";
            $bfTxnTypes = $useFullTxnTypes
                ? "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE', 'ADJUSTMENT')"
                : "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM')";
            $dailyTxnTypes = $useFullTxnTypes
                ? "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE', 'ADJUSTMENT')"
                : "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM')";
            $dailyFromTxnTypes = $useFullTxnTypes
                ? "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE')"
                : "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM')";

            // To Account
            $sql = "SELECT COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -amount
                        WHEN transaction_type = 'CONTRA' THEN -amount
                        WHEN transaction_type = 'CLEAR' THEN -amount
                        WHEN transaction_type = 'PAYMENT' AND sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN amount
                        WHEN transaction_type = 'PAYMENT' AND sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                        WHEN transaction_type = 'PAYMENT' AND (sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN amount
                        WHEN transaction_type = 'PAYMENT' THEN -amount
                        WHEN transaction_type = 'WIN' AND (description LIKE 'Process: %') THEN amount
                        WHEN transaction_type = 'LOSE' AND (description LIKE 'Process: %') THEN -amount
                        WHEN transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN -amount
                        WHEN transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN amount
                        WHEN transaction_type = 'ADJUSTMENT' THEN amount
                        ELSE 0
                    END), 0)
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.account_id IN ($ids_placeholder)
                      AND t.transaction_date < ?
                      AND t.transaction_type IN $bfTxnTypes" . $currency_filter_t_to . $clearFilter . $contraApproval . $dashTxnSubSql;
            $bf_stmt = $pdo->prepare($sql);
            $bf_stmt->execute(array_merge([$ledgerCompanyId], $account_ids, [$date_from_db], $currency_params_t_to));
            $total_bf = dashboardMoneyAdd($total_bf, $bf_stmt->fetchColumn());

            // From Account（含手动 PROFIT WIN/LOSE，与 search_api from 侧一致）
            $sql = "SELECT COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'CLEAR') THEN amount
                        WHEN transaction_type = 'CONTRA' THEN amount
                        WHEN transaction_type = 'PAYMENT' AND sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN 0
                        WHEN transaction_type = 'PAYMENT' AND sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                        WHEN transaction_type = 'PAYMENT' AND (sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN -amount
                        WHEN transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN amount
                        WHEN transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN -amount
                        ELSE 0
                    END), 0)
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.from_account_id IN ($ids_placeholder)
                      AND t.transaction_date < ?
                      AND t.transaction_type IN $bfTxnTypes" . $currency_filter_t_from . $clearFilter . $fromDomainFilter . $contraApproval . $dashTxnSubSql;
            $bf_stmt = $pdo->prepare($sql);
            $bf_stmt->execute(array_merge([$ledgerCompanyId], $account_ids, [$date_from_db], $currency_params_t_from));
            $total_bf = dashboardMoneyAdd($total_bf, $bf_stmt->fetchColumn());

            // RATE B/F from transaction_entry
            try {
                if (dashboardHasTransactionEntry($pdo)) { // static 缓存，不重复 SHOW
                    $sql = "SELECT COALESCE(SUM(CASE
                                WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                                ELSE e.amount
                            END), 0)
                            FROM transaction_entry e
                            JOIN transactions h ON e.header_id = h.id
                            WHERE h.company_id = ?
                              AND e.company_id = ?
                              AND e.account_id IN ($ids_placeholder)
                              AND h.transaction_date < ?" . $currency_filter_e . $dashTxnSubSqlH;
                    $bf_stmt = $pdo->prepare($sql);
                    $bf_stmt->execute(array_merge([$ledgerCompanyId, $ledgerCompanyId], $account_ids, [$date_from_db], $currency_params_e));
                    $total_bf = dashboardMoneyAdd($total_bf, $bf_stmt->fetchColumn());
                }
            } catch (Throwable $e) {
            }
        }

        // --- 2. 计算每日数据 (Daily Deltas) ---
        if (!$isExpensesRole) {
        if ($kpiOnly) {
            $sql = "SELECT COALESCE(SUM({$dcdAmountSql}), 0) as period_total
                    FROM data_capture_details dcd
                    JOIN data_captures dc ON dcd.capture_id = dc.id
                    WHERE dc.company_id IN ($capture_company_placeholder)
                      AND dcd.company_id IN ($capture_company_placeholder)
                      AND dcd.currency_id IS NOT NULL
                      AND dc.capture_date BETWEEN ? AND ?" . $acct_filter_dcd . $currency_filter_dcd;
            $period_stmt = $pdo->prepare($sql);
            $period_stmt->execute(array_merge(
                $captureCompanyIds,
                $captureCompanyIds,
                [$date_from_db, $date_to_db],
                $acct_params_dcd,
                $currency_params_dcd
            ));
            $rolePeriodDelta = dashboardMoneyAdd($rolePeriodDelta, $period_stmt->fetchColumn());
        } else {
        $sql = "SELECT DATE(dc.capture_date) as date, 
                       COALESCE(SUM({$dcdAmountSql}), 0) as win_loss
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                WHERE dc.company_id IN ($capture_company_placeholder)
                  AND dcd.company_id IN ($capture_company_placeholder)
                  AND dcd.currency_id IS NOT NULL
                  AND dc.capture_date BETWEEN ? AND ?" . $acct_filter_dcd . $currency_filter_dcd . "
                GROUP BY DATE(dc.capture_date)
                ORDER BY DATE(dc.capture_date)";
        $daily_stmt = $pdo->prepare($sql);
        $daily_stmt->execute(array_merge(
            $captureCompanyIds,
            $captureCompanyIds,
            [$date_from_db, $date_to_db],
            $acct_params_dcd,
            $currency_params_dcd
        ));
        foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            dashboardAddDailyAmount($daily_data, (string) $row['date'], $row['win_loss'] ?? '0');
        }
        }
        }

        // B. Transactions Daily
        if ($hasTransactionCurrency) {
            $clearFilter = $excludeClear ? " AND t.transaction_type <> 'CLEAR'" : "";
            $contraApproval = dashboardContraApprovedWhere($pdo, 't');
            $winLossTxnTypes = "('WIN', 'LOSE', 'ADJUSTMENT')";
            $crDrTxnTypes = "('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE')";

            // EXPENSES: Win/Loss + Cr/Dr use search_api-aligned bundles (see dashboardExpensesBuild*).
            if (!$isExpensesRole) {
            if ($kpiOnly) {
                $sql = "SELECT COALESCE(SUM(CASE 
                               WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -t.amount
                               WHEN transaction_type = 'CONTRA' THEN -t.amount
                               WHEN transaction_type = 'CLEAR' THEN -t.amount
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN t.amount
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                               WHEN transaction_type = 'PAYMENT' AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN t.amount
                               WHEN transaction_type = 'PAYMENT' THEN -t.amount
                               WHEN t.transaction_type = 'WIN' AND (t.description LIKE 'Process: %') THEN t.amount
                               WHEN t.transaction_type = 'LOSE' AND (t.description LIKE 'Process: %') THEN -t.amount
                               WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                               WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                               WHEN t.transaction_type = 'ADJUSTMENT' THEN t.amount
                               ELSE 0
                           END), 0) as period_total
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.account_id IN ($ids_placeholder)
                      AND t.transaction_date BETWEEN ? AND ?
                      AND t.transaction_type IN $dailyTxnTypes"
                    . $currency_filter_t_to . $clearFilter . $contraApproval . $dashTxnSubSql;
                $period_stmt = $pdo->prepare($sql);
                $period_stmt->execute(array_merge([$ledgerCompanyId], $account_ids, [$date_from_db, $date_to_db], $currency_params_t_to));
                $rolePeriodDelta = dashboardMoneyAdd($rolePeriodDelta, $period_stmt->fetchColumn());

                $sql = "SELECT COALESCE(SUM(CASE 
                               WHEN transaction_type = 'CONTRA' THEN t.amount
                               WHEN transaction_type = 'CLEAR' THEN t.amount
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN 0
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                               WHEN transaction_type = 'PAYMENT' AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN -t.amount
                               WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN t.amount
                               WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                               WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                               ELSE 0
                           END), 0) as period_total
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.from_account_id IN ($ids_placeholder)
                      AND t.transaction_date BETWEEN ? AND ?
                      AND t.transaction_type IN $dailyFromTxnTypes"
                    . $currency_filter_t_from . $clearFilter . $fromDomainFilter . $contraApproval . $dashTxnSubSql;
                $period_stmt = $pdo->prepare($sql);
                $period_stmt->execute(array_merge([$ledgerCompanyId], $account_ids, [$date_from_db, $date_to_db], $currency_params_t_from));
                $rolePeriodDelta = dashboardMoneyAdd($rolePeriodDelta, $period_stmt->fetchColumn());

                try {
                    if (dashboardHasTransactionEntry($pdo)) {
                        $sql = "SELECT COALESCE(SUM(CASE
                                       WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                       WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                       WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                                       ELSE e.amount
                                   END), 0) as period_total
                            FROM transaction_entry e
                            JOIN transactions h ON e.header_id = h.id
                            WHERE h.company_id = ?
                              AND e.company_id = ?
                              AND e.account_id IN ($ids_placeholder)
                              AND h.transaction_date BETWEEN ? AND ?" . $currency_filter_e . $dashTxnSubSqlH;
                        $period_stmt = $pdo->prepare($sql);
                        $period_stmt->execute(array_merge([$ledgerCompanyId, $ledgerCompanyId], $account_ids, [$date_from_db, $date_to_db], $currency_params_e));
                        $rolePeriodDelta = dashboardMoneyAdd($rolePeriodDelta, $period_stmt->fetchColumn());
                    }
                } catch (Throwable $e) {
                }
            } else {
            // To Account
            $sql = "SELECT DATE(t.transaction_date) as date,
                           COALESCE(SUM(CASE 
                               WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -t.amount
                               WHEN transaction_type = 'CONTRA' THEN -t.amount
                               WHEN transaction_type = 'CLEAR' THEN -t.amount
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN t.amount
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                               WHEN transaction_type = 'PAYMENT' AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN t.amount
                               WHEN transaction_type = 'PAYMENT' THEN -t.amount
                               WHEN t.transaction_type = 'WIN' AND (t.description LIKE 'Process: %') THEN t.amount
                               WHEN t.transaction_type = 'LOSE' AND (t.description LIKE 'Process: %') THEN -t.amount
                               WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                               WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                               WHEN t.transaction_type = 'ADJUSTMENT' THEN t.amount
                               ELSE 0
                           END), 0) as cr_dr
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.account_id IN ($ids_placeholder)
                      AND t.transaction_date BETWEEN ? AND ?
                      AND t.transaction_type IN $dailyTxnTypes"
                . $currency_filter_t_to . $clearFilter . $contraApproval . $dashTxnSubSql . "
                    GROUP BY DATE(t.transaction_date)
                    ORDER BY DATE(t.transaction_date)";
            $daily_stmt = $pdo->prepare($sql);
            $daily_stmt->execute(array_merge([$ledgerCompanyId], $account_ids, [$date_from_db, $date_to_db], $currency_params_t_to));
            foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                dashboardAddDailyAmount($daily_data, (string) $row['date'], $row['cr_dr'] ?? '0');
            }

            // From Account（含手动 PROFIT WIN/LOSE）
            $sql = "SELECT DATE(t.transaction_date) as date,
                           COALESCE(SUM(CASE 
                               WHEN transaction_type = 'CONTRA' THEN t.amount
                               WHEN transaction_type = 'CLEAR' THEN t.amount
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%' THEN 0
                               WHEN transaction_type = 'PAYMENT' AND t.sms LIKE '[DOMAIN_NET_PROFIT|%' THEN 0
                               WHEN transaction_type = 'PAYMENT' AND (t.sms LIKE '[DOMAIN_LIST_FEE|%' OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %') THEN -t.amount
                               WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN t.amount
                               WHEN t.transaction_type = 'WIN' AND " . dashboardManualProfitDescSql('t') . " THEN t.amount
                               WHEN t.transaction_type = 'LOSE' AND " . dashboardManualProfitDescSql('t') . " THEN -t.amount
                               ELSE 0
                           END), 0) as cr_dr
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.from_account_id IN ($ids_placeholder)
                      AND t.transaction_date BETWEEN ? AND ?
                      AND t.transaction_type IN $dailyFromTxnTypes"
                . $currency_filter_t_from . $clearFilter . $fromDomainFilter . $contraApproval . $dashTxnSubSql . "
                    GROUP BY DATE(t.transaction_date)
                    ORDER BY DATE(t.transaction_date)";
            $daily_stmt = $pdo->prepare($sql);
            $daily_stmt->execute(array_merge([$ledgerCompanyId], $account_ids, [$date_from_db, $date_to_db], $currency_params_t_from));
            foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                dashboardAddDailyAmount($daily_data, (string) $row['date'], $row['cr_dr'] ?? '0');
            }
            }

            // RATE daily from transaction_entry (EXPENSES Win/Loss uses search-aligned bundle)
            if (!$isExpensesRole && !$kpiOnly) {
            try {
                if (dashboardHasTransactionEntry($pdo)) {
                    $sql = "SELECT DATE(h.transaction_date) as date,
                                   COALESCE(SUM(CASE
                                       WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                       WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                       WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                                       ELSE e.amount
                                   END), 0) as rate_delta
                            FROM transaction_entry e
                            JOIN transactions h ON e.header_id = h.id
                            WHERE h.company_id = ?
                              AND e.company_id = ?
                              AND e.account_id IN ($ids_placeholder)
                              AND h.transaction_date BETWEEN ? AND ?" . $currency_filter_e . $dashTxnSubSqlH . "
                            GROUP BY DATE(h.transaction_date)";
                    $daily_stmt = $pdo->prepare($sql);
                    $daily_stmt->execute(array_merge([$ledgerCompanyId, $ledgerCompanyId], $account_ids, [$date_from_db, $date_to_db], $currency_params_e));
                    foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        dashboardAddDailyAmount($daily_data, (string) $row['date'], $row['rate_delta'] ?? '0');
                    }
                }
            } catch (Throwable $e) {
            }
            }
            }
        }
        }

        if ($isExpensesRole && $expenseAccountRowsById !== []) {
            $hadAccounts = true;
            $wlBundle = dashboardExpensesBuildWinLossBundle(
                $pdo,
                $company_id,
                array_values($expenseAccountRowsById),
                $currency_map,
                $date_from_db,
                $date_to_db,
                $filter_currency_code
            );
            $daily_win_loss = $wlBundle['daily'];
            $total_bf = dashboardMoneyAdd($total_bf, $wlBundle['capture_bf']);
            $expensesPeriodWlFromBundle = $wlBundle['period_wl'];
            $crDrBundle = dashboardExpensesBuildCrDrBundle(
                $pdo,
                $company_id,
                array_values($expenseAccountRowsById),
                $currency_map,
                $date_from_db,
                $date_to_db,
                $filter_currency_code
            );
            $expensesPeriodCrDrFromBundle = $crDrBundle['period_cr_dr'];
            foreach ($crDrBundle['daily'] as $date => $amount) {
                dashboardAddDailyAmount($daily_win_loss, $date, $amount);
            }
        }

        if (!$hadAccounts) {
            $result[strtolower($role)] = dashboardEmptyRoleBucket($role);
            continue;
        }

        // --- 2b. PROFIT 口径对齐 Transaction List：从池账号扣回 Domain Share Commission（毛额 -> 净额） ---
        if ($role === 'PROFIT' && !empty($primaryAccountIds)) {
            $profitIdsPlaceholder = implode(',', array_fill(0, count($primaryAccountIds), '?'));
            list($profitAdjCurrencyFilter, $profitAdjCurrencyParams) = dashboardTransactionCurrencyFilterSql(
                $filter_currency_code,
                'from_account_id'
            );

            // A) 调整期初：起始日前的 Share Commission 需要从 B/F 扣回
            $adjBfSql = "SELECT COALESCE(SUM(t.amount), 0) AS adj_total
                         FROM transactions t
                         WHERE t.company_id = ?
                           AND t.transaction_type = 'PAYMENT'
                           AND t.from_account_id IN ($profitIdsPlaceholder)
                           AND t.transaction_date < ?
                           AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%'" . $profitAdjCurrencyFilter . $dashTxnSubSql;
            $adjBfStmt = $pdo->prepare($adjBfSql);
            $adjBfStmt->execute(array_merge([$company_id], $primaryAccountIds, [$date_from_db], $profitAdjCurrencyParams));
            $adjBf = $adjBfStmt->fetchColumn();
            if (money_cmp(money_abs($adjBf), '0.00001') > 0) {
                $total_bf = dashboardMoneySub($total_bf, $adjBf);
            }

            // B) 调整本期：按日扣回，保证图表与 period_total 一致
            if ($kpiOnly) {
                $adjPeriodSql = "SELECT COALESCE(SUM(t.amount), 0) AS adj_total
                            FROM transactions t
                            WHERE t.company_id = ?
                              AND t.transaction_type = 'PAYMENT'
                              AND t.from_account_id IN ($profitIdsPlaceholder)
                              AND t.transaction_date BETWEEN ? AND ?
                              AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%'" . $profitAdjCurrencyFilter . $dashTxnSubSql;
                $adjPeriodStmt = $pdo->prepare($adjPeriodSql);
                $adjPeriodStmt->execute(array_merge([$company_id], $primaryAccountIds, [$date_from_db, $date_to_db], $profitAdjCurrencyParams));
                $rolePeriodDelta = dashboardMoneySub($rolePeriodDelta, $adjPeriodStmt->fetchColumn() ?? '0');
            } else {
            $adjDailySql = "SELECT DATE(t.transaction_date) AS date, COALESCE(SUM(t.amount), 0) AS adj_total
                            FROM transactions t
                            WHERE t.company_id = ?
                              AND t.transaction_type = 'PAYMENT'
                              AND t.from_account_id IN ($profitIdsPlaceholder)
                              AND t.transaction_date BETWEEN ? AND ?
                              AND t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%'" . $profitAdjCurrencyFilter . $dashTxnSubSql . "
                            GROUP BY DATE(t.transaction_date)
                            ORDER BY DATE(t.transaction_date)";
            $adjDailyStmt = $pdo->prepare($adjDailySql);
            $adjDailyStmt->execute(array_merge([$company_id], $primaryAccountIds, [$date_from_db, $date_to_db], $profitAdjCurrencyParams));
            foreach ($adjDailyStmt->fetchAll(PDO::FETCH_ASSOC) as $adjRow) {
                $d = (string) ($adjRow['date'] ?? '');
                if ($d === '') {
                    continue;
                }
                $daily_data[$d] = dashboardMoneySub($daily_data[$d] ?? '0', $adjRow['adj_total'] ?? '0');
            }
            }
        }

        // --- 3. 计算本期总余额 ---
        if ($isExpensesRole) {
            // 与 Transaction List / Payment History 一致：本期 Win/Loss + Cr/Dr（不含 CLEAR）。
            $periodWinLoss = $expensesPeriodWlFromBundle !== null
                ? $expensesPeriodWlFromBundle
                : dashboardSumDailyAmounts($daily_win_loss);
            $periodCrDr = $expensesPeriodCrDrFromBundle ?? dashboardMoneyZero();
            $total_period_delta = dashboardMoneyAdd($periodWinLoss, $periodCrDr);
            $total_balance = dashboardMoneyAdd($total_bf, $total_period_delta);
            $daily_data = $kpiOnly ? [] : $daily_win_loss;
        } else {
            $total_period_delta = $kpiOnly ? $rolePeriodDelta : dashboardSumDailyAmounts($daily_data);
            $total_balance = dashboardMoneyAdd($total_bf, $total_period_delta);
            if ($kpiOnly) {
                $daily_data = [];
            }
        }

        $result[strtolower($role)] = [
            'role' => $role,
            'total_balance' => dashboardOut($total_balance),
            'initial_balance' => dashboardOut($total_bf),
            'period_total' => dashboardOut($total_period_delta),
            'daily_data' => dashboardOutMap($daily_data)
        ];
    }

    if ($earningsOnly) {
        foreach (['capital', 'expenses', 'profit'] as $roleKey) {
            if (!isset($result[$roleKey])) {
                $result[$roleKey] = [
                    'role' => strtoupper($roleKey),
                    'total_balance' => '0',
                    'initial_balance' => '0',
                    'period_total' => '0',
                    'daily_data' => [],
                ];
            }
        }
    }

    // ── RATE_MIDDLEMAN 手续费同步至 Profit ──────────────────────────────────
    // RATE 账户（role='RATE'）的 Win/Loss 来自 RATE_MIDDLEMAN 分录，
    // 不属于 PROFIT role 账户，被上方 roles 循环跳过，导致 Dashboard 显示 0。
    // 此处专门汇总全公司当期所有 RATE_MIDDLEMAN 金额，直接累加到 profit 里，
    // 确保 transaction.php 显示的 Win/Loss 与 Dashboard Profit 卡片一致。
    if (dashboardHasTransactionEntry($pdo)) {
        try {
            $rateMMSql = "
                SELECT
                    DATE(h.transaction_date) AS date,
                    COALESCE(SUM(e.amount), 0) AS total
                FROM transaction_entry e
                JOIN transactions h ON e.header_id = h.id
                WHERE h.company_id = ?
                  AND e.company_id = ?
                  AND e.entry_type = 'RATE_MIDDLEMAN'
                  AND h.transaction_date BETWEEN ? AND ?{$dashTxnSubSqlH}
            ";
            $rateMMParams = [$company_id, $company_id, $date_from_db, $date_to_db];
            $skipRateMM = false;

            // 按币种过滤（与前端选择的 currency 一致）
            if ($filter_currency_code !== null) {
                $rateCurrId = array_search($filter_currency_code, $currency_map);
                if ($rateCurrId === false) {
                    // 该公司无此币种：勿把其它币种的 RATE_MIDDLEMAN 并入 profit
                    $skipRateMM = true;
                } else {
                    $rateMMSql .= " AND e.currency_id = ?";
                    $rateMMParams[] = $rateCurrId;
                }
            }

            $rateMMDaily = [];
            $rateMMPeriodTotal = dashboardMoneyZero();
            if (!$skipRateMM) {
                if ($kpiOnly) {
                    $rateMMSql = "
                        SELECT COALESCE(SUM(e.amount), 0) AS total
                        FROM transaction_entry e
                        JOIN transactions h ON e.header_id = h.id
                        WHERE h.company_id = ?
                          AND e.company_id = ?
                          AND e.entry_type = 'RATE_MIDDLEMAN'
                          AND h.transaction_date BETWEEN ? AND ?{$dashTxnSubSqlH}
                    ";
                    if ($filter_currency_code !== null && !$skipRateMM) {
                        $rateMMSql .= ' AND e.currency_id = ?';
                    }
                    $rateMMStmt = $pdo->prepare($rateMMSql);
                    $rateMMStmt->execute($rateMMParams);
                    $rateMMPeriodTotal = (string) ($rateMMStmt->fetchColumn() ?? '0');
                } else {
                $rateMMSql .= " GROUP BY DATE(h.transaction_date)";
                $rateMMStmt = $pdo->prepare($rateMMSql);
                $rateMMStmt->execute($rateMMParams);
                while ($rateRow = $rateMMStmt->fetch(PDO::FETCH_ASSOC)) {
                    $d = $rateRow['date'];
                    $v = $rateRow['total'] ?? '0';
                    dashboardAddDailyAmount($rateMMDaily, (string) $d, $v);
                    $rateMMPeriodTotal = dashboardMoneyAdd($rateMMPeriodTotal, $v);
                }
                }
            }

            // 合并到 profit：period_total、daily_data、total_balance
            if ($kpiOnly && money_cmp(money_abs($rateMMPeriodTotal), '0.00001') > 0) {
                $result['profit']['period_total'] = dashboardOut(dashboardMoneyAdd($result['profit']['period_total'] ?? '0', $rateMMPeriodTotal));
                $result['profit']['total_balance'] = dashboardOut(dashboardMoneyAdd($result['profit']['total_balance'] ?? '0', $rateMMPeriodTotal));
            } elseif (!empty($rateMMDaily)) {
                foreach ($rateMMDaily as $d => $v) {
                    dashboardAddDailyAmount($result['profit']['daily_data'], (string) $d, $v);
                }
                $result['profit']['period_total'] = dashboardOut(dashboardMoneyAdd($result['profit']['period_total'] ?? '0', $rateMMPeriodTotal));
                $result['profit']['total_balance'] = dashboardOut(dashboardMoneyAdd($result['profit']['total_balance'] ?? '0', $rateMMPeriodTotal));
                $result['profit']['daily_data'] = dashboardOutMap($result['profit']['daily_data']);
            }
        } catch (Throwable $rateMMErr) {
            // RATE_MIDDLEMAN 查询失败不影响主数据（向后兼容）
        }
    }
    // ────────────────────────────────────────────────────────────────────────

    // 严格流水口径：仅 PAYMENT + PROFIT 账户 的日净额（To 为负，From 为正）
    $profit_payment_flow_daily = $kpiOnly
        ? []
        : calculateProfitPaymentDailyFlow(
            $pdo,
            $company_id,
            $date_from_db,
            $date_to_db,
            $filter_currency_code,
            $hasTransactionCurrency,
            dashboardHasContraApprovalColumns($pdo)
        );

    // 获取当前账户的 ownership_percentage（按 date_to 月份读取历史或 live）
    $view_group = isset($_GET['view_group']) ? trim((string) $_GET['view_group']) : '';
    $ownershipFields = dashboardLoadCompanyDashboardOwnership(
        $pdo,
        $company_id,
        (string) $date_to,
        $view_group
    );
    $ownership_percentage = $ownershipFields['ownership_percentage'];
    $has_ownership_setup = $ownershipFields['has_ownership_setup'];
    $group_equity_percentage = $ownershipFields['group_equity_percentage'];
    $group_account_percentage = $ownershipFields['group_account_percentage'];
    $has_group_ownership = $ownershipFields['has_group_ownership'];

    // Profit（仪表板 NET PROFIT 卡片）= 所有 Role 为 PROFIT 的账户余额总和
    echo json_encode([
        'success' => true,
        'data' => [
            'capital' => $result['capital']['total_balance'],
            'expenses' => $result['expenses']['period_total'],
            'profit' => $result['profit']['total_balance'],
            'ownership_percentage' => $ownership_percentage,
            'has_ownership_setup' => $has_ownership_setup,
            'group_equity_percentage' => $group_equity_percentage,
            'group_account_percentage' => $group_account_percentage,
            'has_group_ownership' => $has_group_ownership,
            'period_total' => [
                'capital' => $result['capital']['period_total'],
                'expenses' => $result['expenses']['period_total'],
                'profit' => $result['profit']['period_total']
            ],
            'initial_balance' => [
                'capital' => $result['capital']['initial_balance'],
                'expenses' => $result['expenses']['initial_balance'],
                'profit' => $result['profit']['initial_balance']
            ],
            'daily_data' => [
                'capital' => $result['capital']['daily_data'],
                'expenses' => $result['expenses']['daily_data'],
                'profit' => $result['profit']['daily_data'],
                'profit_payment_flow_daily' => $profit_payment_flow_daily
            ],
            'date_range' => [
                'from' => $date_from,
                'to' => $date_to
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('dashboard_api: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
}

/**
 * Run dashboard_api_main with overridden query params; capture JSON without extra HTTP.
 *
 * @return array{success:bool,message?:string,data?:mixed,error?:string}
 */
function dashboard_api_capture(array $queryParams): array
{
    $backupGet = $_GET;
    $backupGlobals = [
        'DASHBOARD_KPI_ONLY' => $GLOBALS['DASHBOARD_KPI_ONLY'] ?? null,
        'DASHBOARD_EARNINGS_ONLY' => $GLOBALS['DASHBOARD_EARNINGS_ONLY'] ?? null,
        'DASHBOARD_SUBSIDIARY_LEDGER' => $GLOBALS['DASHBOARD_SUBSIDIARY_LEDGER'] ?? null,
    ];
    foreach ($queryParams as $key => $value) {
        if ($value === null || $value === '') {
            unset($_GET[$key]);
        } else {
            $_GET[$key] = (string) $value;
        }
    }

    ob_start();
    try {
        dashboard_api_main();
    } finally {
        $_GET = $backupGet;
        foreach ($backupGlobals as $key => $value) {
            if ($value === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
    }
    $raw = ob_get_clean();
    http_response_code(200);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'message' => 'Invalid dashboard response',
            'data' => null,
            'error' => 'Invalid dashboard response',
        ];
    }

    return $decoded;
}

if (!defined('DASHBOARD_API_SKIP_MAIN') || !DASHBOARD_API_SKIP_MAIN) {
    dashboard_api_main();
}

/**
 * 严格流水口径：仅统计 PAYMENT 且账户角色为 PROFIT 的当日净额
 * To Account(PROFIT) 记负数；From Account(PROFIT) 记正数
 */
function calculateProfitPaymentDailyFlow(
    PDO $pdo,
    int $company_id,
    string $date_from,
    string $date_to,
    ?string $filter_currency_code,
    bool $hasTransactionCurrency,
    bool $hasContraApproval
): array {
    $rows = [];
    $txnSubSql = dashboard_sql_txn_subsidiary_only($pdo, 't');
    $currSubSql = !empty($GLOBALS['DASHBOARD_SUBSIDIARY_LEDGER'])
        ? dashboard_sql_currency_subsidiary_only($pdo, 'c')
        : '';
    $toAcSubSql = !empty($GLOBALS['DASHBOARD_SUBSIDIARY_LEDGER'])
        ? dashboard_sql_account_company_subsidiary_only($pdo, 'to_ac')
        : '';
    $fromAcSubSql = !empty($GLOBALS['DASHBOARD_SUBSIDIARY_LEDGER'])
        ? dashboard_sql_account_company_subsidiary_only($pdo, 'from_ac')
        : '';

    if ($hasTransactionCurrency && $filter_currency_code !== null) {
        $sql = "
            SELECT DATE(t.transaction_date) AS date,
                   COALESCE(SUM(
                     CASE
                       WHEN to_ac.account_id IS NOT NULL THEN -t.amount
                       WHEN from_ac.account_id IS NOT NULL THEN t.amount
                       ELSE 0
                     END
                   ), 0) AS flow_amount
            FROM transactions t
            LEFT JOIN account to_acc
              ON to_acc.id = t.account_id
             AND UPPER(to_acc.role) = 'PROFIT'
            LEFT JOIN account_company to_ac
              ON to_ac.account_id = to_acc.id
             AND to_ac.company_id = t.company_id{$toAcSubSql}
            LEFT JOIN account from_acc
              ON from_acc.id = t.from_account_id
             AND UPPER(from_acc.role) = 'PROFIT'
            LEFT JOIN account_company from_ac
              ON from_ac.account_id = from_acc.id
             AND from_ac.company_id = t.company_id{$fromAcSubSql}
            INNER JOIN currency c
              ON c.id = t.currency_id
             AND c.company_id = t.company_id{$currSubSql}
            WHERE t.company_id = ?
              AND t.transaction_type = 'PAYMENT'
              AND t.transaction_date BETWEEN ? AND ?
              AND UPPER(c.code) = ?
              " . ($hasContraApproval ? " AND (t.transaction_type <> 'CONTRA' OR t.approval_status = 'APPROVED')" : "") . "{$txnSubSql}
              AND (to_ac.account_id IS NOT NULL OR from_ac.account_id IS NOT NULL)
            GROUP BY DATE(t.transaction_date)
            ORDER BY DATE(t.transaction_date)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $date_from, $date_to, $filter_currency_code]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "
            SELECT DATE(t.transaction_date) AS date,
                   COALESCE(SUM(
                     CASE
                       WHEN to_ac.account_id IS NOT NULL THEN -t.amount
                       WHEN from_ac.account_id IS NOT NULL THEN t.amount
                       ELSE 0
                     END
                   ), 0) AS flow_amount
            FROM transactions t
            LEFT JOIN account to_acc
              ON to_acc.id = t.account_id
             AND UPPER(to_acc.role) = 'PROFIT'
            LEFT JOIN account_company to_ac
              ON to_ac.account_id = to_acc.id
             AND to_ac.company_id = t.company_id{$toAcSubSql}
            LEFT JOIN account from_acc
              ON from_acc.id = t.from_account_id
             AND UPPER(from_acc.role) = 'PROFIT'
            LEFT JOIN account_company from_ac
              ON from_ac.account_id = from_acc.id
             AND from_ac.company_id = t.company_id{$fromAcSubSql}
            WHERE t.company_id = ?
              AND t.transaction_type = 'PAYMENT'
              AND t.transaction_date BETWEEN ? AND ?
              " . ($hasContraApproval ? " AND (t.transaction_type <> 'CONTRA' OR t.approval_status = 'APPROVED')" : "") . "{$txnSubSql}
              AND (to_ac.account_id IS NOT NULL OR from_ac.account_id IS NOT NULL)
            GROUP BY DATE(t.transaction_date)
            ORDER BY DATE(t.transaction_date)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $date_from, $date_to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $daily = [];
    foreach ($rows as $row) {
        $date = (string) ($row['date'] ?? '');
        if ($date === '')
            continue;
        $daily[$date] = dashboardOut($row['flow_amount'] ?? '0');
    }

    return $daily;
}
