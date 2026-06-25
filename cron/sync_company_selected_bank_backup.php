<?php
/**
 * 将 company_selected_banks 全量同步到 company_selected_bank_backup（与源表列一致）。
 * 主键须为 (company_id, country, bank)；若误为仅 company_id 会先尝试自动修复。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_company_selected_bank_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

$srcTable = null;
if ($pdo->query("SHOW TABLES LIKE 'company_selected_banks'")->rowCount() > 0) {
    $srcTable = 'company_selected_banks';
} elseif ($pdo->query("SHOW TABLES LIKE 'company_selected_bank'")->rowCount() > 0) {
    $srcTable = 'company_selected_bank';
}
if ($srcTable === null) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_selected_bank_backup: skip, source table missing\n");
    exit(0);
}

if ($pdo->query("SHOW TABLES LIKE 'company_selected_bank_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_company_selected_bank_backup: FAIL company_selected_bank_backup missing\n");
    exit(1);
}

$pkRows = $pdo->query("SHOW INDEX FROM company_selected_bank_backup WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
usort($pkRows, static function ($a, $b) {
    return ((int) ($a['Seq_in_index'] ?? 0)) <=> ((int) ($b['Seq_in_index'] ?? 0));
});
$pkCols = array_values(array_filter(array_map(static function ($r) {
    return $r['Column_name'] ?? null;
}, $pkRows)));
if ($pkCols === ['company_id']) {
    try {
        $pdo->exec('ALTER TABLE company_selected_bank_backup DROP PRIMARY KEY, ADD PRIMARY KEY (company_id, country, bank)');
        fwrite(STDERR, '[' . date('c') . "] sync_company_selected_bank_backup: repaired PRIMARY KEY -> (company_id, country, bank)\n");
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] sync_company_selected_bank_backup: FAIL fix PK — ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$bakCols = [];
foreach ($pdo->query('SHOW COLUMNS FROM company_selected_bank_backup') as $row) {
    $bakCols[$row['Field']] = true;
}

$sqlDelete = 'DELETE FROM company_selected_bank_backup';
if (isset($bakCols['company_name'])) {
    $sqlInsert = "
INSERT INTO company_selected_bank_backup (
  company_id, company_name, country, bank, sort_order
)
SELECT
  cs.company_id,
  cs.country,
  cs.bank,
  cs.sort_order
FROM `{$srcTable}` cs
";
} else {
    $sqlInsert = "
INSERT INTO company_selected_bank_backup (
  company_id, country, bank, sort_order
)
SELECT
  cs.company_id,
  cs.country,
  cs.bank,
  cs.sort_order
FROM `{$srcTable}` cs
";
}

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_company_selected_bank_backup: OK, src={$srcTable}, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_company_selected_bank_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
