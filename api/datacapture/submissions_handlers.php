<?php
/**
 * Data Capture: submitted list, process picker, save_submission, group process id.
 */

function dcFetchGroupPayrollSubmissionsByCaptureDate(
    PDO $pdo,
    array $captureScopeCtx,
    int $processCompanyId,
    string $captureDate,
    string $permissionCondition,
    array $permissionProcessIds
): array {
    $ledgerDc = dcSubmittedLedgerFilter('dc', 'data_captures');
    $scopeProcessFilter = dcSubmittedProcessScopeFilter('p');

    $stmt = $pdo->prepare("
        SELECT
            dc.id AS capture_id,
            dc.process_id,
            DATE_FORMAT(dc.capture_date, '%Y-%m-%d') AS date_submitted,
            dc.capture_date,
            dc.created_at,
            dc.user_type,
            p.process_id AS process_code,
            d.name AS description_name,
            COALESCE(u.login_id, o.owner_code) AS submitted_by
        FROM data_captures dc
        JOIN process p ON dc.process_id = p.id
        LEFT JOIN description d ON p.description_id = d.id
        LEFT JOIN user u ON dc.created_by = u.id AND dc.user_type = 'user'
        LEFT JOIN owner o ON dc.created_by = o.id AND dc.user_type = 'owner'
        WHERE 1=1
          {$ledgerDc['sql']}
          AND DATE(dc.capture_date) = ?
          AND p.company_id = ?
        {$scopeProcessFilter}
        {$permissionCondition}
        ORDER BY dc.created_at ASC, dc.id ASC
    ");

    $params = array_merge(
        dcCaptureLedgerBindParams($ledgerDc),
        [$captureDate, $processCompanyId],
        $permissionProcessIds
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $labeled = dcAnnotateSameDayPayrollSubmissionLabels($rows);

    return array_reverse($labeled);
}

function dcGetSubmissionsByCaptureDate(int $user_id): void
{
    global $pdo, $company_id, $capture_scope_ctx, $capture_scope_group;

    try {
        $currentCompanyId = $company_id;
        $processCompanyId = !empty($capture_scope_ctx)
            ? dcCaptureProcessCompanyId($capture_scope_ctx)
            : $currentCompanyId;
        $ledgerSp = dcSubmittedLedgerFilter('sp', 'submitted_processes');
        $ledgerDc = dcSubmittedLedgerFilter('dc', 'data_captures');

        if (!$currentCompanyId) {
            echo json_encode(['success' => false, 'error' => 'User company_id not found']);
            return;
        }

        $capture_date = $_GET['capture_date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $capture_date)) {
            echo json_encode(['success' => false, 'error' => 'Invalid date format']);
            return;
        }

        $processIds = [];
        $user_type = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' ? 'owner' : 'user';
        if ($user_type === 'user') {
            $processIds = dcLoadCaptureProcessPermissionIds(
                $pdo,
                $user_id,
                (int) $currentCompanyId,
                $user_type
            );
        }

        $permissionCondition = '';
        if (!empty($processIds) && is_array($processIds)) {
            $processIds = array_values(array_filter($processIds, 'is_numeric'));
            if (!empty($processIds)) {
                $placeholders = str_repeat('?,', count($processIds) - 1) . '?';
                $permissionCondition = "AND p.id IN ($placeholders)";
            }
        }

        try {
            $testStmt = $pdo->prepare('SELECT capture_date FROM submitted_processes LIMIT 1');
            $testStmt->execute();
            $hasCaptureDateColumn = true;
        } catch (PDOException $e) {
            $hasCaptureDateColumn = false;
        }

        $spDateFilter = $hasCaptureDateColumn
            ? 'DATE(COALESCE(sp.capture_date, sp.date_submitted)) = ?'
            : 'DATE(sp.date_submitted) = ?';
        $dateParam = $capture_date;
        $notExistsCorrelate = dcSqlSubmittedProcessCorrelatesWithCapture('spx', 'dc', $pdo);
        $notExistsDateClause = dcSqlSubmittedProcessCaptureDateMatch('spx', 'dc', $hasCaptureDateColumn);
        $scopeProcessFilter = dcSubmittedProcessScopeFilter('p');

        if ($capture_scope_group) {
            $submissions = dcFetchGroupPayrollSubmissionsByCaptureDate(
                $pdo,
                $capture_scope_ctx,
                (int) $processCompanyId,
                $capture_date,
                $permissionCondition,
                !empty($processIds) ? $processIds : []
            );
            echo json_encode([
                'success' => true,
                'data' => $submissions,
                'capture_date' => $capture_date,
            ]);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT * FROM (
                SELECT
                    sp.id,
                    sp.process_id,
                    sp.date_submitted,
                    sp.created_at,
                    sp.user_type,
                    p.process_id as process_code,
                    d.name as description_name,
                    COALESCE(u.login_id, o.owner_code) as submitted_by
                FROM submitted_processes sp
                JOIN process p ON sp.process_id = p.id
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN user u ON sp.user_id = u.id AND sp.user_type = 'user'
                LEFT JOIN owner o ON sp.user_id = o.id AND sp.user_type = 'owner'
                WHERE 1=1
                  {$ledgerSp['sql']}
                  AND $spDateFilter
                  AND p.company_id = ?
                $scopeProcessFilter
                $permissionCondition

                UNION ALL

                SELECT
                    NULL AS id,
                    dc.process_id,
                    DATE_FORMAT(dc.capture_date, '%Y-%m-%d') AS date_submitted,
                    dc.created_at,
                    dc.user_type,
                    p.process_id as process_code,
                    d.name as description_name,
                    COALESCE(u.login_id, o.owner_code) as submitted_by
                FROM data_captures dc
                JOIN process p ON dc.process_id = p.id
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN user u ON dc.created_by = u.id AND dc.user_type = 'user'
                LEFT JOIN owner o ON dc.created_by = o.id AND dc.user_type = 'owner'
                WHERE 1=1
                  {$ledgerDc['sql']}
                  AND DATE(dc.capture_date) = ?
                  AND p.company_id = ?
                $scopeProcessFilter
                  AND NOT EXISTS (
                      SELECT 1 FROM submitted_processes spx
                      WHERE 1=1
                        {$notExistsCorrelate}
                        AND {$notExistsDateClause}
                  )
                  $permissionCondition
            ) AS merged
            ORDER BY merged.created_at DESC
        ");

        $paramsSegment = array_merge(
            dcCaptureLedgerBindParams($ledgerSp),
            [$dateParam, $processCompanyId],
            !empty($processIds) ? $processIds : []
        );
        $paramsDcSegment = array_merge(
            dcCaptureLedgerBindParams($ledgerDc),
            [$dateParam, $processCompanyId],
            !empty($processIds) ? $processIds : []
        );
        $params = array_merge($paramsSegment, $paramsDcSegment);

        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $submissions,
            'capture_date' => $capture_date,
        ]);
    } catch (PDOException $e) {
        error_log('SQL Error in dcGetSubmissionsByCaptureDate: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log('Error in dcGetSubmissionsByCaptureDate: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Internal error: ' . $e->getMessage()]);
    }
}

function dcGetProcessesByDay(int $user_id): void
{
    global $pdo, $company_id, $capture_scope_ctx;

    $currentCompanyId = $company_id;
    $processCompanyId = !empty($capture_scope_ctx)
        ? dcCaptureProcessCompanyId($capture_scope_ctx)
        : $currentCompanyId;
    $ledgerSp = dcSubmittedLedgerFilter('sp', 'submitted_processes');
    $ledgerDc = dcSubmittedLedgerFilter('dc', 'data_captures');

    if (!$currentCompanyId) {
        echo json_encode(['success' => false, 'error' => 'User company_id not found']);
        return;
    }

    $selected_date = $_GET['date'] ?? date('Y-m-d');
    $day_of_week = date('N', strtotime($selected_date));

    try {
        $testStmt = $pdo->prepare('SELECT capture_date FROM submitted_processes LIMIT 1');
        $testStmt->execute();
        $hasCaptureDateColumn = true;
    } catch (PDOException $e) {
        $hasCaptureDateColumn = false;
    }
    $submittedDateMatchSql = $hasCaptureDateColumn
        ? 'DATE(COALESCE(sp.capture_date, sp.date_submitted)) = ?'
        : 'DATE(sp.date_submitted) = ?';

    $scopeProcessFilter = dcSubmittedProcessScopeFilter('p');

    $baseSql = "
        SELECT
            p.id,
            p.process_id,
            d.name as description_name,
            day.day_name
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        JOIN process_day pd ON p.id = pd.process_id
        JOIN day ON pd.day_id = day.id
        WHERE day.id = ?
        AND p.status = 'active'
        AND p.company_id = ?
        $scopeProcessFilter
        AND NOT EXISTS (
            SELECT 1 FROM submitted_processes sp
            WHERE sp.process_id = p.id
              {$ledgerSp['sql']}
              AND $submittedDateMatchSql
        )
        AND NOT EXISTS (
            SELECT 1 FROM data_captures dc
            WHERE dc.process_id = p.id
              {$ledgerDc['sql']}
              AND DATE(dc.capture_date) = ?
        )";

    $baseParams = array_merge(
        [$day_of_week, $processCompanyId],
        dcCaptureLedgerBindParams($ledgerSp),
        [$selected_date],
        dcCaptureLedgerBindParams($ledgerDc),
        [$selected_date]
    );

    list($baseSql, $baseParams) = filterProcessesByPermissions($pdo, $baseSql, $baseParams, $currentCompanyId);
    $baseSql .= ' ORDER BY p.process_id ASC';

    try {
        $stmt = $pdo->prepare($baseSql);
        $stmt->execute($baseParams);
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($processes as &$proc) {
            $proc['process_display'] = (!empty($proc['description_name']))
                ? $proc['process_id'] . ' (' . $proc['description_name'] . ')'
                : $proc['process_id'];
        }
        unset($proc);

        echo json_encode([
            'success' => true,
            'data' => $processes,
            'selected_date' => $selected_date,
            'day_of_week' => $day_of_week,
        ]);
    } catch (PDOException $e) {
        error_log('SQL Error in dcGetProcessesByDay: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function dcSaveSubmission(int $user_id): void
{
    global $pdo, $company_id, $capture_scope_ctx, $capture_scope_group;

    try {
        if (is_partnership_audit_read_only_active($pdo)) {
            echo json_encode(['success' => false, 'error' => '只读账号无法执行此操作']);
            return;
        }

        $process_id = (int) ($_POST['process_id'] ?? 0);
        $date_submitted = $_POST['date_submitted'] ?? date('Y-m-d');
        $capture_date = $_POST['capture_date'] ?? $date_submitted;
        $user_type = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' ? 'owner' : 'user';

        if ($process_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Missing process_id']);
            return;
        }

        $result = dcSaveSubmittedProcessRecord(
            $pdo,
            $user_id,
            $user_type,
            $process_id,
            $capture_date,
            is_array($capture_scope_ctx) ? $capture_scope_ctx : [],
            (int) $company_id,
            (bool) $capture_scope_group
        );

        if (!empty($result['skipped'])) {
            echo json_encode([
                'success' => true,
                'message' => 'Group scope uses data_captures for submitted list',
                'skipped' => true,
            ]);
            return;
        }

        if (!$result['success']) {
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed to save submission']);
            return;
        }

        if (!empty($result['already_exists'])) {
            echo json_encode([
                'success' => true,
                'submission_id' => $result['submission_id'],
                'message' => 'Submission already exists',
                'already_exists' => true,
            ]);
            return;
        }

        echo json_encode([
            'success' => true,
            'submission_id' => $result['submission_id'],
            'message' => 'Submission saved successfully',
        ]);
    } catch (PDOException $e) {
        error_log('SQL Error in dcSaveSubmission: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log('Error in dcSaveSubmission: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Internal error: ' . $e->getMessage()]);
    }
}

function dcGetGroupProcessId(): void
{
    global $pdo, $company_id, $capture_scope_group, $scopeParams, $capture_scope_ctx;

    $processCode = strtoupper(trim((string) ($_GET['process_code'] ?? '')));
    if ($processCode === '') {
        echo json_encode(['success' => false, 'error' => 'Missing process_code']);
        return;
    }

    $groupIdForEnsure = dcNormalizeGroupId(
        $scopeParams['group_id'] ?? $scopeParams['view_group'] ?? ''
    );
    $preferredCurrencyId = isset($_GET['currency_id']) ? (int) $_GET['currency_id'] : 0;
    if ($preferredCurrencyId <= 0 && isset($_POST['currency_id'])) {
        $preferredCurrencyId = (int) $_POST['currency_id'];
    }

    $entityCompanyId = !empty($capture_scope_ctx)
        ? dcCaptureProcessCompanyId($capture_scope_ctx)
        : (int) $company_id;
    if ($entityCompanyId <= 0 && $capture_scope_group && $groupIdForEnsure !== '') {
        $entityCompanyId = gc_resolve_group_anchor_company_id($pdo, $groupIdForEnsure);
        if ($entityCompanyId <= 0) {
            $resolvedEntity = tx_resolve_group_entity_company_id($pdo, $groupIdForEnsure);
            if ($resolvedEntity > 0) {
                $entityCompanyId = $resolvedEntity;
            }
        }
    }

    $processId = dcEnsureProcessIdByCode(
        $pdo,
        $entityCompanyId,
        $processCode,
        (bool) $capture_scope_group,
        $groupIdForEnsure !== '' ? $groupIdForEnsure : null,
        $preferredCurrencyId > 0 ? $preferredCurrencyId : null
    );
    if ($processId === null) {
        $detail = dcGroupProcessEnsureLastError();
        echo json_encode([
            'success' => false,
            'error' => $detail !== '' ? $detail : 'Process not found for scope',
        ]);
        return;
    }

    dcFixGroupPayrollProcessDescription($pdo, (int) $processId);

    echo json_encode([
        'success' => true,
        'data' => [
            'process_id' => $processId,
            'process_code' => $processCode,
        ],
    ]);
}

/** @return list<string> */
function dcSubmissionsApiActions(): array
{
    return [
        'get_submissions_by_capture_date',
        'get_processes_by_day',
        'save_submission',
        'get_group_process_id',
    ];
}

function dcDispatchSubmissionsApi(string $action, int $user_id): void
{
    try {
        switch ($action) {
            case 'get_submissions_by_capture_date':
                dcGetSubmissionsByCaptureDate($user_id);
                break;
            case 'get_processes_by_day':
                dcGetProcessesByDay($user_id);
                break;
            case 'save_submission':
                dcSaveSubmission($user_id);
                break;
            case 'get_group_process_id':
                dcGetGroupProcessId();
                break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
                break;
        }
    } catch (Exception $e) {
        error_log('Data Capture submissions API error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error']);
    }
}
