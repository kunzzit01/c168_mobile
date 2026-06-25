<?php
/**
 * 将 user_company_permission 全量同步到 user_company_permission_backup。
 * user_name 来自 user.login_id；company_name 来自 company.company_id。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_user_company_permission_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$sqlDelete = 'DELETE FROM user_company_permission_backup';  
$sqlInsert = <<<'SQL'
INSERT INTO user_company_permission_backup (
  id, user_id, user_name, company_id, company_name, account_permissions, process_permissions, created_at, updated_at
)
SELECT
  ucp.id,
  ucp.user_id,
  COALESCE(u.login_id, o.owner_code, '') AS user_name,
  ucp.company_id,
  COALESCE(c.company_id, '') AS company_name,
  ucp.account_permissions,
  ucp.process_permissions,
  ucp.created_at,
  ucp.updated_at
FROM user_company_permissions ucp
LEFT JOIN user u ON u.id = ucp.user_id
LEFT JOIN company c ON c.id = ucp.company_id
LEFT JOIN owner o ON o.id = c.owner_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_user_company_permission_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_user_company_permission_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
