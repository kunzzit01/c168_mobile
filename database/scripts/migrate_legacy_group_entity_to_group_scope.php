<?php
/**
 * Repoint legacy group-entity company rows (e.g. company.id=312 AP) to group ledger scope.
 *
 * Run BEFORE deleting company rows where company_id IN ('AP','IG').
 * If those rows are already deleted, restore from backup into a staging DB and pass --source-db.
 *
 * Usage:
 *   php database/scripts/migrate_legacy_group_entity_to_group_scope.php --group=AP [--dry-run]
 *   php database/scripts/migrate_legacy_group_entity_to_group_scope.php --group=AP --legacy-company-id=312
 *   php database/scripts/migrate_legacy_group_entity_to_group_scope.php --group=AP --source-db=c168site_src
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/includes/config.php';
require $root . '/includes/group_scope_resolve.php';

$opts = getopt('', ['group:', 'legacy-company-id::', 'source-db::', 'dry-run']);
$groupCode = gc_normalize_group_code($opts['group'] ?? '');
$dryRun = isset($opts['dry-run']);
$legacyId = isset($opts['legacy-company-id']) ? (int) $opts['legacy-company-id'] : 0;
$sourceDb = trim((string) ($opts['source-db'] ?? ''));

if ($groupCode === '') {
    fwrite(STDERR, "Missing --group=AP|IG\n");
    exit(1);
}

$groupPk = gc_resolve_group_pk_by_code($pdo, $groupCode);
if ($groupPk <= 0) {
    fwrite(STDERR, "Group {$groupCode} not found in groups table.\n");
    exit(1);
}

if ($legacyId <= 0) {
    $legacyId = gc_resolve_legacy_group_entity_company_id($pdo, $groupCode);
}
if ($legacyId <= 0 && $sourceDb !== '') {
    $src = new PDO(
        "mysql:host={$host};dbname={$sourceDb};charset=utf8mb4",
        $dbuser,
        $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $st = $src->prepare('SELECT id FROM company WHERE UPPER(TRIM(company_id)) = ? LIMIT 1');
    $st->execute([$groupCode]);
    $legacyId = (int) ($st->fetchColumn() ?: 0);
    echo "Legacy company id from {$sourceDb}: {$legacyId}\n";
}

if ($legacyId <= 0) {
    fwrite(STDERR, "No legacy company row for {$groupCode}. Pass --legacy-company-id= or --source-db=.\n");
    exit(1);
}

$tables = [
    'currency' => 'company_id',
    'account_company' => 'company_id',
    'transactions' => 'company_id',
    'data_captures' => 'company_id',
    'data_capture_details' => 'company_id',
    'description' => 'company_id',
    'data_capture_templates' => 'company_id',
    'transaction_entry' => 'company_id',
    'user_company_map' => 'company_id',
];

echo ($dryRun ? '[DRY RUN] ' : '') . "Migrate company_id={$legacyId} -> scope_type=group, scope_id={$groupPk} ({$groupCode})\n";

foreach ($tables as $table => $col) {
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
    } catch (Throwable $e) {
        echo "  skip {$table}: not found\n";
        continue;
    }
    if (!$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'scope_type'")->fetch()) {
        echo "  skip {$table}: no scope_type column\n";
        continue;
    }
    $countSt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$col} = ?");
    $countSt->execute([$legacyId]);
    $n = (int) $countSt->fetchColumn();
    if ($n === 0) {
        echo "  {$table}: 0 rows\n";
        continue;
    }
    $sql = "
        UPDATE `{$table}`
        SET scope_type = 'group', scope_id = ?
        WHERE {$col} = ?
          AND (scope_id IS NULL OR scope_type = 'company')
    ";
    if (!$dryRun) {
        $upd = $pdo->prepare($sql);
        $upd->execute([$groupPk, $legacyId]);
    }
    echo "  {$table}: {$n} row(s) " . ($dryRun ? 'would update' : 'updated') . "\n";
}

echo "Done.\n";
