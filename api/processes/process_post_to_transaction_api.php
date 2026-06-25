<?php
/**
 * Process Post to Transaction API
 * 将选中的 Bank Process 的 Buy Price / Sell Price / Profit 分别记入 Supplier / Customer / Company 账户（Transaction 页面显示）
 * 支持 period_types[]：partial_first_month = 首月按比例（day_start 到月底），monthly = 按 frequency=monthly 的「对日对月」服务区间比例（与 Inbox 一致）；frequency=1st_of_every_month 的 monthly 且 day_end_monthly_cap_enabled=ON 时，若 day_end 落在该账单自然月内则该期按「月初～day_end」比例（与 Inbox 一致）。day_end_tail = 尾段 prorateInclusiveDateRange（1st+cap 列 ON 时为 max(exclusiveEnd, day_end 月首)～day_end；否则 exclusiveEnd～day_end 且需 day_end≥exclusiveEnd；1st+cap OFF 不入账尾段），
 * resend_consolidated_range = 仅 Resend 弹窗同时填 day_start+day_end 时：按自然月切段 [day_start, day_end] 合并为一笔（与 Inbox 一致）。
 * 入账请求仅针对当前公司下选中的 Bank Process；Frequency=once 且 period_type=once_one_off 入账成功后，将该 process 的 status 置为 inactive（Accounting Due 的 Dismiss 不写 status）。
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../bankprocess_maintenance/maintenance_accounting_resend_lib.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/../includes/ensure_bank_process_day_end_monthly_cap_column.php';
require_once __DIR__ . '/../includes/transaction_approval.php';
require_once __DIR__ . '/contract_billing_addon.php';

if (isset($pdo) && $pdo instanceof PDO) {
    ensureBankProcessDayEndMonthlyCapEnabledColumn($pdo);
}

/** 统一 JSON 响应 */
function jsonResponse(bool $success, string $message = '', $data = null): void
{
    $payload = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload);
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

function getBankProcessIssueFlagSql(string $tableAlias, bool $hasIssueFlagColumn, bool $hasFlagColumn): string
{
    if ($hasIssueFlagColumn && $hasFlagColumn) {
        return "COALESCE(NULLIF($tableAlias.`flag`, ''), NULLIF($tableAlias.`issue_flag`, ''))";
    }
    if ($hasFlagColumn)
        return "$tableAlias.`flag`";
    if ($hasIssueFlagColumn)
        return "$tableAlias.`issue_flag`";
    return "NULL";
}

function normalizedBankIssueFlagSql(string $columnRef): string
{
    return "LOWER(REPLACE(REPLACE(TRIM(COALESCE($columnRef, '')), '-', '_'), ' ', '_'))";
}

function insertTransactionRow(PDO $pdo, array $data): int
{
    $columns = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO transactions (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    return (int) $pdo->lastInsertId();
}

/** Accounting Due 入账：写入审批字段（一律 APPROVED；含 PARTNER 账户或 partnership 用户提交）。 */
function applyBankProcessPostApprovalFields(
    PDO $pdo,
    array &$txn,
    array $process,
    int $accountId,
    string $userRole,
    ?int $createdByUser,
    $ownerId,
    string $ledgerDateYmd
): void {
    $approved = true;
    $approverUser = null;
    $approverOwner = null;
    if ($approved) {
        if ($createdByUser !== null && $createdByUser > 0) {
            $approverUser = $createdByUser;
        }
        if ($ownerId !== null && (int) $ownerId > 0) {
            $approverOwner = (int) $ownerId;
        }
    }
    foreach (tx_apply_transaction_approval_fields($pdo, $approved, $approverUser, $approverOwner) as $key => $value) {
        $txn[$key] = $value;
    }
}

/**
 * 兼容旧库里的 transactions 金额触发器（要求 amount > 0）。
 * 业务需要允许 0.00（如 Profit 被 Share 抵消），仅禁止负数。
 * ADJUSTMENT 允许正负金额（仅禁止 0），与 submit_api / allow_adjustment_signed_amount.sql 一致。
 */
function ensureTransactionsAllowZeroAmount(PDO $pdo): void
{
    $triggers = $pdo->query("SHOW TRIGGERS WHERE `Table` = 'transactions'")->fetchAll(PDO::FETCH_ASSOC);
    $legacyTriggerNames = [];

    foreach ($triggers as $tr) {
        $stmt = strtolower((string) ($tr['Statement'] ?? ''));
        $isAmountGuard = (
            strpos($stmt, 'new.amount') !== false
            && strpos($stmt, '45000') !== false
            && (
                strpos($stmt, '金额必须大于 0') !== false
                || strpos($stmt, '金额必须大于0') !== false
                || strpos($stmt, 'amount must be greater than 0') !== false
                || strpos($stmt, '<= 0') !== false
            )
        );
        if ($isAmountGuard && !empty($tr['Trigger'])) {
            $legacyTriggerNames[] = (string) $tr['Trigger'];
        }
    }

    foreach ($legacyTriggerNames as $name) {
        $safeName = str_replace('`', '``', $name);
        $pdo->exec("DROP TRIGGER IF EXISTS `$safeName`");
    }

    // 非 ADJUSTMENT：金额不能小于 0（允许 0.00）；ADJUSTMENT：保留正负号，仅禁止 0
    $pdo->exec("DROP TRIGGER IF EXISTS `tr_transactions_amount_guard_bi`");
    $pdo->exec("DROP TRIGGER IF EXISTS `tr_transactions_amount_guard_bu`");

    $pdo->exec("
        CREATE TRIGGER `tr_transactions_amount_guard_bi`
        BEFORE INSERT ON `transactions`
        FOR EACH ROW
        BEGIN
            IF NEW.transaction_type = 'ADJUSTMENT' THEN
                IF NEW.amount = 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT 金额不能为 0';
                END IF;
                IF NEW.from_account_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT only supports one account';
                END IF;
            ELSE
                IF NEW.amount < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '金额不能小于 0';
                END IF;
            END IF;
        END
    ");

    $pdo->exec("
        CREATE TRIGGER `tr_transactions_amount_guard_bu`
        BEFORE UPDATE ON `transactions`
        FOR EACH ROW
        BEGIN
            IF NEW.transaction_type = 'ADJUSTMENT' THEN
                IF NEW.amount = 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT 金额不能为 0';
                END IF;
                IF NEW.from_account_id IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT only supports one account';
                END IF;
            ELSE
                IF NEW.amount < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '金额不能小于 0';
                END IF;
            END IF;
        END
    ");
}

/**
 * 清理 Transaction List 搜索缓存，确保 Process 入账（含 Resend）后列表立即同步。
 */
function clearTransactionSearchCache(): void
{
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'count168_tx_search';
    if (!is_dir($cacheDir)) {
        return;
    }
    foreach (scandir($cacheDir) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $fullPath = $cacheDir . DIRECTORY_SEPARATOR . $file;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

/**
 * 截断到2位小数（不四舍五入）
 */
function txnTrunc2($value): string
{
    return money_normalize($value, 2);
}

function txnFormat2($value): string
{
    return txnTrunc2($value);
}

function txnDescriptionAmount($value): string
{
    return money_out($value, 2);
}

/** Pro-rated cost/price/profit for partial first month (day_start to end of that month) */
function partialFirstMonthAmounts(string $dayStart, string $cost, string $price, string $profit): array
{
    $ts = strtotime($dayStart);
    if ($ts === false) {
        return ['cost' => $cost, 'price' => $price, 'profit' => $profit];
    }
    $daysInMonth = (int) date('t', $ts);
    $dayOfMonth = (int) date('j', $ts);
    $daysRemaining = $daysInMonth - $dayOfMonth + 1;
    if ($daysInMonth <= 0) {
        return ['cost' => $cost, 'price' => $price, 'profit' => $profit];
    }
    $ratio = money_div((string) $daysRemaining, (string) $daysInMonth, MONEY_CALC_SCALE);
    return [
        'cost' => money_mul($cost, $ratio, 2),
        'price' => money_mul($price, $ratio, 2),
        'profit' => money_mul($profit, $ratio, 2),
    ];
}

/** Pro-rated amounts from $startYmd (inclusive) to end of that month (inclusive). */
function prorateToMonthEndFromStart(string $startYmd, string $cost, string $price, string $profit): array
{
    $ts = strtotime($startYmd);
    if ($ts === false) {
        return ['cost' => $cost, 'price' => $price, 'profit' => $profit];
    }
    $daysInMonth = (int) date('t', $ts);
    $dayOfMonth = (int) date('j', $ts);
    if ($daysInMonth <= 0) {
        return ['cost' => $cost, 'price' => $price, 'profit' => $profit];
    }
    $daysRemaining = max(0, $daysInMonth - $dayOfMonth + 1);
    $ratio = money_div((string) $daysRemaining, (string) $daysInMonth, MONEY_CALC_SCALE);
    return [
        'cost' => money_mul($cost, $ratio, 2),
        'price' => money_mul($price, $ratio, 2),
        'profit' => money_mul($profit, $ratio, 2),
    ];
}

function ymdFromNullableDateTime($raw, string $fallbackYmd): string
{
    if ($raw === null) {
        return $fallbackYmd;
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return $fallbackYmd;
    }
    $ts = strtotime($s);
    return $ts === false ? $fallbackYmd : date('Y-m-d', $ts);
}

/**
 * bank_process.day_start 等：优先解析 d/m/Y、d-m-Y，避免 "06-04-2026" 被 strtotime 当成美式 m-d-Y。
 */
function bankProcessDateFieldToYmd($raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    $dateStr = str_replace('/', '-', $s);
    if (preg_match('/^\d{1,2}-\d{1,2}$/', $dateStr)) {
        $dateStr .= '-' . date('Y');
    }
    $ts = strtotime($dateStr);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function maxYmd(string $a, string $b): string
{
    return ($a >= $b) ? $a : $b;
}

function getBillingTermMonthsFromContract(?string $contract): ?int
{
    if ($contract === null || trim($contract) === '') {
        return null;
    }
    $c = trim($contract);
    if (preg_match('/^1\+(\d+)$/i', $c, $m)) {
        // 1+N 在 active regular billing 仅计 1 个月；
        // 额外 N 个月仅在 manual_inactive 赔付逻辑中处理（见 getExtraMonthsFromContract / multiplier）。
        return 1;
    }
    if (preg_match('/^(\d+)\s*MONTHS?$/i', $c, $m)) {
        return max(1, (int) $m[1]);
    }
    return null;
}

function billingContractExclusiveEndYmd(string $dayStartYmd, int $termMonths): ?string
{
    if ($termMonths < 1) {
        return null;
    }
    try {
        return (new DateTimeImmutable($dayStartYmd))->modify("+{$termMonths} months")->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function billingContractExclusiveEndYmdFirstOfMonth(string $dayStartYmd, int $termMonths): ?string
{
    if ($termMonths < 1) {
        return null;
    }
    try {
        $start = new DateTimeImmutable($dayStartYmd);
        if ((int) $start->format('j') === 1) {
            return $start->modify("+{$termMonths} months")->format('Y-m-d');
        }
        $firstAnchor = $start->modify('first day of next month');
        return $firstAnchor->modify("+{$termMonths} months")->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * monthly + day_start 非1号：起租当月不计入合同 N 个月；exclusive = 次月起首应付日 + N 月（与 process_accounting_inbox_api 一致）。
 */
function billingContractExclusiveEndYmdMonthlyAfterPartialFirst(string $dayStartYmd, int $termMonths): ?string
{
    if ($termMonths < 1) {
        return null;
    }
    try {
        $start = new DateTimeImmutable($dayStartYmd);
        if ((int) $start->format('j') === 1) {
            return billingContractExclusiveEndYmd($dayStartYmd, $termMonths);
        }
        $firstContractDue = billingMonthlyFirstContractDueAfterPartialFirst($dayStartYmd);
        if ($firstContractDue === null) {
            return null;
        }

        return (new DateTimeImmutable($firstContractDue))->modify("+{$termMonths} months")->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** 与 process_accounting_inbox_api::inboxAnchorMonthCapAfterPartialFirst 一致 */
function txnAnchorMonthCapAfterPartialFirst(?string $contract, int $startDayOfMonth): ?int
{
    if ($startDayOfMonth === 1) {
        return null;
    }
    $term = getBillingTermMonthsFromContract($contract);
    if ($term === null || $term < 1) {
        return null;
    }
    return max(0, $term);
}

function contractExclusiveEndYmdForFrequency(string $startYmd, ?string $contract, string $frequency): ?string
{
    $term = getBillingTermMonthsFromContract($contract);
    if ($term === null || $term < 1) {
        return null;
    }
    if ($frequency === 'monthly') {
        return billingContractExclusiveEndYmdMonthlyAfterPartialFirst($startYmd, $term);
    }
    return billingContractExclusiveEndYmdFirstOfMonth($startYmd, $term);
}

function prorateInclusiveDateRange(string $fromYmd, string $toYmd, string $cost, string $price, string $profit): array
{
    if ($fromYmd > $toYmd) {
        return ['cost' => '0.00000000', 'price' => '0.00000000', 'profit' => '0.00000000'];
    }
    try {
        $cur = new DateTimeImmutable($fromYmd);
        $end = new DateTimeImmutable($toYmd);
    } catch (Throwable $e) {
        return ['cost' => '0.00000000', 'price' => '0.00000000', 'profit' => '0.00000000'];
    }
    $tc = '0.00000000';
    $tp = '0.00000000';
    $tf = '0.00000000';
    while ($cur <= $end) {
        $dim = (int) $cur->format('t');
        $monthEnd = $cur->modify('last day of this month');
        $chunkEnd = $monthEnd <= $end ? $monthEnd : $end;
        $d0 = (int) $cur->format('j');
        $d1 = (int) $chunkEnd->format('j');
        $chunkDays = $d1 - $d0 + 1;
        if ($dim > 0 && $chunkDays > 0) {
            $ratio = money_div((string) $chunkDays, (string) $dim, MONEY_CALC_SCALE);
            $tc = money_add($tc, money_mul($cost, $ratio, MONEY_CALC_SCALE), MONEY_CALC_SCALE);
            $tp = money_add($tp, money_mul($price, $ratio, MONEY_CALC_SCALE), MONEY_CALC_SCALE);
            $tf = money_add($tf, money_mul($profit, $ratio, MONEY_CALC_SCALE), MONEY_CALC_SCALE);
        }
        $cur = $chunkEnd->modify('+1 day');
    }
    return [
        'cost' => txnTrunc2($tc),
        'price' => txnTrunc2($tp),
        'profit' => txnTrunc2($tf),
    ];
}

/** 与 process_accounting_inbox_api::inboxTryDayEndMonthlyCapAmounts1stOfMonth 一致（入账端） */
function txnTryDayEndMonthlyCapAmounts1stOfMonth(array $p, bool $hasCol, string $frequency, int $billYear, int $billMonth): ?array
{
    if (!$hasCol || $frequency !== '1st_of_every_month') {
        return null;
    }
    if (function_exists('bmp_shouldSkipDayEndMonthlyCapForResendCrossMonthRange') && bmp_shouldSkipDayEndMonthlyCapForResendCrossMonthRange($p)) {
        return null;
    }
    $raw = $p['day_end_monthly_cap_enabled'] ?? null;
    $on = in_array((string) $raw, ['1', 'true', 'TRUE'], true) || $raw === 1 || $raw === true;
    if (!$on) {
        return null;
    }
    $dayEndYmd = bankProcessDateFieldToYmd($p['day_end'] ?? null);
    if ($dayEndYmd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayEndYmd)) {
        return null;
    }
    $tsM = mktime(0, 0, 0, $billMonth, 1, $billYear);
    if ($tsM === false) {
        return null;
    }
    $monthFirst = sprintf('%04d-%02d-01', $billYear, $billMonth);
    $monthLast = date('Y-m-t', $tsM);
    if ($dayEndYmd < $monthFirst || $dayEndYmd > $monthLast) {
        return null;
    }
    $bc = money_normalize($p['cost'] ?? '0');
    $bpAmt = money_normalize($p['price'] ?? '0');
    $bf = money_normalize($p['profit'] ?? '0');

    return prorateInclusiveDateRange($monthFirst, $dayEndYmd, $bc, $bpAmt, $bf);
}

/** 某月第 N 日（不超过该月最后一天） */
function calendarMonthDueYmd(int $year, int $month, int $dueDay): string
{
    $last = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    $d = min(max(1, $dueDay), $last);
    return sprintf('%04d-%02d-%02d', $year, $month, $d);
}

/**
 * Accounting Due 的 monthly 行：账单所属自然月的应付日（与 inbox 规则一致），用于 process_accounting_posted.posted_date；
 * Payment History 的 transaction_date 另用 day_start 锚定（见主循环 monthly 分支）。
 */
function monthlyDueYmdForBillingMonth(string $billingMonthYn, string $dayStartYmd, string $frequency): ?string
{
    return bmp_monthlyDueYmdFromBillingAnchor($billingMonthYn, $dayStartYmd, $frequency);
}

/** 与 process_accounting_inbox_api 一致：某自然月是否已有 monthly / monthly_skipped */
function hasMonthlyPostedOrSkippedInCalendarMonthForTxn(PDO $pdo, int $companyId, int $processId, int $year, int $month): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM process_accounting_posted WHERE company_id = ? AND process_id = ? AND YEAR(posted_date) = ? AND MONTH(posted_date) = ? AND (period_type IN ('monthly','monthly_skipped') OR period_type IS NULL OR period_type = '') LIMIT 1");
        $stmt->execute([$companyId, $processId, $year, $month]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/** Monthly 先付：按应付日判断是否已入账/跳过。 */
function txnHasMonthlyPeriodPosted(
    PDO $pdo,
    int $companyId,
    int $processId,
    string $frequency,
    int $year,
    int $month,
    string $dueYmd
): bool {
    if ($frequency === 'monthly') {
        return bmp_hasMonthlyPostedOrSkippedForDueYmd($pdo, $companyId, $processId, $dueYmd);
    }
    return hasMonthlyPostedOrSkippedInCalendarMonthForTxn($pdo, $companyId, $processId, $year, $month);
}

/** 与 process_accounting_inbox_api 的 isWithinRecurringBillingWindow 一致 */
function isWithinRecurringBillingWindowForTxn(string $todayYmd, ?string $dayStartYmd, ?string $contract, ?string $dayEndYmd, ?string $frequency = null, bool $bypassPreStartGate = false, bool $ignoreContractEndForResendSingle = false): bool
{
    if ($dayStartYmd === null || $dayStartYmd === '' || strtotime($dayStartYmd) === false) {
        return true;
    }
    $start = date('Y-m-d', strtotime($dayStartYmd));
    if (!$bypassPreStartGate && $todayYmd < $start) {
        return false;
    }
    if ($ignoreContractEndForResendSingle) {
        return true;
    }

    $freq = ($frequency === 'monthly') ? 'monthly' : '1st_of_every_month';
    $exclusiveFirstDayAfter = contractExclusiveEndYmdForFrequency($start, $contract, $freq);

    $contractLastInclusive = null;
    if ($exclusiveFirstDayAfter !== null) {
        try {
            $contractLastInclusive = (new DateTimeImmutable($exclusiveFirstDayAfter))->modify('-1 day')->format('Y-m-d');
        } catch (Throwable $e) {
            $contractLastInclusive = null;
        }
    }

    $dayEndInc = null;
    if ($dayEndYmd !== null && $dayEndYmd !== '' && strtotime($dayEndYmd) !== false) {
        $dayEndInc = date('Y-m-d', strtotime($dayEndYmd));
    }

    if ($contractLastInclusive === null && $dayEndInc === null) {
        return true;
    }
    if ($contractLastInclusive !== null && $dayEndInc === null) {
        return $todayYmd <= $contractLastInclusive;
    }
    if ($contractLastInclusive === null) {
        return $todayYmd <= $dayEndInc;
    }
    if ($dayEndInc > $contractLastInclusive) {
        return $todayYmd <= $dayEndInc;
    }
    return $todayYmd <= min($contractLastInclusive, $dayEndInc);
}

/**
 * 未传 billing_month 时，按 Accounting Inbox 的 regular monthly 规则推断第一个未结清账单所属自然月（Y-n），
 * 使入账时的 billing_month 与 posted_date（应付日）一致；transaction_date 在 post API 中对 monthly 固定为 day_start。
 */
function inferOpenMonthlyBillingMonthYn(PDO $pdo, int $companyId, array $r, string $today): ?string
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return null;
        }
    } catch (Throwable $e) {
        return null;
    }

    $frequency = $r['day_start_frequency'] ?? '1st_of_every_month';
    $dayStart = $r['day_start'] ?? null;
    $startDate = !empty($dayStart) ? bankProcessDateFieldToYmd($dayStart) : null;
    if ($startDate === null) {
        return null;
    }
    $startTs = strtotime($startDate);
    if ($startTs === false) {
        return null;
    }
    $contract = $r['contract'] ?? null;
    $dayEnd = $r['day_end'] ?? null;
    $processId = (int) ($r['id'] ?? 0);
    if ($processId <= 0) {
        return null;
    }
    $createdYmd = ymdFromNullableDateTime($r['dts_created'] ?? null, $today);
    $createdYmd = bmp_inboxEffectiveCreatedYmd($createdYmd, $startDate, !empty($r['accounting_resend_relax_created_floor']));
    $resendSinglePeriod = !empty($r['accounting_resend_single_period_from_schedule']);

    if ($frequency === '1st_of_every_month') {
        $resendRelax = !empty($r['accounting_resend_relax_created_floor']);
        $todayYm = (new DateTimeImmutable($today))->format('Y-n');
        $createdYmOnly = (new DateTimeImmutable($createdYmd))->format('Y-n');
        // 规则：
        // 1) 非 resend：旧月份不补（仅保留创建当月及之后）；
        // 2) day_start 在 1 号时，首月按 day_start(1号) 锚定，不按创建日截断；
        // 3) day_start 非 1 号时，monthly 从次月起按整月（1号）判断，不受创建日当月日影响。
        try {
            $startDayOfMonth = (int) date('j', $startTs);
            $startYm = (new DateTimeImmutable($startDate))->format('Y-n');
            $todayYm = (new DateTimeImmutable($today))->format('Y-n');
            $billYear = (int) date('Y', $startTs);
            $billMonth = (int) date('n', $startTs);
            // 与 process_accounting_inbox_api：Resend 单期 + day_start=1 号时须能推断锚点自然月，不依赖「今天与 day_start 同月」。
            if ($startDayOfMonth === 1
                && ($todayYm === $startYm || $resendSinglePeriod)
                && $today >= $startDate
                && !hasMonthlyPostedOrSkippedInCalendarMonthForTxn($pdo, $companyId, $processId, $billYear, $billMonth)
                && isWithinRecurringBillingWindowForTxn($today, $dayStart, $contract, $dayEnd, '1st_of_every_month', $resendRelax, $resendSinglePeriod)) {
                return $startYm;
            }
        } catch (Throwable $e) {
            // continue
        }
        $firstAccountingTs = strtotime('first day of next month', $startTs);
        $firstAccountingDate = $firstAccountingTs !== false ? date('Y-m-d', $firstAccountingTs) : '';
        if ($firstAccountingDate === '' || (!$resendRelax && $today < $firstAccountingDate)) {
            return null;
        }
        if (!isWithinRecurringBillingWindowForTxn($today, $dayStart, $contract, $dayEnd, '1st_of_every_month', $resendRelax, $resendSinglePeriod)) {
            return null;
        }
        try {
            $iter = new DateTimeImmutable($firstAccountingDate);
            $iter = $iter->modify('first day of this month');
            $endCap = (new DateTimeImmutable($today))->modify('first day of this month');
            if ($resendRelax && $iter > $endCap) {
                $endCap = $iter;
            }
            $term = getBillingTermMonthsFromContract($contract);
            $exclusiveEnd = ($term !== null && $term >= 1) ? billingContractExclusiveEndYmdFirstOfMonth($startDate, $term) : null;
            $anchorMonthCap = txnAnchorMonthCapAfterPartialFirst($contract, (int) date('j', $startTs));
            $anchorSlotIndex = 0;
            $onlyAnchorYmFirstOfMonth = null;
            if ($resendSinglePeriod && $startDate !== '') {
                try {
                    $onlyAnchorYmFirstOfMonth = (new DateTimeImmutable($startDate))->format('Y-n');
                } catch (Throwable $e) {
                    $onlyAnchorYmFirstOfMonth = null;
                }
            }
            while ($iter <= $endCap) {
                if ($anchorMonthCap !== null && $anchorSlotIndex >= $anchorMonthCap) {
                    break;
                }
                $y = (int) $iter->format('Y');
                $mo = (int) $iter->format('n');
                $firstOfThis = $iter->format('Y-m-d');
                if ($exclusiveEnd !== null && $firstOfThis >= $exclusiveEnd) {
                    break;
                }
                $billYm = $iter->format('Y-n');
                if ($onlyAnchorYmFirstOfMonth !== null && $billYm !== $onlyAnchorYmFirstOfMonth) {
                    $anchorSlotIndex++;
                    $iter = $iter->modify('+1 month');
                    continue;
                }
                // 非 resend：旧数据不拿，仅允许当前自然月进入候选（例如 today=4月，只可出4月）。
                if (!$resendRelax && $billYm !== $todayYm) {
                    $anchorSlotIndex++;
                    $iter = $iter->modify('+1 month');
                    continue;
                }
                // 非 resend：旧月（创建月之前）直接跳过，不补历史账。
                if (!$resendRelax) {
                    $billYmInt = $y * 100 + $mo;
                    $createdYmInt = ((int) date('Y', strtotime($createdYmd))) * 100 + ((int) date('n', strtotime($createdYmd)));
                    if ($billYmInt < $createdYmInt) {
                        $anchorSlotIndex++;
                        $iter = $iter->modify('+1 month');
                        continue;
                    }
                }
                // 1st_of_every_month 的 regular monthly（day_start 非 1 号）按整月判断；
                // 仅 day_start=1 且首月=创建月时，首笔可按创建日截断。
                $effectiveDue = $firstOfThis;
                if (!$resendRelax && $startDayOfMonth === 1 && $billYm === $startYm && $createdYmOnly === $startYm) {
                    $effectiveDue = maxYmd($firstOfThis, $createdYmd);
                }
                if (($today >= $effectiveDue || $resendRelax)
                    && !hasMonthlyPostedOrSkippedInCalendarMonthForTxn($pdo, $companyId, $processId, $y, $mo)) {
                    return $iter->format('Y-n');
                }
                $anchorSlotIndex++;
                $iter = $iter->modify('+1 month');
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    $resendRelaxMonthly = !empty($r['accounting_resend_relax_created_floor']);
    if (!isWithinRecurringBillingWindowForTxn($today, $dayStart, $contract, $dayEnd, 'monthly', $resendRelaxMonthly, $resendSinglePeriod)) {
        return null;
    }
    $onlyAnchorYmMonthly = null;
    if ($resendSinglePeriod) {
        try {
            $onlyAnchorYmMonthly = (new DateTimeImmutable($startDate))->format('Y-n');
        } catch (Throwable $e) {
            $onlyAnchorYmMonthly = null;
        }
    }
    if ($startDate !== '' && ($resendRelaxMonthly || $today >= $createdYmd)) {
        $term = getBillingTermMonthsFromContract($contract);
        $exclusiveEnd = ($term !== null && $term >= 1) ? billingContractExclusiveEndYmdMonthlyAfterPartialFirst($startDate, $term) : null;
        $anchors = billingCollectMonthlyChainedDueAnchors(
            $startDate,
            $today,
            $createdYmd,
            $exclusiveEnd,
            $resendRelaxMonthly,
            $resendSinglePeriod,
            $onlyAnchorYmMonthly,
            static function (string $due, int $y, int $mo, string $dueYm) use ($pdo, $companyId, $processId): bool {
                return !txnHasMonthlyPeriodPosted($pdo, $companyId, $processId, 'monthly', $y, $mo, $due);
            }
        );
        if (!empty($anchors)) {
            return $anchors[0];
        }
    }
    return null;
}

/** 根据 id 列表获取 Bank Process（含 company/owner），支持 active、inactive，以及 OFFICIAL / E-INVOICE 这类 inactive-like 记录（Accounting Due 中 manual_inactive 可入账） */
function fetchBankProcessesByIds(PDO $pdo, array $ids, int $companyId): array
{
    if (empty($ids)) {
        return [];
    }
    bmp_ensureBankProcessAccountingResendScheduleColumns($pdo);
    bmp_ensureBankProcessAccountingResendOpenAnchorsColumn($pdo);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $hasFrequency = tableHasColumn($pdo, 'bank_process', 'day_start_frequency');
    $hasIssueFlagColumn = tableHasColumn($pdo, 'bank_process', 'issue_flag');
    $hasFlagColumn = tableHasColumn($pdo, 'bank_process', 'flag');
    $hasResendRelax = tableHasColumn($pdo, 'bank_process', 'accounting_resend_relax_created_floor');
    $hasDayEndTailCol = tableHasColumn($pdo, 'bank_process', 'day_end_monthly_cap_enabled');
    $hasSchedCols = bmp_bankProcessHasResendScheduleColumns($pdo);
    $hasOpenAnchorsCol = bmp_resend_tableHasColumn($pdo, 'bank_process', 'accounting_resend_open_anchors');
    $issueFlagSql = getBankProcessIssueFlagSql('bp', $hasIssueFlagColumn, $hasFlagColumn);
    $sql = "SELECT bp.id, bp.name, bp.bank, bp.country, bp.cost, bp.price, bp.profit, bp.day_start, bp.day_end, bp.contract, bp.status,
            bp.dts_created" . ($hasFrequency ? ", bp.day_start_frequency" : "") .
        ($hasResendRelax ? ", bp.accounting_resend_relax_created_floor" : "") .
        ($hasDayEndTailCol ? ", bp.day_end_monthly_cap_enabled" : "") .
        ($hasSchedCols ? ", bp.accounting_resend_schedule_day_start, bp.accounting_resend_schedule_day_end, bp.accounting_resend_schedule_frequency" : "") .
        ($hasOpenAnchorsCol ? ", bp.accounting_resend_open_anchors" : "") . ",
            bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.company_id, bp.profit_sharing, c.owner_id
            FROM bank_process bp
            LEFT JOIN company c ON bp.company_id = c.id
            WHERE bp.id IN ($placeholders) AND bp.company_id = ? AND (" .
        (($hasIssueFlagColumn || $hasFlagColumn)
            ? "bp.status IN ('active','inactive') OR " . normalizedBankIssueFlagSql($issueFlagSql) . " IN ('official','e_invoice')"
            : "bp.status IN ('active','inactive')") .
        ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ids, [$companyId]));
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $merged = bmp_mergeResendScheduleIntoBankProcessRowForAccounting($row);
        $byId[(int) $merged['id']] = $merged;
    }
    return $byId;
}

/** 1+1/1+2/1+3 的「额外月数」：1+1→1，1+2→2，1+3→3，其他 0（用于 manual_inactive 入账后给 day_end 加月） */
function getExtraMonthsFromContract(?string $contract): int
{
    if ($contract === null || $contract === '') {
        return 0;
    }
    $c = trim($contract);
    if ($c === '1+1') {
        return 1;
    }
    if ($c === '1+2') {
        return 2;
    }
    if ($c === '1+3') {
        return 3;
    }
    return 0;
}

/** 赔款月份文案：1+1 => One Month, 1+2 => Two Month, 1+3 => Three Month */
function getCompensationMonthLabelFromContract(?string $contract): string
{
    $extra = getExtraMonthsFromContract($contract);
    if ($extra === 1) {
        return 'One Month';
    }
    if ($extra === 2) {
        return 'Two Month';
    }
    if ($extra === 3) {
        return 'Three Month';
    }
    return 'One Month';
}

/** 日期加 N 个月，返回 Y-m-d */
function addMonthsToDate(?string $dateStr, int $months): ?string
{
    if ($dateStr === null || $dateStr === '' || $months <= 0) {
        return $dateStr;
    }
    try {
        $dt = new DateTime($dateStr);
        $dt->modify("+{$months} month");
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return $dateStr;
    }
}

/** 根据 contract 与当前 day_start 计算下次 day_start（用于 manual_inactive 入账后恢复 active 并更新日期） */
function nextDayStartFromContract(?string $dayStart, ?string $contract): string
{
    $base = $dayStart && strtotime($dayStart) !== false ? $dayStart : date('Y-m-d');
    $ts = strtotime($base);
    if ($ts === false) {
        return date('Y-m-d');
    }
    $months = 1;
    if ($contract !== null && $contract !== '') {
        if (preg_match('/^(\d+)\s*MONTHS?$/i', trim($contract), $m)) {
            $months = (int) $m[1];
        } elseif (preg_match('/^1\+(\d+)$/i', trim($contract), $m)) {
            $months = 1 + (int) $m[1];
        }
    }
    $next = strtotime("+{$months} month", $ts);
    return $next !== false ? date('Y-m-d', $next) : date('Y-m-d');
}

/** 获取或创建 currency 的 id（按 code + company_id） */
function getOrCreateCurrencyId(PDO $pdo, string $code, int $companyId): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
    $stmt->execute([$code, $companyId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
    $stmt->execute([$code, $companyId]);
    return (int) $pdo->lastInsertId();
}

/** 记录 process 已入账到 process_accounting_posted */
function recordProcessAccountingPosted(PDO $pdo, int $companyId, int $processId, string $date, string $periodType, bool $hasPeriodType): void
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return;
        }
        if ($hasPeriodType) {
            $ins = $pdo->prepare("INSERT IGNORE INTO process_accounting_posted (company_id, process_id, posted_date, period_type) VALUES (?, ?, ?, ?)");
            $ins->execute([$companyId, $processId, $date, $periodType]);
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO process_accounting_posted (company_id, process_id, posted_date) VALUES (?, ?, ?)");
            $ins->execute([$companyId, $processId, $date]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** Day consolidated：为区间内每个自然日写入 daily 入账标记。 */
function recordDailyRangeAccountingPosted(
    PDO $pdo,
    int $companyId,
    int $processId,
    string $startYmd,
    string $endYmd,
    bool $hasPeriodType
): void {
    $d = $startYmd;
    while ($d !== '' && $d <= $endYmd) {
        recordProcessAccountingPosted($pdo, $companyId, $processId, $d, 'daily', $hasPeriodType);
        $next = dailyNextDayYmd($d);
        if ($next === null) {
            break;
        }
        $d = $next;
    }
}

/**
 * Resend 合并区间入账后：为 [fromYmd, toYmd] 覆盖的每个自然月写 monthly_skipped，
 * 避免列表页「Transaction」再按 inferOpenMonthly 推断出 5/1 等整月重复入账。
 */
function txnRecordMonthlySkippedCoveringConsolidatedRange(
    PDO $pdo,
    int $companyId,
    int $processId,
    string $fromYmd,
    string $toYmd,
    bool $hasPeriodType
): void {
    if (!$hasPeriodType) {
        return;
    }
    try {
        $cur = (new DateTimeImmutable($fromYmd))->modify('first day of this month');
        $endM = (new DateTimeImmutable($toYmd))->modify('first day of this month');
    } catch (Throwable $e) {
        return;
    }
    while ($cur <= $endM) {
        recordProcessAccountingPosted($pdo, $companyId, $processId, $cur->format('Y-m-01'), 'monthly_skipped', $hasPeriodType);
        $cur = $cur->modify('+1 month');
    }
}

/** 与 Inbox hasWeeklyPostedForPeriodStart：该周周期起点是否已入账或已跳过。 */
function txnIsWeeklyPostedOrSkippedForPeriodStart(PDO $pdo, int $companyId, int $processId, string $periodStartYmd): bool
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return false;
        }
        if (!tableHasColumn($pdo, 'process_accounting_posted', 'period_type')) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT 1 FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ? AND DATE(posted_date) = DATE(?)
               AND period_type IN ('weekly','weekly_skipped')
             LIMIT 1"
        );
        $stmt->execute([$companyId, $processId, $periodStartYmd]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Weekly Resend 单期入账后：对「库里真实 day_start → Resend 锚点之前」的标准周写 weekly_skipped，
 * 避免清除 relax 后 Inbox 从原始锚点再排出历史 backlog。
 */
function txnRecordWeeklySkippedBeforeResendAnchor(
    PDO $pdo,
    int $companyId,
    int $processId,
    string $storedDayStartYmd,
    string $resendAnchorYmd,
    bool $hasPeriodType
): void {
    if (!$hasPeriodType) {
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedDayStartYmd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $resendAnchorYmd)) {
        return;
    }
    if ($storedDayStartYmd >= $resendAnchorYmd) {
        return;
    }
    $due = $storedDayStartYmd;
    $guard = 0;
    while ($due !== '' && $due < $resendAnchorYmd && $guard < 520) {
        if (!txnIsWeeklyPostedOrSkippedForPeriodStart($pdo, $companyId, $processId, $due)) {
            recordProcessAccountingPosted($pdo, $companyId, $processId, $due, 'weekly_skipped', $hasPeriodType);
        }
        $next = weekPeriodNextStartYmd($due);
        if ($next === null || $next === $due) {
            break;
        }
        $due = $next;
        $guard++;
    }
}

/** 与 Inbox hasDailyPostedOrSkippedForDay：该自然日是否已入账或已跳过。 */
function txnIsDailyPostedOrSkippedForDay(PDO $pdo, int $companyId, int $processId, string $dayYmd): bool
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return false;
        }
        if (!tableHasColumn($pdo, 'process_accounting_posted', 'period_type')) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT 1 FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ? AND DATE(posted_date) = DATE(?)
               AND period_type IN ('daily','daily_skipped')
             LIMIT 1"
        );
        $stmt->execute([$companyId, $processId, $dayYmd]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Daily Resend 单期入账后：对「库里真实 day_start → Resend 锚点之前」的自然日写 daily_skipped，
 * 避免清除 relax 后 Inbox 从原始锚点再排出历史 backlog。
 */
function txnRecordDailySkippedBeforeResendAnchor(
    PDO $pdo,
    int $companyId,
    int $processId,
    string $storedDayStartYmd,
    string $resendAnchorYmd,
    bool $hasPeriodType
): void {
    if (!$hasPeriodType) {
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedDayStartYmd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $resendAnchorYmd)) {
        return;
    }
    if ($storedDayStartYmd >= $resendAnchorYmd) {
        return;
    }
    $d = $storedDayStartYmd;
    $guard = 0;
    while ($d !== '' && $d < $resendAnchorYmd && $guard < 4000) {
        if (!txnIsDailyPostedOrSkippedForDay($pdo, $companyId, $processId, $d)) {
            recordProcessAccountingPosted($pdo, $companyId, $processId, $d, 'daily_skipped', $hasPeriodType);
        }
        $next = dailyNextDayYmd($d);
        if ($next === null || $next === $d) {
            break;
        }
        $d = $next;
        $guard++;
    }
}

/** 与 Inbox：首月 partial 是否已入账或已 dismiss（任一则不再排队 partial） */
function txnIsPartialFirstMonthPostedOrSkipped(PDO $pdo, int $companyId, int $processId): bool
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return false;
        }
        if (!tableHasColumn($pdo, 'process_accounting_posted', 'period_type')) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT 1 FROM process_accounting_posted WHERE company_id = ? AND process_id = ?
             AND period_type IN ('partial_first_month','partial_first_month_skipped') LIMIT 1"
        );
        $stmt->execute([$companyId, $processId]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

/** 解析 profit_sharing 字符串 "RUP3 - 55, RUP4 - 10" 为 [['account_text'=>'RUP3','amount'=>55], ...] */
function parseProfitSharingString(string $profitSharing): array
{
    $result = [];
    $s = trim($profitSharing);
    if ($s === '') {
        return $result;
    }
    foreach (explode(',', $s) as $part) {
        $t = trim($part);
        $dash = strrpos($t, ' - ');
        if ($dash !== false) {
            $accountText = trim(substr($t, 0, $dash));
            $amountStr = trim(substr($t, $dash + 3));
            if ($accountText !== '' && money_is_valid($amountStr) && money_cmp($amountStr, '0') > 0) {
                $result[] = ['account_text' => $accountText, 'amount' => txnTrunc2($amountStr)];
            }
        }
    }
    return $result;
}

/** 按公司内 account_id 或 name 解析账户，返回 account.id，找不到返回 null */
function resolveAccountIdByText(PDO $pdo, int $companyId, string $accountText): ?int
{
    $text = trim($accountText);
    if ($text === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT a.id FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id AND ac.company_id = ?
            WHERE (LOWER(TRIM(a.account_id)) = LOWER(?) OR LOWER(TRIM(a.name)) = LOWER(?)) LIMIT 1");
    $stmt->execute([$companyId, $text, $text]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        jsonResponse(false, '请先登录', null);
        exit;
    }

    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $ids = array_filter($ids);
    $periodTypes = isset($_POST['period_types']) && is_array($_POST['period_types']) ? $_POST['period_types'] : [];
    if (empty($ids)) {
        http_response_code(400);
        jsonResponse(false, '请至少选择一个 Process', null);
        exit;
    }

    $billingMonths = isset($_POST['billing_months']) && is_array($_POST['billing_months']) ? $_POST['billing_months'] : [];
    $allowFutureMonthly = !empty($_POST['allow_future_monthly']);
    $pairs = [];
    foreach ($ids as $i => $id) {
        $pt = isset($periodTypes[$i]) ? trim($periodTypes[$i]) : 'monthly';
        if ($pt !== 'partial_first_month' && $pt !== 'manual_inactive' && $pt !== 'day_end_tail'
            && $pt !== 'resend_consolidated_range' && $pt !== 'resend_monthly_reopen' && $pt !== 'once_one_off' && $pt !== 'weekly'
            && $pt !== 'daily' && $pt !== 'daily_consolidated') {
            $pt = 'monthly';
        }
        $pairs[] = [
            'id' => (int) $id,
            'period_type' => $pt,
            'billing_month' => isset($billingMonths[$i]) ? trim((string) $billingMonths[$i]) : '',
        ];
    }
    // Accounting Due 每行只入账一次：monthly 按 billing_month 区分多期；其它 period_type 仍按 process_id + period_type 去重
    $seen = [];
    $pairs = array_values(array_filter($pairs, function ($p) use (&$seen) {
        $pt = $p['period_type'] ?? '';
        $bm = trim((string) ($p['billing_month'] ?? ''));
        $key = $p['id'] . '_' . $pt . '_' . ((($pt === 'monthly' || $pt === 'resend_monthly_reopen' || $pt === 'weekly' || $pt === 'daily' || $pt === 'daily_consolidated') && $bm !== '') ? $bm : '');
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;
        return true;
    }));

    usort($pairs, static function ($a, $b) {
        if ((int) $a['id'] !== (int) $b['id']) {
            return (int) $a['id'] <=> (int) $b['id'];
        }
        $ba = trim((string) ($a['billing_month'] ?? ''));
        $bb = trim((string) ($b['billing_month'] ?? ''));
        if ($ba === '' && $bb === '') {
            return 0;
        }
        if (!preg_match('/^(\d{4})-(\d{1,2})$/', $ba, $ma)) {
            return $ba <=> $bb;
        }
        if (!preg_match('/^(\d{4})-(\d{1,2})$/', $bb, $mb)) {
            return $ba <=> $bb;
        }
        $ta = (int) $ma[1] * 100 + (int) $ma[2];
        $tb = (int) $mb[1] * 100 + (int) $mb[2];
        return $ta <=> $tb;
    });

    $company_id = (int) ($_SESSION['company_id'] ?? 0);
    if (!$company_id) {
        http_response_code(400);
        jsonResponse(false, '缺少公司信息', null);
        exit;
    }
    // 自动移除“金额必须大于 0”的旧触发器限制（允许 0.00）。
    ensureTransactionsAllowZeroAmount($pdo);
    $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
    $owner_id = $isOwner ? ($_SESSION['owner_id'] ?? $_SESSION['user_id']) : null;
    $created_by_user = $isOwner ? null : $_SESSION['user_id'];
    $postUserRole = isset($_SESSION['role']) ? strtolower((string) $_SESSION['role']) : '';

    $uniqueIds = array_values(array_unique(array_column($pairs, 'id')));
    $processesById = fetchBankProcessesByIds($pdo, $uniqueIds, $company_id);
    if (empty($processesById)) {
        http_response_code(400);
        jsonResponse(false, '未找到可入账的 Process（仅处理当前公司下 active 或 Accounting Due 中的 Process）', null);
        exit;
    }

    $has_currency_id = tableHasColumn($pdo, 'transactions', 'currency_id');
    $has_approval_status = tableHasColumn($pdo, 'transactions', 'approval_status');
    $has_source_bank_process_id = tableHasColumn($pdo, 'transactions', 'source_bank_process_id');
    $has_source_bank_process_period_type = tableHasColumn($pdo, 'transactions', 'source_bank_process_period_type');
    $has_period_type = tableHasColumn($pdo, 'process_accounting_posted', 'period_type');
    $has_resend_relax_col = tableHasColumn($pdo, 'bank_process', 'accounting_resend_relax_created_floor');
    $has_day_end_tail_switch_col = tableHasColumn($pdo, 'bank_process', 'day_end_monthly_cap_enabled');
    $fallbackDate = date('Y-m-d');
    $createdCount = 0;
    $skippedFutureMonthlyDueCount = 0;
    $currencyCache = [];

    foreach ($pairs as $pair) {
        $p = $processesById[$pair['id']] ?? null;
        if (!$p) {
            continue;
        }
        $skipCurrentPair = false;
        $pairPostedTxn = false;
        $monthlyProrationPsRatio = null;
        $dayEndTailAnchorYmd = null;
        $origPeriodType = trim((string) ($pair['period_type'] ?? 'monthly'));
        $periodType = $origPeriodType;
        if ($periodType === 'resend_monthly_reopen') {
            $periodType = 'monthly';
        }
        $cost = money_normalize($p['cost'] ?? '0');
        $price = money_normalize($p['price'] ?? '0');
        $profit = money_normalize($p['profit'] ?? '0');
        $lastProrationRatio = null;

        $dayStartYmd = !empty($p['day_start']) ? bankProcessDateFieldToYmd($p['day_start']) : null;
        $frequency = $p['day_start_frequency'] ?? '1st_of_every_month';

        // monthly：若前端未传 billing_month（例如列表页批量 Transaction），按 Inbox 规则推断账单自然月，保证 proration 与 transaction_date 一致
        $resolvedMonthlyBm = '';
        if ($periodType === 'monthly') {
            $resolvedMonthlyBm = trim((string) ($pair['billing_month'] ?? ''));
            if ($resolvedMonthlyBm === '' && $dayStartYmd) {
                $cidForInfer = (int) ($p['company_id'] ?? $company_id);
                $inf = inferOpenMonthlyBillingMonthYn($pdo, $cidForInfer, $p, $fallbackDate);
                if ($inf !== null && $inf !== '') {
                    $resolvedMonthlyBm = $inf;
                }
            }
            // Resend relax 期间：Y-n 锚点为正常流程行，应付日/proration 用库里真实 day_start。
            if ($resolvedMonthlyBm !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedMonthlyBm)) {
                $storedRaw = $p['bank_process_stored_day_start'] ?? null;
                if ($storedRaw !== null && trim((string) $storedRaw) !== ''
                    && !empty($p['accounting_resend_relax_created_floor'])) {
                    $storedYmd = bankProcessDateFieldToYmd((string) $storedRaw);
                    if ($storedYmd !== null) {
                        $dayStartYmd = $storedYmd;
                    }
                }
            }
        }

        $relaxCreatedFloor = $has_resend_relax_col && !empty($p['accounting_resend_relax_created_floor']);
        if ($periodType === 'monthly' && $resolvedMonthlyBm !== ''
            && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedMonthlyBm)
            && !empty($p['bank_process_stored_day_start'])) {
            $relaxCreatedFloor = false;
        }
        $createdYmd = bmp_inboxEffectiveCreatedYmd(
            ymdFromNullableDateTime($p['dts_created'] ?? null, $fallbackDate),
            $dayStartYmd,
            $relaxCreatedFloor
        );

        if ($periodType === 'resend_consolidated_range' && $dayStartYmd) {
            $dayEndRawRc = $p['day_end'] ?? null;
            $endYmdRc = $dayEndRawRc !== null && trim((string) $dayEndRawRc) !== ''
                ? bankProcessDateFieldToYmd((string) $dayEndRawRc)
                : null;
            if ($endYmdRc === null || $endYmdRc === '' || $dayStartYmd > $endYmdRc) {
                continue;
            }
            $totRc = prorateInclusiveDateRange($dayStartYmd, $endYmdRc, $cost, $price, $profit);
            $cost = $totRc['cost'];
            $price = $totRc['price'];
            $profit = $totRc['profit'];
        } elseif ($periodType === 'partial_first_month' && $dayStartYmd) {
            $startTs = strtotime($dayStartYmd);
            if ($startTs === false) {
                continue;
            }
            // day_start is the 1st → no partial-first-month period; don't create this row.
            if ((int) date('j', $startTs) === 1) {
                continue;
            }
            $firstMonthEnd = date('Y-m-t', $startTs);
            if ($createdYmd > $firstMonthEnd) {
                continue;
            }
            $partialStart = $dayStartYmd;
            if ($partialStart > $firstMonthEnd) {
                continue;
            }
            $lastProrationRatio = ratioRemainingDaysInMonthFromStartYmd($partialStart);
            $partial = prorateToMonthEndFromStart($partialStart, $cost, $price, $profit);
            $cost = $partial['cost'];
            $price = $partial['price'];
            $profit = $partial['profit'];
        } elseif ($periodType === 'day_end_tail' && $dayStartYmd) {
            if ($has_day_end_tail_switch_col && $frequency === '1st_of_every_month') {
                $raw = $p['day_end_monthly_cap_enabled'] ?? null;
                $tailOn = in_array((string) $raw, ['1', 'true', 'TRUE'], true) || $raw === 1 || $raw === true;
                if (!$tailOn) {
                    continue;
                }
            }
            $dayEndRaw = $p['day_end'] ?? null;
            if ($dayEndRaw === null || trim((string) $dayEndRaw) === '' || strtotime((string) $dayEndRaw) === false) {
                continue;
            }
            $term = getBillingTermMonthsFromContract($p['contract'] ?? null);
            if ($term === null || $term < 1) {
                continue;
            }
            $exclusiveEnd = contractExclusiveEndYmdForFrequency($dayStartYmd, $p['contract'] ?? null, $frequency);
            $dayEndInc = date('Y-m-d', strtotime((string) $dayEndRaw));
            if ($exclusiveEnd === null) {
                continue;
            }
            $useSwitchGatedTail = ($frequency === '1st_of_every_month' && $has_day_end_tail_switch_col);
            if ($useSwitchGatedTail) {
                try {
                    $monthFirst = (new DateTimeImmutable($dayEndInc))->modify('first day of this month')->format('Y-m-d');
                } catch (Throwable $e) {
                    continue;
                }
                $tailFrom = max($exclusiveEnd, $monthFirst);
                if ($tailFrom > $dayEndInc) {
                    continue;
                }
            } else {
                if ($dayEndInc < $exclusiveEnd) {
                    continue;
                }
                $tailFrom = $exclusiveEnd;
            }
            $dayEndTailAnchorYmd = $tailFrom;
            if ($fallbackDate < maxYmd($dayStartYmd, $createdYmd)) {
                continue;
            }
            $tail = prorateInclusiveDateRange($tailFrom, $dayEndInc, $cost, $price, $profit);
            $cost = $tail['cost'];
            $price = $tail['price'];
            $profit = $tail['profit'];
        } elseif ($periodType === 'daily_consolidated') {
            $rangeDaily = dailyParseConsolidatedBillingRange(trim((string) ($pair['billing_month'] ?? '')));
            if ($rangeDaily === null) {
                continue;
            }
            $dayCountDaily = dailyInclusiveDayCount($rangeDaily['start'], $rangeDaily['end']);
            if ($dayCountDaily < 1) {
                continue;
            }
            $amountsDaily = dailyAmountsForDayCount($cost, $price, $profit, $dayCountDaily);
            $cost = $amountsDaily['cost'];
            $price = $amountsDaily['price'];
            $profit = $amountsDaily['profit'];
        }

        // Resend 单期（Y-m-d 锚点）：金额区间与 Inbox 一致——1st 为锚点～月末，Monthly 为锚点～+1月-1日。
        if ($origPeriodType === 'resend_monthly_reopen' && $periodType === 'monthly') {
            $bmResendPost = trim((string) ($pair['billing_month'] ?? ''));
            if ($bmResendPost !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bmResendPost)) {
                $resolvedMonthlyBm = $bmResendPost;
                if ($frequency === 'monthly') {
                    [$p0, $p1] = billingMonthlyAnniversaryInclusiveRangeFromDue($bmResendPost, $bmResendPost);
                    $pr = prorateMonthlyAnniversaryPeriodLinear($p0, $p1, $p0, $cost, $price, $profit);
                    $cost = $pr['cost'];
                    $price = $pr['price'];
                    $profit = $pr['profit'];
                    if ($pr['ratio'] !== null) {
                        $monthlyProrationPsRatio = $pr['ratio'];
                    }
                } elseif ($frequency === '1st_of_every_month') {
                    $lastProrationRatio = ratioRemainingDaysInMonthFromStartYmd($bmResendPost);
                    $pr = prorateToMonthEndFromStart($bmResendPost, $cost, $price, $profit);
                    $cost = $pr['cost'];
                    $price = $pr['price'];
                    $profit = $pr['profit'];
                }
            }
        }

        // monthly：与 Inbox 一致；1st_of_every_month 新建在创建日晚于当月1号时从创建日摊到月末；Resend 仍从 dueYmd（1号）起算比例。
        if ($periodType === 'monthly' && $resolvedMonthlyBm !== '' && preg_match('/^(\d{4})-(\d{1,2})$/', $resolvedMonthlyBm, $m)) {
            $billY = (int) $m[1];
            $billMo = (int) $m[2];
            $billYm = sprintf('%04d-%d', $billY, $billMo);
            try {
                $createdYm = (new DateTimeImmutable($createdYmd))->format('Y-n');
                $resendRelax = $has_resend_relax_col && !empty($p['accounting_resend_relax_created_floor']);
                // 防呆：1st_of_every_month 且 day_start 非 1 号时，首自然月只能走 partial_first_month；
                // 若客户端误把首月传成 monthly，会造成首月金额重复（例如 partial + full monthly）。
                if ($frequency === '1st_of_every_month' && $dayStartYmd) {
                    $startTsGuard = strtotime($dayStartYmd);
                    if ($startTsGuard !== false && (int) date('j', $startTsGuard) !== 1) {
                        $startYmGuard = (new DateTimeImmutable($dayStartYmd))->format('Y-n');
                        if ($billYm === $startYmGuard) {
                            $skipCurrentPair = true;
                        }
                    }
                }
                if (!$resendRelax) {
                    $billYmInt = $billY * 100 + $billMo;
                    $createdDt = new DateTimeImmutable($createdYmd);
                    $createdYmInt = ((int) $createdDt->format('Y')) * 100 + ((int) $createdDt->format('n'));
                    if ($billYmInt < $createdYmInt) {
                        $skipCurrentPair = true;
                    }
                }
                $firstMonthOnFirstHandled = false;
                if ($frequency === '1st_of_every_month' && $dayStartYmd) {
                    $startYmForBill = (new DateTimeImmutable($dayStartYmd))->format('Y-n');
                    $sdTs = strtotime($dayStartYmd);
                    if ($startYmForBill === $billYm && $sdTs !== false && (int) date('j', $sdTs) === 1) {
                        // 1st_of_every_month + 首月(day_start=1号)统一按1号起算，不按创建日截断。
                        $prorateFrom = $dayStartYmd;
                        $lastProrationRatio = ratioRemainingDaysInMonthFromStartYmd($prorateFrom);
                        $pr = prorateToMonthEndFromStart($prorateFrom, $cost, $price, $profit);
                        $cost = $pr['cost'];
                        $price = $pr['price'];
                        $profit = $pr['profit'];
                        $tPr = strtotime($prorateFrom);
                        if ($tPr !== false) {
                            $dim = (int) date('t', $tPr);
                            $dj = (int) date('j', $tPr);
                            if ($dim > 0) {
                                $monthlyProrationPsRatio = money_div((string) ($dim - $dj + 1), (string) $dim, MONEY_CALC_SCALE);
                            }
                        }
                        $firstMonthOnFirstHandled = true;
                    }
                }
                // monthly：先付整期 [应付日, 应付日+1月-1日] 入账
                // 不再因创建日晚于区间起点而按比例截断，确保整期金额固定为 process 原值。
                if ($frequency === 'monthly' && $dayStartYmd) {
                    $dueYmdM = monthlyDueYmdForBillingMonth($resolvedMonthlyBm, $dayStartYmd, 'monthly');
                    if ($dueYmdM !== null) {
                        [$p0, $p1] = billingMonthlyChainedInclusiveRangeFromDue($dueYmdM, $dayStartYmd);
                        $from = $p0;
                        if ($from <= $p1) {
                            $pr = prorateMonthlyAnniversaryPeriodLinear($p0, $p1, $from, $cost, $price, $profit);
                            $cost = $pr['cost'];
                            $price = $pr['price'];
                            $profit = $pr['profit'];
                            if ($pr['ratio'] !== null) {
                                $monthlyProrationPsRatio = $pr['ratio'];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        // 1st_of_every_month + Day end 旁开关 ON：day_end 落在该账单自然月内时按「月初～day_end」比例（与 Inbox 一致）。
        if ($periodType === 'monthly' && $frequency === '1st_of_every_month' && $has_day_end_tail_switch_col
            && $resolvedMonthlyBm !== '' && preg_match('/^(\d{4})-(\d{1,2})$/', $resolvedMonthlyBm, $mCapPost)) {
            $capPost = txnTryDayEndMonthlyCapAmounts1stOfMonth(
                $p,
                $has_day_end_tail_switch_col,
                $frequency,
                (int) $mCapPost[1],
                (int) $mCapPost[2]
            );
            if ($capPost !== null) {
                $cost = $capPost['cost'];
                $price = $capPost['price'];
                $profit = $capPost['profit'];
                $fpPs = money_normalize($p['profit'] ?? '0');
                if (money_cmp($fpPs, '0') > 0) {
                    $monthlyProrationPsRatio = money_div($profit, $fpPs, MONEY_CALC_SCALE);
                } else {
                    $monthlyProrationPsRatio = null;
                }
            }
        }

        // 1+1/1+2/1+3：active 期间统一按 1 个月价格入账；仅 manual_inactive 才按赔付月数放大。
        if ($periodType === 'manual_inactive') {
            $mult = getManualInactiveMultiplierFromContract($p['contract'] ?? null);
            $cost = money_mul($cost, (string) $mult, 2);
            $price = money_mul($price, (string) $mult, 2);
            $profit = money_mul($profit, (string) $mult, 2);
        }
        $isManualInactiveCompensation = ($periodType === 'manual_inactive' && getExtraMonthsFromContract($p['contract'] ?? null) > 0);

        $processLabel = $p['name'] ?: ($p['bank'] . ' #' . $p['id']);
        $companyId = (int) $p['company_id'];
        $ownerId = $p['owner_id'] ?? null;
        $currencyCode = trim($p['country'] ?? '');
        if ($currencyCode === '') {
            continue;
        }

        $currencyId = null;
        if ($has_currency_id) {
            $cacheKey = $companyId . '_' . $currencyCode;
            if (isset($currencyCache[$cacheKey])) {
                $currencyId = $currencyCache[$cacheKey];
            } else {
                $currencyId = getOrCreateCurrencyId($pdo, $currencyCode, $companyId);
                $currencyCache[$cacheKey] = $currencyId;
            }
        }
        if (!$currencyId && $has_currency_id) {
            continue;
        }

        // transaction_date：写入「经济归属日」供 Transaction List / Payment History 按 capture 日期筛选；不用 max(day_start,创建日)，否则晚提交会落在 submit 日导致按 day_start 查不到。
        // posted_date：仍单独用应付日（与 Inbox 去重一致）。
        // manual_inactive 的 process_accounting_posted.posted_date 仍用「今天」，否则 posted_date < dts_modified 时
        // fetchInactiveBankProcessesPendingTransaction 的 NOT EXISTS 无法识别本轮已入账（见 process_accounting_inbox_api）。
        $transactionDate = $fallbackDate;
        $postedDateForInbox = $fallbackDate;

        if ($periodType === 'partial_first_month' && $dayStartYmd) {
            $transactionDate = $dayStartYmd;
            $postedDateForInbox = $dayStartYmd;
        } elseif ($periodType === 'resend_consolidated_range' && $dayStartYmd) {
            $transactionDate = $dayStartYmd;
            $postedDateForInbox = $dayStartYmd;
        } elseif ($periodType === 'manual_inactive') {
            // 1+1 / 1+2 / 1+3 的赔款（manual_inactive）按执行当天入账，
            // 不回写到原 process day_start；首月正常合同入账仍走 monthly/partial 逻辑。
            $transactionDate = $fallbackDate;
            $postedDateForInbox = $fallbackDate;
        } elseif ($periodType === 'day_end_tail' && $dayStartYmd) {
            // day_end_tail：归属日与 posted 锚点用尾段起点（1st+cap ON 时可能与 exclusiveEnd 相同或为 day_end 月首 max）
            if ($dayEndTailAnchorYmd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayEndTailAnchorYmd)) {
                $transactionDate = $dayEndTailAnchorYmd;
                $postedDateForInbox = $dayEndTailAnchorYmd;
            } else {
                $term = getBillingTermMonthsFromContract($p['contract'] ?? null);
                if ($term !== null && $term >= 1) {
                    $exclusiveEnd = contractExclusiveEndYmdForFrequency($dayStartYmd, $p['contract'] ?? null, $frequency);
                    if ($exclusiveEnd !== null) {
                        $transactionDate = $exclusiveEnd;
                        $postedDateForInbox = $exclusiveEnd;
                    }
                }
            }
        } elseif ($periodType === 'once_one_off') {
            // 一次性合同：不按应付日限制；归属日与 Inbox 去重锚点用 day_start，缺失则用今日
            $transactionDate = ($dayStartYmd !== null && $dayStartYmd !== '') ? $dayStartYmd : $fallbackDate;
            $postedDateForInbox = $transactionDate;
        } elseif ($periodType === 'weekly') {
            $resolvedWeeklyStart = trim((string) ($pair['billing_month'] ?? ''));
            if ($resolvedWeeklyStart === '' && $dayStartYmd) {
                $resolvedWeeklyStart = $dayStartYmd;
            }
            if ($resolvedWeeklyStart !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedWeeklyStart)) {
                $resendRelax = $has_resend_relax_col && !empty($p['accounting_resend_relax_created_floor']);
                if (!$allowFutureMonthly && !$resendRelax && $resolvedWeeklyStart > $fallbackDate) {
                    $skipCurrentPair = true;
                    $skippedFutureMonthlyDueCount++;
                }
                $transactionDate = $resolvedWeeklyStart;
                $postedDateForInbox = $resolvedWeeklyStart;
            }
        } elseif ($periodType === 'daily_consolidated') {
            $rangeDailyTx = dailyParseConsolidatedBillingRange(trim((string) ($pair['billing_month'] ?? '')));
            if ($rangeDailyTx === null) {
                $skipCurrentPair = true;
            } else {
                $transactionDate = $fallbackDate;
                $postedDateForInbox = $fallbackDate;
            }
        } elseif ($periodType === 'daily') {
            $resolvedDailyYmd = trim((string) ($pair['billing_month'] ?? ''));
            if ($resolvedDailyYmd === '' && $dayStartYmd) {
                $resolvedDailyYmd = $dayStartYmd;
            }
            if ($resolvedDailyYmd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedDailyYmd)) {
                if (!$allowFutureMonthly && $resolvedDailyYmd > $fallbackDate) {
                    $skipCurrentPair = true;
                    $skippedFutureMonthlyDueCount++;
                }
                $transactionDate = $resolvedDailyYmd;
                $postedDateForInbox = $resolvedDailyYmd;
            }
        } elseif ($periodType === 'monthly') {
            // monthly：Payment History 归档日固定为该期应付日（dueYmd），
            // 非 resend 场景未到应付日不允许提前入账；resend 维持可回补旧期能力。
            if ($resolvedMonthlyBm !== '' && $dayStartYmd) {
                $dueTx = monthlyDueYmdForBillingMonth($resolvedMonthlyBm, $dayStartYmd, $frequency);
                if ($dueTx !== null) {
                    $resendRelax = $has_resend_relax_col && !empty($p['accounting_resend_relax_created_floor']);
                    if (!$allowFutureMonthly && !$resendRelax && $dueTx > $fallbackDate) {
                        $skipCurrentPair = true;
                        $skippedFutureMonthlyDueCount++;
                    }
                    $transactionDate = $dueTx;
                    $postedDateForInbox = $dueTx;
                }
            }
        }

        if ($skipCurrentPair) {
            continue;
        }

        // Resend 合并账：流水与 PAP 必须锚在弹窗 Day start，不得落到区间内某月 1 号（与 monthly 应付日逻辑混用）
        if ($periodType === 'resend_consolidated_range' && $dayStartYmd !== null && $dayStartYmd !== '') {
            $transactionDate = $dayStartYmd;
            $postedDateForInbox = $dayStartYmd;
        }

        $ledgerDate = $transactionDate;

        $baseTxn = [
            'company_id' => $companyId,
            'transaction_type' => 'WIN',
            'transaction_date' => $transactionDate,
            'created_by' => $created_by_user,
            'created_by_owner' => $ownerId,
        ];
        if ($has_currency_id && $currencyId) {
            $baseTxn['currency_id'] = $currencyId;
        }
        if ($has_source_bank_process_id) {
            $baseTxn['source_bank_process_id'] = (int) $p['id'];
        }
        if ($has_source_bank_process_period_type) {
            $baseTxn['source_bank_process_period_type'] = $periodType;
        }

        $suffix = $periodType === 'partial_first_month' ? ' (partial first month)' : ($periodType === 'day_end_tail' ? ' (day end tail)' : ($periodType === 'resend_consolidated_range' ? ' (resend consolidated)' : ($periodType === 'once_one_off' ? ' (once)' : ($periodType === 'daily_consolidated' ? ' (daily consolidated)' : ($periodType === 'daily' ? ' (daily)' : '')))));
        $resendEndMarker = '';
        $dailyRangeMarker = '';
        if ($periodType === 'daily_consolidated') {
            $rangeDailyMarker = dailyParseConsolidatedBillingRange(trim((string) ($pair['billing_month'] ?? '')));
            if ($rangeDailyMarker !== null) {
                $dailyRangeMarker = ' [DAILY_RANGE=' . $rangeDailyMarker['start'] . '|' . $rangeDailyMarker['end'] . ']';
            }
        }
        if ($periodType === 'resend_consolidated_range') {
            $endRawForMarker = $p['day_end'] ?? null;
            $endYmdForMarker = $endRawForMarker !== null && trim((string) $endRawForMarker) !== ''
                ? bankProcessDateFieldToYmd((string) $endRawForMarker)
                : null;
            if ($endYmdForMarker !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endYmdForMarker)) {
                // 保留在原始 description 供 history 展示层读取 resend 的临时 day_end（入账后该字段会被清除）
                $resendEndMarker = ' [RESEND_END=' . $endYmdForMarker . ']';
            }
        }
        $compMonthLabel = getCompensationMonthLabelFromContract($p['contract'] ?? null);
        // Cost → Supplier(card_merchant)，Price → Customer，Profit → Company；首月按比例时三笔均用折算后的 cost/price/profit
        if (!empty($p['card_merchant_id']) && money_cmp($cost, '0') > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $p['card_merchant_id'];
            $txn['amount'] = txnTrunc2($cost);
            $txn['description'] = $isManualInactiveCompensation
                ? ("Compensation " . $compMonthLabel . ' ' . txnDescriptionAmount($cost))
                : ("Process: Buy Price for $processLabel" . $suffix . $resendEndMarker . $dailyRangeMarker);
            if ($has_approval_status) {
                applyBankProcessPostApprovalFields(
                    $pdo,
                    $txn,
                    $p,
                    (int) $p['card_merchant_id'],
                    $postUserRole,
                    $created_by_user !== null ? (int) $created_by_user : null,
                    $ownerId,
                    $ledgerDate
                );
            }
            insertTransactionRow($pdo, $txn);
            $createdCount++;
            $pairPostedTxn = true;
        }
        // Sell Price → Customer：用 LOSE + 正数 amount，Win/Loss 计算时按 -amount 显示在右边「-」侧（Customer 要还钱）；Cost/Profit/Profit Sharing 用 WIN + 正数显示在左边「+」侧
        if (!empty($p['customer_id']) && money_cmp($price, '0') > 0) {
            $txn = $baseTxn;
            $txn['transaction_type'] = 'LOSE';
            $txn['account_id'] = (int) $p['customer_id'];
            $txn['amount'] = txnTrunc2($price);
            $txn['description'] = $isManualInactiveCompensation
                ? ("Compensation " . $compMonthLabel . ' ' . txnDescriptionAmount($price))
                : ("Process: Sell Price for $processLabel" . $suffix . $resendEndMarker . $dailyRangeMarker);
            if ($has_approval_status) {
                applyBankProcessPostApprovalFields(
                    $pdo,
                    $txn,
                    $p,
                    (int) $p['customer_id'],
                    $postUserRole,
                    $created_by_user !== null ? (int) $created_by_user : null,
                    $ownerId,
                    $ledgerDate
                );
            }
            insertTransactionRow($pdo, $txn);
            $createdCount++;
            $pairPostedTxn = true;
        }
        // Profit：先扣 Profit Sharing 再入 Company；Profit Sharing 每笔入对应 account（均记 Win/Loss）
        // 1st of every month 首月按比例时，Profit Sharing 金额也按「剩余天数/当月天数」折算，再分给各 account
        $psRatio = '1.0000000000000000';
        if ($periodType === 'partial_first_month') {
            $ts = strtotime($ledgerDate);
            if ($ts !== false) {
                $daysInMonth = (int) date('t', $ts);
                $dayOfMonth = (int) date('j', $ts);
                $daysRemaining = $daysInMonth - $dayOfMonth + 1;
                if ($daysInMonth > 0) {
                    $psRatio = money_div((string) $daysRemaining, (string) $daysInMonth, MONEY_CALC_SCALE);
                }
            }
        } elseif ($monthlyProrationPsRatio !== null) {
            $psRatio = $monthlyProrationPsRatio;
        } elseif ($periodType === 'once_one_off') {
            $psRatio = '1.0000000000000000';
        } elseif ($periodType === 'day_end_tail' || $periodType === 'resend_consolidated_range' || $periodType === 'daily_consolidated') {
            $fp = money_normalize($p['profit'] ?? '0');
            $psRatio = money_cmp($fp, '0') > 0 ? money_div($profit, $fp, MONEY_CALC_SCALE) : '0.0000000000000000';
        }
        $profitSharingEntries = parseProfitSharingString($p['profit_sharing'] ?? '');
        $profitSharingResolved = [];
        $totalPs = '0.00000000';
        $psMult = ($periodType === 'manual_inactive') ? getManualInactiveMultiplierFromContract($p['contract'] ?? null) : 1;
        foreach ($profitSharingEntries as $entry) {
            $accId = resolveAccountIdByText($pdo, $companyId, $entry['account_text']);
            if ($accId !== null && money_cmp($entry['amount'], '0') > 0) {
                $proratedAmount = money_mul(money_mul($entry['amount'], $psRatio, MONEY_CALC_SCALE), (string) $psMult, 2);
                if (money_cmp($proratedAmount, '0') > 0) {
                    $profitSharingResolved[] = ['account_id' => $accId, 'amount' => $proratedAmount, 'account_text' => $entry['account_text']];
                    $totalPs = money_add($totalPs, $proratedAmount);
                }
            }
        }
        // bank_process.profit：新版前端存「净毛利」(sell−buy−已扣 PS)，旧版/JS 存「毛利」(sell−buy)。
        // 公司 Profit 必须以本笔入账的 sell/buy 差额为毛利再扣 PS；若用 profit 再减 PS，净毛利会重复扣除分成（Once 等场景 Win/Loss 与 Description 不符）。
        $grossProfitForTxn = money_sub($price, $cost, 2);
        $companyProfit = money_sub($grossProfitForTxn, $totalPs, 2);
        if (money_cmp(money_abs($companyProfit), '0.00001') < 0) {
            $companyProfit = '0.00000000';
        }
        // Profit 被 Share 抵消为 0.00 时，也要保留一条 Profit 记录给 Transaction Payment / History。
        if (!empty($p['profit_account_id']) && money_cmp($companyProfit, '0') >= 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $p['profit_account_id'];
            $txn['amount'] = txnTrunc2($companyProfit);
            $txn['description'] = $isManualInactiveCompensation
                ? ("Compensation " . $compMonthLabel . ' ' . txnDescriptionAmount($profit))
                : ("Process: Profit for $processLabel" . $suffix . $resendEndMarker . $dailyRangeMarker);
            if ($has_approval_status) {
                applyBankProcessPostApprovalFields(
                    $pdo,
                    $txn,
                    $p,
                    (int) $p['profit_account_id'],
                    $postUserRole,
                    $created_by_user !== null ? (int) $created_by_user : null,
                    $ownerId,
                    $ledgerDate
                );
            }
            insertTransactionRow($pdo, $txn);
            $createdCount++;
            $pairPostedTxn = true;
        }
        foreach ($profitSharingResolved as $ps) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $ps['account_id'];
            $txn['amount'] = txnTrunc2($ps['amount']);
            $txn['description'] = $isManualInactiveCompensation
                ? ("Compensation " . $compMonthLabel . ' ' . txnDescriptionAmount($ps['amount']))
                : ("Process: Profit Sharing for $processLabel (" . $ps['account_text'] . ' ' . money_out($ps['amount'], 2) . ')' . $suffix . $resendEndMarker . $dailyRangeMarker);
            if ($has_approval_status) {
                applyBankProcessPostApprovalFields(
                    $pdo,
                    $txn,
                    $p,
                    (int) $ps['account_id'],
                    $postUserRole,
                    $created_by_user !== null ? (int) $created_by_user : null,
                    $ownerId,
                    $ledgerDate
                );
            }
            insertTransactionRow($pdo, $txn);
            $createdCount++;
            $pairPostedTxn = true;
        }

        if ($periodType === 'daily_consolidated') {
            $rangeDailyPost = dailyParseConsolidatedBillingRange(trim((string) ($pair['billing_month'] ?? '')));
            if ($rangeDailyPost !== null) {
                recordDailyRangeAccountingPosted(
                    $pdo,
                    $companyId,
                    (int) $p['id'],
                    $rangeDailyPost['start'],
                    $rangeDailyPost['end'],
                    $has_period_type
                );
            }
        } else {
            recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $postedDateForInbox, $periodType, $has_period_type);
        }

        if ($has_source_bank_process_id && $pairPostedTxn) {
            $anchorForGuard = bankProcessDateFieldToYmd($transactionDate);
            if ($anchorForGuard !== null && $anchorForGuard !== '') {
                bmp_recordAccountingResendDailyGuardOnTransactionPost($pdo, $companyId, (int) $p['id'], $anchorForGuard);
            }
        }

        if ($periodType === 'once_one_off' && $frequency === 'once' && trim((string) ($p['status'] ?? '')) === 'active') {
            $updOnceInactive = $pdo->prepare(
                "UPDATE bank_process SET status = 'inactive', dts_modified = NOW() WHERE id = ? AND company_id = ? AND status = 'active'"
            );
            $updOnceInactive->execute([(int) $p['id'], $companyId]);
            $p['status'] = 'inactive';
        }

        if ($periodType === 'resend_consolidated_range' && $has_period_type && $dayStartYmd) {
            $endRawPost = $p['day_end'] ?? null;
            $endYmdPost = $endRawPost !== null && trim((string) $endRawPost) !== ''
                ? bankProcessDateFieldToYmd((string) $endRawPost)
                : null;
            if ($endYmdPost !== null && $endYmdPost !== '' && $dayStartYmd <= $endYmdPost) {
                txnRecordMonthlySkippedCoveringConsolidatedRange(
                    $pdo,
                    $companyId,
                    (int) $p['id'],
                    $dayStartYmd,
                    $endYmdPost,
                    $has_period_type
                );
                $termPost = getBillingTermMonthsFromContract($p['contract'] ?? null);
                if ($termPost !== null && $termPost >= 1) {
                    $exclPost = contractExclusiveEndYmdForFrequency($dayStartYmd, $p['contract'] ?? null, $frequency);
                    if ($exclPost !== null && $endYmdPost >= $exclPost) {
                        recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $exclPost, 'day_end_tail_skipped', $has_period_type);
                    }
                }
            }
            if ($frequency === '1st_of_every_month') {
                $storedRawPc = $p['bank_process_stored_day_start'] ?? null;
                $storedYmdPc = $storedRawPc !== null && trim((string) $storedRawPc) !== '' ? bankProcessDateFieldToYmd((string) $storedRawPc) : null;
                if ($storedYmdPc !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedYmdPc)) {
                    $tsPc = strtotime($storedYmdPc);
                    if ($tsPc !== false
                        && (int) date('j', $tsPc) !== 1) {
                        if ($has_period_type) {
                            if (!txnIsPartialFirstMonthPostedOrSkipped($pdo, $companyId, (int) $p['id'])) {
                                recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $storedYmdPc, 'partial_first_month_skipped', $has_period_type);
                            }
                        } else {
                            // 兼容无 period_type 结构：写入真实锚点日，避免 Resend 单期后立刻再排出首月/同锚点账单。
                            recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $storedYmdPc, 'monthly', $has_period_type);
                        }
                    }
                }
            }
        }

        // Resend 弹窗锚点（如 1/1）入账整月 monthly 后，会清除暂存并回到库里真实 day_start（如 4/15）。
        // 「1st_of_every_month + 非 1 号真实 day_start」仍会排队首月 partial，与刚补的历史整月无关，易误判为重复 — 写入 skipped 抑制该幽灵行（与 dismiss 一致）。
        if ($periodType === 'monthly'
            && !empty($p['accounting_resend_single_period_from_schedule'])
            && ($frequency === '1st_of_every_month' || $frequency === 'monthly')) {
            $storedRaw = $p['bank_process_stored_day_start'] ?? null;
            $storedYmd = $storedRaw !== null && trim((string) $storedRaw) !== '' ? bankProcessDateFieldToYmd((string) $storedRaw) : null;
            if ($storedYmd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedYmd)) {
                $tsS = strtotime($storedYmd);
                if ($tsS !== false
                    && (int) date('j', $tsS) !== 1) {
                    if ($has_period_type) {
                        if (!txnIsPartialFirstMonthPostedOrSkipped($pdo, $companyId, (int) $p['id'])) {
                            recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $storedYmd, 'partial_first_month_skipped', $has_period_type);
                        }
                    } else {
                        // 兼容无 period_type：用真实锚点日写一条 posted 作为去重锚点。
                        recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $storedYmd, 'monthly', $has_period_type);
                    }
                }
            }
        }

        // Daily Resend 单期入账后：抑制「回到库里真实 day_start」时排出的历史日 backlog（与 weekly_skipped 同理）。
        if ($periodType === 'daily'
            && !empty($p['accounting_resend_single_period_from_schedule'])
            && $frequency === 'day') {
            $storedRawDay = $p['bank_process_stored_day_start'] ?? null;
            $storedYmdDay = $storedRawDay !== null && trim((string) $storedRawDay) !== ''
                ? bankProcessDateFieldToYmd((string) $storedRawDay)
                : null;
            $resendAnchorDay = trim((string) ($pair['billing_month'] ?? ''));
            if ($resendAnchorDay === '' && $dayStartYmd) {
                $resendAnchorDay = $dayStartYmd;
            }
            if ($storedYmdDay !== null && $resendAnchorDay !== ''
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedYmdDay)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $resendAnchorDay)) {
                txnRecordDailySkippedBeforeResendAnchor(
                    $pdo,
                    $companyId,
                    (int) $p['id'],
                    $storedYmdDay,
                    $resendAnchorDay,
                    $has_period_type
                );
            }
        }

        // Weekly Resend 单期入账后：抑制「回到库里真实 day_start」时排出的历史周 backlog（与 monthly partial_first_month_skipped 同理）。
        if ($periodType === 'weekly'
            && !empty($p['accounting_resend_single_period_from_schedule'])
            && $frequency === 'week') {
            $storedRawWeek = $p['bank_process_stored_day_start'] ?? null;
            $storedYmdWeek = $storedRawWeek !== null && trim((string) $storedRawWeek) !== ''
                ? bankProcessDateFieldToYmd((string) $storedRawWeek)
                : null;
            $resendAnchorWeek = trim((string) ($pair['billing_month'] ?? ''));
            if ($resendAnchorWeek === '' && $dayStartYmd) {
                $resendAnchorWeek = $dayStartYmd;
            }
            if ($storedYmdWeek !== null && $resendAnchorWeek !== ''
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedYmdWeek)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $resendAnchorWeek)) {
                txnRecordWeeklySkippedBeforeResendAnchor(
                    $pdo,
                    $companyId,
                    (int) $p['id'],
                    $storedYmdWeek,
                    $resendAnchorWeek,
                    $has_period_type
                );
            }
        }

        if ($has_resend_relax_col && !empty($p['accounting_resend_relax_created_floor'])) {
            if ($origPeriodType === 'resend_consolidated_range') {
                bmp_clearResendRelaxState($pdo, (int) $p['id'], $companyId);
                $p['accounting_resend_relax_created_floor'] = 0;
            } elseif ($origPeriodType === 'resend_monthly_reopen') {
                $resendAnchorYmd = null;
                $bmPost = trim((string) ($pair['billing_month'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bmPost)) {
                    $resendAnchorYmd = $bmPost;
                }
                bmp_maybeClearResendRelaxAfterAnchorHandled($pdo, (int) $p['id'], $companyId, $resendAnchorYmd);
                if (empty(bmp_loadResendOpenAnchorsFromDb($pdo, (int) $p['id'], $companyId))) {
                    $p['accounting_resend_relax_created_floor'] = 0;
                }
            } elseif (in_array($periodType, ['weekly', 'daily', 'once_one_off'], true)) {
                $resendAnchorYmd = null;
                $bmPost = trim((string) ($pair['billing_month'] ?? ''));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bmPost)) {
                    $resendAnchorYmd = $bmPost;
                } elseif ($dayStartYmd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStartYmd)) {
                    $resendAnchorYmd = $dayStartYmd;
                }
                if ($resendAnchorYmd !== null
                    && bmp_resendOpenAnchorAlreadyExists($pdo, (int) $p['id'], $companyId, $resendAnchorYmd)) {
                    bmp_maybeClearResendRelaxAfterAnchorHandled($pdo, (int) $p['id'], $companyId, $resendAnchorYmd);
                    if (empty(bmp_loadResendOpenAnchorsFromDb($pdo, (int) $p['id'], $companyId))) {
                        $p['accounting_resend_relax_created_floor'] = 0;
                    }
                }
            }
        }

        // manual_inactive 入账后：保持 inactive；1+1/1+2/1+3 时给 day_end 加对应月数（与 Frequency 无关，1st of every month 与 monthly 行为一致，仅算账日不同）
        if ($periodType === 'manual_inactive') {
            $extraMonths = getExtraMonthsFromContract($p['contract'] ?? null);
            $dayEnd = $p['day_end'] ?? null;
            $dayStart = $p['day_start'] ?? null;
            $baseDate = ($dayEnd !== null && $dayEnd !== '') ? $dayEnd : $dayStart;
            if ($extraMonths > 0 && $baseDate !== null && $baseDate !== '') {
                $newDayEnd = addMonthsToDate($baseDate, $extraMonths);
                if ($newDayEnd !== null) {
                    $upd = $pdo->prepare("UPDATE bank_process SET day_end = ?, dts_modified = NOW() WHERE id = ? AND company_id = ?");
                    $upd->execute([$newDayEnd, (int) $p['id'], $companyId]);
                }
            }
        }
    }

    // 入账成功后立刻清理 Transaction List 缓存，避免 Resend 后短时间显示旧账单。
    clearTransactionSearchCache();

    if ($createdCount === 0 && $skippedFutureMonthlyDueCount > 0) {
        jsonResponse(true, "未到应付日，暂不生成交易记录（Resend 除外）。", [
            'created_count' => 0,
            'skipped_future_monthly_due_count' => $skippedFutureMonthlyDueCount
        ]);
        exit;
    }

    jsonResponse(true, "已入账，共生成 $createdCount 条交易记录。", ['created_count' => $createdCount]);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
} catch (PDOException $e) {
    error_log('process_post_to_transaction_api: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '服务器错误', null);
}