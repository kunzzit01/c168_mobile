<?php
/**
 * 将 company_selected_countries 全量同步到 company_selected_countries_backup。
 * 仅 company_id、country、sort_order（与源表一致）。
 * 若备份表误将主键建为仅 company_id，会先尝试改为 (company_id, country)。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_company_selected_countries_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'company_selected_countries'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_selected_countries_backup: skip, company_selected_countries missing\n");
    exit(0);
}

if ($pdo->query("SHOW TABLES LIKE 'company_selected_countries_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_selected_countries_backup: FAIL company_selected_countries_backup missing\n");
    exit(1);
}

$pkRows = $pdo->query("SHOW INDEX FROM company_selected_countries_backup WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
usort($pkRows, static function ($a, $b) {
    return ((int) ($a['Seq_in_index'] ?? 0)) <=> ((int) ($b['Seq_in_index'] ?? 0));
});
$pkCols = array_values(array_filter(array_map(static function ($r) {
    return $r['Column_name'] ?? null;
}, $pkRows)));
if ($pkCols === ['company_id']) {
    try {
        $pdo->exec('ALTER TABLE company_selected_countries_backup DROP PRIMARY KEY, ADD PRIMARY KEY (company_id, country)');
        fwrite(STDERR, '[' . date('c') . "] sync_company_selected_countries_backup: repaired PRIMARY KEY -> (company_id, country)\n");
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] sync_company_selected_countries_backup: FAIL fix PK — ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$sqlDelete = 'DELETE FROM company_selected_countries_backup';
$sqlInsert = <<<'SQL'
INSERT INTO company_selected_countries_backup (
  company_id, country, sort_order
)
SELECT
  cc.company_id,
  cc.country,
  cc.sort_order
FROM company_selected_countries cc
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_company_selected_countries_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_company_selected_countries_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
