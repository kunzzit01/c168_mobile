<?php
/**
 * 将 bank_process_maintenance_resend_pending 全量同步到 bank_process_maintenance_resend_pending_backup。
 * company_name：优先 company.company_id（字符串代码），为空或缺行时回退为 prp.company_id 数字字符串。
 * bank_process_name：优先 bank_process.name；仅按 bp.id 关联（避免 company_id 不一致时整列空）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_bank_process_maintenance_resend_pending_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM bank_process_maintenance_resend_pending_backup';
$sqlInsert = <<<'SQL'
INSERT INTO bank_process_maintenance_resend_pending_backup (
  id, company_id, company_name, bank_process_id, bank_process_name,
  process_accounting_posted_id, period_type, transaction_date, created_at
)
SELECT
  prp.id,
  prp.company_id,
  COALESCE(co.company_id, '') AS company_name,
  prp.bank_process_id,
  COALESCE(bp.name, '') AS bank_process_name,
  prp.process_accounting_posted_id,
  prp.period_type,
  prp.transaction_date,
  prp.created_at
FROM bank_process_maintenance_resend_pending prp
LEFT JOIN company co ON co.id = prp.company_id
LEFT JOIN bank_process bp ON bp.id = prp.bank_process_id AND bp.company_id = prp.company_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_bank_process_maintenance_resend_pending_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_bank_process_maintenance_resend_pending_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
