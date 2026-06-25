<?php
/**
 * Live ownership table schema helpers (sort_order column, cached probes).
 */

function &ownership_schema_probe_cache(): array
{
    static $cache = ['tables' => [], 'columns' => []];
    return $cache;
}

function ownership_table_exists(PDO $pdo, string $table): bool
{
    $cache = &ownership_schema_probe_cache();
    if (array_key_exists($table, $cache['tables'])) {
        return $cache['tables'][$table];
    }
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    $cache['tables'][$table] = $stmt->rowCount() > 0;
    return $cache['tables'][$table];
}

function ownership_column_exists(PDO $pdo, string $table, string $column): bool
{
    $cache = &ownership_schema_probe_cache();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache['columns'])) {
        return $cache['columns'][$key];
    }
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    $cache['columns'][$key] = $stmt->rowCount() > 0;
    return $cache['columns'][$key];
}

/** Once per PHP process: create group_ownership if missing (legacy installs). */
function ownership_ensure_group_ownership_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    if (ownership_table_exists($pdo, 'group_ownership')) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS group_ownership (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id VARCHAR(50) NOT NULL,
            owner_id INT NOT NULL,
            account_id INT NOT NULL,
            owner_type ENUM('owner','user','group') NOT NULL DEFAULT 'owner',
            percentage DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            partner_group_id VARCHAR(50) DEFAULT NULL,
            read_only TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $cache = &ownership_schema_probe_cache();
    $cache['tables']['group_ownership'] = true;
}

function ownership_ensure_sort_order_column(PDO $pdo, string $table): void
{
    static $ensured = [];
    if (isset($ensured[$table])) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    } catch (Exception $e) {
    }
    $ensured[$table] = true;
}

function ownership_owners_order_by_sql(string $alias = 'co'): string
{
    return "ORDER BY {$alias}.sort_order ASC, {$alias}.id ASC";
}
