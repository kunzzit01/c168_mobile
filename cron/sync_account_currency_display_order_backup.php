<?php
/**
 * 将 account_currency_display_order 全量同步到 account_currency_display_order_backup。
 * account_name 来自 account.name。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_account_currency_display_order_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM account_currency_display_order_backup';
$sqlInsert = <<<'SQL'
INSERT INTO account_currency_display_order_backup (
  id, account_id, account_name, currency_order, updated_at
)
SELECT
  ac.id,
  ac.account_id,
  COALESCE(a.name, '') AS account_name,
  ac.currency_order,
  ac.updated_at
FROM account_currency_display_order ac
LEFT JOIN account a ON a.id = ac.account_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_account_currency_display_order_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_account_currency_display_order_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
