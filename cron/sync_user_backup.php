<?php
/**
 * 将 user 全量同步到 user_backup（列结构需与 user 一致，见 database/create_user_backup.sql）。
 * 仅允许 CLI 执行，供 Hostinger Cron: php /path/to/cron/sync_user_backup.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once dirname(__DIR__) . '/includes/config.php';

if ($pdo->query("SHOW TABLES LIKE 'user'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_user_backup: skip, user missing\n");
    exit(0);
}
if ($pdo->query("SHOW TABLES LIKE 'user_backup'")->rowCount() < 1) {
    fwrite(STDERR, '[' . date('c') . "] sync_user_backup: FAIL user_backup missing (run database/create_user_backup.sql)\n");
    exit(1);
}

$sqlDelete = 'DELETE FROM user_backup';
$sqlInsert = 'INSERT INTO user_backup SELECT * FROM `user`';

try {
    $pdo->beginTransaction();
    $pdo->exec($sqlDelete);
    $inserted = $pdo->exec($sqlInsert);
    $pdo->commit();
    fwrite(STDERR, '[' . date('c') . "] sync_user_backup: OK, inserted={$inserted}\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '[' . date('c') . '] sync_user_backup: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
