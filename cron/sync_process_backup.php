<?php
/**
 * 将 process 全量同步到 process_backup。
 * company_name：按 company 表实际列 — COALESCE(name, company_name, company_id) 中存在的列；description_name：description.name；
 * created_name：与 processlist_api 一致 — COALESCE(user.login_id, owner.owner_code)。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_process_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'process'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_process_backup: skip, process missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'process_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_process_backup: FAIL process_backup missing (run database/create_process_backup.sql)\n");
    exit(1);
}

// currency 表在部分环境列名可能不同；无 code 时回退（需已 JOIN 别名 c）
$currencyNameExpr = "''";
try {
    $curFields = $pdo->query('SHOW COLUMNS FROM currency')->fetchAll(PDO::FETCH_COLUMN, 0);
    $curSet = array_fill_keys($curFields, true);
    if (isset($curSet['code'])) {
        $currencyNameExpr = 'COALESCE(c.code, \'\')';
    } elseif (isset($curSet['currency_code'])) {
        $currencyNameExpr = 'COALESCE(c.currency_code, \'\')';
    } elseif (isset($curSet['name'])) {
        $currencyNameExpr = 'COALESCE(c.name, \'\')';
    }
} catch (Throwable $e) {
    $currencyNameExpr = "''";
}

$companyNameExpr = "''";
try {
    $coFields = $pdo->query('SHOW COLUMNS FROM company')->fetchAll(PDO::FETCH_COLUMN, 0);
    $coSet = array_fill_keys($coFields, true);
    $coParts = [];
    if (isset($coSet['name'])) {
        $coParts[] = 'co.name';
    }
    if (isset($coSet['company_name'])) {
        $coParts[] = 'co.company_name';
    }
    if (isset($coSet['company_id'])) {
        $coParts[] = 'co.company_id';
    }
    if ($coParts !== []) {
        $companyNameExpr = 'COALESCE(' . implode(', ', $coParts) . ", '')";
    }
} catch (Throwable $e) {
    $companyNameExpr = "''";
}

$sqlDelete = 'DELETE FROM process_backup';
$sqlInsert = <<<SQL
INSERT INTO process_backup (
  id, process_id, description_id, description_name, currency_id,
  currency_name, remove_word, replace_word_from, replace_word_to, remark, status,
  dts_modified, modified_by, modified_name, modified_by_type, modified_by_owner_id,
  dts_created, created_by, created_by_type, created_by_owner_id,
  created_name, company_id, company_name, sync_source_process_id
)
SELECT
  p.id,
  p.process_id,
  p.description_id,
  COALESCE(d.name, '') AS description_name,
  p.currency_id,
  {$currencyNameExpr} AS currency_name,
  p.remove_word,
  p.replace_word_from,
  p.replace_word_to,
  p.remark,
  p.status,
  p.dts_modified,
  p.modified_by,
  COALESCE(u_modified.login_id, o_modified.owner_code, '') AS modified_name,
  p.modified_by_type,
  p.modified_by_owner_id,
  p.dts_created,
  p.created_by,
  p.created_by_type,
  p.created_by_owner_id,
  COALESCE(u_created.login_id, o_created.owner_code, '') AS created_name,
  p.company_id,
  {$companyNameExpr} AS company_name,
  p.sync_source_process_id
FROM `process` p
LEFT JOIN description d ON d.id = p.description_id
LEFT JOIN currency c ON c.id = p.currency_id
LEFT JOIN company co ON co.id = p.company_id
LEFT JOIN `user` u_created ON p.created_by = u_created.id
LEFT JOIN owner o_created ON p.created_by_owner_id = o_created.id
LEFT JOIN `user` u_modified ON p.modified_by = u_modified.id
  AND (p.modified_by_type IS NULL OR p.modified_by_type = 'user')
LEFT JOIN owner o_modified ON p.modified_by_owner_id = o_modified.id
  AND p.modified_by_type = 'owner'
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_process_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_process_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
