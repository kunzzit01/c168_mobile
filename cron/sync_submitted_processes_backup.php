<?php
/**
 * 将 submitted_processes 全量同步到 submitted_processes_backup。
 * company_name：按 company 表实际列 — COALESCE(name, company_name, company_id) 中存在的列；
 * user_name：与 submitted_processes_api 一致 — COALESCE(user.login_id, owner.owner_code)；
 * process_name：process.process_id（process.id = submitted_processes.process_id）。
 * 若源表无 capture_date 列，则备份表中该列为 NULL。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_submitted_processes_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'submitted_processes'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_submitted_processes_backup: skip, submitted_processes missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'submitted_processes_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_submitted_processes_backup: FAIL submitted_processes_backup missing (run database/create_submitted_processes_backup.sql)\n");
    exit(1);
}

$hasCaptureDate = false;
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM submitted_processes LIKE 'capture_date'");
    $hasCaptureDate = $colStmt && $colStmt->rowCount() > 0;
} catch (Throwable $e) {
    $hasCaptureDate = false;
}

$captureSelect = $hasCaptureDate ? 'sp.capture_date' : 'NULL';

$companyNameExpr = "''";
try {
    $coFields = $pdo->query('SHOW COLUMNS FROM company')->fetchAll(PDO::FETCH_COLUMN, 0);
    $coSet = array_fill_keys($coFields, true);
    $coParts = [];
    if (isset($coSet['name'])) {
        $coParts[] = 'c.name';
    }
    if (isset($coSet['company_name'])) {
        $coParts[] = 'c.company_name';
    }
    if (isset($coSet['company_id'])) {
        $coParts[] = 'c.company_id';
    }
    if ($coParts !== []) {
        $companyNameExpr = 'COALESCE(' . implode(', ', $coParts) . ", '')";
    }
} catch (Throwable $e) {
    $companyNameExpr = "''";
}

$sqlDelete = 'DELETE FROM submitted_processes_backup';
$sqlInsert = <<<SQL
INSERT INTO submitted_processes_backup (
  id, company_id, company_name, user_id, user_name, user_type,
  process_id, process_name, date_submitted, capture_date, created_at
)
SELECT
  sp.id,
  sp.company_id,
  {$companyNameExpr} AS company_name,
  sp.user_id,
  COALESCE(u.login_id, o.owner_code, '') AS user_name,
  sp.user_type,
  sp.process_id,
  COALESCE(p.process_id, '') AS process_name,
  sp.date_submitted,
  {$captureSelect},
  sp.created_at
FROM submitted_processes sp
LEFT JOIN company c ON c.id = sp.company_id
LEFT JOIN `user` u ON sp.user_id = u.id AND sp.user_type = 'user'
LEFT JOIN owner o ON sp.user_id = o.id AND sp.user_type = 'owner'
LEFT JOIN `process` p ON p.id = sp.process_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_submitted_processes_backup: OK, inserted={$inserted}, capture_date_column=" . ($hasCaptureDate ? 'yes' : 'no') . "\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_submitted_processes_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
