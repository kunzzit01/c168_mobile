<?php
/**
 * Recurring billing helpers: contract length (1+1 / 1+2 / 1+3 / "N MONTHS"),
 * monthly due-date list, and whether a calendar day is still inside the billing window.
 */

declare(strict_types=1);

/**
 * Total number of monthly billing periods in the current contract term.
 * Matches UI: 1+1 MONTH → 2 periods, 1+2 → 3, 1+3 → 4; "5 MONTHS" → 5.
 * Returns null if contract is empty or unrecognized → no cap (legacy behaviour).
 */
function getBillingTermMonthsFromContract(?string $contract): ?int
{
    if ($contract === null || trim($contract) === '') {
        return null;
    }
    $c = trim($contract);
    if (preg_match('/^1\+(\d+)$/i', $c, $m)) {
        return 1 + (int) $m[1];
    }
    if (preg_match('/^(\d+)\s*MONTHS?$/i', $c, $m)) {
        return max(1, (int) $m[1]);
    }
    return null;
}

/** First calendar day after the last billing period (exclusive). */
function billingContractExclusiveEndYmd(string $dayStartYmd, int $termMonths): ?string
{
    if ($termMonths < 1) {
        return null;
    }
    try {
        $start = new DateTimeImmutable($dayStartYmd);
        return $start->modify("+{$termMonths} months")->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** 1st-of-month + day_start 非1号：起租当月 partial 不计入合同 N 个月；exclusive = 次月1号 + N 月。1号起租与 billingContractExclusiveEndYmd 同。 */
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

/** monthly + 非1号：次月起首应付日（链式首段末日）+ N 月 exclusive（与 inbox / post 一致）。 */
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
        if (!function_exists('billingMonthlyFirstContractDueAfterPartialFirst')) {
            require_once __DIR__ . '/contract_billing_addon.php';
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

function contractExclusiveEndYmdForFrequency(string $startYmd, ?string $contract, ?string $frequency): ?string
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

/**
 * @return string[] Y-m-d chained monthly due dates for contract term (after partial first month)
 */
function generateMonthlyBillingDueDates(string $dayStartYmd, int $termMonths): array
{
    if ($termMonths < 1) {
        return [];
    }
    if (!function_exists('billingMonthlyFirstContractDueAfterPartialFirst')) {
        require_once __DIR__ . '/contract_billing_addon.php';
    }
    $firstDue = billingMonthlyFirstContractDueAfterPartialFirst($dayStartYmd);
    if ($firstDue === null) {
        return [];
    }
    $dates = [];
    $due = $firstDue;
    for ($i = 0; $i < $termMonths; $i++) {
        $dates[] = $due;
        $next = billingMonthlyChainedNextDueYmd($due, $dayStartYmd);
        if ($next === null || $next <= $due) {
            break;
        }
        $due = $next;
    }
    return $dates;
}

/**
 * Whether $todayYmd may still show Accounting Due for this process (contract + optional day_end).
 * day_end 为最后一天计入（可与 process_accounting_inbox_api 一致）；monthly / 1st 均用 contractExclusiveEndYmdForFrequency（非 1 号起租当月不计入 N）。
 */
function isWithinRecurringBillingWindow(
    string $todayYmd,
    ?string $dayStartYmd,
    ?string $contract,
    ?string $dayEndYmd,
    ?string $frequency = null
): bool {
    if ($dayStartYmd === null || $dayStartYmd === '' || strtotime($dayStartYmd) === false) {
        return true;
    }
    $start = date('Y-m-d', strtotime($dayStartYmd));
    if ($todayYmd < $start) {
        return false;
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

/** Week frequency：收费周期为 periodStart 起连续 7 天（含首尾共 7 日）。 */
function weekPeriodEndInclusiveYmd(string $periodStartYmd): ?string
{
    try {
        return (new DateTimeImmutable($periodStartYmd))->modify('+6 days')->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

/** 收费周期是否与指定自然月有日期重叠。 */
function weekPeriodOverlapsCalendarMonth(string $periodStartYmd, string $periodEndYmd, int $year, int $month): bool
{
    if ($month < 1 || $month > 12 || $year < 1970) {
        return false;
    }
    try {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
        return $periodStartYmd <= $monthEnd && $periodEndYmd >= $monthStart;
    } catch (Throwable $e) {
        return false;
    }
}

/** 该周收费周期（以 periodStart 为锚）是否已入账或已跳过。 */
function hasWeeklyPostedForPeriodStart(PDO $pdo, int $companyId, int $processId, string $periodStartYmd): bool
{
    try {
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
