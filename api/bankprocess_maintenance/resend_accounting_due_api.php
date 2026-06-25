<?php
/**
 * Bank Process List：Resend — 清除已入账标记，使 Process 可再次进入 Accounting Due（入账规则不变）。
 * 成功后置 accounting_resend_relax_created_floor，使 Inbox 在「旧数据不拿」上与日常新建流程区分（见 maintenance_accounting_resend_lib::bmp_inboxEffectiveCreatedYmd）。
 * 请求体中的 day_start / day_end / day_start_frequency 仅用于本次按哪一账期清除标记，不 UPDATE 到 bank_process（与 Edit Process 分离）。
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/maintenance_accounting_resend_lib.php';

/** 与 processlist / 前端 isBankInactiveLike：Official、E-INVOICE、Block 不可 Resend（这些在 DB 里常为 status=active） */

/** @return string|null Y-m-d */
function bank_resend_anchor_ymd_from_raw(?string $raw): ?string
{
    if ($raw === null || trim((string) $raw) === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $raw), $m)) {
        return null;
    }
    return $m[1] . '-' . $m[2] . '-' . $m[3];
}

/** @return string|null Y-m-d（优先 d/m/Y，其次 yyyy-mm-dd） */
function bank_resend_parse_ymd_from_any_raw(?string $raw): ?string
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
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    return null;
}

function bank_resend_blocking_issue_flag_from_row(array $bpRow): ?string
{
    $combined = '';
    if (isset($bpRow['flag']) && trim((string) $bpRow['flag']) !== '') {
        $combined = trim((string) $bpRow['flag']);
    } elseif (isset($bpRow['issue_flag']) && trim((string) $bpRow['issue_flag']) !== '') {
        $combined = trim((string) $bpRow['issue_flag']);
    }
    $normalized = strtolower(str_replace([' ', '-'], '_', $combined));
    if (in_array($normalized, ['official', 'e_invoice', 'block'], true)) {
        return $normalized;
    }
    return null;
}

/** @return string|null Y-m-d（支持 yyyy-mm-dd / dd/mm/yyyy） */
function bank_resend_parse_ymd_from_any_raw_or_dmy(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    return null;
}

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

function bank_resend_isLockedToday(PDO $pdo, int $companyId, int $bankProcessId, string $dayStartYmd): bool
{
    return bmp_accountingResendIsLockedToday($pdo, $companyId, $bankProcessId, $dayStartYmd);
}

/** Due 已 Delete/Skip 但 open anchor 仍占位时，Resend 前自动清掉陈旧锚点。 */
function bank_resend_reconcileStaleOpenAnchor(PDO $pdo, int $companyId, int $processId, string $anchorYmd): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchorYmd)) {
        return;
    }
    bmp_ensureBankProcessAccountingResendOpenAnchorsColumn($pdo);
    if (!bmp_resendOpenAnchorAlreadyExists($pdo, $processId, $companyId, $anchorYmd)) {
        return;
    }
    bmp_ensureAccountingDueDismissedTable($pdo);
    $handled = bmp_hasMonthlyPostedOrSkippedForDueYmd($pdo, $companyId, $processId, $anchorYmd);
    if (!$handled) {
        foreach (['resend_monthly_reopen', 'monthly', 'weekly', 'daily'] as $pt) {
            if (bmp_isAccountingDueSoftDismissed($pdo, $companyId, $processId, $pt, $anchorYmd)) {
                $handled = true;
                break;
            }
        }
    }
    if ($handled) {
        bmp_maybeClearResendRelaxAfterAnchorHandled($pdo, $processId, $companyId, $anchorYmd);
    }
}

/** @return string|null */
function bank_resend_normalizeOptionalYmd($value): ?string
{
    if ($value === null) {
        return null;
    }
    $v = trim((string) $value);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        throw new Exception('日期格式无效（需 YYYY-MM-DD）');
    }
    return $v;
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    $company_id = (int) $_SESSION['company_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('无效的请求数据');
    }
    $bankProcessId = isset($payload['bank_process_id']) ? (int) $payload['bank_process_id'] : 0;
    if ($bankProcessId <= 0) {
        throw new Exception('无效的 Process ID');
    }

    $mode = isset($payload['mode']) ? trim((string) $payload['mode']) : '';
    if ($mode === 'check_daystart_lock') {
        $dayStartYmd = bank_resend_parse_ymd_from_any_raw_or_dmy($payload['day_start'] ?? null);
        if ($dayStartYmd === null) {
            jsonResponse(true, '', [
                'locked' => false,
                'day_start' => null,
            ]);
            return;
        }
        bmp_ensureAccountingResendDailyGuardTable($pdo);
        bmp_pruneStaleAccountingResendDailyGuardsForProcess($pdo, $company_id, $bankProcessId);
        $locked = bank_resend_isLockedToday($pdo, $company_id, $bankProcessId, $dayStartYmd);
        bmp_ensureBankProcessAccountingResendOpenAnchorsColumn($pdo);
        bank_resend_reconcileStaleOpenAnchor($pdo, $company_id, $bankProcessId, $dayStartYmd);
        $duplicateOpen = bmp_resendOpenAnchorAlreadyExists($pdo, $bankProcessId, $company_id, $dayStartYmd);
        jsonResponse(true, '', [
            'locked' => $locked,
            'duplicate_open_anchor' => $duplicateOpen,
            'day_start' => $dayStartYmd,
        ]);
        return;
    }

    $scheduleFromClient = array_key_exists('day_start', $payload)
        || array_key_exists('day_end', $payload)
        || array_key_exists('day_start_frequency', $payload);
    $newDayStart = null;
    $newDayEnd = null;
    $newFrequency = '1st_of_every_month';
    if ($scheduleFromClient) {
        $rawDayStart = $payload['day_start'] ?? null;
        if ($rawDayStart === null || trim((string) $rawDayStart) === '') {
            $newDayStart = null;
        } else {
            $newDayStart = bank_resend_parse_ymd_from_any_raw_or_dmy($rawDayStart);
            if ($newDayStart === null) {
                throw new Exception('日期格式无效（需 YYYY-MM-DD 或 DD/MM/YYYY）');
            }
        }
        $rawDayEnd = $payload['day_end'] ?? null;
        if ($rawDayEnd === null || trim((string) $rawDayEnd) === '') {
            $newDayEnd = null;
        } else {
            $newDayEnd = bank_resend_parse_ymd_from_any_raw_or_dmy($rawDayEnd);
            if ($newDayEnd === null) {
                throw new Exception('日期格式无效（需 YYYY-MM-DD 或 DD/MM/YYYY）');
            }
        }
        $newFrequency = trim((string) ($payload['day_start_frequency'] ?? '1st_of_every_month'));
        if (!in_array($newFrequency, ['1st_of_every_month', 'monthly', 'week', 'day', 'once'], true)) {
            $newFrequency = '1st_of_every_month';
        }
        if ($newFrequency === 'once' || $newFrequency === 'week' || $newFrequency === 'day' || $newFrequency === 'monthly') {
            $newDayEnd = null;
        }
        if ($newDayStart !== null && $newDayEnd !== null && $newDayEnd < $newDayStart) {
            throw new Exception('Day end 不能早于 Day start');
        }
        // Resend 弹窗允许与 Edit 不同的组合（仅本次入账/Inbox），不再把「有 day_end + monthly」强制改为 1st。
    }

    $selectCols = ['id', 'status'];
    if (bmp_resend_tableHasColumn($pdo, 'bank_process', 'day_start')) {
        $selectCols[] = 'day_start';
    }
    if (bmp_resend_tableHasColumn($pdo, 'bank_process', 'issue_flag')) {
        $selectCols[] = 'issue_flag';
    }
    if (bmp_resend_tableHasColumn($pdo, 'bank_process', 'flag')) {
        $selectCols[] = 'flag';
    }
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $selectCols) . ' FROM bank_process WHERE id = ? AND company_id = ? LIMIT 1');
    $stmt->execute([$bankProcessId, $company_id]);
    $bpRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bpRow) {
        throw new Exception('未找到该 Bank Process 或无权操作');
    }
    if (strtolower(trim((string) ($bpRow['status'] ?? ''))) !== 'active') {
        throw new Exception('仅状态为 Active 的 Process 可使用 Resend');
    }
    if (bank_resend_blocking_issue_flag_from_row($bpRow) !== null) {
        throw new Exception('Official、E-INVOICE、Block 状态的 Process 不可使用 Resend');
    }

    bmp_ensureMaintenanceResendPendingTable($pdo);
    bmp_ensureBankProcessAccountingResendRelaxColumn($pdo);
    bmp_ensureBankProcessAccountingResendScheduleColumns($pdo);
    bmp_ensureBankProcessAccountingResendOpenAnchorsColumn($pdo);
    bmp_ensureAccountingDueDismissedTable($pdo);
    bmp_ensureAccountingResendDailyGuardTable($pdo);
    // 若 Maintenance 已删除对应账单，guard 可能已无交易凭证，需先清理否则会误拦。
    bmp_pruneStaleAccountingResendDailyGuardsForProcess($pdo, $company_id, $bankProcessId);

    $effectiveDayStartYmd = $scheduleFromClient && $newDayStart !== null
        ? $newDayStart
        : bank_resend_parse_ymd_from_any_raw(isset($bpRow['day_start']) ? (string) $bpRow['day_start'] : null);
    if ($effectiveDayStartYmd === null) {
        throw new Exception('无法识别 Day start，Resend 仅支持按 Day start 当月补单月记录。');
    }
    if (bank_resend_isLockedToday($pdo, $company_id, $bankProcessId, $effectiveDayStartYmd)) {
        throw new Exception('This process already has a transaction posted for this Day start today. Delete it from Bank Process Maintenance before resending.');
    }
    bmp_ensureBankProcessAccountingResendOpenAnchorsColumn($pdo);
    bank_resend_reconcileStaleOpenAnchor($pdo, $company_id, $bankProcessId, $effectiveDayStartYmd);
    if (bmp_resendOpenAnchorAlreadyExists($pdo, $bankProcessId, $company_id, $effectiveDayStartYmd)) {
        throw new Exception('This process already has an open Resend bill for this Day start in Accounting Due. Transaction or delete it before resending the same date.');
    }

    $pdo->beginTransaction();
    // 一次性入账 / 从 Due 移除：清除后 Process 可再次进入 Accounting Due
    $delOncePap = $pdo->prepare(
        "DELETE FROM process_accounting_posted
         WHERE company_id = ? AND process_id = ?
           AND period_type IN ('once_one_off','once_one_off_skipped')"
    );
    $delOncePap->execute([$company_id, $bankProcessId]);

    $targetYear = (int) substr($effectiveDayStartYmd, 0, 4);
    $targetMonth = (int) substr($effectiveDayStartYmd, 5, 2);
    // Monthly 弹窗同时填 day_start + day_end：清除该区间内各月 monthly 及 partial / tail / 合并期标记，便于生成单笔合并账单。
    // 1st_of_every_month 不走此分支，避免误删同月正常流程账单。
    if ($scheduleFromClient && $newDayStart !== null && $newDayEnd !== null && $newFrequency === 'monthly') {
        $startYmInt = (int) substr($newDayStart, 0, 4) * 100 + (int) substr($newDayStart, 5, 2);
        $endYmInt = (int) substr($newDayEnd, 0, 4) * 100 + (int) substr($newDayEnd, 5, 2);
        $delMonthPap = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND (
                 (
                   period_type IN (
                     'partial_first_month','partial_first_month_skipped',
                     'day_end_tail','day_end_tail_skipped',
                     'resend_consolidated_range','resend_consolidated_range_skipped'
                   )
                   AND posted_date BETWEEN ? AND ?
                 )
                 OR (
                   (YEAR(posted_date) * 100 + MONTH(posted_date)) BETWEEN ? AND ?
                   AND (period_type IN ('monthly','monthly_skipped') OR period_type IS NULL OR period_type = '')
                 )
               )"
        );
        $delMonthPap->execute([$company_id, $bankProcessId, $newDayStart, $newDayEnd, $startYmInt, $endYmInt]);
        // Due Delete 留下的 consolidated *_skipped（锚日可能不在区间内）须一并清除，否则 Inbox 仍视为已处理。
        $delAnchorConsolidated = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND period_type IN ('resend_consolidated_range','resend_consolidated_range_skipped')
               AND DATE(posted_date) = ?"
        );
        $delAnchorConsolidated->execute([$company_id, $bankProcessId, $effectiveDayStartYmd]);
        $removedPap = $delMonthPap->rowCount();
    } elseif ($scheduleFromClient && $newFrequency === 'week') {
        // Week：仅清除该周锚点的 weekly / weekly_skipped，避免按整月误删同月其他周。
        $delWeekPap = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND period_type IN ('weekly','weekly_skipped')
               AND DATE(posted_date) = ?"
        );
        $delWeekPap->execute([$company_id, $bankProcessId, $effectiveDayStartYmd]);
        $removedPap = $delWeekPap->rowCount();
    } elseif ($scheduleFromClient && $newFrequency === 'day') {
        // Day：仅清除该自然日的 daily / daily_skipped，避免按整月误删同月其他天。
        $delDayPap = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND period_type IN ('daily','daily_skipped')
               AND DATE(posted_date) = ?"
        );
        $delDayPap->execute([$company_id, $bankProcessId, $effectiveDayStartYmd]);
        $removedPap = $delDayPap->rowCount();
    } elseif ($scheduleFromClient && ($newFrequency === 'monthly' || $newFrequency === '1st_of_every_month')) {
        // Monthly / 1st_of_every_month：仅清除该应付日锚点的 monthly，避免误删同月正常流程账单。
        $deleteAnchorYmd = $effectiveDayStartYmd;
        if ($newFrequency === 'monthly') {
            $dueAnchorTry = bmp_monthlyDueYmdFromBillingAnchor($effectiveDayStartYmd, $effectiveDayStartYmd, 'monthly');
            if ($dueAnchorTry !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueAnchorTry)) {
                $deleteAnchorYmd = $dueAnchorTry;
            }
        }
        $delMonthlyPap = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND (period_type IN ('monthly','monthly_skipped') OR period_type IS NULL OR period_type = '')
               AND DATE(posted_date) = ?"
        );
        $delMonthlyPap->execute([$company_id, $bankProcessId, $deleteAnchorYmd]);
        $removedPap = $delMonthlyPap->rowCount();
        $delAnchorConsolidated = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND period_type IN ('resend_consolidated_range','resend_consolidated_range_skipped')
               AND DATE(posted_date) = ?"
        );
        $delAnchorConsolidated->execute([$company_id, $bankProcessId, $deleteAnchorYmd]);
        $removedPap += $delAnchorConsolidated->rowCount();
    } else {
        // 仅清除 day_start 所在月份的 posted 标记，避免一次 Resend 把整合同期都补回。
        // 兜底：
        // 1) 当月全部 posted 标记（含 maintenance 产生的 *_skipped）都清除，避免残留记录继续拦截 Accounting Due；
        // 2) partial_first_month(_skipped) 在 Inbox 中按「是否存在」判定，非按月份判定，因此也要一并清除。
        $delMonthPap = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND (
                   (YEAR(posted_date) = ? AND MONTH(posted_date) = ?)
                   OR period_type IN ('partial_first_month','partial_first_month_skipped')
               )"
        );
        $delMonthPap->execute([$company_id, $bankProcessId, $targetYear, $targetMonth]);
        $delAnchorConsolidated = $pdo->prepare(
            "DELETE FROM process_accounting_posted
             WHERE company_id = ? AND process_id = ?
               AND period_type IN ('resend_consolidated_range','resend_consolidated_range_skipped')
               AND DATE(posted_date) = ?"
        );
        $delAnchorConsolidated->execute([$company_id, $bankProcessId, $effectiveDayStartYmd]);
        $removedPap = $delMonthPap->rowCount();
    }

    $delPend = $pdo->prepare(
        'DELETE FROM bank_process_maintenance_resend_pending WHERE company_id = ? AND bank_process_id = ?'
    );
    $delPend->execute([$company_id, $bankProcessId]);

    // 客户端传入的 day_start / day_end / frequency 仅用于本次计算清除哪个月份的 posted 标记，不写入 bank_process（与 Edit Process 保存分离）。

    if (bmp_bankProcessHasResendScheduleColumns($pdo)) {
        if ($scheduleFromClient) {
            // Always persist the effective reopen anchor as schedule_day_start when the client opened the
            // Resend schedule panel. If day_start is left blank, $newDayStart is null but leaving the DB
            // column NULL would skip accounting_resend_single_period_from_schedule in merge and surface
            // every open monthly period (duplicate "bills") while relax is on — especially with Monthly frequency.
            $scheduleDayStartForDb = $newDayStart ?? $effectiveDayStartYmd;
            $flg = $pdo->prepare(
                'UPDATE bank_process SET accounting_resend_relax_created_floor = 1,
                    accounting_resend_schedule_day_start = ?,
                    accounting_resend_schedule_day_end = ?,
                    accounting_resend_schedule_frequency = ?,
                    dts_modified = NOW()
                 WHERE id = ? AND company_id = ?'
            );
            $flg->execute([
                $scheduleDayStartForDb,
                $newDayEnd,
                $newFrequency,
                $bankProcessId,
                $company_id,
            ]);
        } else {
            $flg = $pdo->prepare(
                'UPDATE bank_process SET accounting_resend_relax_created_floor = 1,
                    accounting_resend_schedule_day_start = NULL,
                    accounting_resend_schedule_day_end = NULL,
                    accounting_resend_schedule_frequency = NULL,
                    dts_modified = NOW()
                 WHERE id = ? AND company_id = ?'
            );
            $flg->execute([$bankProcessId, $company_id]);
        }
    } else {
        $flg = $pdo->prepare(
            'UPDATE bank_process SET accounting_resend_relax_created_floor = 1, dts_modified = NOW() WHERE id = ? AND company_id = ?'
        );
        $flg->execute([$bankProcessId, $company_id]);
    }
    // 单期 Resend（非 monthly 合并区间）：追加 open 锚点，多笔并存、同锚点拒绝重复。
    if ($scheduleFromClient
        && $effectiveDayStartYmd !== null
        && !($newFrequency === 'monthly' && $newDayStart !== null && $newDayEnd !== null)) {
        bmp_appendResendOpenAnchor($pdo, $bankProcessId, $company_id, $effectiveDayStartYmd, $newFrequency);
    }
    bmp_clearResendAnchorAccountingDueSideEffects($pdo, $bankProcessId, $company_id, $effectiveDayStartYmd);
    $pdo->commit();
    jsonResponse(true, 'Done: This process can appear in Accounting Due again.', [
        'bank_process_id' => $bankProcessId,
        'process_accounting_posted_removed' => $removedPap,
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}