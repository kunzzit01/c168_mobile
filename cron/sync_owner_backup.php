<?php
/**
 * 将 owner 全量同步到 owner_backup。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_owner_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM owner_backup';
$sqlInsert = <<<'SQL'
INSERT INTO owner_backup (
  id, owner_code, name, email, password, secondary_password, status, created_by, created_at
)
SELECT
  o.id,
  o.owner_code,
  o.name,
  o.email,
  o.password,
  o.secondary_password,
  o.status,
  o.created_by,
  o.created_at
FROM owner o
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_owner_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_owner_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
