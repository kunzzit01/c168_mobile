<?php
/**
 * 将 account 全量同步到 account_backup（与 MySQL EVENT 等效的定时任务入口）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_account_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM account_backup';
$sqlInsert = <<<'SQL'
INSERT INTO account_backup (
  id, account_id, name, status, created_source,
  last_login, role, password, payment_alert,
  alert_day, alert_specific_date, alert_amount, remark
)
SELECT
  id, account_id, name, status, created_source,
  last_login, role, password, payment_alert,
  alert_day, alert_specific_date, alert_amount, remark
FROM account
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_account_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_account_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
