<?php
/**
 * Summary server-side state handlers (row order, formulas, rates).
 */
require_once __DIR__ . '/summary_api_lib.php';

function dcSummaryStateApiActions(): array
{
    return ['get_summary_state', 'save_summary_state'];
}

function dcSummaryApiHandleGetSummaryState(): void
{
    global $pdo, $company_id, $capture_scope_ctx;

    try {
        ensureSummaryStateTable($pdo);
        $hasScopeColumns = dcEnsureSummaryStateScopeColumns($pdo);
        $scopeBind = resolveSummaryStateScopeBind(
            is_array($capture_scope_ctx) ? $capture_scope_ctx : null,
            (int) $company_id
        );
        $processId = isset($_GET['process_id']) && $_GET['process_id'] !== '' && is_numeric($_GET['process_id'])
            ? (int) $_GET['process_id']
            : null;
        $processCode = isset($_GET['process_code']) ? trim((string) $_GET['process_code']) : '';
        $processKey = $processId !== null
            ? ('pid_' . $processId)
            : ('code_' . ($processCode !== '' ? $processCode : 'none'));

        if ($hasScopeColumns) {
            $stmt = $pdo->prepare("
                SELECT state_json
                FROM data_capture_summary_state
                WHERE company_id = ?
                  AND process_key = ?
                  AND scope_type = ?
                  AND scope_id = ?
                LIMIT 1
            ");
            $stmt->execute([
                $company_id,
                $processKey,
                $scopeBind['scope_type'],
                $scopeBind['scope_id'],
            ]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT state_json FROM data_capture_summary_state WHERE company_id = ? AND process_key = ? LIMIT 1'
            );
            $stmt->execute([$company_id, $processKey]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $data = null;
        if ($row && !empty($row['state_json'])) {
            $decoded = json_decode($row['state_json'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        error_log('get_summary_state error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => null]);
    }
}

function dcSummaryApiHandleSaveSummaryState(): void
{
    global $pdo, $company_id, $capture_scope_ctx;

    try {
        $jsonData = file_get_contents('php://input');
        $payload = json_decode($jsonData, true);
        if (!is_array($payload) || !isset($payload['processId']) || !isset($payload['processCode'])) {
            echo json_encode(['success' => false, 'message' => 'Missing processId or processCode']);
            return;
        }

        ensureSummaryStateTable($pdo);
        $hasScopeColumns = dcEnsureSummaryStateScopeColumns($pdo);
        $scopeBind = resolveSummaryStateScopeBind(
            is_array($capture_scope_ctx) ? $capture_scope_ctx : null,
            (int) $company_id
        );
        $processId = isset($payload['processId'])
            && $payload['processId'] !== null
            && $payload['processId'] !== ''
            && is_numeric($payload['processId'])
            ? (int) $payload['processId']
            : null;
        $processCode = isset($payload['processCode']) ? trim((string) $payload['processCode']) : '';
        $processKey = $processId !== null
            ? ('pid_' . $processId)
            : ('code_' . ($processCode !== '' ? $processCode : 'none'));
        $stateJson = json_encode([
            'processId' => $payload['processId'] ?? null,
            'processCode' => $payload['processCode'] ?? '',
            'rowsByKey' => $payload['rowsByKey'] ?? [],
            'rowsByStableKey' => $payload['rowsByStableKey'] ?? [],
            'rowsByRowUid' => $payload['rowsByRowUid'] ?? [],
            'rowOrder' => $payload['rowOrder'] ?? [],
            'rateValuesByKey' => $payload['rateValuesByKey'] ?? [],
            'rateValuesByRowUid' => $payload['rateValuesByRowUid'] ?? [],
            'rateValuesByRateFingerprint' => $payload['rateValuesByRateFingerprint'] ?? [],
            'savedAt' => $payload['savedAt'] ?? null,
        ]);

        if ($hasScopeColumns) {
            $stmt = $pdo->prepare("
                INSERT INTO data_capture_summary_state
                    (company_id, scope_type, scope_id, process_key, state_json, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = NOW()
            ");
            $stmt->execute([
                $company_id,
                $scopeBind['scope_type'],
                $scopeBind['scope_id'],
                $processKey,
                $stateJson,
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO data_capture_summary_state (company_id, process_key, state_json, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = NOW()
            ");
            $stmt->execute([$company_id, $processKey, $stateJson]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('save_summary_state error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function dcDispatchSummaryStateApi(string $action): void
{
    switch ($action) {
        case 'get_summary_state':
            dcSummaryApiHandleGetSummaryState();
            break;
        case 'save_summary_state':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
                return;
            }
            dcSummaryApiHandleSaveSummaryState();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
            break;
    }
}
