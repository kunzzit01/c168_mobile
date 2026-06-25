<?php
/**
 * 1+N 合同规则：
 * - active：统一按 1 个月价格计算；
 * - manual_inactive：仅在 inactive 赔付时，按 +N 月数放大（由 getManualInactiveMultiplierFromContract 控制）。
 */

declare(strict_types=1);

/** 与 manual_inactive 相同：1+1/1+2/1+3 → N，其余 → 1 */
function getManualInactiveMultiplierFromContract(?string $contract): int
{
    if ($contract === null || $contract === '') {
        return 1;
    }
    $c = trim($contract);
    if (preg_match('/^1\+(\d+)$/i', $c, $m)) {
        return max(1, (int) $m[1]);
    }
    return 1;
}

/** @deprecated active 场景不再使用 1+N 放大，仅保留兼容。 */
function getContractOnePlusExtraFullMonths(?string $contract): int
{
    if ($contract === null || trim($contract) === '') {
        return 0;
    }
    $c = strtoupper(trim($contract));
    if (preg_match('/^1\+(\d+)/', $c, $m)) {
        return max(0, (int) $m[1]);
    }
    return 0;
}

/** 从 startYmd 到当月底（含）占当月天数的比例 */
function ratioRemainingDaysInMonthFromStartYmd(string $startYmd): ?string
{
    $ts = strtotime($startYmd);
    if ($ts === false) {
        return null;
    }
    $daysInMonth = (int) date('t', $ts);
    $dayOfMonth = (int) date('j', $ts);
    if ($daysInMonth <= 0) {
        return null;
    }
    $daysRemaining = max(0, $daysInMonth - $dayOfMonth + 1);

    return money_div((string) $daysRemaining, (string) $daysInMonth, MONEY_CALC_SCALE);
}

/**
 * @param string|null $prorationRatio 本次入账「剩余天数/当月天数」；null 或 >=1 时不调整
 * @param string      $origCost       整月 Buy
 * @param string      $origPrice      整月 Sell
 * @param string      $origProfit     整月 Profit
 */
function applyOnePlusXRemainingDaysBuySellAddon(
    ?string $contract,
    string $origCost,
    string $origPrice,
    string $origProfit,
    string &$cost,
    string &$price,
    string &$profit,
    ?string $prorationRatio
): void {
    // New rule: active billing always keeps 1-month amounts.
    // 1+N compensation is handled only in manual_inactive flow.
    return;
}

/**
 * 某自然月第 N 日（不超过该月最后一天）— 与 process_accounting_inbox_api 的 calendarMonthDueYmd 一致。
 */
function billingCalendarMonthDueYmd(int $year, int $month, int $dueDay): string
{
    $last = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    $d = min(max(1, $dueDay), $last);

    return sprintf('%04d-%02d-%02d', $year, $month, $d);
}

/**
 * Frequency=monthly（先付 / prepaid）：应付日当天付连续 1 个月服务。
 * 首期（due=day_start）：[due, due+1月-1日]（5/22→5/22–6/21）。
 * 链式后续期（due>首段）：[due, due+1月]（6/21→6/21–7/21）；下一期应付 = 上期末日。
 *
 * @return array{0:string,1:string}
 */
function billingMonthlyChainedInclusiveRangeFromDue(string $dueYmd, string $contractStartYmd): array
{
    try {
        $due = new DateTimeImmutable($dueYmd);
        if ($dueYmd === $contractStartYmd) {
            return [$dueYmd, $due->modify('+1 month')->modify('-1 day')->format('Y-m-d')];
        }

        return [$dueYmd, $due->modify('+1 month')->format('Y-m-d')];
    } catch (Throwable $e) {
        return [$dueYmd, $dueYmd];
    }
}

/**
 * @deprecated Use billingMonthlyChainedInclusiveRangeFromDue for frequency=monthly.
 * @return array{0:string,1:string}
 */
function billingMonthlyAnniversaryInclusiveRangeFromDue(string $dueYmd, string $contractStartYmd): array
{
    return billingMonthlyChainedInclusiveRangeFromDue($dueYmd, $contractStartYmd);
}

/** 链式 monthly：本期末日 = 下一期应付日。 */
function billingMonthlyChainedPeriodEndYmd(string $dueYmd, string $contractStartYmd): ?string
{
    [, $end] = billingMonthlyChainedInclusiveRangeFromDue($dueYmd, $contractStartYmd);

    return $end;
}

function billingMonthlyChainedNextDueYmd(string $currentDueYmd, string $contractStartYmd): ?string
{
    return billingMonthlyChainedPeriodEndYmd($currentDueYmd, $contractStartYmd);
}

/**
 * 从 day_start 起按链式 monthly 推算，落在指定自然月内的应付日（若无则 null）。
 */
function billingMonthlyChainedDueYmdInCalendarMonth(string $dayStartYmd, int $year, int $month): ?string
{
    if ($year < 1970 || $month < 1 || $month > 12 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStartYmd)) {
        return null;
    }
    try {
        $monthFirst = sprintf('%04d-%02d-01', $year, $month);
        $monthLast = (new DateTimeImmutable($monthFirst))->modify('last day of this month')->format('Y-m-d');
        $due = $dayStartYmd;
        $guard = 0;
        while ($guard < 520) {
            if ($due >= $monthFirst && $due <= $monthLast) {
                return $due;
            }
            if ($due > $monthLast) {
                return null;
            }
            $next = billingMonthlyChainedNextDueYmd($due, $dayStartYmd);
            if ($next === null || $next <= $due) {
                return null;
            }
            $due = $next;
            $guard++;
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

/** monthly + 非1号：起租首段不计入合同 N 个月；合同首笔应付 = 首段末日（链式）。 */
function billingMonthlyFirstContractDueAfterPartialFirst(string $dayStartYmd): ?string
{
    try {
        $start = new DateTimeImmutable($dayStartYmd);
        if ((int) $start->format('j') === 1) {
            return $dayStartYmd;
        }

        return billingMonthlyChainedPeriodEndYmd($dayStartYmd, $dayStartYmd);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Monthly 先付链式：收集应付日锚点（Y-m-d），规则与 process_accounting_inbox_api::inboxCollectMonthlyPrepaidBillingAnchors 一致。
 *
 * @param callable(string,int,int,string):bool $shouldCollect ($dueYmd, $year, $month, $dueYm)
 * @return string[]
 */
function billingCollectMonthlyChainedDueAnchors(
    string $startDate,
    string $today,
    string $createdYmd,
    ?string $exclusiveEnd,
    bool $resendRelax,
    bool $resendSinglePeriod,
    ?string $onlyAnchorYm,
    callable $shouldCollect
): array {
    if ($startDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        return [];
    }
    if (!($resendRelax || $today >= $createdYmd)) {
        return [];
    }

    $anchors = [];
    $due = $startDate;
    $guard = 0;
    $todayYm = (new DateTimeImmutable($today))->format('Y-n');

    try {
        $endCap = (new DateTimeImmutable($today))->modify('first day of this month');
        if ($resendRelax) {
            $startMonthFirst = (new DateTimeImmutable($startDate))->modify('first day of this month');
            if ($startMonthFirst > $endCap) {
                $endCap = $startMonthFirst;
            }
        }
        $endCapYmd = $endCap->format('Y-m-d');
    } catch (Throwable $e) {
        return [];
    }

    while ($guard < 520) {
        if (!$resendSinglePeriod && $exclusiveEnd !== null && $due >= $exclusiveEnd) {
            break;
        }

        try {
            $dueDt = new DateTimeImmutable($due);
        } catch (Throwable $e) {
            break;
        }

        $dueYm = $dueDt->format('Y-n');
        $dueMonthFirst = $dueDt->modify('first day of this month')->format('Y-m-d');

        if ($onlyAnchorYm !== null && $dueYm !== $onlyAnchorYm) {
            $next = billingMonthlyChainedNextDueYmd($due, $startDate);
            if ($next === null || $next <= $due) {
                break;
            }
            $due = $next;
            $guard++;
            continue;
        }

        if ($resendRelax && $dueMonthFirst > $endCapYmd) {
            break;
        }

        if (!$resendRelax && !$resendSinglePeriod && $dueYm !== $todayYm) {
            $next = billingMonthlyChainedNextDueYmd($due, $startDate);
            if ($next === null || $next <= $due) {
                break;
            }
            $due = $next;
            $guard++;
            continue;
        }

        if (!$resendRelax && $due < $createdYmd) {
            try {
                $createdYmOnly = (new DateTimeImmutable($createdYmd))->format('Y-n');
                if ($dueYm !== $createdYmOnly) {
                    $next = billingMonthlyChainedNextDueYmd($due, $startDate);
                    if ($next === null || $next <= $due) {
                        break;
                    }
                    $due = $next;
                    $guard++;
                    continue;
                }
            } catch (Throwable $e) {
                $next = billingMonthlyChainedNextDueYmd($due, $startDate);
                if ($next === null || $next <= $due) {
                    break;
                }
                $due = $next;
                $guard++;
                continue;
            }
        }

        $y = (int) $dueDt->format('Y');
        $mo = (int) $dueDt->format('n');

        if (($today >= $due || $resendRelax) && $shouldCollect($due, $y, $mo, $dueYm)) {
            $anchors[] = $due;
        }

        if (!$resendRelax && !$resendSinglePeriod) {
            break;
        }

        $next = billingMonthlyChainedNextDueYmd($due, $startDate);
        if ($next === null || $next <= $due) {
            break;
        }
        $due = $next;
        $guard++;
    }

    return $anchors;
}

/** 含首尾两日的天数；无效或 from>to 时返回 0 */
function billingInclusiveDaysBetween(string $fromYmd, string $toYmd): int
{
    $a = strtotime($fromYmd);
    $b = strtotime($toYmd);
    if ($a === false || $b === false || $fromYmd > $toYmd) {
        return 0;
    }

    return (int) round(($b - $a) / 86400) + 1;
}

/**
 * Monthly 对日对月：整期 [p0,p1] 对应一笔固定月价（cost/price/profit），仅按 [from,p1] 占整期的日历天数比例缩放。
 * 不可使用 prorateInclusiveDateRange：该函数按「每个自然月」切片乘整月价，跨两自然月的一期会得到比例之和 >1（如 1111→1125）。
 *
 * @return array{cost:string,price:string,profit:string,ratio:?string}
 */
function prorateMonthlyAnniversaryPeriodLinear(
    string $p0,
    string $p1,
    string $from,
    string $cost,
    string $price,
    string $profit
): array {
    if ($from > $p1) {
        return ['cost' => '0.00000000', 'price' => '0.00000000', 'profit' => '0.00000000', 'ratio' => null];
    }
    $adjFrom = $from < $p0 ? $p0 : $from;
    $fullD = billingInclusiveDaysBetween($p0, $p1);
    $useD = billingInclusiveDaysBetween($adjFrom, $p1);
    if ($fullD <= 0) {
        return ['cost' => '0.00000000', 'price' => '0.00000000', 'profit' => '0.00000000', 'ratio' => null];
    }
    $r = money_div((string) $useD, (string) $fullD, MONEY_CALC_SCALE);

    return [
        'cost' => money_mul($cost, $r, 2),
        'price' => money_mul($price, $r, 2),
        'profit' => money_mul($profit, $r, 2),
        'ratio' => $r,
    ];
}

/** Week：单期 [start, start+6]（含首尾 7 日）。 */
function weekPeriodEndInclusiveYmd(string $periodStartYmd): ?string
{
    try {
        return (new DateTimeImmutable($periodStartYmd))->modify('+6 days')->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** 下一期起点 = 上一期结束日次日（周期间不重叠，如 6/1–6/7 后接 6/8–6/14）。 */
function weekPeriodNextStartYmd(string $currentPeriodStartYmd): ?string
{
    try {
        return (new DateTimeImmutable($currentPeriodStartYmd))->modify('+7 days')->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** 非 Resend：仅当今天 >= 周期开始日，该周才进入 Accounting Due / 允许入账（例：6/1–6/7 在 6/1 出现）。 */
function weekPeriodIsReadyForAccounting(string $periodStartYmd, string $todayYmd, bool $resendRelax): bool
{
    if ($resendRelax) {
        return true;
    }
    return $todayYmd >= $periodStartYmd;
}

/** Day frequency：下一自然日 Y-m-d。 */
function dailyNextDayYmd(string $ymd): ?string
{
    try {
        return (new DateTimeImmutable($ymd))->modify('+1 day')->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** 指定自然月首日 Y-m-d。 */
function calendarMonthFirstYmd(int $year, int $month): string
{
    return sprintf('%04d-%02d-01', $year, max(1, min(12, $month)));
}

/** Day frequency：按天数累乘 cost / price / profit（单日全额 × N）。 */
function dailyAmountsForDayCount(string $cost, string $price, string $profit, int $days): array
{
    $d = (string) max(1, $days);
    return [
        'cost' => money_mul($cost, $d, 2),
        'price' => money_mul($price, $d, 2),
        'profit' => money_mul($profit, $d, 2),
    ];
}

/** 解析 daily consolidated billing_month 锚点 `start|end`（均为 Y-m-d）。 */
function dailyParseConsolidatedBillingRange(?string $billingMonth): ?array
{
    $raw = trim((string) $billingMonth);
    if ($raw === '' || strpos($raw, '|') === false) {
        return null;
    }
    [$start, $end] = array_map('trim', explode('|', $raw, 2));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        return null;
    }
    if ($start > $end) {
        return null;
    }
    return ['start' => $start, 'end' => $end];
}

/** 含首尾的自然日天数。 */
function dailyInclusiveDayCount(string $startYmd, string $endYmd): int
{
    try {
        $a = new DateTimeImmutable($startYmd);
        $b = new DateTimeImmutable($endYmd);
        if ($b < $a) {
            return 0;
        }
        return (int) $a->diff($b)->days + 1;
    } catch (Throwable $e) {
        return 0;
    }
}
