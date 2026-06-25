<?php
/**
 * 客户报表 API：按公司、账户、日期、货币返回 Win/Lose 报表
 * 路径: api/reports/customer_report_api.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/report_scope_common.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行

function reportMoneyOut($value): string {
    return money_out($value ?? '0');
}

function tableExists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
    return $stmt && $stmt->rowCount() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
    return $stmt && $stmt->rowCount() > 0;
}

/**
 * 与 Transaction 列表一致：公司代码 + 集团 ID（大写），供报表行展示。
 */
function fetchCompanyReportMeta(PDO $pdo, int $companyId): array {
    $stmt = $pdo->prepare("SELECT company_id, group_id FROM company WHERE id = ? LIMIT 1");
    $stmt->execute([$companyId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return ['company_id' => null, 'group_id' => null];
    }
    $cid = isset($r['company_id']) ? strtoupper(trim((string) $r['company_id'])) : '';
    $gidRaw = $r['group_id'] ?? null;
    $gid = ($gidRaw !== null && trim((string) $gidRaw) !== '')
        ? strtoupper(trim((string) $gidRaw)) : null;
    return [
        'company_id' => $cid !== '' ? $cid : null,
        'group_id' => $gid,
    ];
}

/**
 * @param array<string, mixed> $listScope from tx_resolve_transaction_list_scope
 */
function getAccountsForReport(PDO $pdo, array $listScope, string $accountIdFilter): array {
    $isGroup = (($listScope['mode'] ?? '') === 'group');
    $params = [];
    if ($isGroup) {
        $groupPk = (int) ($listScope['group_scope_id'] ?? 0);
        $accountIds = tenant_collect_group_account_ids($pdo, $groupPk);
        if ($accountIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($accountIds), '?'));
        $sql = "SELECT a.id, a.account_id, a.name
                FROM account a
                WHERE a.id IN ($ph)";
        $params = $accountIds;
    } else {
        $companyId = (int) ($listScope['company_id'] ?? 0);
        if ($companyId <= 0) {
            return [];
        }
        $useAccountCompany = tableExists($pdo, 'account_company');
        if ($useAccountCompany) {
            $sql = "SELECT a.id, a.account_id, a.name
                    FROM account a
                    INNER JOIN account_company ac ON a.id = ac.account_id
                    WHERE ac.company_id = ?";
            $sql .= tenant_sql_account_company_subsidiary_only($pdo, 'ac');
        } else {
            $sql = "SELECT id, account_id, name FROM account WHERE company_id = ?";
        }
        $params = [$companyId];
    }
    if ($accountIdFilter !== '') {
        $params[] = (int) $accountIdFilter;
        $sql .= " AND a.id = ?";
    }
    $sql .= " ORDER BY a.account_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAccountCurrencies(PDO $pdo, int $accountId): array {
    $map = getAccountCurrenciesBulk($pdo, [$accountId]);
    return $map[$accountId] ?? [];
}

/**
 * 一次查询取多个账户的币种绑定（替代逐账户 N 次查询）。
 * @return array<int, list<array{currency_id:int,currency_code:string}>>
 */
function getAccountCurrenciesBulk(PDO $pdo, array $accountIds): array {
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    $out = [];
    foreach ($accountIds as $aid) {
        $out[$aid] = [];
    }
    if (empty($accountIds)) {
        return $out;
    }
    if (tableExists($pdo, 'account_currency')) {
        $chunkSize = 400;
        for ($i = 0; $i < count($accountIds); $i += $chunkSize) {
            $chunk = array_slice($accountIds, $i, $chunkSize);
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT ac.account_id, c.id AS currency_id, c.code AS currency_code
                    FROM account_currency ac
                    INNER JOIN currency c ON ac.currency_id = c.id
                    WHERE ac.account_id IN ($in)
                    ORDER BY ac.account_id, ac.created_at ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $aid = (int) $row['account_id'];
                if (!isset($out[$aid])) {
                    $out[$aid] = [];
                }
                $out[$aid][] = [
                    'currency_id' => (int) $row['currency_id'],
                    'currency_code' => $row['currency_code'],
                ];
            }
        }
        return $out;
    }
    if (columnExists($pdo, 'account', 'currency_id')) {
        $chunkSize = 400;
        for ($i = 0; $i < count($accountIds); $i += $chunkSize) {
            $chunk = array_slice($accountIds, $i, $chunkSize);
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT a.id AS account_id, c.id AS currency_id, c.code AS currency_code
                    FROM account a
                    INNER JOIN currency c ON a.currency_id = c.id
                    WHERE a.id IN ($in)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $aid = (int) $row['account_id'];
                $out[$aid][] = [
                    'currency_id' => (int) $row['currency_id'],
                    'currency_code' => $row['currency_code'],
                ];
            }
        }
    }
    return $out;
}

/**
 * 批量：各账户 × 币种 在日期内的 Win/Lose（与逐条 getWinLoseByCurrency 语义一致）。
 * @return array<string, array{win:string,lose:string}> key = "accountId:currencyId"
 */
function fetchWinLoseByAccountCurrencyBulk(
    PDO $pdo,
    array $accountIds,
    string $dateFrom,
    string $dateTo,
    int $dcdCompanyId
): array {
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    if (empty($accountIds) || $dcdCompanyId <= 0) {
        return [];
    }
    $chunkSize = 250;
    $agg = [];
    for ($i = 0; $i < count($accountIds); $i += $chunkSize) {
        $chunk = array_slice($accountIds, $i, $chunkSize);
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT dcd.account_id, dcd.currency_id,
                COALESCE(SUM(CASE WHEN dcd.processed_amount > 0 THEN dcd.processed_amount ELSE 0 END), 0) AS win_total,
                COALESCE(SUM(CASE WHEN dcd.processed_amount < 0 THEN dcd.processed_amount ELSE 0 END), 0) AS lose_total
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.account_id IN ($in)
              AND dcd.company_id = ?
              AND dc.company_id = ?
              AND dc.capture_date BETWEEN ? AND ?
            GROUP BY dcd.account_id, dcd.currency_id";
        $params = array_merge($chunk, [$dcdCompanyId, $dcdCompanyId, $dateFrom, $dateTo]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aid = (int) $row['account_id'];
            $cid = (int) $row['currency_id'];
            $key = $aid . ':' . $cid;
            $agg[$key] = [
                'win' => reportMoneyOut($row['win_total'] ?? '0'),
                'lose' => reportMoneyOut($row['lose_total'] ?? '0'),
            ];
        }
    }
    return $agg;
}

/**
 * 批量：无币种绑定账户在日期内的 Win/Lose（与 getWinLoseNoCurrency 一致：不按 currency_id 过滤）。
 * @return array<int, array{win:string,lose:string}>
 */
function fetchWinLoseNoCurrencyBulk(
    PDO $pdo,
    array $accountIds,
    string $dateFrom,
    string $dateTo,
    int $dcdCompanyId
): array {
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    if (empty($accountIds) || $dcdCompanyId <= 0) {
        return [];
    }
    $chunkSize = 250;
    $out = [];
    for ($i = 0; $i < count($accountIds); $i += $chunkSize) {
        $chunk = array_slice($accountIds, $i, $chunkSize);
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT dcd.account_id,
                COALESCE(SUM(CASE WHEN dcd.processed_amount > 0 THEN dcd.processed_amount ELSE 0 END), 0) AS win_total,
                COALESCE(SUM(CASE WHEN dcd.processed_amount < 0 THEN dcd.processed_amount ELSE 0 END), 0) AS lose_total
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.account_id IN ($in)
              AND dcd.company_id = ?
              AND dc.company_id = ?
              AND dc.capture_date BETWEEN ? AND ?
            GROUP BY dcd.account_id";
        $params = array_merge($chunk, [$dcdCompanyId, $dcdCompanyId, $dateFrom, $dateTo]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aid = (int) $row['account_id'];
            $out[$aid] = [
                'win' => reportMoneyOut($row['win_total'] ?? '0'),
                'lose' => reportMoneyOut($row['lose_total'] ?? '0'),
            ];
        }
    }
    return $out;
}

function applyCurrencyFilter(array $currencyList, string $filterCodes): array {
    if ($filterCodes === '') {
        return $currencyList;
    }
    $codes = array_map('strtoupper', array_map('trim', explode(',', $filterCodes)));
    return array_filter($currencyList, function ($c) use ($codes) {
        return in_array(strtoupper($c['currency_code']), $codes);
    });
}

/**
 * @param array<string, mixed> $listScope
 */
function buildReportData(
    PDO $pdo,
    array $listScope,
    string $accountId,
    string $dateFrom,
    string $dateTo,
    bool $showAll,
    string $currencyFilter
): array {
    $accounts = getAccountsForReport($pdo, $listScope, $accountId);
    $isGroup = (($listScope['mode'] ?? '') === 'group');
    $metaCompanyId = $isGroup
        ? tx_permission_company_id_for_scope($pdo, $listScope)
        : (int) ($listScope['company_id'] ?? 0);
    $dcdCompanyId = $isGroup
        ? $metaCompanyId
        : (int) ($listScope['company_id'] ?? 0);
    $coMeta = fetchCompanyReportMeta($pdo, $metaCompanyId > 0 ? $metaCompanyId : $dcdCompanyId);
    $reportData = [];
    $totalWin = '0.00000000';
    $totalLose = '0.00000000';

    if (empty($accounts)) {
        return [$reportData, reportMoneyOut($totalWin), reportMoneyOut($totalLose)];
    }

    $accountIds = array_map(static function ($a) {
        return (int) $a['id'];
    }, $accounts);
    $curByAccount = getAccountCurrenciesBulk($pdo, $accountIds);

    $idsWithAssignedCurrency = [];
    $idsNoCurrency = [];
    foreach ($accountIds as $aid) {
        $allCurrencies = $curByAccount[$aid] ?? [];
        if (!empty($allCurrencies)) {
            $idsWithAssignedCurrency[] = $aid;
        } else {
            $idsNoCurrency[] = $aid;
        }
    }

    $wlByPair = !empty($idsWithAssignedCurrency)
        ? fetchWinLoseByAccountCurrencyBulk($pdo, $idsWithAssignedCurrency, $dateFrom, $dateTo, $dcdCompanyId)
        : [];
    $wlNoCur = !empty($idsNoCurrency)
        ? fetchWinLoseNoCurrencyBulk($pdo, $idsNoCurrency, $dateFrom, $dateTo, $dcdCompanyId)
        : [];

    $zeroWin = reportMoneyOut('0');
    $zeroLose = reportMoneyOut('0');

    foreach ($accounts as $account) {
        $accId = (int) $account['id'];
        $allCurrencies = $curByAccount[$accId] ?? [];
        $currencyList = applyCurrencyFilter($allCurrencies, $currencyFilter);

        if (!empty($currencyList)) {
            foreach ($currencyList as $cur) {
                $cid = (int) $cur['currency_id'];
                $key = $accId . ':' . $cid;
                $wl = $wlByPair[$key] ?? ['win' => $zeroWin, 'lose' => $zeroLose];
                if (!$showAll && money_cmp($wl['win'], '0') === 0 && money_cmp($wl['lose'], '0') === 0) {
                    continue;
                }
                $totalWin = money_add($totalWin, $wl['win']);
                $totalLose = money_add($totalLose, $wl['lose']);
                $reportData[] = [
                    'id' => $account['id'],
                    'account_id' => $account['account_id'],
                    'name' => $account['name'],
                    'group_id' => $coMeta['group_id'],
                    'company_id' => $coMeta['company_id'],
                    'currency' => strtoupper(trim((string) $cur['currency_code'])),
                    'win' => $wl['win'],
                    'lose' => $wl['lose'],
                ];
            }
        } elseif (!empty($allCurrencies)) {
            continue;
        } else {
            if ($currencyFilter !== '') {
                continue;
            }
            $wl = $wlNoCur[$accId] ?? ['win' => $zeroWin, 'lose' => $zeroLose];
            if (!$showAll && money_cmp($wl['win'], '0') === 0 && money_cmp($wl['lose'], '0') === 0) {
                continue;
            }
            $totalWin = money_add($totalWin, $wl['win']);
            $totalLose = money_add($totalLose, $wl['lose']);
            $reportData[] = [
                'id' => $account['id'],
                'account_id' => $account['account_id'],
                'name' => $account['name'],
                'group_id' => $coMeta['group_id'],
                'company_id' => $coMeta['company_id'],
                'currency' => null,
                'win' => $wl['win'],
                'lose' => $wl['lose'],
            ];
        }
    }

    return [$reportData, reportMoneyOut($totalWin), reportMoneyOut($totalLose)];
}

function jsonResponse(bool $success, string $message, $data = null, array $extra = []): void {
    $out = [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
    foreach ($extra as $k => $v) {
        $out[$k] = $v;
    }
    echo json_encode($out);
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    $dateFrom = trim($_GET['date_from'] ?? '');
    $dateTo = trim($_GET['date_to'] ?? '');
    if ($dateFrom === '' || $dateTo === '') {
        http_response_code(400);
        jsonResponse(false, '开始日期和结束日期不能为空', null);
        return;
    }

    $dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFrom);
    $dateToObj = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$dateFromObj || !$dateToObj) {
        http_response_code(400);
        jsonResponse(false, '日期格式不正确，请使用 YYYY-MM-DD 格式', null);
        return;
    }
    if ($dateFromObj > $dateToObj) {
        http_response_code(400);
        jsonResponse(false, '开始日期不能大于结束日期', null);
        return;
    }

    $accountId = trim($_GET['account_id'] ?? '');
    $showAll = filter_var($_GET['show_all'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $currencyFilter = trim($_GET['currency'] ?? '');

    $resolved = resolveReportRequestCompanyScope($pdo, $_GET);
    $listScope = $resolved['list_scope'];
    $scope = ($resolved['report_scope_hint'] === 'group' || ($listScope['mode'] ?? '') === 'group')
        ? 'group'
        : 'company';

    list($reportData, $totalWin, $totalLose) = buildReportData(
        $pdo,
        $listScope,
        $accountId,
        $dateFrom,
        $dateTo,
        $showAll,
        $currencyFilter
    );

    jsonResponse(true, '', $reportData, [
        'scope' => $scope,
        'total_win' => $totalWin,
        'total_lose' => $totalLose,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
} catch (PDOException $e) {
    error_log('Customer Report API Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '数据库查询失败', null);
}