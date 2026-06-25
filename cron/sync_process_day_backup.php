<?php
/**
 * 将 process_day 全量同步到 process_day_backup。
 * process_name：process.process_id（通过 process.id = process_day.process_id）；
 * day_name：day.day_name（通过 day.id = process_day.day_id）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_process_day_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'process_day'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_process_day_backup: skip, process_day missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'process_day_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_process_day_backup: FAIL process_day_backup missing (run database/create_process_day_backup.sql)\n");
    exit(1);
}

$sqlDelete = 'DELETE FROM process_day_backup';
$sqlInsert = <<<'SQL'
INSERT INTO process_day_backup (
  id, process_id, day_id, process_name, day_name
)
SELECT
  pd.id,
  pd.process_id,
  pd.day_id,
  COALESCE(p.process_id, '') AS process_name,
  COALESCE(dy.day_name, '') AS day_name
FROM process_day pd
LEFT JOIN `process` p ON p.id = pd.process_id
LEFT JOIN day dy ON dy.id = pd.day_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_process_day_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_process_day_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
