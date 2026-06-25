<?php
/**
 * Shared helpers for maintenance_marquee APIs.
 */

function maintenanceMarqueeResetPrefixColumnCache(): void
{
    $GLOBALS['_maintenance_marquee_has_prefix'] = null;
}

function maintenanceMarqueeHasPrefixColumn(PDO $pdo): bool
{
    if (array_key_exists('_maintenance_marquee_has_prefix', $GLOBALS)
        && $GLOBALS['_maintenance_marquee_has_prefix'] !== null) {
        return (bool) $GLOBALS['_maintenance_marquee_has_prefix'];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM maintenance_marquee LIKE 'prefix'");
        $hasPrefix = $stmt && $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $hasPrefix = false;
    }
    $GLOBALS['_maintenance_marquee_has_prefix'] = $hasPrefix;
    return $hasPrefix;
}

/**
 * Ensure prefix column exists (idempotent). Returns true when column is present after call.
 */
function ensureMaintenanceMarqueePrefixColumn(PDO $pdo): bool
{
    if (maintenanceMarqueeHasPrefixColumn($pdo)) {
        return true;
    }
    try {
        $pdo->exec(
            "ALTER TABLE `maintenance_marquee`
             ADD COLUMN `prefix` VARCHAR(100) NULL DEFAULT NULL
             COMMENT 'Marquee label prefix, e.g. System Maintenance:' AFTER `content`"
        );
        maintenanceMarqueeResetPrefixColumnCache();
        $GLOBALS['_maintenance_marquee_has_prefix'] = true;
        return true;
    } catch (PDOException $e) {
        error_log('maintenance_marquee prefix migration failed: ' . $e->getMessage());
        return false;
    }
}
