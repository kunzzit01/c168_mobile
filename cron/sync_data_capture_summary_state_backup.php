<?php
/**
 * 将 data_capture_summary_state 全量同步到 data_capture_summary_state_backup。
 * company_name：company.company_id（co.id = s.company_id，与其它 *_backup 一致）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_data_capture_summary_state_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'data_capture_summary_state'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_data_capture_summary_state_backup: skip, data_capture_summary_state missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'data_capture_summary_state_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_data_capture_summary_state_backup: FAIL data_capture_summary_state_backup missing\n");
    exit(1);
}

$sqlDelete = 'DELETE FROM data_capture_summary_state_backup';
$sqlInsert = <<<'SQL'
INSERT INTO data_capture_summary_state_backup (
  id, company_id, company_name, process_key, state_json, updated_at
)
SELECT
  s.id,
  s.company_id,
  COALESCE(co.company_id, '') AS company_name,
  s.process_key,
  s.state_json,
  s.updated_at
FROM data_capture_summary_state s
LEFT JOIN company co ON co.id = s.company_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_data_capture_summary_state_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_data_capture_summary_state_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
