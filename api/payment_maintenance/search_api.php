<?php
/**
 * Payment Maintenance Search API
 * 返回指定日期范围内「Transaction Payment」相关流水（CONTRA / PAYMENT / RATE 等），
 * 不含由 Bank Process 入账的行（source_bank_process_id 或 Process:/Auto: 成本售价利润描述）。
 * 路径: api/payment_maintenance/search_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../c168/c168_domain_access.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';
require_once __DIR__ . '/../transactions/transaction_scope.php';
require_once __DIR__ . '/../../includes/group_company_access.php';

/**
 * 标准 JSON 响应：success, message, data
 */
function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 解析并校验当前请求的公司 ID（GET company_id 或 session）
 */
/**
 * Group ledger vs subsidiary company (aligned with history_api / transaction search).
 *
 * @return array<string, mixed>
 */
function paymentMaintenanceResolveListScope(PDO $pdo, array $params): array
{
    $listParams = $params;
    $scopeHint = strtolower(trim((string) ($params['report_scope'] ?? $params['capture_scope'] ?? '')));
    if ($scopeHint === 'group') {
        unset($listParams['company_id']);
        if (!isset($listParams['group_aggregate']) || trim((string) $listParams['group_aggregate']) === '') {
            $listParams['group_aggregate'] = '1';
        }
    }

    return tx_resolve_transaction_list_scope($pdo, $listParams);
}

/**
 * @return array{sql: string, bind: int, is_group: bool}
 */
function paymentMaintenanceScopeFilter(PDO $pdo, array $listScope, string $alias, string $table = 'transactions'): array
{
    $isGroup = (($listScope['mode'] ?? '') === 'group');
    if (tx_table_has_scope_column($pdo, $table)) {
        $sql = tx_sql_transaction_scope_where($listScope, $alias);
        if (!$isGroup) {
            $sql .= tx_sql_transaction_company_ledger_only($alias);
        }

        return [
            'sql' => $sql,
            'bind' => tx_bind_transaction_scope_id($listScope),
            'is_group' => $isGroup,
        ];
    }
    $permId = tx_permission_company_id_for_scope($pdo, $listScope);

    return [
        'sql' => "{$alias}.company_id = ?",
        'bind' => $permId,
        'is_group' => $isGroup,
    ];
}

function resolveCompanyId(PDO $pdo) {
    $params = $_GET;
    if (isset($params['group_id']) || isset($params['view_group']) || gc_is_group_login()) {
        return tx_resolve_request_company_id($pdo, $params);
    }
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requestedCompanyId = (int) $_GET['company_id'];
        gc_assert_api_company_access($pdo, $requestedCompanyId, null);
        return $requestedCompanyId;
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    $sessionId = (int) $_SESSION['company_id'];
    gc_assert_api_company_access($pdo, $sessionId, gc_is_group_login() ? gc_session_login_identifier() : null);
    return $sessionId;
}

/**
 * 检测数据库货币相关表结构，返回用于构建 SQL 的片段
 */
function getCurrencySchema(PDO $pdo) {
    $schema = [
        'has_currency_id' => false,
        'account_has_created_source' => false,
        'account_has_currency_column' => false,
        'account_has_currency_id_column' => false,
        'has_account_currency_table' => false,
        'has_deleted_table' => false,
        'deleted_has_currency_id' => false,
        'selectCurrency' => "'' AS currency_code",
        'currencyJoinSql' => '',
        'currencyFilterField' => null,
        'deletedSelectCurrency' => "'' AS currency_code",
        'deletedCurrencyJoinSql' => '',
        'deletedCurrencyFilterField' => null,
    ];

    try {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $schema['has_currency_id'] = $columnStmt->rowCount() > 0;
    } catch (PDOException $e) {}

    try {
        $schema['account_has_currency_column'] = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency'")->rowCount() > 0;
    } catch (PDOException $e) {}
    try {
        $schema['account_has_currency_id_column'] = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency_id'")->rowCount() > 0;
    } catch (PDOException $e) {}
    try {
        $schema['has_account_currency_table'] = $pdo->query("SHOW TABLES LIKE 'account_currency'")->rowCount() > 0;
    } catch (PDOException $e) {}
    try {
        $schema['has_deleted_table'] = $pdo->query("SHOW TABLES LIKE 'transactions_deleted'")->rowCount() > 0;
    } catch (PDOException $e) {}
    try {
        $schema['account_has_created_source'] = $pdo->query("SHOW COLUMNS FROM account LIKE 'created_source'")->rowCount() > 0;
    } catch (PDOException $e) {
        $schema['account_has_created_source'] = false;
    }
    if ($schema['has_deleted_table']) {
        try {
            $colDel = $pdo->query("SHOW COLUMNS FROM transactions_deleted LIKE 'currency_id'");
            $schema['deleted_has_currency_id'] = $colDel->rowCount() > 0;
        } catch (PDOException $e) {
            $schema['deleted_has_currency_id'] = false;
        }
    }

    if ($schema['has_currency_id']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(c.code, '')) AS currency_code";
        $schema['currencyJoinSql'] = " LEFT JOIN currency c ON t.currency_id = c.id";
        $schema['currencyFilterField'] = "UPPER(COALESCE(c.code, ''))";
    } elseif ($schema['account_has_currency_column']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(to_acc.currency, '')) AS currency_code";
        $schema['currencyFilterField'] = "UPPER(COALESCE(to_acc.currency, ''))";
    } elseif ($schema['account_has_currency_id_column']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(acc_cur.code, '')) AS currency_code";
        $schema['currencyJoinSql'] = " LEFT JOIN currency acc_cur ON to_acc.currency_id = acc_cur.id";
        $schema['currencyFilterField'] = "UPPER(COALESCE(acc_cur.code, ''))";
    } elseif ($schema['has_account_currency_table']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(acc_default.currency_code, '')) AS currency_code";
        $schema['currencyJoinSql'] = " LEFT JOIN (
                SELECT ac.account_id, UPPER(c.code) AS currency_code
                FROM account_currency ac INNER JOIN currency c ON ac.currency_id = c.id
                INNER JOIN (SELECT account_id, MIN(id) AS min_id FROM account_currency GROUP BY account_id) ac_first ON ac.id = ac_first.min_id
            ) acc_default ON acc_default.account_id = to_acc.id";
        $schema['currencyFilterField'] = "UPPER(COALESCE(acc_default.currency_code, ''))";
    }

    if ($schema['deleted_has_currency_id']) {
        // deleted 表存在 currency_id：优先使用备份下来的 currency_id，与主表保持一致
        $schema['deletedSelectCurrency'] = "UPPER(COALESCE(c_del.code, '')) AS currency_code";
        $schema['deletedCurrencyJoinSql'] = " LEFT JOIN currency c_del ON td.currency_id = c_del.id";
        $schema['deletedCurrencyFilterField'] = "UPPER(COALESCE(c_del.code, ''))";
    } elseif ($schema['account_has_currency_column']) {
        $schema['deletedSelectCurrency'] = "UPPER(COALESCE(to_acc.currency, '')) AS currency_code";
        $schema['deletedCurrencyFilterField'] = "UPPER(COALESCE(to_acc.currency, ''))";
    } elseif ($schema['account_has_currency_id_column']) {
        $schema['deletedSelectCurrency'] = "UPPER(COALESCE(acc_cur.code, '')) AS currency_code";
        $schema['deletedCurrencyJoinSql'] = " LEFT JOIN currency acc_cur ON to_acc.currency_id = acc_cur.id";
        $schema['deletedCurrencyFilterField'] = "UPPER(COALESCE(acc_cur.code, ''))";
    } elseif ($schema['has_account_currency_table']) {
        $schema['deletedSelectCurrency'] = "UPPER(COALESCE(acc_default.currency_code, '')) AS currency_code";
        $schema['deletedCurrencyJoinSql'] = " LEFT JOIN (
                SELECT ac.account_id, UPPER(c.code) AS currency_code
                FROM account_currency ac INNER JOIN currency c ON ac.currency_id = c.id
                INNER JOIN (SELECT account_id, MIN(id) AS min_id FROM account_currency GROUP BY account_id) ac_first ON ac.id = ac_first.min_id
            ) acc_default ON acc_default.account_id = to_acc.id";
        $schema['deletedCurrencyFilterField'] = "UPPER(COALESCE(acc_default.currency_code, ''))";
    }

    return $schema;
}

function paymentMaintenanceAccountMetaSelectSql(array $schema): string
{
    if (!empty($schema['account_has_created_source'])) {
        return ", to_acc.role AS to_account_role, COALESCE(to_acc.created_source,'') AS to_account_created_source, from_acc.role AS from_account_role, COALESCE(from_acc.created_source,'') AS from_account_created_source";
    }
    return ", to_acc.role AS to_account_role, '' AS to_account_created_source, from_acc.role AS from_account_role, '' AS from_account_created_source";
}

/**
 * 查询主表 transactions（非 RATE）及可选 transactions_deleted
 * $exclude_bank_process_rows：为 true 时排除由 Bank Process 入账的行（source_bank_process_id），仅保留 Transaction Payment 等手工流水
 */
function fetchMainTransactions(PDO $pdo, array $listScope, $date_from_db, $date_to_db, $transaction_type, array $currency_filters, array $schema, $exclude_bank_process_rows = false) {
    $scopeFilter = paymentMaintenanceScopeFilter($pdo, $listScope, 't');
    $accMeta = paymentMaintenanceAccountMetaSelectSql($schema);
    $sql = "SELECT
                t.id,
                DATE_FORMAT(t.transaction_date, '%d/%m/%Y') AS transaction_date,
                t.transaction_type, t.amount, t.description,
                COALESCE(t.sms, '') AS remark,
                DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.account_id AS account_code, to_acc.name AS account_name,
                from_acc.account_id AS from_account_code, from_acc.name AS from_account_name
                $accMeta,
                {$schema['selectCurrency']},
                u.login_id AS created_by_login, o.owner_code AS created_by_owner,
                0 AS is_deleted, NULL AS deleted_by_login, NULL AS deleted_by_owner, NULL AS dts_deleted
            FROM transactions t
            JOIN account to_acc ON t.account_id = to_acc.id
            LEFT JOIN account from_acc ON t.from_account_id = from_acc.id
            {$schema['currencyJoinSql']}
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
            WHERE {$scopeFilter['sql']} AND t.transaction_date BETWEEN ? AND ?";
    $params = [$scopeFilter['bind'], $date_from_db, $date_to_db];
    if ($exclude_bank_process_rows) {
        $sql .= " AND (t.source_bank_process_id IS NULL OR t.source_bank_process_id = 0)";
    }
    // 无 source_bank_process_id 的旧数据：仍排除 Bank Process 自动入账的典型描述
    $sql .= " AND NOT (
        UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'PROCESS: BUY PRICE%'
        OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'PROCESS: SELL PRICE%'
        OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'PROCESS: PROFIT FOR%'
        OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'PROCESS: PROFIT SHARING%'
        OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'AUTO: BUY PRICE%'
        OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'AUTO: SELL PRICE%'
        OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'AUTO: PROFIT FOR%'
    )";
    if (!empty($transaction_type)) {
        $sql .= " AND t.transaction_type = ?";
        $params[] = $transaction_type;
    }
    if (!empty($currency_filters) && $schema['currencyFilterField'] !== null) {
        $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $sql .= " AND {$schema['currencyFilterField']} IN ($placeholders)";
        $params = array_merge($params, array_map('strtoupper', $currency_filters));
    }
    $sql .= " AND t.transaction_type <> 'RATE' ORDER BY t.created_at DESC, t.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 将主表/删除表的一行转换为统一输出项
 */
function rowToItem(array $row, $is_deleted = 0, string $ownerCode = '', string $profitCode = 'PROFIT') {
    $isDomainShareCommission = false;
    $isDomainListFee = false;
    $isAutoRenewFee = false;
    $descriptionRaw = (string)($row['description'] ?? '');
    $remarkRaw = (string)($row['remark'] ?? '');
    $remarkTrim = trim($remarkRaw);
    if (stripos(trim($descriptionRaw), 'Commision FROM ') === 0
        || stripos(trim($descriptionRaw), 'Commision for ') === 0
        || $remarkTrim === '[DOMAIN_SHARE_COMMISSION]'
        || stripos($remarkTrim, '[DOMAIN_SHARE_COMMISSION|') === 0
        || stripos($remarkTrim, '[AUTO_RENEW|COMMISSION|') === 0) {
        $isDomainShareCommission = true;
    }
    if (stripos(trim($descriptionRaw), 'Pay Domain Fee') === 0
        || $remarkTrim === '[DOMAIN_LIST_FEE]'
        || stripos($remarkTrim, '[DOMAIN_LIST_FEE|') === 0) {
        $isDomainListFee = true;
    }
    if (stripos($remarkTrim, '[AUTO_RENEW|') === 0) {
        $isAutoRenewFee = true;
    }

    $description = $row['description'] ?? '';
    if (empty($description) && ($row['transaction_type'] ?? '') === 'ADJUSTMENT') {
        $description = 'ADJUSTMENT - WIN/LOSS';
    } elseif (empty($description) && in_array($row['transaction_type'] ?? '', ['WIN', 'LOSE'], true)) {
        $fromCode = trim((string) ($row['from_account_code'] ?? ''));
        $description = (($row['transaction_type'] ?? '') === 'WIN' ? 'PROFIT FROM ' : 'PROFIT TO ') . ($fromCode !== '' ? $fromCode : 'N/A');
    } elseif (empty($description) && in_array($row['transaction_type'] ?? '', ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM'])) {
        $description = ($row['transaction_type'] ?? '') . ' FROM ' . ($row['from_account_code'] ?? 'N/A');
    }
    if ($isDomainShareCommission) {
        $roleLabel = 'Commission';
        if (preg_match('/\|ROLE:([A-Z]+)\|/i', $remarkTrim, $mRole)) {
            $roleCode = strtoupper(trim((string)$mRole[1]));
            if ($roleCode === 'PROFIT') {
                $roleLabel = 'Profit';
            } elseif (in_array($roleCode, ['SALES', 'CS', 'IT'], true)) {
                $roleLabel = $roleCode;
            }
        } elseif (preg_match('/^(Sales|CS|IT)\s+Commision\b/i', trim((string)$description), $mRole2)) {
            $roleLabel = strtoupper(trim((string)$mRole2[1]));
        } elseif (preg_match('/^Profit\s+(Commision|Commission|for)\b/i', trim((string)$description))) {
            $roleLabel = 'Profit';
        }
        $sourceCompany = '';
        if (stripos($remarkTrim, '[AUTO_RENEW|COMMISSION|') === 0) {
            if (preg_match('/^\[AUTO_RENEW\|COMMISSION\|GROUP\|([^|\]]+)/i', $remarkTrim, $mAr)) {
                $sourceCompany = strtoupper(trim((string) $mAr[1]));
            } elseif (preg_match('/^\[AUTO_RENEW\|COMMISSION\|([^|\]]+)/i', $remarkTrim, $mAr)) {
                $sourceCompany = strtoupper(trim((string) $mAr[1]));
            }
            if ($sourceCompany === '') {
                if (preg_match('/Commision\s+for\s+([A-Za-z0-9_-]+)/i', trim((string) $descriptionRaw), $mFor)) {
                    $sourceCompany = strtoupper(trim((string) $mFor[1]));
                }
            }
        } elseif (preg_match('/^\[DOMAIN_SHARE_COMMISSION\|([^|\]]+)/i', $remarkTrim, $mSrc)) {
            $sourceCompany = strtoupper(trim((string)$mSrc[1]));
        }
        if ($sourceCompany === '') {
            $sourceCompany = strtoupper(trim((string)($row['from_account_code'] ?? '')));
        }
        if ($sourceCompany === '') {
            $sourceCompany = 'LAG';
        }
        if ($roleLabel === 'Profit') {
            $description = 'Profit From ' . $sourceCompany;
        } else {
            $description = $roleLabel . ' Commission From ' . $sourceCompany;
        }
    }
    if ($isDomainListFee) {
        $description = 'Pay Domain Fee';
    }
    if ($isAutoRenewFee) {
        if (preg_match('/^\s*Renew\s+(.+)$/i', trim($descriptionRaw), $mRenew)) {
            $description = 'Renew ' . trim((string) $mRenew[1]);
        } else {
            $description = trim($descriptionRaw) !== '' ? trim($descriptionRaw) : 'Renew';
        }
    }
    // Domain List Fee：顾客 from 有账号，入账「池」在业务上视为从总额中扣 % 的前序步骤，Maintenance 上 Account(To) 不展示具体池账号（与 JK 上净利润行口径一致）
    $accRaw = (string) ($row['account_code'] ?? '');
    $displayAccount = $accRaw !== '' ? $accRaw : '-';
    $displayAccount = domainProvisionedMemberAccountIdForDisplay(
        $displayAccount === '-' ? '' : $displayAccount,
        (string) ($row['to_account_role'] ?? ''),
        array_key_exists('to_account_created_source', $row) ? (string) $row['to_account_created_source'] : null
    );
    if ($displayAccount === '') {
        $displayAccount = $accRaw !== '' ? $accRaw : '-';
    }
    $displayAccount = remapPaymentMaintenanceAccountCode((string) $displayAccount, $ownerCode, $profitCode);
    if ($isDomainListFee) {
        $displayAccount = '-';
    }
    if ($isDomainShareCommission) {
        $fromDisplay = '-';
    } else {
        $fromRaw = (string) ($row['from_account_code'] ?? '-');
        $fromDisplay = domainProvisionedMemberAccountIdForDisplay(
            $fromRaw === '-' ? '' : $fromRaw,
            (string) ($row['from_account_role'] ?? ''),
            array_key_exists('from_account_created_source', $row) ? (string) $row['from_account_created_source'] : null
        );
        if ($fromDisplay === '') {
            $fromDisplay = ($fromRaw !== '' && $fromRaw !== '-') ? $fromRaw : '-';
        }
        $fromDisplay = remapPaymentMaintenanceAccountCode((string) $fromDisplay, $ownerCode, $profitCode);
    }
    if (is_string($description) && $description !== '') {
        $ownerCodeUpper = strtoupper(trim($ownerCode));
        // 净利润：库内已含账户标签（如 Profit By JK[...]）保持原文；否则 sms 解析来源公司；再否则回退 ownerCode
        if (preg_match('/^\s*PROFIT\s+BY\b/i', $description)) {
            if (strpos($description, '[') !== false) {
                // keep as stored
            } else {
                $profitSrc = $remarkTrim !== '' ? paymentMaintenanceParseDomainSourceCodeFromSms($remarkTrim) : '';
                if ($profitSrc !== '') {
                    $description = 'PROFIT BY ' . $profitSrc;
                } elseif ($ownerCodeUpper !== '') {
                    $description = 'PROFIT BY ' . $ownerCodeUpper;
                } else {
                    $description = strtoupper(trim($description));
                }
            }
            
        } else {
            // 只将系统代码 C168 替换为 PROFIT；不替换 owner code，
            // 避免把描述中的正常账户名（如 K）错误地替换成其他账户名（如 ALBB）。
            $description = preg_replace('/\bC168\b/i', 'PROFIT', $description);
        }
        $description = strtoupper($description);
    }
    $createdBy = !empty($row['created_by_login']) ? $row['created_by_login'] : ($row['created_by_owner'] ?? '-');
    $deletedBy = !empty($row['deleted_by_login']) ? $row['deleted_by_login'] : ($row['deleted_by_owner'] ?? null);
    return [
        'transaction_id' => (int) $row['id'],
        'date' => $row['transaction_date'],
        'account' => $displayAccount,
        'from_account' => $fromDisplay,
        'currency' => $row['currency_code'] ?? '-',
        'amount' => money_out($row['amount'] ?? '0'),
        'description' => $description,
        'remark' => ($isDomainShareCommission || $isDomainListFee || $isAutoRenewFee) ? '' : ($row['remark'] ?? ''),
        'dts_created' => $row['dts_created'] ?? '',
        'created_by' => $createdBy,
        'transaction_type' => $row['transaction_type'],
        'is_deleted' => $is_deleted,
        'deleted_by' => $deletedBy,
        'dts_deleted' => $row['dts_deleted'] ?? null,
    ];
}

function resolveCompanyOwnerCode(PDO $pdo, int $companyId): string {
    try {
        $st = $pdo->prepare("
            SELECT UPPER(TRIM(COALESCE(o.owner_code, ''))) AS owner_code
            FROM company c
            INNER JOIN owner o ON o.id = c.owner_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $st->execute([$companyId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? strtoupper(trim((string)$v)) : '';
    } catch (PDOException $e) {
        return '';
    }
}

function resolveProfitDisplayCode(PDO $pdo, int $companyId): string
{
    try {
        $st = $pdo->prepare("
            SELECT UPPER(TRIM(COALESCE(a.account_id, ''))) AS account_code
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND (
                    LOWER(TRIM(COALESCE(a.role, ''))) = 'profit'
                    OR UPPER(TRIM(COALESCE(a.account_id, ''))) = 'PROFIT'
              )
            ORDER BY CASE WHEN UPPER(TRIM(COALESCE(a.account_id, ''))) = 'PROFIT' THEN 0 ELSE 1 END, a.id ASC
            LIMIT 1
        ");
        $st->execute([$companyId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') {
            return strtoupper(trim((string)$v));
        }
    } catch (PDOException $e) {
    }
    return 'PROFIT';
}

function remapPaymentMaintenanceAccountCode(?string $code, string $ownerCode, string $profitCode): string
{
    $v = strtoupper(trim((string)$code));
    if ($v === '') {
        return '-';
    }
    // Account(To/From) 统一展示真实 account_id，不做 C168->PROFIT 映射。
    return $v;
}

function paymentMaintenanceSortTimestamp(array $item): int {
    $created = trim((string)($item['dts_created'] ?? ''));
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}:\d{2}:\d{2})$/', $created, $m)) {
        $ts = strtotime($m[3] . '-' . $m[2] . '-' . $m[1] . ' ' . $m[4]);
        return $ts === false ? 0 : $ts;
    }
    return 0;
}

function resolveDomainSubmitter(PDO $pdo, array $listScope, string $dateFromDb, string $dateToDb): string
{
    $scopeFilter = paymentMaintenanceScopeFilter($pdo, $listScope, 't');
    try {
        $st = $pdo->prepare("
            SELECT COALESCE(u.login_id, o.owner_code, '-') AS submitter
            FROM transactions t
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
            WHERE {$scopeFilter['sql']}
              AND t.transaction_type = 'PAYMENT'
              AND t.transaction_date BETWEEN ? AND ?
              AND (
                    t.sms LIKE '[DOMAIN_NET_PROFIT|%'
                    OR t.sms LIKE '[DOMAIN_LIST_FEE|%'
                    OR t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%'
                    OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'PROFIT BY %'
              )
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT 1
        ");
        $st->execute([$scopeFilter['bind'], $dateFromDb, $dateToDb]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '' && strtolower(trim((string)$v)) !== 'null') {
            return trim((string)$v);
        }
    } catch (PDOException $e) {
    }
    try {
        $sessionUserType = strtolower((string)($_SESSION['user_type'] ?? ''));
        if ($sessionUserType === 'owner') {
            $ownerId = (int)($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);
            if ($ownerId > 0) {
                $st2 = $pdo->prepare("SELECT owner_code FROM owner WHERE id = ? LIMIT 1");
                $st2->execute([$ownerId]);
                $oc = $st2->fetchColumn();
                if ($oc !== false && $oc !== null && trim((string)$oc) !== '') {
                    return trim((string)$oc);
                }
            }
        } else {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId > 0) {
                $st3 = $pdo->prepare("SELECT login_id FROM user WHERE id = ? LIMIT 1");
                $st3->execute([$userId]);
                $lid = $st3->fetchColumn();
                if ($lid !== false && $lid !== null && trim((string)$lid) !== '') {
                    return trim((string)$lid);
                }
            }
        }
    } catch (PDOException $e) {
        // ignore fallback errors
    }
    return '-';
}

/**
 * 从 Domain 流水 sms 解析「来源公司短码」（如 QA），供净利润描述 PROFIT BY {QA} 使用。
 */
function paymentMaintenanceParseDomainSourceCodeFromSms(string $sms): string
{
    $t = trim($sms);
    if (preg_match('/^\[DOMAIN_NET_PROFIT\|GROUP\|([^\]|]+)/i', $t, $m)) {
        return strtoupper(trim((string) $m[1]));
    }
    if (preg_match('/^\[DOMAIN_NET_PROFIT\|([^\]|]+)/i', $t, $m)) {
        return strtoupper(trim((string) $m[1]));
    }
    if (preg_match('/^\[DOMAIN_LIST_FEE\|GROUP\|([^\]|]+)/i', $t, $m)) {
        return strtoupper(trim((string) $m[1]));
    }
    if (preg_match('/^\[DOMAIN_LIST_FEE\|([^\]|]+)/i', $t, $m)) {
        $v = strtoupper(trim((string) $m[1]));
        return $v !== 'GROUP' ? $v : '';
    }
    if (preg_match('/^\[DOMAIN_SHARE_COMMISSION\|GROUP\|([^\]|]+)/i', $t, $m)) {
        return strtoupper(trim((string) $m[1]));
    }
    if (preg_match('/^\[DOMAIN_SHARE_COMMISSION\|([^\]|]+)/i', $t, $m)) {
        $v = strtoupper(trim((string) $m[1]));
        return $v !== 'GROUP' ? $v : '';
    }
    return '';
}

/**
 * 将单笔 PAYMENT 归入「来源公司 × 币别」的 list fee / commission 桶（仅识别带 sms 标记的典型行）。
 *
 * @return array{src:string,cur:string,kind:string}|null  kind 为 fee|comm
 */
function paymentMaintenanceClassifyDomainFeeOrCommissionRow(array $row): ?array
{
    $sms = trim((string) ($row['sms'] ?? ''));
    $cur = strtoupper(trim((string) ($row['currency_code'] ?? '')));
    if ($cur === '') {
        return null;
    }
    if ($sms !== '' && stripos($sms, '[DOMAIN_LIST_FEE|') === 0) {
        $src = paymentMaintenanceParseDomainSourceCodeFromSms($sms);
        if ($src !== '') {
            return ['src' => $src, 'cur' => $cur, 'kind' => 'fee'];
        }
    }
    if ($sms !== '' && stripos($sms, '[DOMAIN_SHARE_COMMISSION|') === 0) {
        $src = paymentMaintenanceParseDomainSourceCodeFromSms($sms);
        if ($src !== '') {
            return ['src' => $src, 'cur' => $cur, 'kind' => 'comm'];
        }
    }
    if (preg_match('/^\s*DOMAIN\s+LIST\s+FEE\s+FROM\s+(\S+)/i', (string) ($row['description'] ?? ''), $md)) {
        $src = strtoupper(trim((string) ($md[1] ?? '')));
        if ($src !== '') {
            return ['src' => $src, 'cur' => $cur, 'kind' => 'fee'];
        }
    }

    return null;
}

/**
 * 将 DB datetime/date 转为 Payment Maintenance 列表用的 d/m/Y H:i:s（与 fetchMainTransactions 一致）。
 */
function paymentMaintenanceFormatRowDtsCreated(?string $dbDatetime): string
{
    if ($dbDatetime === null || trim($dbDatetime) === '') {
        return '';
    }
    $ts = strtotime($dbDatetime);
    if ($ts === false) {
        return '';
    }
    return date('d/m/Y H:i:s', $ts);
}

function appendVirtualDomainNetProfitItem(
    PDO $pdo,
    array &$data,
    array $listScope,
    string $dateFromDb,
    string $dateToDb,
    array $currencyFilters,
    string $ownerCode
): void {
    $permCompanyId = tx_permission_company_id_for_scope($pdo, $listScope);
    foreach ($data as $item) {
        $desc = strtoupper(trim((string)($item['description'] ?? '')));
        $remark = strtoupper(trim((string)($item['remark'] ?? '')));
        if (strpos($desc, 'PROFIT BY ') === 0 || strpos($remark, '[DOMAIN_NET_PROFIT|') === 0) {
            return;
        }
    }

    $profitCode = resolveProfitDisplayCode($pdo, $permCompanyId);
    $fallbackSubmitter = resolveDomainSubmitter($pdo, $listScope, $dateFromDb, $dateToDb);
    $ownerCodeU = strtoupper(trim($ownerCode));
    $scopeFilter = paymentMaintenanceScopeFilter($pdo, $listScope, 't');

    // 与 Payment History rollup 一致：展示元数据取自「同源 List Fee 入账」中按业务时间最早的一笔（fee_tx 口径）
    $sql = "SELECT t.sms, t.description, t.amount, t.transaction_date, t.created_at,
                UPPER(COALESCE(c.code, '')) AS currency_code,
                u.login_id AS created_by_login, o.owner_code AS created_by_owner
            FROM transactions t
            LEFT JOIN currency c ON t.currency_id = c.id
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
            WHERE {$scopeFilter['sql']}
              AND t.transaction_type = 'PAYMENT'
              AND t.transaction_date BETWEEN ? AND ?
              AND (
                    t.sms LIKE '[DOMAIN_LIST_FEE|%'
                 OR t.sms LIKE '[DOMAIN_SHARE_COMMISSION|%'
                 OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'DOMAIN LIST FEE FROM %'
                 OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'COMMISION FOR %'
                 OR UPPER(TRIM(COALESCE(t.description, ''))) LIKE 'COMMISSION FOR %'
              )
            ORDER BY t.transaction_date ASC, t.created_at ASC, t.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$scopeFilter['bind'], $dateFromDb, $dateToDb]);
    // [src][currency] => fee, comm, fee_ref（首笔 List Fee 元数据，供日期/创建人/创建时间展示）
    $agg = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $cls = paymentMaintenanceClassifyDomainFeeOrCommissionRow($r);
        if ($cls === null) {
            continue;
        }
        $src = $cls['src'];
        $currencyCode = $cls['cur'];
        if (!empty($currencyFilters) && !in_array($currencyCode, array_map('strtoupper', $currencyFilters), true)) {
            continue;
        }
        if (!isset($agg[$src][$currencyCode])) {
            $agg[$src][$currencyCode] = ['fee' => '0', 'comm' => '0', 'fee_ref' => null];
        }
        $amt = money_normalize($r['amount'] ?? '0');
        if ($cls['kind'] === 'fee') {
            $agg[$src][$currencyCode]['fee'] = money_add($agg[$src][$currencyCode]['fee'], $amt);
            if ($agg[$src][$currencyCode]['fee_ref'] === null) {
                $agg[$src][$currencyCode]['fee_ref'] = [
                    'transaction_date' => $r['transaction_date'] ?? null,
                    'created_at' => $r['created_at'] ?? null,
                    'created_by_login' => $r['created_by_login'] ?? '',
                    'created_by_owner' => $r['created_by_owner'] ?? '',
                ];
            }
        } else {
            $agg[$src][$currencyCode]['comm'] = money_add($agg[$src][$currencyCode]['comm'], $amt);
        }
    }

    foreach ($agg as $src => $curMap) {
        foreach ($curMap as $currencyCode => $tot) {
            $fee = money_normalize($tot['fee'] ?? '0');
            $comm = money_normalize($tot['comm'] ?? '0');
            $net = money_sub($fee, $comm);
            if (money_cmp($net, '0') <= 0) {
                continue;
            }
            $labelSrc = $src !== '' ? $src : ($ownerCodeU !== '' ? $ownerCodeU : $profitCode);
            $feeRef = isset($tot['fee_ref']) && is_array($tot['fee_ref']) ? $tot['fee_ref'] : null;
            $txDateRaw = $feeRef['transaction_date'] ?? null;
            $dateDisplay = ($txDateRaw !== null && $txDateRaw !== '')
                ? date('d/m/Y', strtotime((string) $txDateRaw))
                : date('d/m/Y', strtotime($dateToDb));
            $dtsCreated = paymentMaintenanceFormatRowDtsCreated(isset($feeRef['created_at']) ? (string) $feeRef['created_at'] : null);
            if ($dtsCreated === '') {
                $dtsCreated = paymentMaintenanceFormatRowDtsCreated($dateToDb . ' 00:00:00');
            }
            $createdBy = '';
            if ($feeRef !== null) {
                $createdBy = !empty($feeRef['created_by_login']) ? trim((string) $feeRef['created_by_login']) : trim((string) ($feeRef['created_by_owner'] ?? ''));
            }
            if ($createdBy === '') {
                $createdBy = $fallbackSubmitter;
            }
            $data[] = [
                'transaction_id' => 0,
                'date' => $dateDisplay,
                'account' => $profitCode,
                'from_account' => '-',
                'currency' => $currencyCode,
                'amount' => money_out($net),
                'description' => 'PROFIT BY ' . $labelSrc,
                'remark' => '',
                'dts_created' => $dtsCreated,
                'created_by' => $createdBy,
                'transaction_type' => 'PAYMENT',
                'is_deleted' => 0,
                'deleted_by' => null,
                'dts_deleted' => null,
            ];
        }
    }
}

/**
 * 查询 RATE 类型交易（transaction_entry）并返回输出项数组
 */
function fetchRateTransactionItems(PDO $pdo, array $listScope, $date_from_db, $date_to_db, array $currency_filters, string $ownerCode = '', string $profitCode = 'PROFIT') {
    $permCompanyId = tx_permission_company_id_for_scope($pdo, $listScope);
    $hFilter = paymentMaintenanceScopeFilter($pdo, $listScope, 'h');
    $rateCurrencyFilter = '';
    $rateParams = [$hFilter['bind'], $permCompanyId, $date_from_db, $date_to_db];
    if (!empty($currency_filters)) {
        $currencyPlaceholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $currencyIdStmt = $pdo->prepare("SELECT id FROM currency WHERE code IN ($currencyPlaceholders) AND company_id = ?");
        $currencyIdStmt->execute(array_merge(array_map('strtoupper', $currency_filters), [$permCompanyId]));
        $currencyIds = $currencyIdStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($currencyIds)) {
            $rateCurrencyFilter = " AND e.currency_id IN (" . implode(',', array_fill(0, count($currencyIds), '?')) . ")";
            $rateParams = array_merge($rateParams, $currencyIds);
        } else {
            $rateCurrencyFilter = " AND 1=0";
        }
    }
    $rateSql = "SELECT e.id AS entry_id, e.amount, e.entry_type, e.description AS entry_description, e.currency_id,
                UPPER(COALESCE(c.code, '')) AS currency_code, h.id AS header_id,
                DATE_FORMAT(h.transaction_date, '%d/%m/%Y') AS transaction_date, COALESCE(h.sms, '') AS remark,
                DATE_FORMAT(h.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                acc.account_id AS account_code, acc.name AS account_name,
                u.login_id AS created_by_login, o.owner_code AS created_by_owner
                FROM transaction_entry e
                JOIN transactions h ON e.header_id = h.id JOIN account acc ON e.account_id = acc.id
                INNER JOIN account_company ac ON ac.account_id = acc.id
                LEFT JOIN currency c ON e.currency_id = c.id
                LEFT JOIN user u ON h.created_by = u.id LEFT JOIN owner o ON h.created_by_owner = o.id
                WHERE {$hFilter['sql']} AND ac.company_id = ? AND h.transaction_type = 'RATE'
                AND e.entry_type IN ('RATE_FIRST_TO', 'RATE_TRANSFER_TO') AND h.transaction_date BETWEEN ? AND ?
                $rateCurrencyFilter
                ORDER BY h.created_at DESC, e.id DESC";
    $rateStmt = $pdo->prepare($rateSql);
    $rateStmt->execute($rateParams);
    $rateRows = $rateStmt->fetchAll(PDO::FETCH_ASSOC);

    $relatedEntryStmt = $pdo->prepare("
        SELECT e.entry_type, e.account_id, acc.account_id AS account_code, acc.name AS account_name
        FROM transaction_entry e JOIN account acc ON e.account_id = acc.id
        WHERE e.header_id = ? AND e.entry_type IN ('RATE_FIRST_FROM', 'RATE_FIRST_TO', 'RATE_TRANSFER_FROM', 'RATE_TRANSFER_TO')
        ORDER BY e.id
    ");
    $rateDetailStmt = $pdo->prepare("SELECT rate_transfer_from_amount FROM transactions_rate WHERE transaction_id = ? AND rate_transfer_from_amount IS NOT NULL LIMIT 1");

    $items = [];
    foreach ($rateRows as $rateRow) {
        $headerId = $rateRow['header_id'];
        $entryType = $rateRow['entry_type'];
        $relatedEntryStmt->execute([$headerId]);
        $relatedEntries = $relatedEntryStmt->fetchAll(PDO::FETCH_ASSOC);
        $fromAccountCode = null;

        // 精确匹配成对的 FROM 账户：
        // - RATE_FIRST_TO  -> RATE_FIRST_FROM
        // - RATE_TRANSFER_TO -> RATE_TRANSFER_FROM
        if ($entryType === 'RATE_FIRST_TO') {
            foreach ($relatedEntries as $related) {
                if ($related['entry_type'] === 'RATE_FIRST_FROM') {
                    $fromAccountCode = $related['account_code'];
                    break;
                }
            }
        } elseif ($entryType === 'RATE_TRANSFER_TO') {
            foreach ($relatedEntries as $related) {
                if ($related['entry_type'] === 'RATE_TRANSFER_FROM') {
                    $fromAccountCode = $related['account_code'];
                    break;
                }
            }
        }
        // 兼容旧数据：如果上面没找到，再退回到「任一 FROM 类型」的旧逻辑
        if ($fromAccountCode === null) {
            foreach ($relatedEntries as $related) {
                if (in_array($related['entry_type'], ['RATE_FIRST_FROM', 'RATE_TRANSFER_FROM'])) {
                    $fromAccountCode = $related['account_code'];
                    break;
                }
            }
        }
        $description = $rateRow['entry_description'] ?: 'RATE';
        if (empty($rateRow['entry_description'])) {
            $description = 'RATE FROM ' . ($fromAccountCode ?: 'N/A');
        } else {
            if (preg_match('/^(RATE) (FROM|TO) (.+)$/', $rateRow['entry_description'], $matches) && $matches[2] === 'TO') {
                $description = 'RATE FROM ' . ($fromAccountCode ?: $matches[3]);
            } else {
                $description = $rateRow['entry_description'];
            }
        }
        $displayAmount = money_normalize($rateRow['amount'] ?? '0');
        if ($entryType === 'RATE_TRANSFER_TO') {
            $rateDetailStmt->execute([$headerId]);
            $originalAmount = $rateDetailStmt->fetchColumn();
            if ($originalAmount !== false && $originalAmount !== null && money_cmp($originalAmount, '0') > 0) {
                $displayAmount = money_normalize($originalAmount);
            }
        }
        $description = strtoupper((string) $description);
        $items[] = [
            'transaction_id' => (int) $rateRow['header_id'],
            'date' => $rateRow['transaction_date'],
            'account' => remapPaymentMaintenanceAccountCode((string)($rateRow['account_code'] ?? '-'), $ownerCode, $profitCode),
            'from_account' => remapPaymentMaintenanceAccountCode((string)($fromAccountCode ?? '-'), $ownerCode, $profitCode),
            'currency' => $rateRow['currency_code'] ?? '-',
            'amount' => money_out($displayAmount),
            'description' => $description,
            'remark' => $rateRow['remark'] ?? '',
            'dts_created' => $rateRow['dts_created'] ?? '',
            'created_by' => !empty($rateRow['created_by_login']) ? $rateRow['created_by_login'] : ($rateRow['created_by_owner'] ?? '-'),
            'transaction_type' => 'RATE',
            'is_deleted' => 0,
            'deleted_by' => null,
            'dts_deleted' => null,
        ];
    }
    return $items;
}

/**
 * 查询 transactions_deleted 表
 */
function fetchDeletedTransactions(PDO $pdo, array $listScope, $date_from_db, $date_to_db, $transaction_type, array $currency_filters, array $schema, $exclude_bank_process_rows = false, $deleted_has_source_bank_process = false) {
    $scopeFilter = paymentMaintenanceScopeFilter($pdo, $listScope, 'td', 'transactions_deleted');
    $accMeta = paymentMaintenanceAccountMetaSelectSql($schema);
    $sql = "SELECT td.transaction_id AS id,
                DATE_FORMAT(td.transaction_date, '%d/%m/%Y') AS transaction_date,
                td.transaction_type, td.amount, td.description, COALESCE(td.sms, '') AS remark,
                DATE_FORMAT(td.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.account_id AS account_code, to_acc.name AS account_name,
                from_acc.account_id AS from_account_code, from_acc.name AS from_account_name
                $accMeta,
                {$schema['deletedSelectCurrency']},
                u.login_id AS created_by_login, o.owner_code AS created_by_owner,
                1 AS is_deleted, du.login_id AS deleted_by_login, do.owner_code AS deleted_by_owner,
                DATE_FORMAT(td.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted
            FROM transactions_deleted td
            JOIN account to_acc ON td.account_id = to_acc.id
            LEFT JOIN account from_acc ON td.from_account_id = from_acc.id
            {$schema['deletedCurrencyJoinSql']}
            LEFT JOIN user u ON td.created_by = u.id LEFT JOIN owner o ON td.created_by_owner = o.id
            LEFT JOIN user du ON td.deleted_by_user_id = du.id LEFT JOIN owner do ON td.deleted_by_owner_id = do.id
            WHERE {$scopeFilter['sql']} AND td.transaction_date BETWEEN ? AND ?";
    $params = [$scopeFilter['bind'], $date_from_db, $date_to_db];
    if ($exclude_bank_process_rows && $deleted_has_source_bank_process) {
        $sql .= " AND (td.source_bank_process_id IS NULL OR td.source_bank_process_id = 0)";
    }
    $sql .= " AND NOT (
        UPPER(TRIM(COALESCE(td.description, ''))) LIKE 'PROCESS: BUY PRICE%'
        OR UPPER(TRIM(COALESCE(td.description, ''))) LIKE 'PROCESS: SELL PRICE%'
        OR UPPER(TRIM(COALESCE(td.description, ''))) LIKE 'PROCESS: PROFIT FOR%'
        OR UPPER(TRIM(COALESCE(td.description, ''))) LIKE 'PROCESS: PROFIT SHARING%'
        OR UPPER(TRIM(COALESCE(td.description, ''))) LIKE 'AUTO: BUY PRICE%'
        OR UPPER(TRIM(COALESCE(td.description, ''))) LIKE 'AUTO: SELL PRICE%'
        OR UPPER(TRIM(COALESCE(td.description, ''))) LIKE 'AUTO: PROFIT FOR%'
    )";
    if (!empty($transaction_type)) {
        $sql .= " AND td.transaction_type = ?";
        $params[] = $transaction_type;
    }
    if (!empty($currency_filters) && $schema['deletedCurrencyFilterField'] !== null) {
        $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $sql .= " AND {$schema['deletedCurrencyFilterField']} IN ($placeholders)";
        $params = array_merge($params, array_map('strtoupper', $currency_filters));
    }
    // 包含所有被删除的交易类型（包括 RATE），以便在 Maintenance - Payment 中用红色删除线展示历史记录
    $sql .= " ORDER BY td.created_at DESC, td.transaction_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    $scopeParams = $_GET;
    $hasExplicitScope = dcRequestHasExplicitScope($scopeParams);
    $viewGroupForAccess = dcNormalizeGroupId(
        $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ''
    );

    $listScope = paymentMaintenanceResolveListScope($pdo, $scopeParams);
    $permCompanyId = tx_permission_company_id_for_scope($pdo, $listScope);
    if ($permCompanyId <= 0 && ($listScope['mode'] ?? '') !== 'group') {
        throw new Exception('缺少公司或集团信息');
    }
    if ($permCompanyId > 0) {
        dcAssertUserCanAccessCompany(
            $pdo,
            $permCompanyId,
            $viewGroupForAccess !== '' ? $viewGroupForAccess : null
        );
    }

    $companyOwnerCode = resolveCompanyOwnerCode($pdo, $permCompanyId);
    $profitDisplayCode = resolveProfitDisplayCode($pdo, $permCompanyId);

    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $transaction_type = isset($_GET['transaction_type']) ? strtoupper(trim($_GET['transaction_type'])) : '';
    $currency_filters = [];
    if (isset($_GET['currency']) && $_GET['currency'] !== '') {
        foreach (explode(',', $_GET['currency']) as $code) {
            $code = strtoupper(trim($code));
            if ($code !== '') {
                $currency_filters[$code] = true;
            }
        }
        $currency_filters = array_keys($currency_filters);
    }

    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));

    $schema = getCurrencySchema($pdo);
    if (!empty($currency_filters) && $schema['currencyFilterField'] === null) {
        throw new Exception('系统缺少货币信息，无法按货币筛选，请联系管理员');
    }

    $has_source_bank_process_id = false;
    try {
        $colSrc = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
        $has_source_bank_process_id = $colSrc && $colSrc->rowCount() > 0;
    } catch (PDOException $e) {
        $has_source_bank_process_id = false;
    }
    $exclude_bank_process_rows = $has_source_bank_process_id;

    $deleted_has_source_bank_process = false;
    if ($exclude_bank_process_rows && !empty($schema['has_deleted_table'])) {
        try {
            $cd = $pdo->query("SHOW COLUMNS FROM transactions_deleted LIKE 'source_bank_process_id'");
            $deleted_has_source_bank_process = $cd && $cd->rowCount() > 0;
        } catch (PDOException $e) {
            $deleted_has_source_bank_process = false;
        }
    }

    $data = [];
    $mainRows = fetchMainTransactions($pdo, $listScope, $date_from_db, $date_to_db, $transaction_type, $currency_filters, $schema, $exclude_bank_process_rows);
    foreach ($mainRows as $row) {
        $data[] = rowToItem($row, 0, $companyOwnerCode, $profitDisplayCode);
    }
    if (empty($transaction_type) || $transaction_type === 'PAYMENT') {
        appendVirtualDomainNetProfitItem($pdo, $data, $listScope, $date_from_db, $date_to_db, $currency_filters, $companyOwnerCode);
    }

    if (empty($transaction_type) || $transaction_type === 'RATE') {
        $rateItems = fetchRateTransactionItems($pdo, $listScope, $date_from_db, $date_to_db, $currency_filters, $companyOwnerCode, $profitDisplayCode);
        $data = array_merge($data, $rateItems);
    }

    if ($schema['has_deleted_table']) {
        if (!empty($currency_filters) && $schema['deletedCurrencyFilterField'] === null) {
            throw new Exception('系统缺少货币信息，无法按货币筛选，请联系管理员');
        }
        $deletedRows = fetchDeletedTransactions($pdo, $listScope, $date_from_db, $date_to_db, $transaction_type, $currency_filters, $schema, $exclude_bank_process_rows, $deleted_has_source_bank_process);
        foreach ($deletedRows as $row) {
            $data[] = rowToItem($row, 1, $companyOwnerCode, $profitDisplayCode);
        }
    }

    usort($data, function ($a, $b) {
        $cmp = paymentMaintenanceSortTimestamp($b) <=> paymentMaintenanceSortTimestamp($a);
        return $cmp !== 0 ? $cmp : ((int)($b['transaction_id'] ?? 0) <=> (int)($a['transaction_id'] ?? 0));
    });

    jsonResponse(true, '查询成功', $data);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}