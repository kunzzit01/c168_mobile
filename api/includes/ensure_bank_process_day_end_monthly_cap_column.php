<?php
/**
 * Idempotent: add bank_process.day_end_monthly_cap_enabled when missing.
 * Called from API entrypoints; requires DB user with ALTER on bank_process.
 */
function ensureBankProcessDayEndMonthlyCapEnabledColumn(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM bank_process LIKE ?');
        $stmt->execute(['day_end_monthly_cap_enabled']);
        if ($stmt && $stmt->rowCount() > 0) {
            return;
        }
    } catch (Throwable $e) {
        error_log('ensureBankProcessDayEndMonthlyCapEnabledColumn read: ' . $e->getMessage());
        return;
    }
    try {
        $pdo->exec('ALTER TABLE bank_process ADD COLUMN day_end_monthly_cap_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER day_end');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE bank_process ADD COLUMN day_end_monthly_cap_enabled TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e2) {
            error_log('ensureBankProcessDayEndMonthlyCapEnabledColumn alter: ' . $e2->getMessage());
            return;
        }
    }
    if (isset($GLOBALS['__bank_process_column_exists_cache']) && is_array($GLOBALS['__bank_process_column_exists_cache'])) {
        unset($GLOBALS['__bank_process_column_exists_cache']['day_end_monthly_cap_enabled']);
    }
}
