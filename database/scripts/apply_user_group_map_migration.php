<?php
/**
 * Create user_group_map / account_group_map and backfill from user_company_map (scope_type=group).
 *
 * Prerequisite: groups table (run 20260528_dual_tenant_company_group.sql first on old DBs).
 *
 * Usage: php database/scripts/apply_user_group_map_migration.php
 */
require_once __DIR__ . '/../../includes/config.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable. Check includes/config.php and ensure MySQL is running.\n");
    exit(1);
}

$sqlFile = __DIR__ . '/../migrations/20260606_user_group_map.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Missing migration file: {$sqlFile}\n");
    exit(1);
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));

        return $stmt !== false && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (!tableExists($pdo, 'groups')) {
    fwrite(STDERR, "Missing table `groups`. Run database/migrations/20260528_dual_tenant_company_group.sql first.\n");
    exit(1);
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$ucmHasScope = tableExists($pdo, 'user_company_map')
    && columnExists($pdo, 'user_company_map', 'scope_type')
    && columnExists($pdo, 'user_company_map', 'scope_id');

$lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($sqlFile));
$buf = '';
$statements = [];
foreach ($lines as $line) {
    $trim = trim($line);
    if ($trim === '' || str_starts_with($trim, '--')) {
        continue;
    }
    $buf .= $line . "\n";
    if (str_ends_with(rtrim($line), ';')) {
        $statements[] = trim($buf);
        $buf = '';
    }
}

$backfillCount = null;
foreach ($statements as $sql) {
    if ($sql === '') {
        continue;
    }
    try {
        if (stripos($sql, 'INSERT IGNORE INTO `user_group_map`') !== false) {
            if (!$ucmHasScope) {
                echo "SKIP: backfill (user_company_map.scope_type not present — run 20260528 first)\n";
                continue;
            }
            $backfillCount = (int) $pdo->exec($sql);
            echo "OK: backfill user_group_map (rows affected: {$backfillCount})\n";
            continue;
        }
        $pdo->exec($sql);
        echo 'OK: ' . strtok($sql, "\n") . "\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (
            str_contains($msg, 'Duplicate key name')
            || str_contains($msg, 'already exists')
            || str_contains($msg, 'Duplicate foreign key constraint name')
        ) {
            echo 'SKIP (already applied): ' . strtok($sql, "\n") . "\n";
            continue;
        }
        throw $e;
    }
}

$ugm = tableExists($pdo, 'user_group_map') ? 'yes' : 'no';
$agm = tableExists($pdo, 'account_group_map') ? 'yes' : 'no';
$ugmRows = 0;
if ($ugm === 'yes') {
    $ugmRows = (int) $pdo->query('SELECT COUNT(*) FROM user_group_map')->fetchColumn();
}

echo "Done. user_group_map={$ugm} ({$ugmRows} rows), account_group_map={$agm}.\n";
