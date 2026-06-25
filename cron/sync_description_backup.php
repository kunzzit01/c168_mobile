<?php
/**
 * 将 description 全量同步到 description_backup。
 * company_name 来自 company.company_id。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_description_backup.php
 */ 
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM description_backup';
$sqlInsert = <<<'SQL'
INSERT INTO description_backup (
  id, name, company_id, company_name
)
SELECT
  d.id,
  d.name,
  d.company_id,
  COALESCE(co.company_id, '') AS company_name
FROM description d
LEFT JOIN company co ON co.id = d.company_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_description_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_description_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
