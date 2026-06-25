<?php
/**
 * Seal last month's ownership history for companies/groups that were never snapshotted.
 * Live tables are unchanged; current month is not written.
 *
 * Suggested Hostinger cron (daily, e.g. 00:15): php /path/to/cron/ownership_history_seal_previous_month.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/api/includes/ownership_history.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, '[' . date('c') . "] ownership_history_seal_previous_month: FAIL database unavailable\n");
    exit(1);
}

$monthKey = ownership_history_previous_month_key();

try {
    $pdo->beginTransaction();
    $result = ownership_history_seal_previous_month_gaps_from_live($pdo, null);
    $pdo->commit();
    fwrite(
        STDERR,
        '[' . date('c') . "] ownership_history_seal_previous_month: OK month={$monthKey}"
        . " effective_month={$result['effective_month']}"
        . " company_rows={$result['company_rows']}"
        . " group_rows={$result['group_rows']}\n"
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] ownership_history_seal_previous_month: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
