<?php
/**
 * 将 company_countries 全量同步到 company_countries_backup。
 * company_name：来自 company.company_id，通过 company.id = company_countries.company_id 关联。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_company_countries_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM company_countries_backup';
$sqlInsert = <<<'SQL'
INSERT INTO company_countries_backup (
  id, company_id, company_name, country, created_at
)
SELECT
  cc.id,
  cc.company_id,
  COALESCE(co.company_id, '') AS company_name,
  cc.country,
  cc.created_at
FROM company_countries cc
LEFT JOIN company co ON co.id = cc.company_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_company_countries_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_company_countries_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
