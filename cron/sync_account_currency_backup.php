<?php
/**
 * 将 account_currency 全量同步到 account_currency_backup。
 * account_name 来自 account.name；currency_name 来自 currency.code（本库 currency 表使用 code 字段）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_account_currency_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM account_currency_backup';
$sqlInsert = <<<'SQL'
INSERT INTO account_currency_backup (
  id, account_id, account_name, currency_id, currency_name, created_at, updated_at
)
SELECT
  ac.id,
  ac.account_id,
  COALESCE(a.name, '') AS account_name,
  ac.currency_id,
  COALESCE(c.code, '') AS currency_name,
  ac.created_at,
  ac.updated_at
FROM account_currency ac
LEFT JOIN account a ON a.id = ac.account_id
LEFT JOIN currency c ON c.id = ac.currency_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_account_currency_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_account_currency_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
