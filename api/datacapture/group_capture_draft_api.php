<?php
/**
 * Shared group-only Data Capture table drafts (SALARY / COMMISSION / BONUS — not PROFIT).
 * Stored in data_capture_draft with scope_type = 'group' (group_id + process_key + currency_id).
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/data_capture_scope_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    session_write_close();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function dcEnsureCaptureDraftTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS data_capture_draft (
                id INT NOT NULL AUTO_INCREMENT,
                scope_type ENUM('group', 'company') NOT NULL,
                group_id VARCHAR(50) NULL,
                company_id INT NULL,
                process_key VARCHAR(64) NOT NULL,
                currency_id INT NOT NULL,
                draft_json LONGTEXT NOT NULL,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_group_process_currency (group_id, process_key, currency_id),
                UNIQUE KEY uk_company_process_currency (company_id, process_key, currency_id),
                KEY idx_scope_type (scope_type),
                KEY idx_group_id (group_id),
                KEY idx_company_id (company_id),
                KEY idx_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        dcMigrateLegacyGroupCaptureDraftIfNeeded($pdo);
    } catch (Throwable $e) {
        error_log('dcEnsureCaptureDraftTable: ' . $e->getMessage());
    }
}

function dcMigrateLegacyGroupCaptureDraftIfNeeded(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;
    try {
        $legacy = $pdo->query("SHOW TABLES LIKE 'data_capture_group_draft'");
        if (!$legacy || !$legacy->fetch(PDO::FETCH_NUM)) {
            return;
        }
        $pdo->exec("
            INSERT INTO data_capture_draft
                (scope_type, group_id, company_id, process_key, currency_id, draft_json, updated_by, updated_at)
            SELECT
                'group',
                group_id,
                NULL,
                process_key,
                currency_id,
                draft_json,
                updated_by,
                updated_at
            FROM data_capture_group_draft
            ON DUPLICATE KEY UPDATE
                draft_json = VALUES(draft_json),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)
        ");
    } catch (Throwable $e) {
        error_log('dcMigrateLegacyGroupCaptureDraftIfNeeded: ' . $e->getMessage());
    }
}

function dcNormalizeGroupCaptureDraftGroupId(?string $groupId): string
{
    return strtoupper(trim((string) $groupId));
}

function dcNormalizeGroupCaptureDraftProcessKey(?string $processKey): string
{
    $key = strtolower(trim((string) $processKey));
    if (!dcIsGroupPayrollDraftProcessCode($key)) {
        return '';
    }
    return $key;
}

function dcNormalizeGroupCaptureDraftCurrencyId($currencyId): int
{
    if ($currencyId === null || $currencyId === '') {
        return 0;
    }
    $id = (int) $currencyId;
    return $id > 0 ? $id : 0;
}

function dcGroupCaptureDraftHasTableData($tableData): bool
{
    if (!is_array($tableData) || !isset($tableData['rows']) || !is_array($tableData['rows'])) {
        return false;
    }
    foreach ($tableData['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $cell) {
            if (is_array($cell)) {
                $value = trim((string) ($cell['value'] ?? ''));
                $html = trim(strip_tags((string) ($cell['html'] ?? '')));
                if ($value !== '' || $html !== '') {
                    return true;
                }
            } elseif (trim((string) $cell) !== '') {
                return true;
            }
        }
    }
    return false;
}

$scopeParams = array_merge($_GET, $_POST);
$jsonBody = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $jsonBody = $decoded;
            $scopeParams = array_merge($scopeParams, $decoded);
        }
    }
}

$capture_scope_ctx = [];
$company_id = 0;

try {
    if (dcRequestHasExplicitScope($scopeParams)) {
        $scopeResolved = resolveDataCaptureRequestScope($pdo, $scopeParams);
        $capture_scope_ctx = dcFinalizeDualTenantCaptureScope($pdo, $scopeResolved, $scopeParams);
        $company_id = (int) ($capture_scope_ctx['company_id'] ?? 0);
    } else {
        $company_id = 0;
        if (isset($scopeParams['company_id']) && $scopeParams['company_id'] !== '') {
            $company_id = (int) $scopeParams['company_id'];
        } elseif (isset($_SESSION['company_id'])) {
            $company_id = (int) $_SESSION['company_id'];
        }
    }
} catch (Throwable $scopeException) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $scopeException->getMessage()]);
    exit;
}

$groupId = dcNormalizeGroupCaptureDraftGroupId(
    $scopeParams['group_id'] ?? $scopeParams['view_group'] ?? ($capture_scope_ctx['group_id'] ?? '')
);
$processKey = dcNormalizeGroupCaptureDraftProcessKey(
    $scopeParams['process_key'] ?? $scopeParams['process'] ?? ''
);
$currencyId = dcNormalizeGroupCaptureDraftCurrencyId(
    $scopeParams['currency_id'] ?? $scopeParams['currency'] ?? ''
);

$action = strtolower(trim((string) ($scopeParams['action'] ?? $_GET['action'] ?? '')));

if ($groupId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing group_id']);
    exit;
}

if ($processKey === '' && $action !== 'get_group_capture_draft') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid process_key']);
    exit;
}

if ($currencyId <= 0 && $action !== 'get_group_capture_draft') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid currency_id']);
    exit;
}

if ($company_id <= 0) {
    $anchorId = function_exists('gc_resolve_group_anchor_company_id')
        ? gc_resolve_group_anchor_company_id($pdo, $groupId)
        : 0;
    if ($anchorId > 0) {
        $company_id = (int) $anchorId;
    }
}

if ($company_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing company scope for permission check']);
    exit;
}

if (!checkReportGamesAccess($pdo, $company_id, $groupId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized category permission (Games required)']);
    exit;
}

if (is_partnership_audit_read_only_active($pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Read-only audit mode']);
    exit;
}

dcEnsureCaptureDraftTable($pdo);

if ($action === 'get_group_capture_draft') {
    if ($processKey === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing process_key']);
        exit;
    }
    if ($currencyId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing currency_id']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT draft_json, updated_at, updated_by
            FROM data_capture_draft
            WHERE scope_type = 'group'
              AND group_id = ?
              AND process_key = ?
              AND currency_id = ?
            LIMIT 1
        ");
        $stmt->execute([$groupId, $processKey, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $data = null;
        if ($row && !empty($row['draft_json'])) {
            $decoded = json_decode($row['draft_json'], true);
            if (is_array($decoded)) {
                $storedKey = strtolower(trim((string) ($decoded['processKey'] ?? '')));
                $storedCurrencyId = (int) ($decoded['currencyId'] ?? 0);
                if ($storedKey !== '' && $storedKey !== $processKey) {
                    $data = null;
                } elseif ($storedCurrencyId > 0 && $storedCurrencyId !== $currencyId) {
                    $data = null;
                } else {
                    $data = $decoded;
                    $data['updatedAt'] = $row['updated_at'] ?? null;
                    $data['updatedBy'] = isset($row['updated_by']) ? (int) $row['updated_by'] : null;
                }
            }
        }
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Throwable $e) {
        error_log('get_group_capture_draft: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to load draft']);
    }
    exit;
}

if ($action === 'save_group_capture_draft' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($processKey === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid process_key']);
        exit;
    }
    if ($currencyId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid currency_id']);
        exit;
    }
    $payload = is_array($jsonBody) ? $jsonBody : [];
    $tableData = $payload['tableData'] ?? null;
    $captureType = trim((string) ($payload['captureType'] ?? '1.Text'));
    if ($captureType === '') {
        $captureType = '1.Text';
    }

    if (!dcGroupCaptureDraftHasTableData($tableData)) {
        try {
            $del = $pdo->prepare("
                DELETE FROM data_capture_draft
                WHERE scope_type = 'group'
                  AND group_id = ?
                  AND process_key = ?
                  AND currency_id = ?
            ");
            $del->execute([$groupId, $processKey, $currencyId]);
        } catch (Throwable $e) {
            error_log('save_group_capture_draft clear empty: ' . $e->getMessage());
        }
        echo json_encode(['success' => true, 'data' => null]);
        exit;
    }

    $draftJson = json_encode([
        'tableData' => $tableData,
        'captureType' => $captureType,
        'processKey' => $processKey,
        'currencyId' => $currencyId,
        'savedAt' => isset($payload['savedAt']) ? (int) $payload['savedAt'] : (int) round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE);
    if ($draftJson === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid draft payload']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO data_capture_draft
                (scope_type, group_id, company_id, process_key, currency_id, draft_json, updated_by, updated_at)
            VALUES ('group', ?, NULL, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                draft_json = VALUES(draft_json),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");
        $stmt->execute([$groupId, $processKey, $currencyId, $draftJson, $user_id > 0 ? $user_id : null]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        error_log('save_group_capture_draft: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save draft']);
    }
    exit;
}

if ($action === 'clear_group_capture_draft') {
    if ($processKey === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid process_key']);
        exit;
    }
    if ($currencyId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid currency_id']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            DELETE FROM data_capture_draft
            WHERE scope_type = 'group'
              AND group_id = ?
              AND process_key = ?
              AND currency_id = ?
        ");
        $stmt->execute([$groupId, $processKey, $currencyId]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        error_log('clear_group_capture_draft: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to clear draft']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action']);
