<?php
/**
 * Bank Process Maintenance Search API
 * 返回指定日期范围内、由 Bank process 入账的交易记录（source_bank_process_id IS NOT NULL）
 * 路径: api/bankprocess_maintenance/search_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../transactions/bank_process_bill_display.php';

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
function resolveCompanyId(PDO $pdo) {
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requestedCompanyId = (int) $_GET['company_id'];
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        if ($userRole === 'owner') {
            $owner_id = isset($_SESSION['owner_id']) ? (int) $_SESSION['owner_id'] : (int) $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requestedCompanyId, $owner_id]);
            if ($stmt->fetchColumn()) {
                return $requestedCompanyId;
            }
            throw new Exception('无权访问该公司');
        }
        if (!isset($_SESSION['company_id']) || $requestedCompanyId !== (int) $_SESSION['company_id']) {
            throw new Exception('无权访问该公司');
        }
        return $requestedCompanyId;
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    return (int) $_SESSION['company_id'];
}

/**
 * 检测数据库货币相关表结构
 */
function getCurrencySchema(PDO $pdo) {
    $schema = [
        'has_currency_id' => false,
        'account_has_currency_column' => false,
        'account_has_currency_id_column' => false,
        'has_account_currency_table' => false,
        'selectCurrency' => "'' AS currency_code",
        'currencyJoinSql' => '',
        'currencyFilterField' => null,
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

    return $schema;
}

/**
 * 将用户输入转为 SQL LIKE 的包含匹配参数（转义 \ % _）；空串返回 null 表示不筛选
 */
function likeContainsPattern(?string $raw): ?string {
    if ($raw === null) {
        return null;
    }
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    return '%' . $escaped . '%';
}

/**
 * 查询主表 transactions 中 source_bank_process_id IS NOT NULL 的记录
 *
 * @param string|null $from_search 单一关键词：可匹配流程名、卡主名、账户代码、银行，或与 From 列一致的「名(银行)」整串（OR）
 */
function fetchBankProcessTransactions(PDO $pdo, $company_id, $date_from_db, $date_to_db, array $currency_filters, array $schema, ?string $from_search = null) {
    $hasSourceBankProcess = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
        $hasSourceBankProcess = $colStmt->rowCount() > 0;
    } catch (PDOException $e) {}

    if (!$hasSourceBankProcess) {
        return [];
    }

    // 每笔交易单独存 period_type 时优先用列，否则用 pap 子查询（与 history 一致，避免同一天 monthly/inactive 互相覆盖）
    $hasPeriodTypeCol = false;
    try {
        $hasPeriodTypeCol = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_period_type'")->rowCount() > 0;
    } catch (PDOException $e) {}
    if ($hasPeriodTypeCol) {
        $periodTypeSelect = ", t.source_bank_process_period_type AS period_type";
    } else {
        $hasPapTable = false;
        try {
            $hasPapTable = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'")->rowCount() > 0;
        } catch (PDOException $e) {}
        $periodTypeSelect = $hasPapTable
            ? ", (SELECT pap.period_type FROM process_accounting_posted pap WHERE pap.company_id = t.company_id AND pap.process_id = t.source_bank_process_id ORDER BY ABS(DATEDIFF(pap.posted_date, DATE(t.transaction_date))), pap.id DESC LIMIT 1) AS period_type"
            : ", NULL AS period_type";
    }

    $sql = "SELECT
                t.id,
                DATE_FORMAT(t.transaction_date, '%d/%m/%Y') AS transaction_date,
                t.transaction_type, t.amount, t.description,
                COALESCE(t.sms, '') AS remark,
                DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.id AS account_id, to_acc.account_id AS account_code, to_acc.name AS account_name,
                from_acc.account_id AS from_account_code, from_acc.name AS from_account_name,
                {$schema['selectCurrency']},
                u.login_id AS created_by_login, o.owner_code AS created_by_owner,
                bp.name AS bank_process_name,
                bp.bank AS process_bank,
                a_cm_bp.name AS card_owner_name,
                bp.profit AS process_profit, bp.cost AS process_cost, bp.price AS process_price, bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.profit_sharing AS process_profit_sharing,
                bp.day_start AS bp_day_start,
                t.source_bank_process_id
                $periodTypeSelect,
                0 AS is_deleted,
                NULL AS deleter
            FROM transactions t
            JOIN account to_acc ON t.account_id = to_acc.id
            LEFT JOIN account from_acc ON t.from_account_id = from_acc.id
            LEFT JOIN bank_process bp ON t.source_bank_process_id = bp.id
            LEFT JOIN account a_cm_bp ON bp.card_merchant_id = a_cm_bp.id
            INNER JOIN account_company ac ON ac.account_id = to_acc.id
            {$schema['currencyJoinSql']}
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
            WHERE ac.company_id = ? AND t.transaction_date BETWEEN ? AND ?
            AND t.source_bank_process_id IS NOT NULL";
    $params = [$company_id, $date_from_db, $date_to_db];
    if (!empty($currency_filters) && $schema['currencyFilterField'] !== null) {
        $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $sql .= " AND {$schema['currencyFilterField']} IN ($placeholders)";
        $params = array_merge($params, array_map('strtoupper', $currency_filters));
    }
    $fromPat = likeContainsPattern($from_search);
    if ($fromPat !== null) {
        // From 列拼接规则与 rowToItem 一致，便于搜「TEST M16(CIMB)」整串
        $sql .= " AND (
            COALESCE(bp.name, '') LIKE ?
            OR COALESCE(a_cm_bp.name, '') LIKE ?
            OR COALESCE(a_cm_bp.account_id, '') LIKE ?
            OR COALESCE(bp.bank, '') LIKE ?
            OR CONCAT(
                CASE
                    WHEN NULLIF(TRIM(COALESCE(bp.name, '')), '') IS NOT NULL THEN TRIM(bp.name)
                    WHEN NULLIF(TRIM(COALESCE(a_cm_bp.name, '')), '') IS NOT NULL THEN TRIM(a_cm_bp.name)
                    ELSE '-'
                END,
                IF(bp.bank IS NOT NULL AND TRIM(bp.bank) <> '', CONCAT('(', TRIM(bp.bank), ')'), '')
            ) LIKE ?
        )";
        $params[] = $fromPat;
        $params[] = $fromPat;
        $params[] = $fromPat;
        $params[] = $fromPat;
        $params[] = $fromPat;
    }
    // deleted 记录：保留在列表中，供前端做删除线展示
    $hasDeletedTable = false;
    try {
        $hasDeletedTable = $pdo->query("SHOW TABLES LIKE 'transactions_deleted'")->rowCount() > 0;
    } catch (PDOException $e) {}

    if ($hasDeletedTable) {
        $deletedHasSourceBpCol = false;
        $deletedHasPeriodTypeCol = false;
        $deletedHasCurrencyIdCol = false;
        try { $deletedHasSourceBpCol = $pdo->query("SHOW COLUMNS FROM transactions_deleted LIKE 'source_bank_process_id'")->rowCount() > 0; } catch (PDOException $e) {}
        try { $deletedHasPeriodTypeCol = $pdo->query("SHOW COLUMNS FROM transactions_deleted LIKE 'source_bank_process_period_type'")->rowCount() > 0; } catch (PDOException $e) {}
        try { $deletedHasCurrencyIdCol = $pdo->query("SHOW COLUMNS FROM transactions_deleted LIKE 'currency_id'")->rowCount() > 0; } catch (PDOException $e) {}

        if ($deletedHasSourceBpCol) {
            $deletedPeriodTypeSelect = $deletedHasPeriodTypeCol
                ? "td.source_bank_process_period_type AS period_type"
                : "NULL AS period_type";
            $deletedCurrencyJoinSql = '';
            $deletedCurrencyFilterField = "''";
            if ($deletedHasCurrencyIdCol) {
                $deletedCurrencyJoinSql = " LEFT JOIN currency c_del ON td.currency_id = c_del.id";
                $deletedCurrencyFilterField = "UPPER(COALESCE(c_del.code, ''))";
                $deletedSelectCurrency = "UPPER(COALESCE(c_del.code, '')) AS currency_code";
            } else {
                $deletedSelectCurrency = "{$schema['selectCurrency']}";
                $deletedCurrencyFilterField = $schema['currencyFilterField'] !== null ? $schema['currencyFilterField'] : "''";
            }

            $sql .= "
                UNION ALL
                SELECT
                    td.transaction_id AS id,
                    DATE_FORMAT(td.transaction_date, '%d/%m/%Y') AS transaction_date,
                    td.transaction_type, td.amount, td.description,
                    COALESCE(td.sms, '') AS remark,
                    DATE_FORMAT(td.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                    to_acc_del.id AS account_id, to_acc_del.account_id AS account_code, to_acc_del.name AS account_name,
                    from_acc_del.account_id AS from_account_code, from_acc_del.name AS from_account_name,
                    {$deletedSelectCurrency},
                    u_del.login_id AS created_by_login, o_del.owner_code AS created_by_owner,
                    bp_del.name AS bank_process_name,
                    bp_del.bank AS process_bank,
                    a_cm_bp_del.name AS card_owner_name,
                    bp_del.profit AS process_profit, bp_del.cost AS process_cost, bp_del.price AS process_price, bp_del.card_merchant_id, bp_del.customer_id, bp_del.profit_account_id, bp_del.profit_sharing AS process_profit_sharing,
                    bp_del.day_start AS bp_day_start,
                    td.source_bank_process_id,
                    {$deletedPeriodTypeSelect},
                    1 AS is_deleted,
                    COALESCE(del_u.login_id, del_o.owner_code, '-') AS deleter
                FROM transactions_deleted td
                LEFT JOIN account to_acc_del ON td.account_id = to_acc_del.id
                LEFT JOIN account from_acc_del ON td.from_account_id = from_acc_del.id
                LEFT JOIN bank_process bp_del ON td.source_bank_process_id = bp_del.id
                LEFT JOIN account a_cm_bp_del ON bp_del.card_merchant_id = a_cm_bp_del.id
                {$deletedCurrencyJoinSql}
                LEFT JOIN user u_del ON td.created_by = u_del.id
                LEFT JOIN owner o_del ON td.created_by_owner = o_del.id
                LEFT JOIN user del_u ON td.deleted_by_user_id = del_u.id
                LEFT JOIN owner del_o ON td.deleted_by_owner_id = del_o.id
                WHERE td.company_id = ? AND td.transaction_date BETWEEN ? AND ?
                  AND td.source_bank_process_id IS NOT NULL";

            $params = array_merge($params, [$company_id, $date_from_db, $date_to_db]);

            if (!empty($currency_filters)) {
                $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
                $sql .= " AND {$deletedCurrencyFilterField} IN ($placeholders)";
                $params = array_merge($params, array_map('strtoupper', $currency_filters));
            }

            if ($fromPat !== null) {
                $sql .= " AND (
                    COALESCE(bp_del.name, '') LIKE ?
                    OR COALESCE(a_cm_bp_del.name, '') LIKE ?
                    OR COALESCE(a_cm_bp_del.account_id, '') LIKE ?
                    OR COALESCE(bp_del.bank, '') LIKE ?
                    OR CONCAT(
                        CASE
                            WHEN NULLIF(TRIM(COALESCE(bp_del.name, '')), '') IS NOT NULL THEN TRIM(bp_del.name)
                            WHEN NULLIF(TRIM(COALESCE(a_cm_bp_del.name, '')), '') IS NOT NULL THEN TRIM(a_cm_bp_del.name)
                            ELSE '-'
                        END,
                        IF(bp_del.bank IS NOT NULL AND TRIM(bp_del.bank) <> '', CONCAT('(', TRIM(bp_del.bank), ')'), '')
                    ) LIKE ?
                )";
                $params[] = $fromPat;
                $params[] = $fromPat;
                $params[] = $fromPat;
                $params[] = $fromPat;
                $params[] = $fromPat;
            }
        }
    }

    // Maintenance 列表统一按 DTS Created 排序（最新在前），且对 deleted / non-deleted 一视同仁。
    $sql .= " ORDER BY STR_TO_DATE(dts_created, '%d/%m/%Y %H:%i:%s') DESC, id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 成本/售价/利润等入账行：与 history_api / search_api 一致，以 description 是否以 Process: 或 Auto: 开头区分
 * （账单类 WIN/LOSE 的 description 不为 Process:/Auto: 前缀，应继续展示 Remaining days bill 等合成描述）
 */
function bankProcessMaintenanceUseRawProcessDescription(?string $rawDesc): bool {
    $d = trim((string) $rawDesc);
    if ($d === '') {
        return false;
    }
    return (bool) preg_match('/^(Process:|Auto:|Compensation\s+)/i', $d);
}

/**
 * 去掉 Process:/Auto: 前缀，并转为标题大小写（如 Buy Price For Test M20 (Partial First Month)）
 */
function bankProcessMaintenanceFormatLedgerDescription(?string $rawFromDb): string {
    $s = trim((string) $rawFromDb);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/^(Process:|Auto:)\s*/i', '', $s);
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_strtolower') && function_exists('mb_convert_case')) {
        $lower = mb_strtolower($s, 'UTF-8');
        return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($s));
}

/**
 * 将一行转换为统一输出项
 * Description：账单类 WIN/LOSE 与 history 一致（Remaining/Monthly bill + 金额）；Process:/Auto: 行去前缀并以标题大小写展示
 */
function rowToItem(array $row) {
    $rawDescription = trim((string) ($row['description'] ?? ''));
    $description = $row['description'] ?? '';

    // WIN/LOSE（Bank process 入账）：与 history_api 一致，账单行 Description 金额用本笔实际入账 amount
    if (in_array($row['transaction_type'] ?? '', ['WIN', 'LOSE'], true)) {
        if (bankProcessMaintenanceUseRawProcessDescription($rawDescription)) {
            $description = bankProcessMaintenanceFormatLedgerDescription($rawDescription);
        } else {
            $periodType = isset($row['period_type']) ? trim((string) $row['period_type']) : '';
            if ($periodType === 'partial_first_month') {
                $description = bankProcessProRatedFirstMonthDescription($row);
            } elseif ($periodType === 'once_one_off') {
                $description = bankProcessOnceOneOffHistoryDescription($row);
            } else {
                if ($periodType === 'day_end_tail') {
                    $description = 'Day end tail bill';
                } elseif ($periodType === 'resend_consolidated_range') {
                    $description = 'Resend consolidated bill';
                } elseif ($periodType === 'manual_inactive') {
                    $description = 'Inactive bill';
                } elseif ($periodType === 'monthly' || $periodType === '') {
                    $description = 'Monthly bill';
                } else {
                    $description = 'Monthly bill';
                }
                $billAmount = bankProcessBillFormatTripartNumber($row['amount'] ?? '0');
                $description = $description . ' ' . $billAmount;
            }
            $description = bankProcessAppendBankSuffixToDescription((string) $description, $row);
        }
    } elseif (empty($description) && in_array($row['transaction_type'] ?? '', ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM'])) {
        $description = ($row['transaction_type'] ?? '') . ' FROM ' . ($row['from_account_code'] ?? 'N/A');
    }

    $createdBy = !empty($row['created_by_login']) ? $row['created_by_login'] : ($row['created_by_owner'] ?? '-');
    // From 列：与 transaction history 的 card_owner 一致——优先 bank_process.name（Card Owner），否则供应商账户名；有银行时追加 (BANK)，如 TEST M16(CIMB)
    $bankProcessName = isset($row['bank_process_name']) ? trim((string) $row['bank_process_name']) : '';
    $cardOwnerName = isset($row['card_owner_name']) ? trim((string) $row['card_owner_name']) : '';
    $fromLabel = $bankProcessName !== '' ? $bankProcessName : ($cardOwnerName !== '' ? $cardOwnerName : '-');
    $processBank = isset($row['process_bank']) ? trim((string) $row['process_bank']) : '';
    if ($fromLabel !== '-' && $processBank !== '') {
        $fromLabel .= '(' . $processBank . ')';
    }

    $periodType = strtolower(trim((string) ($row['period_type'] ?? '')));

    return [
        'transaction_id' => (int) $row['id'],
        'date' => $row['transaction_date'],
        'account' => $row['account_code'] ?? '-',
        'from_account' => $fromLabel,
        'currency' => $row['currency_code'] ?? '-',
        'amount' => money_out($row['amount'] ?? '0'),
        'description' => $description ?: '-',
        'remark' => $row['remark'] ?? '',
        'dts_created' => $row['dts_created'] ?? '',
        'created_by' => $createdBy,
        'deleter' => $row['deleter'] ?? '',
        'is_deleted' => isset($row['is_deleted']) ? ((int) $row['is_deleted'] === 1) : false,
        'transaction_type' => $row['transaction_type'],
        'source_bank_process_id' => (int) ($row['source_bank_process_id'] ?? 0),
        'period_type' => $periodType !== '' ? $periodType : 'monthly',
    ];
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    $company_id = resolveCompanyId($pdo);

    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
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

    $from_search = isset($_GET['q']) && $_GET['q'] !== ''
        ? (string) $_GET['q']
        : (isset($_GET['search']) ? (string) $_GET['search'] : '');

    $rows = fetchBankProcessTransactions($pdo, $company_id, $date_from_db, $date_to_db, $currency_filters, $schema, $from_search);
    $data = [];
    foreach ($rows as $row) {
        $data[] = rowToItem($row);
    }

    jsonResponse(true, '查询成功', $data);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}