<?php
/**
 * 将 bank_process_accounting_resend_daily_guard 全量同步到 bank_process_accounting_resend_daily_guard_backup。
 * company_name 来自 company.company_id；bank_process_name 来自 bank_process.name（同 company 下 id 匹配）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_bank_process_accounting_resend_daily_guard_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM bank_process_accounting_resend_daily_guard_backup';
$sqlInsert = <<<'SQL'
INSERT INTO bank_process_accounting_resend_daily_guard_backup (
  id, company_id, company_name, bank_process_id, bank_process_name,
  resend_day_start, guard_date, created_at
)
SELECT
  rg.id,
  rg.company_id,
  COALESCE(co.company_id, '') AS company_name,
  rg.bank_process_id,
  COALESCE(bp.name, '') AS bank_process_name,
  rg.resend_day_start,
  rg.guard_date,
  rg.created_at
FROM bank_process_accounting_resend_daily_guard rg
LEFT JOIN company co ON co.id = rg.company_id
LEFT JOIN bank_process bp ON bp.id = rg.bank_process_id AND bp.company_id = rg.company_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_bank_process_accounting_resend_daily_guard_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_bank_process_accounting_resend_daily_guard_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
