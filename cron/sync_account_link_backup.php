<?php
/**
 * 将 account_link 全量同步到 account_link_backup。
 * account_name_1/2 来自 account.name；company_name 来自 company.company_id（本库 company 无独立 name，沿用业务显示字段）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_account_link_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM account_link_backup';
$sqlInsert = <<<'SQL'
INSERT INTO account_link_backup (
  id, account_id_1, account_name_1, account_id_2, account_name_2,
  company_id, company_name, link_type, source_account_id, created_at, updated_at
)
SELECT
  al.id,
  al.account_id_1,
  COALESCE(a1.name, '') AS account_name_1,
  al.account_id_2,
  COALESCE(a2.name, '') AS account_name_2,
  al.company_id,
  COALESCE(co.company_id, '') AS company_name,
  al.link_type,
  al.source_account_id,
  al.created_at,
  al.updated_at
FROM account_link al
LEFT JOIN account a1 ON a1.id = al.account_id_1
LEFT JOIN account a2 ON a2.id = al.account_id_2
LEFT JOIN company co ON co.id = al.company_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_account_link_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_account_link_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
