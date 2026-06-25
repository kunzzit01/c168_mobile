<?php
/**
 * Shared submitted_processes write + SQL helpers for Data Capture.
 */
require_once __DIR__ . '/data_capture_scope_common.php';

/**
 * Correlate submitted_processes row with data_captures row (NOT EXISTS dedupe).
 */
function dcSqlSubmittedProcessCorrelatesWithCapture(string $spAlias, string $dcAlias, PDO $pdo): string
{
    $sp = preg_replace('/[^a-zA-Z0-9_]/', '', $spAlias) ?: 'spx';
    $dc = preg_replace('/[^a-zA-Z0-9_]/', '', $dcAlias) ?: 'dc';
    $hasSpScope = tenant_table_has_scope_columns($pdo, 'submitted_processes');
    $hasDcScope = tenant_table_has_scope_columns($pdo, 'data_captures');

    if ($hasSpScope && $hasDcScope) {
        return " AND {$sp}.process_id = {$dc}.process_id
            AND {$sp}.scope_type = {$dc}.scope_type
            AND {$sp}.scope_id = {$dc}.scope_id ";
    }

    return " AND {$sp}.process_id = {$dc}.process_id AND {$sp}.company_id = {$dc}.company_id ";
}

/**
 * SQL fragment for matching submitted row to a capture_date (accounting day).
 */
function dcSqlSubmittedProcessCaptureDateMatch(string $spAlias, string $dcAlias, bool $hasCaptureDateColumn): string
{
    $sp = preg_replace('/[^a-zA-Z0-9_]/', '', $spAlias) ?: 'spx';
    $dc = preg_replace('/[^a-zA-Z0-9_]/', '', $dcAlias) ?: 'dc';
    if ($hasCaptureDateColumn) {
        return "DATE(COALESCE({$sp}.capture_date, {$sp}.date_submitted)) = DATE({$dc}.capture_date)";
    }

    return "DATE({$sp}.date_submitted) = DATE({$dc}.capture_date)";
}

/**
 * Load process permission ids for a user in a company (empty = see all).
 *
 * @return array<int, int|string>
 */
function dcLoadCaptureProcessPermissionIds(PDO $pdo, int $userId, int $companyId, string $userType): array
{
    if ($userType === 'owner') {
        return [];
    }

    try {
        $userStmt = $pdo->prepare(
            'SELECT process_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?'
        );
        $userStmt->execute([$userId, $companyId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || empty($user['process_permissions'])) {
            return [];
        }
        $processPermissions = json_decode($user['process_permissions'], true);
        if (!is_array($processPermissions) || empty($processPermissions)) {
            return [];
        }
        if (isset($processPermissions[0]) && is_array($processPermissions[0]) && isset($processPermissions[0]['id'])) {
            return array_values(array_filter(array_column($processPermissions, 'id'), 'is_numeric'));
        }

        return array_values(array_filter($processPermissions, 'is_numeric'));
    } catch (PDOException $e) {
        error_log('dcLoadCaptureProcessPermissionIds: ' . $e->getMessage());

        return [];
    }
}

/**
 * Persist submitted_processes after a successful Summary submit (company scope only).
 * Group payroll lists read data_captures directly; skip duplicate bookkeeping there.
 *
 * @return array{success: bool, submission_id?: int, already_exists?: bool, error?: string}
 */
function dcSaveSubmittedProcessRecord(
    PDO $pdo,
    int $userId,
    string $userType,
    int $processId,
    string $captureDate,
    array $captureScopeCtx,
    int $companyId,
    bool $captureScopeGroup
): array {
    if ($captureScopeGroup) {
        return ['success' => true, 'skipped' => true];
    }

    if ($processId <= 0) {
        return ['success' => false, 'error' => 'Invalid process_id'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $captureDate)) {
        return ['success' => false, 'error' => 'Invalid capture_date'];
    }

    dcEnsureSubmittedProcessesScopeColumns($pdo);

    $processStmt = $pdo->prepare('SELECT company_id FROM process WHERE id = ? LIMIT 1');
    $processStmt->execute([$processId]);
    $process = $processStmt->fetch(PDO::FETCH_ASSOC);
    if (!$process || !isset($process['company_id'])) {
        return ['success' => false, 'error' => '无法获取 process 的 company_id'];
    }

    $processCompanyId = (int) $process['company_id'];
    $expectedProcessCompanyId = !empty($captureScopeCtx)
        ? dcCaptureProcessCompanyId($captureScopeCtx)
        : $companyId;
    if (!$expectedProcessCompanyId) {
        return ['success' => false, 'error' => '缺少公司信息'];
    }
    if ($processCompanyId !== (int) $expectedProcessCompanyId) {
        return ['success' => false, 'error' => 'Process 不属于当前公司'];
    }

    dcAssertProcessIdInCaptureScope(
        $pdo,
        $processId,
        (int) $expectedProcessCompanyId,
        $captureScopeGroup
    );

    $scopeInsert = !empty($captureScopeCtx)
        ? dcCaptureScopeInsertValues($captureScopeCtx)
        : ['company_id' => $expectedProcessCompanyId, 'scope_type' => null, 'scope_id' => null];
    $storeCompanyId = (int) ($scopeInsert['company_id'] ?? $expectedProcessCompanyId);
    $useScopeColumns = !empty($captureScopeCtx['submitted_dual_tenant']);

    if ($useScopeColumns) {
        $checkStmt = $pdo->prepare('
            SELECT id FROM submitted_processes
            WHERE scope_type = ?
              AND scope_id = ?
              AND user_id = ?
              AND user_type = ?
              AND process_id = ?
              AND date_submitted = ?
            LIMIT 1
        ');
        $checkStmt->execute([
            $scopeInsert['scope_type'],
            $scopeInsert['scope_id'],
            $userId,
            $userType,
            $processId,
            $captureDate,
        ]);
    } else {
        $checkStmt = $pdo->prepare('
            SELECT id FROM submitted_processes
            WHERE company_id = ?
              AND user_id = ?
              AND user_type = ?
              AND process_id = ?
              AND date_submitted = ?
            LIMIT 1
        ');
        $checkStmt->execute([$storeCompanyId, $userId, $userType, $processId, $captureDate]);
    }

    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return [
            'success' => true,
            'submission_id' => (int) $existing['id'],
            'already_exists' => true,
        ];
    }

    try {
        if ($useScopeColumns) {
            $stmt = $pdo->prepare('
                INSERT INTO submitted_processes (company_id, scope_type, scope_id, user_id, user_type, process_id, date_submitted, capture_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $success = $stmt->execute([
                $storeCompanyId,
                $scopeInsert['scope_type'],
                $scopeInsert['scope_id'],
                $userId,
                $userType,
                $processId,
                $captureDate,
                $captureDate,
            ]);
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO submitted_processes (company_id, user_id, user_type, process_id, date_submitted, capture_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $success = $stmt->execute([$storeCompanyId, $userId, $userType, $processId, $captureDate, $captureDate]);
        }
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Unknown column') !== false && strpos($e->getMessage(), 'capture_date') !== false) {
            if ($useScopeColumns) {
                $stmt = $pdo->prepare('
                    INSERT INTO submitted_processes (company_id, scope_type, scope_id, user_id, user_type, process_id, date_submitted)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $success = $stmt->execute([
                    $storeCompanyId,
                    $scopeInsert['scope_type'],
                    $scopeInsert['scope_id'],
                    $userId,
                    $userType,
                    $processId,
                    $captureDate,
                ]);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO submitted_processes (company_id, user_id, user_type, process_id, date_submitted)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $success = $stmt->execute([$storeCompanyId, $userId, $userType, $processId, $captureDate]);
            }
        } else {
            error_log('dcSaveSubmittedProcessRecord: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    if (empty($success)) {
        return ['success' => false, 'error' => 'Failed to save submission'];
    }

    return [
        'success' => true,
        'submission_id' => (int) $pdo->lastInsertId(),
    ];
}
