<?php
/**
 * Backfill company_ownership_history / group_ownership_history for past month(s)
 * from current live tables (after data adjustment).
 *
 * Usage:
 *   php cron/backfill_ownership_history_month.php 2026-04
 *   php cron/backfill_ownership_history_month.php 2026-04 2026-05 2026-06
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/api/includes/ownership_history.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, '[' . date('c') . "] backfill_ownership_history_month: FAIL database unavailable\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php cron/backfill_ownership_history_month.php YYYY-MM [YYYY-MM ...]\n");
    exit(1);
}

$failed = false;
for ($i = 1; $i < $argc; $i++) {
    $monthKey = trim((string) $argv[$i]);
    if ($monthKey === '') {
        continue;
    }
    try {
        $pdo->beginTransaction();
        $result = ownership_history_backfill_month_from_live($pdo, $monthKey, null);
        $pdo->commit();
        fwrite(
            STDERR,
            '[' . date('c') . "] backfill_ownership_history_month: OK month={$monthKey}"
            . " effective_month={$result['effective_month']}"
            . " company_rows={$result['company_rows']}"
            . " group_rows={$result['group_rows']}\n"
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, '[' . date('c') . "] backfill_ownership_history_month: FAIL month={$monthKey} " . $e->getMessage() . "\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
