<?php
/**
 * Verify dual-tenant tables/columns required for group+company user assignment.
 * Usage: php database/scripts/check_dual_tenant_prereqs.php
 */
require_once __DIR__ . '/../../includes/config.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable. Check includes/config.php and ensure MySQL is running.\n");
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

$checks = [
    ['groups', 'table', true],
    ['group_company_map', 'table', true],
    ['user_group_map', 'table', true],
    ['account_group_map', 'table', false],
    ['user_company_map', 'column:scope_type', true],
    ['user_company_map', 'column:scope_id', true],
    ['currency', 'column:scope_type', true],
    ['account_company', 'column:scope_type', true],
];

$ok = true;
foreach ($checks as [$name, $kind, $required]) {
    $present = false;
    if ($kind === 'table') {
        $present = tableExists($pdo, $name);
    } elseif (str_starts_with($kind, 'column:')) {
        $col = substr($kind, 7);
        $present = tableExists($pdo, $name) && columnExists($pdo, $name, $col);
    }
    $label = $required ? 'REQUIRED' : 'OPTIONAL';
    $status = $present ? 'OK' : ($required ? 'MISSING' : 'absent');
    echo sprintf("[%s] %s %s — %s\n", $label, $kind, $name, $status);
    if ($required && !$present) {
        $ok = false;
    }
}

if (tableExists($pdo, 'user_group_map')) {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM user_group_map')->fetchColumn();
    echo "\nuser_group_map row count: {$n}\n";
}
if (tableExists($pdo, 'user_company_map') && columnExists($pdo, 'user_company_map', 'scope_type')) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM user_company_map WHERE scope_type = 'group'")->fetchColumn();
    echo "user_company_map scope_type=group row count: {$n}\n";
}

if (!$ok) {
    echo "\nAction: run 20260528_dual_tenant_company_group.sql then apply_user_group_map_migration.php\n";
    exit(1);
}

echo "\nAll required dual-tenant prerequisites present.\n";
