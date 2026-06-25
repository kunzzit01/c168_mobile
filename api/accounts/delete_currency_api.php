<?php
/**
 * 删除货币 API（规范化版）
 * 路径：api/accounts/delete_currency_api.php
 * 统一响应格式：{ success: bool, message: string, data: mixed }
 * Group / company scope via tenant_resolve_currency_context_from_request (group_only).
 */
session_start();
session_write_close();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method', 'data' => null]);
    exit;
}

function jsonResponse(bool $success, string $message, $data = null): void
{
    $out = ['success' => $success, 'message' => $message, 'data' => $data];
    if (!$success) {
        $out['error'] = $message;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($tableName));

    return $stmt !== false && $stmt->rowCount() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $safeTable = str_replace(['`', ';', ' '], '', $table);
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $safeTable . '` LIKE ' . $pdo->quote($column));

    return $stmt !== false && $stmt->rowCount() > 0;
}

/**
 * @param array<string, mixed> $input
 * @return array{mode: 'group'|'company', group_pk: int, company_id: int, group_code: string}
 */
function resolveDeleteCurrencyContext(PDO $pdo, array $input): array
{
    $groupOnly = !empty($input['group_only'])
        && filter_var($input['group_only'], FILTER_VALIDATE_BOOLEAN);

    $explicitCompanyId = 0;
    if (isset($input['company_id']) && $input['company_id'] !== '' && $input['company_id'] !== null) {
        $explicitCompanyId = (int) $input['company_id'];
    }

    if (gc_is_group_login()) {
        if ($explicitCompanyId > 0) {
            $groupOnly = false;
        } else {
            $groupOnly = true;
        }
    }

    if ($groupOnly) {
        unset($input['company_id']);
    }

    $params = [
        'group_id' => $input['group_id'] ?? ($_GET['group_id'] ?? null),
        'company_id' => $groupOnly ? null : ($input['company_id'] ?? ($_GET['company_id'] ?? null)),
        'group_only' => $groupOnly ? '1' : ($input['group_only'] ?? ($_GET['group_only'] ?? null)),
        'session_company_id' => $_SESSION['company_id'] ?? null,
    ];

    if (gc_is_group_login() && trim((string) ($params['group_id'] ?? '')) === '') {
        $params['group_id'] = $_SESSION['login_identifier'] ?? null;
    }

    return tenant_resolve_currency_context_from_request($pdo, $params);
}

function countAccountCurrencyUsageForContext(PDO $pdo, int $currencyId, array $ctx): int
{
    if (!tableExists($pdo, 'account_currency')) {
        return 0;
    }

    if (($ctx['mode'] ?? '') === 'group') {
        $groupAccountIds = tenant_collect_group_account_ids($pdo, (int) ($ctx['group_pk'] ?? 0));
        if ($groupAccountIds === []) {
            return 0;
        }
        $idPh = implode(',', array_fill(0, count($groupAccountIds), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ac.account_id)
            FROM account_currency ac
            WHERE ac.currency_id = ? AND ac.account_id IN ($idPh)
        ");
        $stmt->execute(array_merge([$currencyId], $groupAccountIds));

        return (int) $stmt->fetchColumn();
    }

    $companyId = (int) ($ctx['company_id'] ?? 0);
    if (!tableExists($pdo, 'account_company')) {
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT account_id) FROM account_currency WHERE currency_id = ?');
        $stmt->execute([$currencyId]);

        return (int) $stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ac.account_id)
        FROM account_currency ac
        INNER JOIN account_company acc ON ac.account_id = acc.account_id
        WHERE ac.currency_id = ? AND acc.company_id = ?"
        . tenant_sql_account_company_subsidiary_only($pdo, 'acc')
    );
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countAccountUsageLegacyByCode(PDO $pdo, string $currencyCode, array $ctx): int
{
    $companyId = (int) ($ctx['company_id'] ?? 0);
    if (($ctx['mode'] ?? '') === 'group') {
        $groupAccountIds = tenant_collect_group_account_ids($pdo, (int) ($ctx['group_pk'] ?? 0));
        if ($groupAccountIds === [] || !columnExists($pdo, 'account', 'currency')) {
            return 0;
        }
        $idPh = implode(',', array_fill(0, count($groupAccountIds), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM account
            WHERE currency = ? AND id IN ($idPh)
        ");
        $stmt->execute(array_merge([$currencyCode], $groupAccountIds));

        return (int) $stmt->fetchColumn();
    }

    if (!tableExists($pdo, 'account_company') || !columnExists($pdo, 'account', 'currency')) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT a.id)
        FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE a.currency = ? AND ac.company_id = ?"
        . tenant_sql_account_company_subsidiary_only($pdo, 'ac')
    );
    $stmt->execute([$currencyCode, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countDataCaptureDetailsUsage(PDO $pdo, int $currencyId, int $companyId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM data_capture_details WHERE currency_id = ? AND company_id = ?');
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countDataCapturesUsage(PDO $pdo, int $currencyId, int $companyId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM data_captures WHERE currency_id = ? AND company_id = ?');
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countTransactionsCurrencyUsage(PDO $pdo, int $currencyId, int $companyId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE currency_id = ? AND company_id = ?');
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countTransactionsRateUsage(PDO $pdo, int $currencyId, int $companyId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM transactions_rate tr
        INNER JOIN transactions t ON tr.transaction_id = t.id
        WHERE (tr.rate_from_currency_id = ? OR tr.rate_to_currency_id = ?) AND t.company_id = ?
    ");
    $stmt->execute([$currencyId, $currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countTransactionsRateDetailsUsage(PDO $pdo, int $currencyId, int $companyId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM transactions_rate_details trd
        INNER JOIN transactions_rate tr ON trd.rate_group_id = tr.rate_group_id
        INNER JOIN transactions t ON tr.transaction_id = t.id
        WHERE trd.currency_id = ? AND t.company_id = ?
    ");
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countDataCaptureTemplatesUsage(PDO $pdo, int $currencyId, int $companyId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM data_capture_templates WHERE currency_id = ? AND company_id = ?');
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countProcessCurrencyUsage(PDO $pdo, int $currencyId, int $companyId): int
{
    if (!tableExists($pdo, 'process') || !columnExists($pdo, 'process', 'currency_id')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM process WHERE currency_id = ? AND company_id = ?');
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

function countDataCaptureTemplatesUsageViaProcess(PDO $pdo, int $currencyId, int $companyId, bool $processIdIsInt): int
{
    if ($processIdIsInt) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM data_capture_templates dct
            INNER JOIN process p ON dct.process_id = p.id
            WHERE dct.currency_id = ? AND p.company_id = ?
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM data_capture_templates dct
            INNER JOIN process p ON CAST(dct.process_id AS CHAR) = CAST(p.process_id AS CHAR)
            WHERE dct.currency_id = ? AND p.company_id = ?
        ");
    }
    $stmt->execute([$currencyId, $companyId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array{0: list<string>, 1: list<string>}
 */
function collectCurrencyUsage(PDO $pdo, int $currencyId, array $ctx, string $currencyCode): array
{
    $usageMessages = [];
    $debugInfo = [];
    $companyId = (int) ($ctx['company_id'] ?? 0);

    if (tableExists($pdo, 'account_currency')) {
        $n = countAccountCurrencyUsageForContext($pdo, $currencyId, $ctx);
        $debugInfo[] = 'account_currency: ' . $n;
        if ($n > 0) {
            $usageMessages[] = $n . ' account(s)';
        }
    } else {
        try {
            $n = countAccountUsageLegacyByCode($pdo, $currencyCode, $ctx);
            if ($n > 0) {
                $usageMessages[] = $n . ' account(s)';
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    try {
        if (tableExists($pdo, 'data_capture_details')) {
            $n = countDataCaptureDetailsUsage($pdo, $currencyId, $companyId);
            if ($n > 0) {
                $usageMessages[] = $n . ' data capture detail(s)';
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        if (tableExists($pdo, 'data_captures')) {
            $n = countDataCapturesUsage($pdo, $currencyId, $companyId);
            if ($n > 0) {
                $usageMessages[] = $n . ' data capture(s)';
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        if (columnExists($pdo, 'transactions', 'currency_id')) {
            $n = countTransactionsCurrencyUsage($pdo, $currencyId, $companyId);
            if ($n > 0) {
                $usageMessages[] = $n . ' transaction(s)';
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        if (tableExists($pdo, 'transactions_rate')) {
            $n = countTransactionsRateUsage($pdo, $currencyId, $companyId);
            if ($n > 0) {
                $usageMessages[] = $n . ' rate transaction(s)';
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        if (tableExists($pdo, 'transactions_rate_details') && columnExists($pdo, 'transactions_rate_details', 'currency_id')) {
            $n = countTransactionsRateDetailsUsage($pdo, $currencyId, $companyId);
            if ($n > 0) {
                $usageMessages[] = $n . ' rate transaction detail(s)';
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        if (tableExists($pdo, 'process') && columnExists($pdo, 'process', 'currency_id')) {
            $n = countProcessCurrencyUsage($pdo, $currencyId, $companyId);
            if ($n > 0) {
                $usageMessages[] = $n . ' process(es)';
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    try {
        if (tableExists($pdo, 'data_capture_templates') && columnExists($pdo, 'data_capture_templates', 'currency_id')) {
            if (columnExists($pdo, 'data_capture_templates', 'company_id')) {
                $n = countDataCaptureTemplatesUsage($pdo, $currencyId, $companyId);
                if ($n > 0) {
                    $usageMessages[] = $n . ' data capture template(s)';
                }
            } else {
                $col = $pdo->query("SHOW COLUMNS FROM data_capture_templates WHERE Field = 'process_id'")->fetch(PDO::FETCH_ASSOC);
                $isInt = isset($col['Type']) && stripos((string) $col['Type'], 'int') !== false;
                $n = countDataCaptureTemplatesUsageViaProcess($pdo, $currencyId, $companyId, $isInt);
                if ($n > 0) {
                    $usageMessages[] = $n . ' data capture template(s)';
                }
            }
        }
    } catch (PDOException $e) {
        // ignore
    }

    return [$usageMessages, $debugInfo];
}

/**
 * Resolve another currency in the same scope to reassign NOT NULL FK rows before delete.
 */
function resolveFallbackCurrencyIdForDetach(PDO $pdo, int $currencyId, array $ctx): ?int
{
    foreach (tenant_fetch_currencies($pdo, $ctx) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && $id !== $currencyId) {
            return $id;
        }
    }

    return null;
}

/**
 * On force delete: detach historical references so FK constraints allow currency row removal.
 *
 * @return string|null Error message when detach cannot complete
 */
function detachCurrencyHistoricalReferences(PDO $pdo, int $currencyId, array $ctx): ?string
{
    $companyId = (int) ($ctx['company_id'] ?? 0);
    if ($companyId <= 0) {
        return 'Missing company scope';
    }

    $fallbackId = resolveFallbackCurrencyIdForDetach($pdo, $currencyId, $ctx);

    $reassignCompanyScoped = static function (PDO $pdo, string $table, int $currencyId, int $companyId, ?int $fallbackId) use (&$blockingError): bool {
        if (!tableExists($pdo, $table) || !columnExists($pdo, $table, 'currency_id') || !columnExists($pdo, $table, 'company_id')) {
            return true;
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE currency_id = ? AND company_id = ?");
        $countStmt->execute([$currencyId, $companyId]);
        $n = (int) $countStmt->fetchColumn();
        if ($n === 0) {
            return true;
        }
        if ($fallbackId === null) {
            $blockingError = 'Cannot force delete: ' . $n . ' ' . $table . ' record(s) require another currency in this company';

            return false;
        }
        $upd = $pdo->prepare("UPDATE `{$table}` SET currency_id = ? WHERE currency_id = ? AND company_id = ?");
        $upd->execute([$fallbackId, $currencyId, $companyId]);

        return true;
    };

    $blockingError = null;
    foreach (['process', 'data_captures', 'data_capture_details', 'data_capture_templates'] as $table) {
        if (!$reassignCompanyScoped($pdo, $table, $currencyId, $companyId, $fallbackId)) {
            return $blockingError;
        }
    }

    try {
        if (columnExists($pdo, 'transactions', 'currency_id')) {
            $stmt = $pdo->prepare('UPDATE transactions SET currency_id = NULL WHERE currency_id = ? AND company_id = ?');
            $stmt->execute([$currencyId, $companyId]);
        }
    } catch (PDOException $e) {
        return 'Failed to detach transactions: ' . $e->getMessage();
    }

    try {
        if (tableExists($pdo, 'transactions_rate')) {
            if ($fallbackId === null) {
                $chk = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM transactions_rate tr
                    INNER JOIN transactions t ON tr.transaction_id = t.id
                    WHERE (tr.rate_from_currency_id = ? OR tr.rate_to_currency_id = ?) AND t.company_id = ?
                ");
                $chk->execute([$currencyId, $currencyId, $companyId]);
                if ((int) $chk->fetchColumn() > 0) {
                    return 'Cannot force delete: rate transactions require another currency in this company';
                }
            } else {
                $stmt = $pdo->prepare("
                    UPDATE transactions_rate tr
                    INNER JOIN transactions t ON tr.transaction_id = t.id
                    SET tr.rate_from_currency_id = CASE WHEN tr.rate_from_currency_id = ? THEN ? ELSE tr.rate_from_currency_id END,
                        tr.rate_to_currency_id = CASE WHEN tr.rate_to_currency_id = ? THEN ? ELSE tr.rate_to_currency_id END
                    WHERE (tr.rate_from_currency_id = ? OR tr.rate_to_currency_id = ?) AND t.company_id = ?
                ");
                $stmt->execute([$currencyId, $fallbackId, $currencyId, $fallbackId, $currencyId, $currencyId, $companyId]);
            }
        }
    } catch (PDOException $e) {
        return 'Failed to detach rate transactions: ' . $e->getMessage();
    }

    try {
        if (tableExists($pdo, 'transactions_rate_details') && columnExists($pdo, 'transactions_rate_details', 'currency_id')) {
            if ($fallbackId === null) {
                $chk = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM transactions_rate_details trd
                    INNER JOIN transactions_rate tr ON trd.rate_group_id = tr.rate_group_id
                    INNER JOIN transactions t ON tr.transaction_id = t.id
                    WHERE trd.currency_id = ? AND t.company_id = ?
                ");
                $chk->execute([$currencyId, $companyId]);
                if ((int) $chk->fetchColumn() > 0) {
                    return 'Cannot force delete: rate transaction details require another currency in this company';
                }
            } else {
                $stmt = $pdo->prepare("
                    UPDATE transactions_rate_details trd
                    INNER JOIN transactions_rate tr ON trd.rate_group_id = tr.rate_group_id
                    INNER JOIN transactions t ON tr.transaction_id = t.id
                    SET trd.currency_id = ?
                    WHERE trd.currency_id = ? AND t.company_id = ?
                ");
                $stmt->execute([$fallbackId, $currencyId, $companyId]);
            }
        }
    } catch (PDOException $e) {
        return 'Failed to detach rate transaction details: ' . $e->getMessage();
    }

    return null;
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(false, '用户未登录或缺少公司信息', null);
        exit;
    }

    if (is_partnership_audit_read_only_active($pdo)) {
        jsonResponse(false, '只读账号无法删除币种', null);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(false, 'Invalid JSON input: ' . json_last_error_msg(), null);
        exit;
    }
    if (!is_array($input)) {
        $input = [];
    }

    try {
        $currencyCtx = resolveDeleteCurrencyContext($pdo, $input);
    } catch (Exception $e) {
        http_response_code(400);
        jsonResponse(false, $e->getMessage(), null);
        exit;
    }

    $company_id = (int) ($currencyCtx['company_id'] ?? 0);
    if ($company_id <= 0) {
        jsonResponse(false, '用户未登录或缺少公司信息', null);
        exit;
    }

    $groupCode = (string) ($currencyCtx['group_code'] ?? '');
    if ($groupCode !== '' && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $company_id, $groupCode);
    }

    if (!isset($input['id']) || empty($input['id'])) {
        jsonResponse(false, 'Currency ID is required', null);
        exit;
    }

    $currencyId = (int) $input['id'];
    $forceDelete = isset($input['force']) && $input['force'] === true;

    $currency = tenant_get_currency_row($pdo, $currencyId, $currencyCtx);
    if (!$currency) {
        jsonResponse(false, 'Currency not found or access denied', null);
        exit;
    }

    if (
        ($currencyCtx['mode'] ?? '') === 'group'
        && tenant_table_has_sync_source_column($pdo)
        && strtolower(trim((string) ($currency['sync_source'] ?? 'manual'))) === 'subsidiary'
    ) {
        jsonResponse(
            false,
            'Cannot delete currency synced from subsidiary companies',
            ['sync_source' => 'subsidiary', 'deletable' => false]
        );
        exit;
    }

    [$usageMessages, $debugInfo] = collectCurrencyUsage($pdo, $currencyId, $currencyCtx, (string) $currency['code']);

    // force=true: skip historical usage (data capture, transactions, templates); still block on linked accounts.
    if ($forceDelete) {
        $usageMessages = array_filter($usageMessages, static function ($msg) {
            return strpos($msg, 'account(s)') !== false;
        });
    }

    if ($usageMessages !== []) {
        $accountsInUse = tenant_get_accounts_using_currency($pdo, $currencyId, $currencyCtx);
        $responseData = ['accounts_in_use' => $accountsInUse];

        if ($accountsInUse !== []) {
            $accountLabels = array_map(static function ($acc) {
                $name = trim((string) ($acc['name'] ?? ''));
                $code = trim((string) ($acc['account_id'] ?? ''));
                if ($name !== '' && $code !== '') {
                    return $name . ' (' . $code . ')';
                }

                return $name !== '' ? $name : $code;
            }, $accountsInUse);
            $errorMsg = 'Cannot delete currency. The following accounts are using it: ' . implode(', ', $accountLabels);
        } else {
            $errorMsg = 'Cannot delete currency that is being used by: ' . implode(', ', $usageMessages);
        }

        if ($debugInfo !== []) {
            $errorMsg .= ' [Debug: ' . implode(', ', $debugInfo) . ']';
        }
        jsonResponse(false, $errorMsg, $responseData);
        exit;
    }

    if ($forceDelete) {
        $detachError = detachCurrencyHistoricalReferences($pdo, $currencyId, $currencyCtx);
        if ($detachError !== null) {
            jsonResponse(false, $detachError, null);
            exit;
        }
    }

    deletedLog(
        $pdo,
        '',
        '/api/accounts/delete_currency_api.php',
        'currency',
        (string) $currencyId,
        'DELETE',
        null,
        (string) $company_id
    );

    $deleted = tenant_delete_currency($pdo, $currencyId, $currencyCtx);
    if ($deleted === 0) {
        if (!tenant_currency_belongs_to_context($pdo, $currencyId, $currencyCtx)) {
            jsonResponse(false, 'Currency not found or does not belong to current company', null);
        } else {
            jsonResponse(false, 'Failed to delete currency. Please check database constraints or permissions.', null);
        }
        exit;
    }

    if (($currencyCtx['mode'] ?? '') === 'company') {
        tenant_reconcile_groups_after_company_currency_deleted(
            $pdo,
            (int) ($currencyCtx['company_id'] ?? $company_id),
            (string) ($currency['code'] ?? '')
        );
    }

    jsonResponse(true, 'Currency deleted successfully', null);
} catch (PDOException $e) {
    error_log('DeleteCurrencyAPI - PDO: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null);
} catch (Exception $e) {
    error_log('DeleteCurrencyAPI - Exception: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
} catch (Error $e) {
    error_log('DeleteCurrencyAPI - Fatal: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, 'Fatal error: ' . $e->getMessage(), null);
}
