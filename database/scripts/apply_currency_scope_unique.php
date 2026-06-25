<?php
/**
 * Apply currency scope-aware unique index (fixes MYR-123 duplicate on group create).
 * Usage: php database/scripts/apply_currency_scope_unique.php
 */
require_once __DIR__ . '/../../includes/config.php';

$sqlFile = __DIR__ . '/../migrations/20260604_currency_scope_unique.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Missing migration file\n");
    exit(1);
}

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

foreach ($statements as $sql) {
    if ($sql === '') {
        continue;
    }
    try {
        $pdo->exec($sql);
        echo "OK: " . strtok($sql, "\n") . "\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, "Can't DROP") || str_contains($msg, "check that it exists")) {
            echo "SKIP (already dropped): unique_code_per_company\n";
            continue;
        }
        if (str_contains($msg, 'Duplicate key name') || str_contains($msg, 'already exists')) {
            echo "SKIP (already applied): uk_currency_scope_code\n";
            continue;
        }
        throw $e;
    }
}

echo "Done.\n";
