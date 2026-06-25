<?php
/**
 * 将 company_ownership 全量同步到 company_ownership_backup。
 * 除 company_name、account_name 外，其余列均为主表同一行的原值（与 company_ownership 一致）。
 * company_name：company.company_id（公司代码，与其它 backup cron 一致）。
 * account_name：与 api/ownership/get_owners_api.php 列表字段 account_name 一致（含 Group: 前缀与 COALESCE 顺序）。
 * 若主表缺少 entity_type / group_id / include_group / partner_group_id / read_only，则用与线表一致的默认值写入备份。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_company_ownership_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_ownership_backup: skip, company_ownership missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'company_ownership_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_ownership_backup: FAIL company_ownership_backup table missing\n");
    exit(1);
}

$bakCols = [];
foreach ($pdo->query('SHOW COLUMNS FROM company_ownership_backup') as $row) {
    $bakCols[$row['Field']] = true;
}
// 备份表列名与主表对齐：company_id / comapny_id；partner_group_id / partner_group
$colCompanyId = isset($bakCols['company_id']) ? 'company_id'
    : (isset($bakCols['comapny_id']) ? 'comapny_id' : null);
$colPartner = isset($bakCols['partner_group_id']) ? 'partner_group_id'
    : (isset($bakCols['partner_group']) ? 'partner_group' : null);
if ($colCompanyId === null) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_ownership_backup: FAIL backup needs company_id (or legacy comapny_id)\n");
    exit(1);
}
if ($colPartner === null) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_ownership_backup: FAIL backup needs partner_group_id (or legacy partner_group)\n");
    exit(1);
}

$srcCols = [];
foreach ($pdo->query('SHOW COLUMNS FROM company_ownership') as $row) {
    $srcCols[$row['Field']] = true;
}

$selEntityType = isset($srcCols['entity_type']) ? 'co.entity_type' : "'account'";
$selGroupId = isset($srcCols['group_id']) ? 'co.group_id' : 'NULL';
$selIncludeGroup = isset($srcCols['include_group']) ? 'co.include_group' : '1';
$selPartnerGroupId = isset($srcCols['partner_group_id']) ? 'co.partner_group_id' : 'NULL';
$selReadOnly = isset($srcCols['read_only']) ? 'co.read_only' : '1';

if (isset($srcCols['owner_type'])) {
    $selOwnerType = 'co.owner_type';
    $joinAccount = "a.id = co.account_id AND COALESCE(co.owner_type, 'account') = 'account'";
    $joinOwner = "o.id = co.account_id AND co.owner_type = 'owner'";
    $joinUser = "u.id = co.account_id AND co.owner_type = 'user'";
    $caseGroupWhen = "co.owner_type = 'group'";
} else {
    $selOwnerType = "'account'";
    $joinAccount = 'a.id = co.account_id';
    $joinOwner = '1 = 0';
    $joinUser = '1 = 0';
    $caseGroupWhen = '1 = 0';
}

$sqlDelete = 'DELETE FROM company_ownership_backup';
// 列顺序与主表一致：在 company_id、account_id 后插入两个派生列，其余与 company_ownership 相同
$sqlInsert = "
INSERT INTO company_ownership_backup (
  id, `{$colCompanyId}`, company_name, entity_type, account_id, account_name, group_id,
  owner_type, percentage, created_at, include_group, `{$colPartner}`, read_only
)
SELECT
  co.id,
  co.company_id,
  COALESCE(c.company_id, '') AS company_name,
  {$selEntityType},
  co.account_id,
  CASE
    WHEN {$caseGroupWhen} THEN CONCAT(COALESCE({$selPartnerGroupId}, ''))
    ELSE COALESCE({$selPartnerGroupId}, a.account_id, o.owner_code, u.login_id)
  END AS account_name,
  {$selGroupId},
  {$selOwnerType},
  co.percentage,
  co.created_at,
  {$selIncludeGroup},
  {$selPartnerGroupId},
  {$selReadOnly}
FROM company_ownership co
LEFT JOIN company c ON c.id = co.company_id
LEFT JOIN account a ON {$joinAccount}
LEFT JOIN owner o ON {$joinOwner}
LEFT JOIN user u ON {$joinUser}
";

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_company_ownership_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_company_ownership_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
