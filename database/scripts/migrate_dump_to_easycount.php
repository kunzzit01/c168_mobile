<?php
/**
 * Copy data from backup dump (imported into c168site_src) into easycount,
 * keeping easycount table definitions (easycount_fresh_install.sql).
 *
 * Usage:
 *   php database/scripts/migrate_dump_to_easycount.php [path-to-dump.sql]
 *
 * Default dump path: Desktop backup file (see $defaultDump below).
 */

declare(strict_types=1);

$srcDb = 'c168site_src';
$dstDb = 'easycount';
$skipImport = in_array('--skip-import', $argv, true);

$root = dirname(__DIR__, 2);
require $root . '/includes/config.php';

if (!$pdo) {
    fwrite(STDERR, "Cannot connect using includes/config.php + config.local.php\n");
    exit(1);
}

$admin = new PDO("mysql:host={$host};charset=utf8mb4", $dbuser, $dbpass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

out('=== Migrate backup data -> easycount (schema unchanged) ===');

// 1) Ensure temp source DB exists (import dump separately if missing)
if (!$skipImport) {
    $exists = (int) $admin->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $admin->quote($srcDb)
    )->fetchColumn();
    if ($exists === 0) {
        fwrite(STDERR, "Source DB `{$srcDb}` not found. Run first:\n");
        fwrite(STDERR, "  mysql -u root -e \"CREATE DATABASE {$srcDb} ...\"\n");
        fwrite(STDERR, "  mysql -u root {$srcDb} < path/to/dump.sql\n");
        fwrite(STDERR, "Then: php migrate_dump_to_easycount.php --skip-import\n");
        exit(1);
    }
    out("Using existing source database: {$srcDb}");
} else {
    $exists = (int) $admin->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $admin->quote($srcDb)
    )->fetchColumn();
    if ($exists === 0) {
        fwrite(STDERR, "Source DB `{$srcDb}` does not exist.\n");
        exit(1);
    }
    out("Source database ready: {$srcDb}");
}

$src = new PDO("mysql:host={$host};dbname={$srcDb};charset=utf8mb4", $dbuser, $dbpass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$dst = new PDO("mysql:host={$host};dbname={$dstDb};charset=utf8mb4", $dbuser, $dbpass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function tableColumns(PDO $pdo, string $schema, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$schema, $table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function tableExists(PDO $pdo, string $schema, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $stmt->execute([$schema, $table]);
    return (bool) $stmt->fetchColumn();
}

$dstTables = $dst->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = " . $dst->quote($dstDb) . " AND TABLE_TYPE = 'BASE TABLE'
     ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

$dst->exec('SET FOREIGN_KEY_CHECKS = 0');
$dst->exec('SET UNIQUE_CHECKS = 0');
$dst->exec('SET AUTOCOMMIT = 0');

$stats = ['copied' => 0, 'skipped' => 0, 'rows' => 0];

foreach ($dstTables as $table) {
    if (!tableExists($src, $srcDb, $table)) {
        out("  skip (no source table): {$table}");
        $stats['skipped']++;
        continue;
    }

    $dstCols = tableColumns($dst, $dstDb, $table);
    $srcCols = tableColumns($src, $srcDb, $table);
    $srcColSet = array_flip($srcCols);

    $selectParts = [];
    foreach ($dstCols as $col) {
        if (isset($srcColSet[$col])) {
            $selectParts[] = '`' . str_replace('`', '``', $col) . '`';
        } else {
            $selectParts[] = 'NULL AS `' . str_replace('`', '``', $col) . '`';
        }
    }

    $dstColList = implode(', ', array_map(static fn ($c) => '`' . str_replace('`', '``', $c) . '`', $dstCols));
    $selectList = implode(', ', $selectParts);

    $dst->exec("TRUNCATE TABLE `{$table}`");
    $sql = "INSERT INTO `{$dstDb}`.`{$table}` ({$dstColList}) SELECT {$selectList} FROM `{$srcDb}`.`{$table}`";
    $dst->exec($sql);
    $count = (int) $dst->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $stats['copied']++;
    $stats['rows'] += $count;
    out("  copied {$table}: {$count} rows");
}

$dst->exec('COMMIT');
$dst->exec('SET FOREIGN_KEY_CHECKS = 1');
$dst->exec('SET UNIQUE_CHECKS = 1');

// Reset AUTO_INCREMENT to max(id)+1 for tables with data
foreach ($dstTables as $table) {
    $dstCols = tableColumns($dst, $dstDb, $table);
    if (!in_array('id', $dstCols, true)) {
        continue;
    }
    $max = $dst->query("SELECT COALESCE(MAX(`id`), 0) FROM `{$table}`")->fetchColumn();
    if ((int) $max > 0) {
        $next = (int) $max + 1;
        $dst->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = {$next}");
    }
}

$admin->exec("DROP DATABASE IF EXISTS `{$srcDb}`");
out('Dropped temp database: ' . $srcDb);

out('--- Summary ---');
out('Tables copied: ' . $stats['copied']);
out('Tables skipped (not in dump): ' . $stats['skipped']);
out('Total rows inserted: ' . $stats['rows']);

// Quick sanity
$owners = (int) $dst->query('SELECT COUNT(*) FROM owner')->fetchColumn();
$companies = (int) $dst->query('SELECT COUNT(*) FROM company')->fetchColumn();
$groups = (int) $dst->query('SELECT COUNT(*) FROM `groups`')->fetchColumn();
out("Sanity: owner={$owners}, company={$companies}, groups={$groups}");
out('Done.');
