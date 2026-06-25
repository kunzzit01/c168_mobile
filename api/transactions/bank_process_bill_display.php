<?php
/**
 * Bank process 账单类描述（首月 partial_first_month）与日期解析，供 history_api / bankprocess_maintenance 等复用。
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/../processes/contract_billing_addon.php';

/**
 * 解析 bank_process.day_start（支持 yyyy-mm-dd、d/m/Y 等），与 history_api 原逻辑一致。
 */
function bankProcessParseDayStartToYmd($raw): ?string
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

function bankProcessBillFormatTripartNumber($amt): string
{
    return money_out($amt ?? '0', 2);
}

/**
 * 关联 bank_process 的 Bank 列（history: bank_name；maintenance: process_bank）。
 */
function bankProcessResolveLinkedBankName(array $t): string
{
    foreach (['bank_name', 'process_bank', 'bp_bank', 'bank'] as $key) {
        $v = trim((string) ($t[$key] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

/**
 * Payment History / Maintenance：Bank process 账单类 Description 末尾追加「 | {bank}」。
 */
function bankProcessAppendBankSuffixToDescription(string $description, array $t): string
{
    $description = trim($description);
    if ($description === '' || $description === '-') {
        return $description;
    }
    $bank = bankProcessResolveLinkedBankName($t);
    if ($bank === '') {
        return $description;
    }
    $suffix = ' | ' . $bank;
    if (preg_match('/\s\|\s' . preg_quote($bank, '/') . '$/iu', $description)) {
        return $description;
    }

    return $description . $suffix;
}

function bankProcessProfitSharingOriginalAmountByAccount(array $t): ?string
{
    $profitSharingRaw = trim((string) ($t['process_profit_sharing'] ?? ''));
    if ($profitSharingRaw === '') {
        return null;
    }
    $currentCode = trim((string) ($t['to_account_code'] ?? $t['account_code'] ?? ''));
    if ($currentCode === '') {
        return null;
    }
    foreach (explode(',', $profitSharingRaw) as $part) {
        $entry = trim($part);
        if ($entry === '') {
            continue;
        }
        $dash = strrpos($entry, ' - ');
        if ($dash === false) {
            continue;
        }
        $accountText = trim(substr($entry, 0, $dash));
        $amountStr = trim(substr($entry, $dash + 3));
        if ($accountText === '' || $amountStr === '') {
            continue;
        }
        if (strcasecmp($accountText, $currentCode) === 0) {
            return money_normalize($amountStr, 2);
        }
    }
    return null;
}

function bankProcessResolveDisplayValueByAccount(array $t): string
{
    $buy = $t['process_cost'] ?? '0';
    $sell = $t['process_price'] ?? '0';
    $profit = $t['process_profit'] ?? '0';

    $txAccountId = (int) ($t['account_id'] ?? 0);
    $cardMerchantId = (int) ($t['card_merchant_id'] ?? 0);
    $customerId = (int) ($t['customer_id'] ?? 0);
    $profitAccountId = (int) ($t['profit_account_id'] ?? 0);

    if ($txAccountId > 0 && $txAccountId === $cardMerchantId) {
        return bankProcessBillFormatTripartNumber($buy);
    }
    if ($txAccountId > 0 && $txAccountId === $customerId) {
        return bankProcessBillFormatTripartNumber(money_abs($sell, 2));
    }
    if ($txAccountId > 0 && $txAccountId === $profitAccountId) {
        return bankProcessBillFormatTripartNumber($profit);
    }
    $psAmount = bankProcessProfitSharingOriginalAmountByAccount($t);
    if ($psAmount !== null) {
        return bankProcessBillFormatTripartNumber($psAmount);
    }
    return bankProcessBillFormatTripartNumber($t['amount'] ?? '0');
}

/**
 * Payment History / Maintenance：Frequency=once 入账行描述（与首月 partial 一致：每行只展示本条对应的金额）。
 * - Supplier(card_merchant)：ONCE (DD/MM/YYYY) @ buy
 * - Customer：ONCE (DD/MM/YYYY) @ sell
 * - Company profit：ONCE (DD/MM/YYYY)（不在 Description 重复金额，与 Win/Loss 列一致）
 * - Profit sharing 账户：ONCE (DD/MM/YYYY) @ 该账号分摊额
 *
 * @param array $t 需含 account_id、card_merchant_id、customer_id、profit_account_id、process_*；transaction_date 优先，否则 bp_day_start
 */
function bankProcessOnceOneOffHistoryDescription(array $t): string
{
    $dmy = '';
    $td = trim((string) ($t['transaction_date'] ?? ''));
    if ($td !== '' && stripos($td, '0000-00-00') !== 0) {
        if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $td)) {
            $dmy = $td;
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $td, $m)) {
            $ts = strtotime($m[1] . '-' . $m[2] . '-' . $m[3]);
            if ($ts !== false) {
                $dmy = date('d/m/Y', $ts);
            }
        } else {
            $ts = strtotime(str_replace('/', '-', $td));
            if ($ts !== false) {
                $dmy = date('d/m/Y', $ts);
            }
        }
    }
    if ($dmy === '') {
        $ymd = bankProcessParseDayStartToYmd($t['bp_day_start'] ?? null);
        if ($ymd !== null) {
            $ts = strtotime($ymd);
            if ($ts !== false) {
                $dmy = date('d/m/Y', $ts);
            }
        }
    }
    if ($dmy === '') {
        $dmy = date('d/m/Y');
    }
    $prefix = 'ONCE (' . $dmy . ')';
    $txAccountId = (int) ($t['account_id'] ?? 0);
    $cardMerchantId = (int) ($t['card_merchant_id'] ?? 0);
    $customerId = (int) ($t['customer_id'] ?? 0);
    $profitAccountId = (int) ($t['profit_account_id'] ?? 0);

    if ($txAccountId > 0 && $txAccountId === $cardMerchantId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($t['process_cost'] ?? '0');
    }
    if ($txAccountId > 0 && $txAccountId === $customerId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber(money_abs($t['process_price'] ?? '0', 2));
    }
    if ($txAccountId > 0 && $txAccountId === $profitAccountId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($t['process_profit'] ?? '0');
    }
    $psAmount = bankProcessProfitSharingOriginalAmountByAccount($t);
    if ($psAmount !== null) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($psAmount);
    }
    return $prefix;
}

/**
 * Payment History / Maintenance：Frequency=week 入账行描述。
 * WEEK (DD/MM/YYYY - DD/MM/YYYY) @ 对应账单价格
 */
function bankProcessWeeklyHistoryDescription(array $t): string
{
    $startYmd = null;
    $td = trim((string) ($t['transaction_date'] ?? ''));
    if ($td !== '') {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $td, $m)) {
            $startYmd = $m[1];
        } else {
            $ts = strtotime(str_replace('/', '-', $td));
            if ($ts !== false) {
                $startYmd = date('Y-m-d', $ts);
            }
        }
    }
    if ($startYmd === null) {
        $startYmd = bankProcessParseDayStartToYmd($t['bp_day_start'] ?? null);
    }
    if ($startYmd === null) {
        $startYmd = date('Y-m-d');
    }
    $endYmd = weekPeriodEndInclusiveYmd($startYmd) ?? $startYmd;
    $startDm = date('d/m/Y', strtotime($startYmd));
    $endDm = date('d/m/Y', strtotime($endYmd));
    $prefix = 'WEEK (' . $startDm . ' - ' . $endDm . ')';
    $txAccountId = (int) ($t['account_id'] ?? 0);
    $cardMerchantId = (int) ($t['card_merchant_id'] ?? 0);
    $customerId = (int) ($t['customer_id'] ?? 0);
    $profitAccountId = (int) ($t['profit_account_id'] ?? 0);

    if ($txAccountId > 0 && $txAccountId === $cardMerchantId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($t['process_cost'] ?? '0');
    }
    if ($txAccountId > 0 && $txAccountId === $customerId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber(money_abs($t['process_price'] ?? '0', 2));
    }
    if ($txAccountId > 0 && $txAccountId === $profitAccountId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($t['process_profit'] ?? '0');
    }
    $psAmount = bankProcessProfitSharingOriginalAmountByAccount($t);
    if ($psAmount !== null) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($psAmount);
    }
    return $prefix;
}

/**
 * Payment History / Maintenance：Frequency=day 入账行描述。
 * DAY (DD/MM/YYYY) 或 DAY (DD/MM/YYYY - DD/MM/YYYY) @ 对应账单价格
 */
function bankProcessParseDailyRangeFromDescription(?string $desc): ?array
{
    if (!preg_match('/\[DAILY_RANGE=(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})\]/', (string) $desc, $m)) {
        return null;
    }
    return ['start' => $m[1], 'end' => $m[2]];
}

function bankProcessDailyHistoryDescription(array $t): string
{
    $range = bankProcessParseDailyRangeFromDescription($t['description'] ?? null);
    if ($range !== null) {
        $startDm = date('d/m/Y', strtotime($range['start']));
        $endDm = date('d/m/Y', strtotime($range['end']));
        $prefix = 'DAY (' . $startDm . ' - ' . $endDm . ')';
    } else {
        $dayYmd = null;
        $td = trim((string) ($t['transaction_date'] ?? ''));
        if ($td !== '') {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $td, $m)) {
                $dayYmd = $m[1];
            } else {
                $ts = strtotime(str_replace('/', '-', $td));
                if ($ts !== false) {
                    $dayYmd = date('Y-m-d', $ts);
                }
            }
        }
        if ($dayYmd === null) {
            $dayYmd = bankProcessParseDayStartToYmd($t['bp_day_start'] ?? null) ?? date('Y-m-d');
        }
        $prefix = 'DAY (' . date('d/m/Y', strtotime($dayYmd)) . ')';
    }
    $txAccountId = (int) ($t['account_id'] ?? 0);
    $cardMerchantId = (int) ($t['card_merchant_id'] ?? 0);
    $customerId = (int) ($t['customer_id'] ?? 0);
    $profitAccountId = (int) ($t['profit_account_id'] ?? 0);

    if ($txAccountId > 0 && $txAccountId === $cardMerchantId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($t['process_cost'] ?? '0');
    }
    if ($txAccountId > 0 && $txAccountId === $customerId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber(money_abs($t['process_price'] ?? '0', 2));
    }
    if ($txAccountId > 0 && $txAccountId === $profitAccountId) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($t['process_profit'] ?? '0');
    }
    $psAmount = bankProcessProfitSharingOriginalAmountByAccount($t);
    if ($psAmount !== null) {
        return $prefix . ' @ ' . bankProcessBillFormatTripartNumber($psAmount);
    }
    return $prefix;
}

/**
 * 仅显示当前这条记录对应的价格：
 * - Supplier(card_merchant): buy price
 * - Customer: sell price（始终负号）
 * - Profit account: profit
 * - Profit sharing account: 取 process_profit_sharing 中该账号的原始金额
 *
 * @param array $t 需含 bp_day_start、process_cost、process_price、process_profit；可选 transaction_date 作 day_start 后备
 */
function bankProcessProRatedFirstMonthDescription(array $t): string
{
    // Resend 场景下，transaction_date 会锚到本次执行的 daystart；
    // 这里优先用 transaction_date，确保 Pro-rated 的月份/天数随 resend daystart 变化。
    $startYmd = null;
    $td = trim((string) ($t['transaction_date'] ?? ''));
    if ($td !== '') {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $td, $m)) {
            $startYmd = $m[1];
        } else {
            $ts = strtotime(str_replace('/', '-', $td));
            if ($ts !== false) {
                $startYmd = date('Y-m-d', $ts);
            }
        }
    }
    if ($startYmd === null) {
        $rawStart = $t['bp_day_start'] ?? null;
        $startYmd = bankProcessParseDayStartToYmd($rawStart);
    }
    if ($startYmd === null) {
        return 'Pro-rated@monthly';
    }
    $tsStart = strtotime($startYmd . ' 12:00:00');
    if ($tsStart === false) {
        return 'Pro-rated@monthly';
    }
    $endYmd = date('Y-m-t', $tsStart);
    $tsEnd = strtotime($endYmd . ' 12:00:00');
    $startDm = date('j/n', $tsStart);
    $endDm = $tsEnd !== false ? date('j/n', $tsEnd) : date('j/n', $tsStart);
    $daysCount = (int) floor((strtotime($endYmd . ' 12:00:00') - $tsStart) / 86400) + 1;
    if ($daysCount < 1) {
        $daysCount = 1;
    }

    $value = bankProcessResolveDisplayValueByAccount($t);

    return "Pro-rated({$startDm} - {$endDm} | {$daysCount}days)@Monthly {$value}";
}

/**
 * day_end 区间账单描述：
 * - $withPrefix=true  => DayEnd - Prorated(dd/mm - dd/mm | N days)@Monthly <value>
 * - $withPrefix=false => Prorated(dd/mm - dd/mm | N days)@Monthly <value>
 */
function bankProcessDayEndProratedDescription(array $t, bool $withPrefix = true): string
{
    $startYmd = null;
    $td = trim((string) ($t['transaction_date'] ?? ''));
    if ($td !== '') {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $td, $m)) {
            $startYmd = $m[1];
        } else {
            $ts = strtotime(str_replace('/', '-', $td));
            if ($ts !== false) {
                $startYmd = date('Y-m-d', $ts);
            }
        }
    }
    $endYmd = bankProcessParseDayStartToYmd($t['bp_resend_day_end'] ?? null);
    if ($endYmd === null) {
        $endYmd = bankProcessParseDayStartToYmd($t['bp_day_end'] ?? null);
    }
    if ($startYmd === null && $endYmd !== null) {
        $startYmd = $endYmd;
    }
    if ($startYmd === null) {
        $value = bankProcessResolveDisplayValueByAccount($t);
        return ($withPrefix ? 'DayEnd - Prorated@Monthly' : 'Prorated@Monthly') . " {$value}";
    }
    if ($endYmd === null || $endYmd < $startYmd) {
        $endYmd = $startYmd;
    }

    $tsStart = strtotime($startYmd . ' 12:00:00');
    $tsEnd = strtotime($endYmd . ' 12:00:00');
    if ($tsStart === false || $tsEnd === false) {
        $value = bankProcessResolveDisplayValueByAccount($t);
        return ($withPrefix ? 'DayEnd - Prorated@Monthly' : 'Prorated@Monthly') . " {$value}";
    }

    $startDm = date('j/n', $tsStart);
    $endDm = date('j/n', $tsEnd);
    $daysCount = (int) floor(($tsEnd - $tsStart) / 86400) + 1;
    if ($daysCount < 1) {
        $daysCount = 1;
    }

    $prefix = $withPrefix ? 'DayEnd - Prorated(' : 'Prorated(';
    $value = bankProcessResolveDisplayValueByAccount($t);
    return $prefix . $startDm . ' - ' . $endDm . ' | ' . $daysCount . " days)@Monthly {$value}";
}

/**
 * 1st_of_every_month + Day end 开关 ON：day_end 落在 transaction_date 所在自然月且早于月末时，展示为 Prorated(月初-day_end|天数)@Monthly；否则 null（走 Full Month 等既有分支）。
 */
function bankProcessMonthlyDayEndCapHistoryDescription(array $t): ?string
{
    $capRaw = $t['bp_day_end_monthly_cap_enabled'] ?? null;
    $capOn = in_array((string) $capRaw, ['1', 'true', 'TRUE'], true) || $capRaw === 1 || $capRaw === true;
    if (!$capOn) {
        return null;
    }
    $freq = strtolower(trim((string) ($t['bp_frequency'] ?? '')));
    if (!in_array($freq, ['1st_of_every_month', ''], true)) {
        return null;
    }
    $txnRaw = trim((string) ($t['transaction_date'] ?? ''));
    $txnYmd = null;
    if ($txnRaw !== '') {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $txnRaw, $mx)) {
            $txnYmd = $mx[1];
        } else {
            $ts0 = strtotime(str_replace('/', '-', $txnRaw));
            if ($ts0 !== false) {
                $txnYmd = date('Y-m-d', $ts0);
            }
        }
    }
    if ($txnYmd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnYmd)) {
        return null;
    }
    $tsTxn = strtotime($txnYmd . ' 12:00:00');
    if ($tsTxn === false) {
        return null;
    }
    $monthFirst = date('Y-m-01', $tsTxn);
    $monthLast = date('Y-m-t', $tsTxn);
    $endYmd = bankProcessParseDayStartToYmd($t['bp_day_end'] ?? null);
    if ($endYmd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endYmd)) {
        return null;
    }
    if ($endYmd < $monthFirst || $endYmd > $monthLast || $endYmd >= $monthLast) {
        return null;
    }
    $tsStart = strtotime($monthFirst . ' 12:00:00');
    $tsEnd = strtotime($endYmd . ' 12:00:00');
    if ($tsStart === false || $tsEnd === false) {
        return null;
    }
    $startDm = date('j/n', $tsStart);
    $endDm = date('j/n', $tsEnd);
    $daysCount = (int) floor(($tsEnd - $tsStart) / 86400) + 1;
    if ($daysCount < 1) {
        $daysCount = 1;
    }
    $value = bankProcessResolveDisplayValueByAccount($t);

    return 'Prorated(' . $startDm . ' - ' . $endDm . ' | ' . $daysCount . " days)@Monthly {$value}";
}
