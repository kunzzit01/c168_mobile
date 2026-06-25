<?php
/**
 * 将 account_company 全量同步到 account_company_backup（account_name 来自 account.name）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_account_company_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM account_company_backup';
$sqlInsert = <<<'SQL'
INSERT INTO account_company_backup (
  id, account_id, account_name, company_id, created_at, updated_at
)
SELECT
  ac.id,
  ac.account_id,
  COALESCE(a.name, '') AS account_name,
  ac.company_id,
  ac.created_at,
  ac.updated_at
FROM account_company ac
LEFT JOIN account a ON a.id = ac.account_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_account_company_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_account_company_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
