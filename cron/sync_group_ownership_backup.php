<?php
/**
 * 将 group_ownership 全量同步到 group_ownership_backup。
 * owner_name：COALESCE(owner.name, owner.owner_code)（go.owner_id）。
 * account_name：与 api/ownership/get_group_available_accounts_api.php 下拉展示对齐 —
 *   owner：COALESCE(TRIM(partner_group_id), 参与 owner 的 name, owner_code)；
 *   user：COALESCE(user.login_id, user.name)；
 *   group：非空 partner_group_id 时为 "Group: {id}"。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_group_ownership_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_group_ownership_backup: skip, group_ownership missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'group_ownership_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_group_ownership_backup: FAIL group_ownership_backup missing\n");
    exit(1);
}

$sqlDelete = 'DELETE FROM group_ownership_backup';
$sqlInsert = <<<'SQL'
INSERT INTO group_ownership_backup (
  id, group_id, owner_id, owner_name, account_id, account_name, owner_type,
  percentage, partner_group_id, read_only, created_at, updated_at
)
SELECT
  go.id,
  go.group_id,
  go.owner_id,
  COALESCE(ow.name, ow.owner_code, '') AS owner_name,
  go.account_id,
  CASE
    WHEN go.owner_type = 'owner' THEN COALESCE(
      NULLIF(TRIM(go.partner_group_id), ''),
      ao.name,
      ao.owner_code,
      ''
    )
    WHEN go.owner_type = 'user' THEN COALESCE(u.login_id, u.name, '')
    WHEN go.owner_type = 'group'
      AND go.partner_group_id IS NOT NULL
      AND TRIM(go.partner_group_id) <> ''
      THEN CONCAT(TRIM(go.partner_group_id))
    ELSE NULL
  END AS account_name,
  go.owner_type,
  go.percentage,
  go.partner_group_id,
  go.read_only,
  go.created_at,
  go.updated_at
FROM group_ownership go
LEFT JOIN owner ow ON ow.id = go.owner_id
LEFT JOIN owner ao ON ao.id = go.account_id AND go.owner_type = 'owner'
LEFT JOIN user u ON u.id = go.account_id AND go.owner_type = 'user'
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_group_ownership_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_group_ownership_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
