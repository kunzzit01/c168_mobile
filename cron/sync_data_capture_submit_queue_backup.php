<?php
/**
 * 将 data_capture_submit_queue 全量同步到 data_capture_submit_queue_backup。
 * company_name：company.company_id（co.id = q.company_id，与其它 *_backup 一致）。
 * capture_name：与维护页一致，COALESCE(description.name, process.process_id)；
 *   经 data_captures → process → description，且限定 dc / p 的 company_id 与队列行一致。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_data_capture_submit_queue_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'data_capture_submit_queue'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_data_capture_submit_queue_backup: skip, data_capture_submit_queue missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'data_capture_submit_queue_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_data_capture_submit_queue_backup: FAIL data_capture_submit_queue_backup missing\n");
    exit(1);
}

$sqlDelete = 'DELETE FROM data_capture_submit_queue_backup';
$sqlInsert = <<<'SQL'
INSERT INTO data_capture_submit_queue_backup (
  id, company_id, user_id, status, request_json, capture_id, rows_count,
  error_message, created_at, finished_at, company_name, capture_name
)
SELECT
  q.id,
  q.company_id,
  q.user_id,
  q.status,
  q.request_json,
  q.capture_id,
  q.rows_count,
  q.error_message,
  q.created_at,
  q.finished_at,
  COALESCE(co.company_id, '') AS company_name,
  CASE
    WHEN q.capture_id IS NULL THEN NULL
    ELSE COALESCE(d.name, p.process_id)
  END AS capture_name
FROM data_capture_submit_queue q
LEFT JOIN company co ON co.id = q.company_id
LEFT JOIN data_captures dc
  ON dc.id = q.capture_id AND dc.company_id = q.company_id
LEFT JOIN process p
  ON p.id = dc.process_id AND p.company_id = q.company_id
LEFT JOIN `description` d ON d.id = p.description_id
SQL;

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_data_capture_submit_queue_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_data_capture_submit_queue_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
