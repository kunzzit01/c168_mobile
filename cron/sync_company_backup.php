<?php
/**
 * 将 company 全量同步到 company_backup。
 * owner_name：来自 owner.name，通过 owner.id = company.owner_id 关联。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_company_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM company_backup';
$sqlInsert = <<<'SQL'
INSERT INTO company_backup (
  id, company_id, owner_id, owner_name,
  created_by, created_at, expiration_date,
  permissions, fee_share_allocations, group_id
)
SELECT
  c.id,
  c.company_id,
  c.owner_id,
  COALESCE(o.name, '') AS owner_name,
  c.created_by,
  c.created_at,
  c.expiration_date,
  c.permissions,
  c.fee_share_allocations,
  c.group_id
FROM company c
LEFT JOIN owner o ON o.id = c.owner_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_company_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_company_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
