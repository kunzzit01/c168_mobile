<?php
/**
 * Transaction Maintenance Search API
 * 按日期/Process 查询交易记录（维护页使用）
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';

@ini_set('memory_limit', '768M');
@set_time_limit(600);

/**
 * 子公司查询：与旧版 maintenance_search_api 一致的 company_id 解析（owner / session / 跨公司权限）。
 */
function maintenanceResolveRequestedCompanyId(PDO $pdo, int $requestedCompanyId, array $scopeParams = []): int
{
    if ($requestedCompanyId <= 0) {
        throw new Exception('无效的 company_id');
    }

    $userRole = strtolower((string) ($_SESSION['role'] ?? ''));

    if ($userRole === 'owner') {
        $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? null;
        if (!$ownerId) {
            throw new Exception('缺少 Owner 信息');
        }
        $stmt = $pdo->prepare('SELECT id FROM company WHERE id = ? AND owner_id = ? LIMIT 1');
        $stmt->execute([$requestedCompanyId, $ownerId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception('无权访问该公司');
        }
        return $requestedCompanyId;
    }

    if (isset($_SESSION['company_id']) && (int) $_SESSION['company_id'] === $requestedCompanyId) {
        return $requestedCompanyId;
    }

    $viewGroup = dcNormalizeGroupId($scopeParams['view_group'] ?? $scopeParams['group_id'] ?? '');
    dcAssertUserCanAccessCompany(
        $pdo,
        $requestedCompanyId,
        $viewGroup !== '' ? $viewGroup : null
    );

    return $requestedCompanyId;
}

function maintenanceResolveProcessIdByCode(PDO $pdo, int $companyId, string $processCode, bool $isGroupScope): ?int
{
    return dcResolveProcessIdByCode($pdo, $companyId, $processCode, $isGroupScope);
}

function maintenanceAssertProcessIdForScope(PDO $pdo, int $processId, int $companyId, bool $isGroupScope): void
{
    dcAssertProcessIdInCaptureScope($pdo, $processId, $companyId, $isGroupScope);
}

/**
 * 统一 Rate 显示：最多 8 位小数，不补尾零（与 Data Summary / Payment History 一致）
 */
function formatRateForDisplay($rate): ?string
{
    if ($rate === null || $rate === '') {
        return null;
    }
    return money_out($rate, 8);
}

function maintenanceSplitCrDr($amount): array
{
    if (!money_is_valid($amount)) {
        return [null, null];
    }
    if (money_cmp($amount, '0') > 0) {
        return [money_out($amount), null];
    }
    if (money_cmp($amount, '0') < 0) {
        return [null, money_out(money_abs($amount))];
    }
    return ['0', null];
}

/**
 * Id_Product 列：与 Transaction History 里 Data Capture 行的 Product 规则一致（history_api.php 678-712），
 * 优先 id_product_sub/main + description，再兜底 id_product、columns_value（与 Summary 行内容来源一致）。
 */
function formatMaintenanceIdProductLikeDataSummary(array $row): string
{
    $idSub = trim((string)($row['id_product_sub'] ?? ''));
    $idMain = trim((string)($row['id_product_main'] ?? ''));
    $idCol = trim((string)($row['id_product'] ?? ''));
    $descSub = isset($row['description_sub']) ? trim((string)$row['description_sub']) : '';
    $descMain = isset($row['description_main']) ? trim((string)$row['description_main']) : '';
    $productType = isset($row['product_type']) ? strtolower(trim((string)$row['product_type'])) : '';

    $product = '';
    $productDescription = null;

    if ($productType === 'sub' && $idSub !== '') {
        $product = $idSub;
        // 优先 description_sub；若为空回退 description_main，兼容旧前端把 sub 行描述误写到 description_main 的历史数据。
        if ($descSub !== '') {
            $productDescription = $descSub;
        } elseif ($descMain !== '') {
            $productDescription = $descMain;
        }
    } elseif ($idMain !== '') {
        $product = $idMain;
        if ($descMain !== '') {
            $productDescription = $descMain;
        }
    } else {
        $product = $idSub !== '' ? $idSub : ($idMain !== '' ? $idMain : '');
        if ($product === '') {
            $product = $idCol !== '' ? $idCol : 'Data Capture';
        }
        if ($idSub !== '' && $descSub !== '') {
            $productDescription = $descSub;
        } elseif ($descMain !== '') {
            $productDescription = $descMain;
        }
    }

    if ($productDescription !== null && $productDescription !== '') {
        $wrapped = '(' . $productDescription . ')';
        if (stripos($product, $wrapped) === false) {
            $product = $product . ' ' . $wrapped;
        }
    }

    $product = trim($product);
    if ($product === '' || $product === 'Data Capture') {
        if ($idCol !== '') {
            return $idCol;
        }
        $cv = trim((string)($row['columns_value'] ?? ''));
        if ($cv !== '') {
            return $cv;
        }
        return '-';
    }
    return $product;
}

/** UNION 分支字符串列统一排序规则，避免 Illegal mix of collations。 */
function maintenanceUnionTextExpr(string $expr): string
{
    return "CONVERT(($expr) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

function maintenanceUnionNullTextCol(): string
{
    return "CONVERT((NULL) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
}

/**
 * data_capture_details.account_id 可能存 account.id 或 account.account_id（业务代码）。
 * 与 history_api / get_accounts_api 一致，避免 INNER JOIN a.id 导致整批 Data Capture 查不到。
 */
function maintenanceDataCaptureAccountJoinSql(string $dcdAlias = 'dcd', string $aAlias = 'a'): string
{
    return "LEFT JOIN account {$aAlias} ON (
        TRIM(CAST({$dcdAlias}.account_id AS CHAR)) = TRIM(CAST({$aAlias}.id AS CHAR))
        OR (
            TRIM(COALESCE({$dcdAlias}.account_id, '')) <> ''
            AND TRIM(CAST({$dcdAlias}.account_id AS CHAR)) = TRIM({$aAlias}.account_id)
        )
    )";
}

function maintenanceDataCaptureAccountIdExpr(string $aAlias = 'a', string $dcdAlias = 'dcd'): string
{
    return maintenanceUnionTextExpr(
        "COALESCE({$aAlias}.account_id, CAST({$dcdAlias}.account_id AS CHAR), '-')"
    );
}

/** 缓存 transactions.source_bank_process_id 列是否存在（避免每次 SHOW COLUMNS）。 */
function maintenanceHasSourceBankCol(PDO $pdo): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
        $cached = $colStmt && $colStmt->rowCount() > 0;
    } catch (PDOException $e) {
        /* ignore */
    }
    return $cached;
}

/** 将 UNION 查询的一行格式化为 API 输出结构。 */
function maintenanceFormatUnionRow(array $row): array
{
    [$crVal, $drVal] = maintenanceSplitCrDr($row['amount'] ?? '0');
    $dataType = (string)($row['data_type'] ?? 'transaction');
    $isCapture = $dataType === 'datacapture';
    $isDeleted = (int)($row['is_deleted'] ?? 0) === 1;

    $rateDisplay = null;
    $idProductDisplay = '-';
    if ($isCapture) {
        $rateDisplay = formatRateForDisplay($row['rate'] ?? null);
        $idProductDisplay = formatMaintenanceIdProductLikeDataSummary($row);
    }

    return [
        'transaction_id' => $row['transaction_id'] ?? null,
        'capture_id' => $row['capture_id'] ?? null,
        'capture_detail_id' => $row['capture_detail_id'] ?? null,
        'process' => $row['process_id'] ?? '-',
        'process_id' => $row['process_id'] ?? null,
        'id_product' => $idProductDisplay,
        'account' => $row['account_id'] ?? '-',
        'from_account' => $isCapture ? null : ($row['from_account'] ?? '-'),
        'description' => $row['description'] ?? '-',
        'remark' => $row['remark'] ?? '-',
        'source' => $isCapture ? ($row['source_value'] ?? null) : null,
        'percent' => ($isCapture && isset($row['source_percent']) && $row['source_percent'] !== '')
            ? (string)$row['source_percent']
            : null,
        'currency' => !empty($row['currency_code']) ? $row['currency_code'] : '-',
        'rate' => $rateDisplay,
        'cr' => $crVal,
        'dr' => $drVal,
        'transaction_date' => $row['transaction_date'] ?? null,
        'dts_created' => $row['dts_created'] ?? '',
        'created_by' => $row['created_by'] ?? '-',
        'is_deleted' => $isDeleted ? 1 : 0,
        'deleted_by' => $row['deleted_by'] ?? null,
        'dts_deleted' => $row['dts_deleted'] ?? null,
        'data_type' => $dataType,
    ];
}

/**
 * 构建 Transaction 分支（UNION 子查询，无 ORDER/LIMIT）。
 * @return array{sql: string, params: array}
 */
function maintenanceBuildTransactionUnionBranch(
    int $company_id,
    string $date_from_db,
    string $date_to_db,
    string $category,
    bool $is_bank_category,
    bool $has_source_bank_col
): array {
    $where = ["t.company_id = ?", "t.transaction_date BETWEEN ? AND ?"];
    $params = [$company_id, $date_from_db, $date_to_db];

    if ($category !== '') {
        if ($is_bank_category) {
            if ($has_source_bank_col) {
                $where[] = "t.source_bank_process_id IS NOT NULL AND t.source_bank_process_id != 0";
            } else {
                $where[] = "1 = 0";
            }
        } elseif ($has_source_bank_col) {
            $where[] = "(t.source_bank_process_id IS NULL OR t.source_bank_process_id = 0)";
        }
    }

    $where[] = "t.transaction_type NOT IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'CLEAR', 'ADJUSTMENT', 'WIN', 'LOSE')";
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            " . maintenanceUnionTextExpr("'transaction'") . " AS data_type,
            t.id AS transaction_id,
            NULL AS capture_id,
            NULL AS capture_detail_id,
            " . maintenanceUnionNullTextCol() . " AS process_id,
            " . maintenanceUnionTextExpr('a.account_id') . " AS account_id,
            " . maintenanceUnionTextExpr('fa.account_id') . " AS from_account,
            " . maintenanceUnionTextExpr('t.description') . " AS description,
            " . maintenanceUnionTextExpr("COALESCE(t.sms, '')") . " AS remark,
            " . maintenanceUnionTextExpr("COALESCE(c.code, '')") . " AS currency_code,
            COALESCE(t.amount, 0) AS amount,
            t.transaction_date,
            t.created_at AS sort_created_at,
            " . maintenanceUnionTextExpr("DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s')") . " AS dts_created,
            " . maintenanceUnionTextExpr("COALESCE(u.login_id, o.owner_code, '-')") . " AS created_by,
            0 AS is_deleted,
            " . maintenanceUnionNullTextCol() . " AS deleted_by,
            " . maintenanceUnionNullTextCol() . " AS dts_deleted,
            " . maintenanceUnionNullTextCol() . " AS source_value,
            " . maintenanceUnionNullTextCol() . " AS source_percent,
            NULL AS rate,
            " . maintenanceUnionNullTextCol() . " AS id_product,
            " . maintenanceUnionNullTextCol() . " AS id_product_main,
            " . maintenanceUnionNullTextCol() . " AS id_product_sub,
            " . maintenanceUnionNullTextCol() . " AS product_type,
            " . maintenanceUnionNullTextCol() . " AS description_main,
            " . maintenanceUnionNullTextCol() . " AS description_sub,
            " . maintenanceUnionNullTextCol() . " AS columns_value
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        LEFT JOIN account fa ON t.from_account_id = fa.id
        LEFT JOIN currency c ON t.currency_id = c.id
        LEFT JOIN user u ON t.created_by = u.id
        LEFT JOIN owner o ON t.created_by_owner = o.id
        $whereSql
    ";

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Build scope context for legacy requests without explicit report_scope params.
 *
 * @return array<string, mixed>
 */
function maintenanceBuildScopeCtxFromLegacy(
    PDO $pdo,
    int $companyId,
    bool $isGroupScope,
    string $scopeProcessFilter,
    string $scopeCompanySql,
    string $scopeCompanySqlDeleted,
    array $params
): array {
    $dualTenant = tenant_table_has_scope_columns($pdo, 'data_captures');
    $ctx = [
        'company_id' => $companyId,
        'anchor_company_id' => $companyId,
        'is_group_scope' => $isGroupScope,
        'scope_process_sql' => $scopeProcessFilter,
        'scope_company_sql' => $scopeCompanySql,
        'scope_company_sql_deleted' => $scopeCompanySqlDeleted,
        'dual_tenant' => $dualTenant,
    ];

    if ($isGroupScope) {
        $groupId = dcNormalizeGroupId($params['view_group'] ?? $params['group_id'] ?? '');
        if ($groupId !== '') {
            $groupPk = gc_resolve_group_pk_by_code($pdo, $groupId);
            $anchorId = gc_resolve_group_anchor_company_id($pdo, $groupId);
            if ($anchorId > 0) {
                $ctx['company_id'] = $anchorId;
                $ctx['anchor_company_id'] = $anchorId;
            }
            if ($groupPk > 0) {
                $ctx['group_id'] = $groupId;
                $ctx['group_scope_id'] = $groupPk;
                $ctx['scope_id'] = $groupPk;
                $ctx['scope_type'] = 'group';
            }
        }
    }

    return $ctx;
}

/**
 * Ledger-aware WHERE for data_captures (group/company isolation).
 *
 * @return array{where_sql: string, params: array}
 */
function maintenanceBuildCaptureWhere(
    PDO $pdo,
    array $scopeCtx,
    string $date_from_db,
    string $date_to_db,
    ?string $process = null,
    string $scopeProcessFilter = ''
): array {
    $ledgerDc = dcBuildCaptureLedgerFilter($pdo, $scopeCtx, 'dc', 'data_captures');
    $ledgerDcd = dcBuildCaptureLedgerFilter($pdo, $scopeCtx, 'dcd', 'data_capture_details');
    $processCompanyId = dcCaptureProcessCompanyId($scopeCtx);

    $conditions = ['dc.capture_date BETWEEN ? AND ?', 'p.company_id = ?'];
    $params = array_merge(
        dcCaptureLedgerBindParams($ledgerDc),
        dcCaptureLedgerBindParams($ledgerDcd),
        [$date_from_db, $date_to_db, $processCompanyId]
    );

    if ($process) {
        $conditions[] = 'p.process_id = ?';
        $params[] = $process;
    }

    $whereSql = 'WHERE 1=1 ' . $ledgerDc['sql'] . $ledgerDcd['sql']
        . ' AND ' . implode(' AND ', $conditions) . $scopeProcessFilter;

    return ['where_sql' => $whereSql, 'params' => $params];
}

/**
 * 构建 Data Capture 分支（UNION 子查询）。
 * @return array{sql: string, params: array}
 */
function maintenanceBuildCaptureUnionBranch(
    PDO $pdo,
    array $scopeCtx,
    string $date_from_db,
    string $date_to_db,
    ?string $process,
    string $scopeProcessFilter = ''
): array {
    $built = maintenanceBuildCaptureWhere(
        $pdo,
        $scopeCtx,
        $date_from_db,
        $date_to_db,
        $process,
        $scopeProcessFilter
    );
    $captureWhereSql = $built['where_sql'];
    $captureParams = $built['params'];

    $sql = "
        SELECT
            " . maintenanceUnionTextExpr("'datacapture'") . " AS data_type,
            NULL AS transaction_id,
            dc.id AS capture_id,
            dcd.id AS capture_detail_id,
            " . maintenanceUnionTextExpr('p.process_id') . " AS process_id,
            " . maintenanceDataCaptureAccountIdExpr('a', 'dcd') . " AS account_id,
            " . maintenanceUnionNullTextCol() . " AS from_account,
            " . maintenanceUnionTextExpr("COALESCE(d.name, dcd.description_main, dcd.description_sub, dcd.columns_value, 'Data Capture')") . " AS description,
            " . maintenanceUnionTextExpr("COALESCE(dc.remark, '')") . " AS remark,
            " . maintenanceUnionTextExpr('c.code') . " AS currency_code,
            dcd.processed_amount AS amount,
            dc.capture_date AS transaction_date,
            dc.created_at AS sort_created_at,
            " . maintenanceUnionTextExpr("DATE_FORMAT(dc.created_at, '%d/%m/%Y %H:%i:%s')") . " AS dts_created,
            " . maintenanceUnionTextExpr("COALESCE(u.login_id, o.owner_code, '-')") . " AS created_by,
            0 AS is_deleted,
            " . maintenanceUnionNullTextCol() . " AS deleted_by,
            " . maintenanceUnionNullTextCol() . " AS dts_deleted,
            " . maintenanceUnionTextExpr('dcd.source_value') . " AS source_value,
            " . maintenanceUnionTextExpr('dcd.source_percent') . " AS source_percent,
            dcd.rate,
            " . maintenanceUnionTextExpr('dcd.id_product') . " AS id_product,
            " . maintenanceUnionTextExpr('dcd.id_product_main') . " AS id_product_main,
            " . maintenanceUnionTextExpr('dcd.id_product_sub') . " AS id_product_sub,
            " . maintenanceUnionTextExpr('dcd.product_type') . " AS product_type,
            " . maintenanceUnionTextExpr('dcd.description_main') . " AS description_main,
            " . maintenanceUnionTextExpr('dcd.description_sub') . " AS description_sub,
            " . maintenanceUnionTextExpr('dcd.columns_value') . " AS columns_value
        FROM data_capture_details dcd
        INNER JOIN data_captures dc ON dcd.capture_id = dc.id
        INNER JOIN process p ON dc.process_id = p.id
        " . maintenanceDataCaptureAccountJoinSql('dcd', 'a') . "
        LEFT JOIN currency c ON dcd.currency_id = c.id
        LEFT JOIN description d ON p.description_id = d.id
        LEFT JOIN user u ON dc.user_type = 'user' AND dc.created_by = u.id
        LEFT JOIN owner o ON dc.user_type = 'owner' AND dc.created_by = o.id
        $captureWhereSql
    ";

    return ['sql' => $sql, 'params' => $captureParams];
}

/**
 * 快速分支查询（无 UNION COLLATE 包装，供分页归并路径专用）。
 * @return array{sql: string, params: array}
 */
function maintenanceBuildTransactionFastBranch(
    int $company_id,
    string $date_from_db,
    string $date_to_db,
    string $category,
    bool $is_bank_category,
    bool $has_source_bank_col
): array {
    $where = ["t.company_id = ?", "t.transaction_date BETWEEN ? AND ?"];
    $params = [$company_id, $date_from_db, $date_to_db];

    if ($category !== '') {
        if ($is_bank_category) {
            if ($has_source_bank_col) {
                $where[] = "t.source_bank_process_id IS NOT NULL AND t.source_bank_process_id != 0";
            } else {
                $where[] = "1 = 0";
            }
        } elseif ($has_source_bank_col) {
            $where[] = "(t.source_bank_process_id IS NULL OR t.source_bank_process_id = 0)";
        }
    }

    $where[] = "t.transaction_type NOT IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'CLEAR', 'ADJUSTMENT', 'WIN', 'LOSE')";
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            'transaction' AS data_type,
            t.id AS transaction_id,
            NULL AS capture_id,
            NULL AS capture_detail_id,
            NULL AS process_id,
            a.account_id AS account_id,
            fa.account_id AS from_account,
            t.description AS description,
            COALESCE(t.sms, '') AS remark,
            COALESCE(c.code, '') AS currency_code,
            COALESCE(t.amount, 0) AS amount,
            t.transaction_date,
            t.created_at AS sort_created_at,
            DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
            COALESCE(u.login_id, o.owner_code, '-') AS created_by,
            0 AS is_deleted,
            NULL AS deleted_by,
            NULL AS dts_deleted,
            NULL AS source_value,
            NULL AS source_percent,
            NULL AS rate,
            NULL AS id_product,
            NULL AS id_product_main,
            NULL AS id_product_sub,
            NULL AS product_type,
            NULL AS description_main,
            NULL AS description_sub,
            NULL AS columns_value
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        LEFT JOIN account fa ON t.from_account_id = fa.id
        LEFT JOIN currency c ON t.currency_id = c.id
        LEFT JOIN user u ON t.created_by = u.id
        LEFT JOIN owner o ON t.created_by_owner = o.id
        $whereSql
    ";

    return ['sql' => $sql, 'params' => $params];
}

/**
 * @return array{sql: string, params: array}
 */
function maintenanceBuildCaptureFastBranch(
    PDO $pdo,
    array $scopeCtx,
    string $date_from_db,
    string $date_to_db,
    ?string $process,
    string $scopeProcessFilter = ''
): array {
    $built = maintenanceBuildCaptureWhere(
        $pdo,
        $scopeCtx,
        $date_from_db,
        $date_to_db,
        $process,
        $scopeProcessFilter
    );
    $captureWhereSql = $built['where_sql'];
    $captureParams = $built['params'];

    $sql = "
        SELECT
            'datacapture' AS data_type,
            NULL AS transaction_id,
            dc.id AS capture_id,
            dcd.id AS capture_detail_id,
            p.process_id AS process_id,
            COALESCE(a.account_id, CAST(dcd.account_id AS CHAR), '-') AS account_id,
            NULL AS from_account,
            COALESCE(d.name, dcd.description_main, dcd.description_sub, dcd.columns_value, 'Data Capture') AS description,
            COALESCE(dc.remark, '') AS remark,
            c.code AS currency_code,
            dcd.processed_amount AS amount,
            dc.capture_date AS transaction_date,
            dc.created_at AS sort_created_at,
            DATE_FORMAT(dc.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
            COALESCE(u.login_id, o.owner_code, '-') AS created_by,
            0 AS is_deleted,
            NULL AS deleted_by,
            NULL AS dts_deleted,
            dcd.source_value AS source_value,
            dcd.source_percent AS source_percent,
            dcd.rate,
            dcd.id_product AS id_product,
            dcd.id_product_main AS id_product_main,
            dcd.id_product_sub AS id_product_sub,
            dcd.product_type AS product_type,
            dcd.description_main AS description_main,
            dcd.description_sub AS description_sub,
            dcd.columns_value AS columns_value
        FROM data_capture_details dcd
        INNER JOIN data_captures dc ON dcd.capture_id = dc.id
        INNER JOIN process p ON dc.process_id = p.id
        " . maintenanceDataCaptureAccountJoinSql('dcd', 'a') . "
        LEFT JOIN currency c ON dcd.currency_id = c.id
        LEFT JOIN description d ON p.description_id = d.id
        LEFT JOIN user u ON dc.user_type = 'user' AND dc.created_by = u.id
        LEFT JOIN owner o ON dc.user_type = 'owner' AND dc.created_by = o.id
        $captureWhereSql
    ";

    return ['sql' => $sql, 'params' => $captureParams];
}

function maintenanceBranchCursorClause(): string
{
    return '(transaction_date < ? OR (transaction_date = ? AND sort_created_at < ?) OR (transaction_date = ? AND sort_created_at = ? AND IFNULL(capture_id, 0) < ?) OR (transaction_date = ? AND sort_created_at = ? AND IFNULL(capture_id, 0) = ? AND IFNULL(capture_detail_id, 0) < ?) OR (transaction_date = ? AND sort_created_at = ? AND IFNULL(capture_id, 0) = ? AND IFNULL(capture_detail_id, 0) = ? AND IFNULL(transaction_id, 0) < ?))';
}

/** @return array<int, mixed> */
function maintenanceBranchCursorParams(array $cursor): array
{
    $td = (string)($cursor['td'] ?? '');
    $sc = (string)($cursor['sc'] ?? '');
    $cid = (int)($cursor['cid'] ?? 0);
    $did = (int)($cursor['did'] ?? 0);
    $tid = (int)($cursor['tid'] ?? 0);

    return [$td, $td, $sc, $td, $sc, $cid, $td, $sc, $cid, $did, $td, $sc, $cid, $did, $tid];
}

function maintenanceNormalizeRowCursor(array $row): array
{
    return [
        'td' => (string)($row['transaction_date'] ?? ''),
        'sc' => (string)($row['sort_created_at'] ?? ''),
        'cid' => (int)($row['capture_id'] ?? 0),
        'did' => (int)($row['capture_detail_id'] ?? 0),
        'tid' => (int)($row['transaction_id'] ?? 0),
    ];
}

/**
 * 分页游标：按分支独立推进，避免双分支归并时丢弃未合并行导致总数提前结束。
 *
 * @return array{t: ?array, c: ?array}
 */
function maintenanceDecodePageCursor(?string $raw): array
{
    $empty = ['t' => null, 'c' => null];
    if ($raw === null || $raw === '') {
        return $empty;
    }
    $json = base64_decode(strtr($raw, '-_', '+/'), true);
    if ($json === false) {
        return $empty;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $empty;
    }
    if (isset($data['t']) || isset($data['c'])) {
        return [
            't' => (is_array($data['t'] ?? null) && isset($data['t']['td'])) ? $data['t'] : null,
            'c' => (is_array($data['c'] ?? null) && isset($data['c']['td'])) ? $data['c'] : null,
        ];
    }
    if (isset($data['td'], $data['sc'])) {
        return ['t' => $data, 'c' => $data];
    }
    return $empty;
}

/** @param array{t: ?array, c: ?array} $perBranch */
function maintenanceEncodePageCursor(array $perBranch): ?string
{
    $payload = [];
    if (!empty($perBranch['t'])) {
        $payload['t'] = $perBranch['t'];
    }
    if (!empty($perBranch['c'])) {
        $payload['c'] = $perBranch['c'];
    }
    if ($payload === []) {
        return null;
    }
    return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
}

/** 全局游标（UNION 单查询分页）；兼容旧版 per-branch / 扁平格式。 */
function maintenanceDecodeGlobalCursor(?string $raw): ?array
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $json = base64_decode(strtr($raw, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    if (isset($data['td'], $data['sc'])) {
        return $data;
    }
    $per = maintenanceDecodePageCursor($raw);
    $candidates = array_filter([$per['t'] ?? null, $per['c'] ?? null]);
    if ($candidates === []) {
        return null;
    }
    $global = $candidates[0];
    foreach (array_slice($candidates, 1) as $candidate) {
        $rowA = [
            'transaction_date' => $global['td'] ?? '',
            'sort_created_at' => $global['sc'] ?? '',
            'capture_id' => $global['cid'] ?? 0,
            'capture_detail_id' => $global['did'] ?? 0,
            'transaction_id' => $global['tid'] ?? 0,
        ];
        $rowB = [
            'transaction_date' => $candidate['td'] ?? '',
            'sort_created_at' => $candidate['sc'] ?? '',
            'capture_id' => $candidate['cid'] ?? 0,
            'capture_detail_id' => $candidate['did'] ?? 0,
            'transaction_id' => $candidate['tid'] ?? 0,
        ];
        if (maintenanceCompareUnionRows($rowA, $rowB) > 0) {
            $global = $candidate;
        }
    }
    return $global;
}

function maintenanceEncodeGlobalCursor(array $row): string
{
    $payload = maintenanceNormalizeRowCursor($row);
    return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
}

/** 与 SQL ORDER BY 一致：返回负数表示 $a 应排在 $b 之前（全局降序）。 */
function maintenanceCompareUnionRows(array $a, array $b): int
{
    $dateA = (string)($a['transaction_date'] ?? '');
    $dateB = (string)($b['transaction_date'] ?? '');
    if ($dateA !== $dateB) {
        return strcmp($dateB, $dateA);
    }

    $tsA = strtotime((string)($a['sort_created_at'] ?? '')) ?: 0;
    $tsB = strtotime((string)($b['sort_created_at'] ?? '')) ?: 0;
    if ($tsA !== $tsB) {
        return $tsB <=> $tsA;
    }

    $capA = (int)($a['capture_id'] ?? 0);
    $capB = (int)($b['capture_id'] ?? 0);
    if ($capA !== $capB) {
        return $capB <=> $capA;
    }

    $detA = (int)($a['capture_detail_id'] ?? 0);
    $detB = (int)($b['capture_detail_id'] ?? 0);
    if ($detA !== $detB) {
        return $detB <=> $detA;
    }

    return ((int)($b['transaction_id'] ?? 0)) <=> ((int)($a['transaction_id'] ?? 0));
}

/**
 * 多路归并：各分支已按 maintenance 排序键降序，取全局前 $maxRows 行。
 *
 * @param array<int, array<int, array>> $lists
 * @return array{rows: array<int, array>, indices: array<int, int>}
 */
function maintenanceMergeSortedRowLists(array $lists, int $maxRows): array
{
    if ($maxRows <= 0) {
        return ['rows' => [], 'indices' => []];
    }
    $indices = array_fill(0, count($lists), 0);
    $merged = [];

    while (count($merged) < $maxRows) {
        $bestIdx = -1;
        $bestRow = null;
        foreach ($lists as $listIdx => $list) {
            $pos = $indices[$listIdx];
            if ($pos >= count($list)) {
                continue;
            }
            $row = $list[$pos];
            if ($bestRow === null || maintenanceCompareUnionRows($row, $bestRow) < 0) {
                $bestRow = $row;
                $bestIdx = $listIdx;
            }
        }
        if ($bestRow === null) {
            break;
        }
        $merged[] = $bestRow;
        $indices[$bestIdx]++;
    }

    return ['rows' => $merged, 'indices' => $indices];
}

/** @return array<string, mixed> */
function maintenanceSearchScopeDebug(
    PDO $pdo,
    int $company_id,
    string $date_from_db,
    string $date_to_db,
    bool $maintenance_scope_group,
    string $scopeProcessFilter
): array {
    $companyRow = null;
    try {
        $stmt = $pdo->prepare('SELECT id, company_id, group_id FROM company WHERE id = ? LIMIT 1');
        $stmt->execute([$company_id]);
        $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        /* ignore */
    }

    $txCount = 0;
    $capCount = 0;
    try {
        $txStmt = $pdo->prepare("
            SELECT COUNT(*) FROM transactions t
            WHERE t.company_id = ? AND t.transaction_date BETWEEN ? AND ?
              AND t.transaction_type NOT IN ('PAYMENT','RECEIVE','CONTRA','CLAIM','RATE','CLEAR','ADJUSTMENT','WIN','LOSE')
        ");
        $txStmt->execute([$company_id, $date_from_db, $date_to_db]);
        $txCount = (int) $txStmt->fetchColumn();

        $capSql = "
            SELECT COUNT(*)
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN process p ON dc.process_id = p.id
            WHERE dc.company_id = ? AND dcd.company_id = ?
              AND dc.capture_date BETWEEN ? AND ?
              $scopeProcessFilter
        ";
        $capStmt = $pdo->prepare($capSql);
        $capStmt->execute([$company_id, $company_id, $date_from_db, $date_to_db]);
        $capCount = (int) $capStmt->fetchColumn();
    } catch (PDOException $e) {
        return [
            'company_id' => $company_id,
            'company_row' => $companyRow,
            'scope_group' => $maintenance_scope_group,
            'error' => $e->getMessage(),
        ];
    }

    return [
        'company_id' => $company_id,
        'company_row' => $companyRow,
        'date_from' => $date_from_db,
        'date_to' => $date_to_db,
        'scope_group' => $maintenance_scope_group,
        'raw_tx_count' => $txCount,
        'raw_capture_detail_count' => $capCount,
    ];
}

/**
 * SQL 层分页：UNION ALL + 全局游标 + LIMIT（单次查询，保证条数完整且更快）。
 */
function maintenanceSearchPaginatedFast(
    PDO $pdo,
    int $company_id,
    array $scopeCtx,
    string $date_from_db,
    string $date_to_db,
    ?string $process,
    string $category,
    bool $is_bank_category,
    int $page,
    int $page_size,
    ?string $cursor_raw = null,
    bool $maintenance_scope_group = false,
    string $scopeProcessFilter = ''
): void {
    $has_source_bank_col = maintenanceHasSourceBankCol($pdo);
    $unionParts = [];
    $params = [];

    if (empty($process) && !$maintenance_scope_group) {
        $tx = maintenanceBuildTransactionFastBranch(
            $company_id,
            $date_from_db,
            $date_to_db,
            $category,
            $is_bank_category,
            $has_source_bank_col
        );
        $unionParts[] = '(' . $tx['sql'] . ')';
        $params = array_merge($params, $tx['params']);
    }

    if (!$is_bank_category) {
        try {
            $cap = maintenanceBuildCaptureFastBranch(
                $pdo,
                $scopeCtx,
                $date_from_db,
                $date_to_db,
                $process,
                $scopeProcessFilter
            );
            $unionParts[] = '(' . $cap['sql'] . ')';
            $params = array_merge($params, $cap['params']);
        } catch (Exception $e) {
            error_log('maintenance_search capture branch: ' . $e->getMessage());
        }
    }

    if (count($unionParts) === 0) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'pagination' => [
                'page' => $page,
                'page_size' => $page_size,
                'total' => 0,
                'has_more' => false,
                'next_cursor' => null,
            ],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $globalCursor = maintenanceDecodeGlobalCursor($cursor_raw);
    $orderSql = 'transaction_date DESC, sort_created_at DESC, '
        . 'IFNULL(capture_id, 0) DESC, IFNULL(capture_detail_id, 0) DESC, IFNULL(transaction_id, 0) DESC';

    $unionSql = implode(' UNION ALL ', $unionParts);
    $sql = 'SELECT * FROM (' . $unionSql . ') AS maintenance_union_rows';
    if ($globalCursor !== null) {
        $sql .= ' WHERE ' . maintenanceBranchCursorClause();
        $params = array_merge($params, maintenanceBranchCursorParams($globalCursor));
    }
    $fetchLimit = $page_size + 1;
    $sql .= ' ORDER BY ' . $orderSql . ' LIMIT ' . (int)$fetchLimit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $page_size;
    $pageRows = array_slice($rows, 0, $page_size);

    $formatted = [];
    foreach ($pageRows as $i => $row) {
        $item = maintenanceFormatUnionRow($row);
        $item['no'] = $i + 1;
        $formatted[] = $item;
    }

    $nextCursor = null;
    if ($hasMore && !empty($pageRows)) {
        $nextCursor = maintenanceEncodeGlobalCursor($pageRows[count($pageRows) - 1]);
    }

    $returned = count($formatted);
    $payload = [
        'success' => true,
        'data' => $formatted,
        'pagination' => [
            'page' => $page,
            'page_size' => $page_size,
            'total' => $hasMore ? -1 : $returned,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ],
    ];
    if (!empty($_GET['debug_scope']) && (string) $_GET['debug_scope'] === '1') {
        $payload['debug'] = maintenanceSearchScopeDebug(
            $pdo,
            $company_id,
            $date_from_db,
            $date_to_db,
            $maintenance_scope_group,
            $scopeProcessFilter
        );
        $payload['debug']['category'] = $category;
        $payload['debug']['is_bank_category'] = $is_bank_category;
        $payload['debug']['scope_process_filter'] = trim($scopeProcessFilter);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    $scopeParams = $_GET;
    $maintenance_scope_group = false;
    $scopeProcessFilter = '';
    $scopeCompanySql = '';
    $scopeCompanySqlDeleted = '';
    $hasExplicitScope = dcRequestHasExplicitScope($scopeParams);
    $requestedViewGroup = dcNormalizeGroupId(
        $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ''
    );

    if ($hasExplicitScope) {
        $scopeResolved = resolveDataCaptureRequestScope($pdo, $scopeParams);
        $scopeCtx = dcFinalizeCaptureMaintenanceScope($pdo, $scopeResolved, $scopeParams);
        $company_id = (int) $scopeCtx['company_id'];
        $maintenance_scope_group = (bool) $scopeCtx['is_group_scope'];
        $scopeProcessFilter = (string) $scopeCtx['scope_process_sql'];
        $scopeCompanySql = (string) $scopeCtx['scope_company_sql'];
        $scopeCompanySqlDeleted = (string) ($scopeCtx['scope_company_sql_deleted'] ?? '');
        if ($scopeCompanySqlDeleted === '' && !$maintenance_scope_group) {
            $scopeCompanySqlDeleted = dcSqlCaptureOnSubsidiaryCompany('dcd');
        }
        dcAssertUserCanAccessCompany(
            $pdo,
            $company_id,
            $requestedViewGroup !== '' ? $requestedViewGroup : null
        );
    } else {
        $company_id = null;
        if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
            $requestedCompanyId = (int) $_GET['company_id'];
            $userRole = strtolower($_SESSION['role'] ?? '');

            if ($userRole === 'owner') {
                $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? null;
                if (!$ownerId) {
                    throw new Exception('缺少 Owner 信息');
                }
                $stmt = $pdo->prepare('SELECT id FROM company WHERE id = ? AND owner_id = ? LIMIT 1');
                $stmt->execute([$requestedCompanyId, $ownerId]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('无权访问该公司');
                }
                $company_id = $requestedCompanyId;
            } else {
                if (!isset($_SESSION['company_id'])) {
                    throw new Exception('缺少公司信息');
                }
                $viewGroupForLegacy = dcNormalizeGroupId(
                    $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ''
                );
                try {
                    dcAssertUserCanAccessCompany(
                        $pdo,
                        $requestedCompanyId,
                        $viewGroupForLegacy !== '' ? $viewGroupForLegacy : null
                    );
                    $company_id = $requestedCompanyId;
                } catch (Exception $accessErr) {
                    if ($requestedCompanyId !== (int) $_SESSION['company_id']) {
                        throw new Exception('无权访问该公司');
                    }
                    $company_id = $requestedCompanyId;
                }
            }
        } else {
            if (!isset($_SESSION['company_id'])) {
                throw new Exception('缺少公司信息');
            }
            $company_id = (int) $_SESSION['company_id'];
        }

        $maintenance_scope_group = dcIsGroupScopeHint([
            'company_id' => $company_id,
            'group_id' => dcNormalizeGroupId($scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ''),
            'report_scope_hint' => '',
        ]);
        $scopeProcessFilter = $maintenance_scope_group
            ? dcSqlGroupProcessFilter('p')
            : dcSqlDataCaptureCompanyProcessFilter($pdo, (int) $company_id, 'p');
        $scopeCompanySql = $maintenance_scope_group
            ? ''
            : dcSqlCaptureOnSubsidiaryCompany('dc');
        $scopeCompanySqlDeleted = $maintenance_scope_group
            ? ''
            : dcSqlCaptureOnSubsidiaryCompany('dcd');
        if ($maintenance_scope_group && $company_id > 0 && dcCompanyIdIsGroupEntity($pdo, $company_id)) {
            $scopeCompanySql = dcSqlCaptureOnGroupEntityCompany('dc');
            $scopeCompanySqlDeleted = dcSqlCaptureOnGroupEntityCompany('dcd');
        }
        $scopeCtx = maintenanceBuildScopeCtxFromLegacy(
            $pdo,
            (int) $company_id,
            $maintenance_scope_group,
            $scopeProcessFilter,
            $scopeCompanySql,
            $scopeCompanySqlDeleted,
            $scopeParams
        );
    }

    if ($maintenance_scope_group && (int) $company_id <= 0) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'pagination' => [
                'page' => isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1,
                'page_size' => isset($_GET['page_size']) ? (int) $_GET['page_size'] : 0,
                'total' => 0,
                'has_more' => false,
                'next_cursor' => null,
            ],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!$maintenance_scope_group && (int) $company_id > 0 && dcCompanyIdIsGroupEntity($pdo, (int) $company_id)) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'pagination' => [
                'page' => isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1,
                'page_size' => isset($_GET['page_size']) ? (int) $_GET['page_size'] : 0,
                'total' => 0,
                'has_more' => false,
                'next_cursor' => null,
            ],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    // 参数
    $date_from = $_GET['date_from'] ?? null;
    $date_to   = $_GET['date_to']   ?? null;
    $process   = isset($_GET['process']) && $_GET['process'] !== '' ? trim((string)$_GET['process']) : null; // process.process_id（如 SPORT）或 "SPORT (SPORT)" 或 process 表 id（数字）
    $category  = trim($_GET['category'] ?? $_GET['permission'] ?? ''); // Games|Bank|Loan|Rate|Money，按 category 只显示该部分数据

    // 分页：前端传 page + page_size 时分页返回，避免单次 JSON 过大触发 HTTP/2 断连
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 0;
    $page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 0;
    if ($page_size > 0) {
        $page_size = min(5000, max(100, $page_size));
    }
    $cursor_raw = isset($_GET['cursor']) ? trim((string)$_GET['cursor']) : '';

    // 统一 process 为 process_id（代码）：前端可能传 "SPORT (SPORT)" 或数字 id
    if ($process !== null && $process !== '') {
        if (preg_match('/^\d+$/', $process)) {
            $processPk = (int) $process;
            try {
                maintenanceAssertProcessIdForScope(
                    $pdo,
                    $processPk,
                    (int) $company_id,
                    (bool) $maintenance_scope_group
                );
            } catch (Exception $e) {
                echo json_encode([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'page' => $page > 0 ? $page : 1,
                        'page_size' => $page_size > 0 ? $page_size : 0,
                        'total' => 0,
                        'has_more' => false,
                        'next_cursor' => null,
                    ],
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
            $stmt = $pdo->prepare('SELECT process_id FROM process WHERE id = ? AND company_id = ? LIMIT 1');
            $stmt->execute([$processPk, $company_id]);
            $res = $stmt->fetchColumn();
            $process = $res !== false ? (string) $res : null;
        } else {
            if (strpos($process, '(') !== false) {
                $process = trim(explode('(', $process)[0]);
            }
            if ($process === '') {
                $process = null;
            } else {
                $resolvedPid = maintenanceResolveProcessIdByCode(
                    $pdo,
                    (int) $company_id,
                    (string) $process,
                    (bool) $maintenance_scope_group
                );
                if ($resolvedPid === null) {
                    echo json_encode([
                        'success' => true,
                        'data' => [],
                        'pagination' => [
                            'page' => $page > 0 ? $page : 1,
                            'page_size' => $page_size > 0 ? $page_size : 0,
                            'total' => 0,
                            'has_more' => false,
                            'next_cursor' => null,
                        ],
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }
        }
    }

    if ($process !== null) {
        $processLower = strtolower($process);
        if (in_array($processLower, ['select all', '--select all--'], true) || in_array($process, ['全部', '--全部--'], true)) {
            $process = null;
        }
    }

    $capture_company_id = (int) $company_id;
    $capture_scope_group = (bool) $maintenance_scope_group;
    $captureScopeProcessFilter = $scopeProcessFilter;

    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }

    $tsFrom = strtotime(str_replace('/', '-', $date_from));
    $tsTo = strtotime(str_replace('/', '-', $date_to));
    if ($tsFrom === false || $tsTo === false) {
        throw new Exception('日期格式无效');
    }

    $date_from_db = date('Y-m-d', $tsFrom);
    $date_to_db   = date('Y-m-d', $tsTo);
    if ($date_from_db > $date_to_db) {
        throw new Exception('开始日期不能晚于结束日期');
    }

    @set_time_limit(120);

    $catUpper = strtoupper($category);
    $is_bank_category = ($catUpper === 'BANK');
    $is_loan_rate_money = in_array($catUpper, ['LOAN', 'RATE', 'MONEY'], true);
    // Loan/Rate/Money 无独立流水；与其它维护页共用 localStorage 时可能误传，按 Games 查询
    if ($is_loan_rate_money) {
        $category = 'Games';
        $catUpper = 'GAMES';
        $is_loan_rate_money = false;
    }
    if ($catUpper === 'GAMBLING') {
        $category = 'Games';
        $catUpper = 'GAMES';
    }

    // 默认不在 Maintenance - Transaction 中显示已删除的交易记录；
    // 仅当显式传入 include_deleted=1 时，才附加 transactions_deleted / data_captures_deleted 的历史记录
    $includeDeleted = isset($_GET['include_deleted']) && $_GET['include_deleted'] === '1';

    // 子公司：与旧 PHP 一致走 legacy 全量查询 + PHP 分页；仅 group scope 用 SQL UNION 游标分页
    if (!$includeDeleted && $page_size > 0 && $capture_scope_group) {
        $page = $page > 0 ? $page : 1;
        try {
            maintenanceSearchPaginatedFast(
                $pdo,
                $capture_company_id,
                $scopeCtx,
                $date_from_db,
                $date_to_db,
                $process,
                $category,
                $is_bank_category,
                $page,
                $page_size,
                $cursor_raw !== '' ? $cursor_raw : null,
                (bool) $capture_scope_group,
                $captureScopeProcessFilter
            );
            return;
        } catch (Throwable $fastErr) {
            error_log('maintenance_search_api fast path fallback: ' . $fastErr->getMessage());
        }
    }

    $has_source_bank_col = maintenanceHasSourceBankCol($pdo);

    $formatted = [];
    $no = 1;

    // ========== 1. 查询 Transaction 数据 ==========
    // Group scope：仅 SALARY/BONUS（Data Capture）；不查无 process 的 Transaction 分支
    // 当指定了 Process 时，不查 Transaction，只由下方 Data Capture 按 process 过滤
    if (empty($process) && !$capture_scope_group) {
    $where = [];
    $params = [];

    // company 过滤（transactions）
    $where[] = "t.company_id = ?";
    $params[] = $company_id;

    $where[] = "t.transaction_date BETWEEN ? AND ?";
    $params[] = $date_from_db;
    $params[] = $date_to_db;

    if ($category !== '') {
        if ($is_bank_category) {
            if ($has_source_bank_col) {
                $where[] = "t.source_bank_process_id IS NOT NULL AND t.source_bank_process_id != 0";
            } else {
                $where[] = "1 = 0";
            }
        } else {
            if ($has_source_bank_col) {
                $where[] = "(t.source_bank_process_id IS NULL OR t.source_bank_process_id = 0)";
            }
        }
    }

    // Payment / Receive / Contra / Claim / Rate / Clear / Adjustment / 手动 Profit(WIN/LOSE) 等
    // 都通过 Transaction Payment / Payment Maintenance 页面维护，
    // 不在 Maintenance - Transaction 中显示，避免重复。
    $where[] = "t.transaction_type NOT IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'CLEAR', 'ADJUSTMENT', 'WIN', 'LOSE')";

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // 主查询（未删除）
    $sql = "
        SELECT
            t.id AS transaction_id,
            NULL AS process_id,
            a.account_id,
            fa.account_id AS from_account,
            t.description,
            COALESCE(t.sms, '') AS remark,
            COALESCE(c.code, '') AS currency_code,
            COALESCE(t.amount, 0) AS amount,
            t.transaction_date AS transaction_date,
            DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
            COALESCE(u.login_id, o.owner_code) AS created_by,
            0 AS is_deleted,
            NULL AS deleted_by,
            NULL AS dts_deleted,
            'transaction' AS data_type
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        LEFT JOIN account fa ON t.from_account_id = fa.id
        LEFT JOIN currency c ON t.currency_id = c.id
        LEFT JOIN user u ON t.created_by = u.id
        LEFT JOIN owner o ON t.created_by_owner = o.id
        $whereSql
        ORDER BY t.transaction_date DESC, t.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        [$crVal, $drVal] = maintenanceSplitCrDr($row['amount'] ?? '0');

        $formatted[] = [
            'no' => $no++,
            'transaction_id' => $row['transaction_id'],
            'capture_id' => null,
            'capture_detail_id' => null,
            'process' => $row['process_id'] ?? '-',
            'process_id' => $row['process_id'] ?? null,
            'id_product' => '-',
            'account' => $row['account_id'] ?? '-',
            'from_account' => $row['from_account'] ?? '-',
            'description' => $row['description'] ?? '-',
            'remark' => $row['remark'] ?? '-',
            'source' => null,
            'percent' => null,
            'currency' => $row['currency_code'] ?: '-',
            'rate' => null,
            'cr' => $crVal,
            'dr' => $drVal,
            'transaction_date' => $row['transaction_date'] ?? null,
            'dts_created' => $row['dts_created'] ?? '',
            'created_by' => $row['created_by'] ?? '-',
            'is_deleted' => 0,
            'deleted_by' => null,
            'dts_deleted' => null,
            'data_type' => 'transaction'
        ];
    }
    } // end if (empty($process)) — 指定 Process 时不返回未关联 process 的 Transaction

    // ========== 2. 查询 Data Capture 数据（Bank category 不包含 Data Capture，仅 Transaction）==========
    if (!$is_bank_category) {
    try {
        $captureBuilt = maintenanceBuildCaptureWhere(
            $pdo,
            $scopeCtx,
            $date_from_db,
            $date_to_db,
            $process,
            $captureScopeProcessFilter
        );
        $captureWhereSql = $captureBuilt['where_sql'];
        $captureParams = $captureBuilt['params'];

        $captureSql = "
            SELECT
                dcd.id AS capture_detail_id,
                dc.id AS capture_id,
                p.process_id,
                a.account_id,
                NULL AS from_account,
                COALESCE(d.name, dcd.description_main, dcd.description_sub, dcd.columns_value, 'Data Capture') AS description,
                COALESCE(dc.remark, '') AS remark,
                c.code AS currency_code,
                dcd.processed_amount AS amount,
                dc.capture_date AS transaction_date,
                DATE_FORMAT(dc.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                COALESCE(u.login_id, o.owner_code) AS created_by,
                0 AS is_deleted,
                NULL AS deleted_by,
                NULL AS dts_deleted,
                dcd.source_value,
                dcd.source_percent,
                dcd.rate,
                dcd.id_product,
                dcd.id_product_main,
                dcd.id_product_sub,
                dcd.product_type,
                dcd.description_main,
                dcd.description_sub,
                dcd.columns_value
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN process p ON dc.process_id = p.id
            INNER JOIN account a ON dcd.account_id = a.id
            INNER JOIN currency c ON dcd.currency_id = c.id
            LEFT JOIN description d ON p.description_id = d.id
            LEFT JOIN user u ON dc.user_type = 'user' AND dc.created_by = u.id
            LEFT JOIN owner o ON dc.user_type = 'owner' AND dc.created_by = o.id
            $captureWhereSql
            ORDER BY dc.capture_date DESC, dc.created_at DESC, dcd.id DESC
        ";
        
        $captureStmt = $pdo->prepare($captureSql);
        $captureStmt->execute($captureParams);
        $captureRows = $captureStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($captureRows as $row) {
            [$crVal, $drVal] = maintenanceSplitCrDr($row['amount'] ?? '0');
            
            $rateDisplay = formatRateForDisplay($row['rate'] ?? null);
            $idProductDisplay = formatMaintenanceIdProductLikeDataSummary($row);
            
            $formatted[] = [
                'no' => $no++,
                'transaction_id' => null,
                'capture_id' => $row['capture_id'],
                'capture_detail_id' => $row['capture_detail_id'] ?? null,
                'process' => $row['process_id'] ?? '-',
                'process_id' => $row['process_id'] ?? null,
                'id_product' => $idProductDisplay,
                'account' => $row['account_id'] ?? '-',
                'from_account' => null,
                'description' => $row['description'] ?? '-',
                'remark' => $row['remark'] ?? '-',
                'source' => $row['source_value'] ?? null,
                'percent' => (isset($row['source_percent']) && $row['source_percent'] !== '')
                    ? (string)$row['source_percent']
                    : null,
                'currency' => $row['currency_code'] ?: '-',
                'rate' => $rateDisplay,
                'cr' => $crVal,
                'dr' => $drVal,
                'transaction_date' => $row['transaction_date'] ?? null,
                'dts_created' => $row['dts_created'] ?? '',
                'created_by' => $row['created_by'] ?? '-',
                'is_deleted' => 0,
                'deleted_by' => null,
                'dts_deleted' => null,
                'data_type' => 'datacapture'
            ];
        }
    } catch (Exception $e) {
        error_log('查询 Data Capture 数据失败: ' . $e->getMessage());
    }
    }
    // ========== 3. 查询已删除的 Transaction 记录（transactions_deleted，可选；指定 Process 时不查）==========
    // 为了避免在 Maintenance - Transaction 页面看到已在 Payment Maintenance 中删除的历史记录，
    // 默认不返回这些已删除记录；仅当 include_deleted=1 且未指定 process 时才附加。
    if ($includeDeleted && empty($process)) {
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'transactions_deleted'");
        if ($check->rowCount() > 0) {
            $delWhere = "td.company_id = ? AND td.transaction_date BETWEEN ? AND ?";
            $delParams = [$company_id, $date_from_db, $date_to_db];
            $hasTdSourceBank = false;
            try {
                $tdCol = $pdo->query("SHOW COLUMNS FROM transactions_deleted LIKE 'source_bank_process_id'");
                $hasTdSourceBank = $tdCol && $tdCol->rowCount() > 0;
            } catch (PDOException $e) { /* ignore */ }
            if ($category !== '') {
                if ($is_bank_category) {
                    if ($hasTdSourceBank) {
                        $delWhere .= " AND td.source_bank_process_id IS NOT NULL AND td.source_bank_process_id != 0";
                    } else {
                        $delWhere .= " AND 1 = 0";
                    }
                } else {
                    if ($hasTdSourceBank) {
                        $delWhere .= " AND (td.source_bank_process_id IS NULL OR td.source_bank_process_id = 0)";
                    }
                }
            }
            // 同样排除 Payment / Receive / Contra / Claim / Rate / Clear / Adjustment / WIN / LOSE 的已删除记录
            $delWhere .= " AND td.transaction_type NOT IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'CLEAR', 'ADJUSTMENT', 'WIN', 'LOSE')";
            $deletedSql = "
                SELECT
                    td.transaction_id,
                    NULL AS process_id,
                    a.account_id,
                    fa.account_id AS from_account,
                    td.description,
                    COALESCE(td.sms, '') AS remark,
                    COALESCE(c.code, '') AS currency_code,
                    COALESCE(td.amount, 0) AS amount,
                    td.transaction_date AS transaction_date,
                    DATE_FORMAT(td.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                    COALESCE(u.login_id, o.owner_code) AS created_by,
                    COALESCE(du.login_id, do.owner_code) AS deleted_by,
                    DATE_FORMAT(td.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted,
                    'transaction' AS data_type
                FROM transactions_deleted td
                INNER JOIN account a ON td.account_id = a.id
                LEFT JOIN account fa ON td.from_account_id = fa.id
                LEFT JOIN currency c ON td.currency_id = c.id
                LEFT JOIN user u ON td.created_by = u.id
                LEFT JOIN owner o ON td.created_by_owner = o.id
                LEFT JOIN user du ON td.deleted_by_user_id = du.id
                LEFT JOIN owner do ON td.deleted_by_owner_id = do.id
                WHERE $delWhere
                ORDER BY td.transaction_date DESC, td.created_at DESC
            ";
            $delStmt = $pdo->prepare($deletedSql);
            $delStmt->execute($delParams);
            $deletedRows = $delStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($deletedRows as $row) {
                [$crVal, $drVal] = maintenanceSplitCrDr($row['amount'] ?? '0');

                $formatted[] = [
                    'no' => $no++,
                    'transaction_id' => $row['transaction_id'],
                    'capture_id' => null,
                    'capture_detail_id' => null,
                    'process' => $row['process_id'] ?? ($process ?: '-'),
                    'process_id' => $row['process_id'] ?? null,
                    'id_product' => '-',
                    'account' => $row['account_id'] ?? '-',
                    'from_account' => $row['from_account'] ?? '-',
                    'description' => $row['description'] ?? '-',
                    'remark' => $row['remark'] ?? '-',
                    'source' => null,
                    'percent' => null,
                    'currency' => $row['currency_code'] ?: '-',
                    'rate' => null,
                    'cr' => $crVal,
                    'dr' => $drVal,
                    'transaction_date' => $row['transaction_date'] ?? null,
                    'dts_created' => $row['dts_created'] ?? '',
                    'created_by' => $row['created_by'] ?? '-',
                    'is_deleted' => 1,
                    'deleted_by' => $row['deleted_by'] ?? null,
                    'dts_deleted' => $row['dts_deleted'] ?? null,
                    'data_type' => 'transaction'
                ];
            }
        }
    } catch (Exception $e) {
        error_log('查询已删除交易失败: ' . $e->getMessage());
    }
    } // end if (empty($process)) — 指定 Process 时不返回已删除的 Transaction

    // ========== 4. 查询已删除的 Data Capture 记录（data_captures_deleted，可选；Bank category 不包含）==========
    // 同样仅在 include_deleted=1 时返回已删除的 Data Capture 记录
    if ($includeDeleted && !$is_bank_category) {
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'data_captures_deleted'");
        if ($check->rowCount() > 0) {
            $deletedCaptureWhere = [];
            $deletedCaptureParams = [];
            
            $deletedCaptureWhere[] = "dcd.company_id = ?";
            $deletedCaptureParams[] = $capture_company_id;
            
            $deletedCaptureWhere[] = "dcd.capture_date BETWEEN ? AND ?";
            $deletedCaptureParams[] = $date_from_db;
            $deletedCaptureParams[] = $date_to_db;
            
            // Process 过滤（如果指定）
            if ($process) {
                $deletedCaptureWhere[] = "p.process_id = ?";
                $deletedCaptureParams[] = $process;
            }
            
            $deletedCaptureWhereSql = 'WHERE ' . implode(' AND ', $deletedCaptureWhere) . $captureScopeProcessFilter . $scopeCompanySqlDeleted;
            
            $deletedCaptureSql = "
                SELECT
                    dcd.id AS capture_detail_id,
                    dcd.capture_id,
                    p.process_id,
                    COALESCE(a.account_id, CAST(dcd.account_id AS CHAR), '-') AS account_id,
                    COALESCE(d.name, dcd.description_main, dcd.description_sub, dcd.columns_value, 'Data Capture') AS description,
                    COALESCE(dcd.remark, '') AS remark,
                    c.code AS currency_code,
                    dcd.processed_amount AS amount,
                    dcd.capture_date AS transaction_date,
                    DATE_FORMAT(dcd.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                    COALESCE(u.login_id, o.owner_code) AS created_by,
                    COALESCE(du.login_id, do.owner_code) AS deleted_by,
                    DATE_FORMAT(dcd.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted,
                    dcd.source_value,
                    dcd.source_percent,
                    dcd.rate,
                    dcd.id_product,
                    dcd.id_product_main,
                    dcd.id_product_sub,
                    dcd.product_type,
                    dcd.description_main,
                    dcd.description_sub,
                    dcd.columns_value
                FROM data_captures_deleted dcd
                INNER JOIN process p ON dcd.process_id = p.id
                " . maintenanceDataCaptureAccountJoinSql('dcd', 'a') . "
                LEFT JOIN currency c ON dcd.currency_id = c.id
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN user u ON dcd.user_type = 'user' AND dcd.created_by = u.id
                LEFT JOIN owner o ON dcd.user_type = 'owner' AND dcd.created_by = o.id
                LEFT JOIN user du ON dcd.deleted_by_user_id = du.id
                LEFT JOIN owner do ON dcd.deleted_by_owner_id = do.id
                $deletedCaptureWhereSql
                ORDER BY dcd.capture_date DESC, dcd.created_at DESC, dcd.id DESC
            ";
            
            $deletedCaptureStmt = $pdo->prepare($deletedCaptureSql);
            $deletedCaptureStmt->execute($deletedCaptureParams);
            $deletedCaptureRows = $deletedCaptureStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($deletedCaptureRows as $row) {
                [$crVal, $drVal] = maintenanceSplitCrDr($row['amount'] ?? '0');
                
                $rateDisplay = formatRateForDisplay($row['rate'] ?? null);
                $idProductDelDisplay = formatMaintenanceIdProductLikeDataSummary($row);
                
                $formatted[] = [
                    'no' => $no++,
                    'transaction_id' => null,
                    'capture_id' => $row['capture_id'],
                    'capture_detail_id' => $row['capture_detail_id'] ?? null,
                    'process' => $row['process_id'] ?? '-',
                    'process_id' => $row['process_id'] ?? null,
                    'id_product' => $idProductDelDisplay,
                    'account' => $row['account_id'] ?? '-',
                    'from_account' => null,
                    'description' => $row['description'] ?? '-',
                    'remark' => $row['remark'] ?? '-',
                    'source' => $row['source_value'] ?? null,
                    'percent' => (isset($row['source_percent']) && $row['source_percent'] !== '')
                        ? (string)$row['source_percent']
                        : null,
                    'currency' => $row['currency_code'] ?: '-',
                    'rate' => $rateDisplay,
                    'cr' => $crVal,
                    'dr' => $drVal,
                    'transaction_date' => $row['transaction_date'] ?? null,
                    'dts_created' => $row['dts_created'] ?? '',
                    'created_by' => $row['created_by'] ?? '-',
                    'is_deleted' => 1,
                    'deleted_by' => $row['deleted_by'] ?? null,
                    'dts_deleted' => $row['dts_deleted'] ?? null,
                    'data_type' => 'datacapture'
                ];
            }
        }
    } catch (Exception $e) {
        error_log('查询已删除 Data Capture 失败: ' . $e->getMessage());
    }
    }
    // ========== 5. 按日期排序合并后的数据 ==========
    usort($formatted, function($a, $b) {
        // 1) 按 transaction_date 降序（YYYY-MM-DD）
        $dateA = $a['transaction_date'] ?? '';
        $dateB = $b['transaction_date'] ?? '';
        if ($dateA !== $dateB) {
            return strcmp($dateB, $dateA);
        }

        // 2) 按 dts_created 的真实时间降序（避免 dd/mm/yyyy 字符串比较误差）
        $createdA = DateTime::createFromFormat('d/m/Y H:i:s', (string)($a['dts_created'] ?? ''));
        $createdB = DateTime::createFromFormat('d/m/Y H:i:s', (string)($b['dts_created'] ?? ''));
        $tsA = $createdA ? $createdA->getTimestamp() : 0;
        $tsB = $createdB ? $createdB->getTimestamp() : 0;
        if ($tsA !== $tsB) {
            return $tsB <=> $tsA;
        }

        // 3) 同一时间戳下：Data Capture 先按 capture_id 分组，保证 Main/Sub 不被打散
        $captureA = (int)($a['capture_id'] ?? 0);
        $captureB = (int)($b['capture_id'] ?? 0);
        if ($captureA !== $captureB) {
            return $captureB <=> $captureA;
        }

        // 4) 同一 capture 内按 detail id（原明细顺序）排序，确保同组紧邻
        $detailA = (int)($a['capture_detail_id'] ?? 0);
        $detailB = (int)($b['capture_detail_id'] ?? 0);
        if ($detailA !== $detailB) {
            return $detailB <=> $detailA;
        }

        // 5) 最后兜底：按 transaction_id 降序，确保排序稳定
        $txnA = (int)($a['transaction_id'] ?? 0);
        $txnB = (int)($b['transaction_id'] ?? 0);
        if ($txnA !== $txnB) {
            return $txnB <=> $txnA;
        }
        return 0;
    });
    
    // 重新编号
    foreach ($formatted as $index => &$result) {
        $result['no'] = $index + 1;
    }
    unset($result);

    if ($page_size > 0) {
        $page = $page > 0 ? $page : 1;
        $total = count($formatted);
        $offset = ($page - 1) * $page_size;
        $slice = array_slice($formatted, $offset, $page_size);
        echo json_encode([
            'success' => true,
            'data' => $slice,
            'pagination' => [
                'page' => $page,
                'page_size' => $page_size,
                'total' => $total,
                'has_more' => ($offset + count($slice)) < $total,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => true,
            'data' => $formatted,
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误: ' . $e->getMessage(),
        'data' => null,
        'error' => '数据库错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
