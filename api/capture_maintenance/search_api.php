<?php
/**
 * Capture Maintenance Search API
 * 用于搜索和显示 Data Capture 数据
 * 路径: api/capture_maintenance/search_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../datacapture/data_capture_scope_common.php';

function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 解析并校验请求中的 company_id
 */
function getCompanyIdForRequest(PDO $pdo) {
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requestedCompanyId = (int)$_GET['company_id'];
        $userRole = strtolower($_SESSION['role'] ?? '');
        if ($userRole === 'owner') {
            $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? null;
            if (!$ownerId) {
                throw new Exception('缺少 Owner 信息');
            }
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ? LIMIT 1");
            $stmt->execute([$requestedCompanyId, $ownerId]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('无权访问该公司');
            }
            return $requestedCompanyId;
        }
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        if ($requestedCompanyId !== (int)$_SESSION['company_id']) {
            throw new Exception('无权访问该公司');
        }
        return $requestedCompanyId;
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    return (int)$_SESSION['company_id'];
}

/**
 * 查询 Data Capture 记录（未删除）
 */
function fetchCaptureRecords(
    PDO $pdo,
    array $scopeCtx,
    string $date_from_db,
    string $date_to_db,
    ?int $process_id,
    ?string $process_name,
    string $scopeProcessFilter = ''
) {
    $ledgerDc = dcBuildCaptureLedgerFilter($pdo, $scopeCtx, 'dc', 'data_captures');
    $ledgerDcd = dcBuildCaptureLedgerFilter($pdo, $scopeCtx, 'dcd', 'data_capture_details');
    $processCompanyId = dcCaptureProcessCompanyId($scopeCtx);

    $where_conditions = ['dc.capture_date BETWEEN ? AND ?', 'p.company_id = ?'];
    $params = array_merge(
        dcCaptureLedgerBindParams($ledgerDc),
        dcCaptureLedgerBindParams($ledgerDcd),
        [$date_from_db, $date_to_db, $processCompanyId]
    );
    if ($process_id !== null) {
        $where_conditions[] = 'p.id = ?';
        $params[] = $process_id;
    } elseif ($process_name) {
        $where_conditions[] = 'p.process_id = ?';
        $params[] = $process_name;
    }
    $where_sql = 'AND ' . implode(' AND ', $where_conditions);
    $productLabelSql = dcSqlCaptureProductLabel('p', 'd');

    $sql = "SELECT dc.id as capture_id, p.process_id, {$productLabelSql} as product_name,
            MIN(dcd.currency_id) as currency_id, MIN(c.code) as currency_code,
            dc.capture_date, DATE_FORMAT(dc.created_at, '%d/%m/%Y %H:%i:%s') as dts_created,
            {$productLabelSql} as wl_group, MAX(COALESCE(u.login_id, o.owner_code)) as submitted_by
            FROM data_captures dc
            INNER JOIN process p ON dc.process_id = p.id
            LEFT JOIN description d ON p.description_id = d.id
            INNER JOIN data_capture_details dcd ON dc.id = dcd.capture_id
            INNER JOIN currency c ON dcd.currency_id = c.id
            LEFT JOIN user u ON dc.created_by = u.id AND dc.user_type = 'user'
            LEFT JOIN owner o ON dc.created_by = o.id AND dc.user_type = 'owner'
            WHERE 1=1 {$ledgerDc['sql']} {$ledgerDcd['sql']} $where_sql $scopeProcessFilter
            GROUP BY dc.id, p.process_id, {$productLabelSql}, dc.capture_date, dc.created_at
            ORDER BY dc.capture_date DESC, p.process_id, {$productLabelSql}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 查询已删除记录（data_captures_deleted 表存在时）
 */
function fetchDeletedRecords(
    PDO $pdo,
    int $company_id,
    string $date_from_db,
    string $date_to_db,
    ?int $process_id,
    ?string $process_name,
    string $scopeProcessFilter = '',
    string $scopeCompanySql = ''
) {
    $checkStmt = $pdo->query("SHOW TABLES LIKE 'data_captures_deleted'");
    if (!$checkStmt->rowCount()) {
        return [];
    }
    $deletedWhereConditions = ["p.company_id = ?"];
    $deletedParams = [$company_id, $date_from_db, $date_to_db];
    if ($process_id !== null) {
        $deletedWhereConditions[] = "p.id = ?";
        $deletedParams[] = $process_id;
    } elseif ($process_name) {
        $deletedWhereConditions[] = "p.process_id = ?";
        $deletedParams[] = $process_name;
    }
    $deletedWhereSql = 'AND ' . implode(' AND ', $deletedWhereConditions);
    $deletedParams[] = $company_id;
    $productLabelSql = dcSqlCaptureProductLabel('p', 'd');

    $sql = "SELECT dcd.capture_id, p.process_id, {$productLabelSql} as product_name, dcd.currency_id, c.code as currency_code,
            dcd.capture_date, DATE_FORMAT(dcd.created_at, '%d/%m/%Y %H:%i:%s') as dts_created,
            {$productLabelSql} as wl_group, COALESCE(u.login_id, o.owner_code) as submitted_by,
            COALESCE(du.login_id, do.owner_code) as deleted_by,
            DATE_FORMAT(dcd.deleted_at, '%d/%m/%Y %H:%i:%s') as dts_deleted
            FROM data_captures_deleted dcd
            INNER JOIN process p ON dcd.process_id = p.id
            LEFT JOIN description d ON p.description_id = d.id
            INNER JOIN currency c ON dcd.currency_id = c.id
            LEFT JOIN user u ON dcd.created_by = u.id AND dcd.user_type = 'user'
            LEFT JOIN owner o ON dcd.created_by = o.id AND dcd.user_type = 'owner'
            LEFT JOIN user du ON dcd.deleted_by_user_id = du.id
            LEFT JOIN owner do ON dcd.deleted_by_owner_id = do.id
            WHERE dcd.company_id = ? AND dcd.capture_date BETWEEN ? AND ? $deletedWhereSql $scopeProcessFilter $scopeCompanySql
            ORDER BY dcd.capture_date DESC, p.process_id, {$productLabelSql}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($deletedParams);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 格式化并合并正常/已删除记录，排序并编号
 */
function formatAndMergeResults(array $results, array $deletedResults, ?string $process_name) {
    $formattedResults = [];
    $rowNumber = 1;
    foreach ($results as $row) {
        $formattedResults[] = [
            'no' => $rowNumber++,
            'capture_id' => $row['capture_id'],
            'process' => $row['process_id'] ?? ($process_name ?: '-'),
            'process_id' => $row['process_id'] ?? null,
            'dts_created' => $row['dts_created'] ?? '',
            'product' => $row['product_name'] ?? '-',
            'currency' => $row['currency_code'] ?? '-',
            'currency_id' => isset($row['currency_id']) ? (int)$row['currency_id'] : null,
            'wl_group' => $row['wl_group'] ?? '-',
            'submitted_by' => $row['submitted_by'] ?? '-',
            'is_deleted' => 0,
            'deleted_by' => null,
            'dts_deleted' => null
        ];
    }
    foreach ($deletedResults as $row) {
        $formattedResults[] = [
            'no' => $rowNumber++,
            'capture_id' => $row['capture_id'],
            'process' => $row['process_id'] ?? ($process_name ?: '-'),
            'process_id' => $row['process_id'] ?? null,
            'dts_created' => $row['dts_created'] ?? '',
            'product' => $row['product_name'] ?? '-',
            'currency' => $row['currency_code'] ?? '-',
            'currency_id' => isset($row['currency_id']) ? (int)$row['currency_id'] : null,
            'wl_group' => $row['wl_group'] ?? '-',
            'submitted_by' => $row['submitted_by'] ?? '-',
            'is_deleted' => 1,
            'deleted_by' => $row['deleted_by'] ?? null,
            'dts_deleted' => $row['dts_deleted'] ?? null
        ];
    }
    usort($formattedResults, function ($a, $b) {
        $dateCompare = strcmp($b['dts_created'] ?? '', $a['dts_created'] ?? '');
        if ($dateCompare !== 0) return $dateCompare;
        return strcmp($b['capture_id'] ?? 0, $a['capture_id'] ?? 0);
    });
    foreach ($formattedResults as $index => &$result) {
        $result['no'] = $index + 1;
    }
    unset($result);
    return $formattedResults;
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    $scopeParams = $_GET;
    $capture_scope_group = false;
    $hasExplicitScope = dcRequestHasExplicitScope($scopeParams);

    $requestedViewGroup = dcNormalizeGroupId(
        $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ''
    );

    if ($hasExplicitScope) {
        $scopeResolved = resolveDataCaptureRequestScope($pdo, $scopeParams);
        $scopeCtx = dcFinalizeCaptureMaintenanceScope($pdo, $scopeResolved, $scopeParams);
        $company_id = (int) $scopeCtx['company_id'];
        $capture_scope_group = (bool) $scopeCtx['is_group_scope'];
        $scopeProcessFilter = (string) $scopeCtx['scope_process_sql'];
        $scopeCompanySqlDeleted = (string) ($scopeCtx['scope_company_sql_deleted'] ?? '');
        if ($scopeCompanySqlDeleted === '' && !$capture_scope_group && empty($scopeCtx['dual_tenant'])) {
            $scopeCompanySqlDeleted = dcSqlCaptureOnSubsidiaryCompany('dcd');
        }
        dcAssertUserCanAccessCompany(
            $pdo,
            $company_id,
            $requestedViewGroup !== '' ? $requestedViewGroup : null
        );
    } else {
        $company_id = getCompanyIdForRequest($pdo);
        $capture_scope_group = false;
        $scopeCtx = [
            'company_id' => $company_id,
            'anchor_company_id' => $company_id,
            'is_group_scope' => false,
            'dual_tenant' => tenant_table_has_scope_columns($pdo, 'data_captures'),
            'scope_process_sql' => dcSqlCompanyProcessFilter('p'),
            'scope_company_sql_deleted' => dcSqlCaptureOnSubsidiaryCompany('dcd'),
        ];
        $scopeProcessFilter = dcSqlCompanyProcessFilter('p');
        $scopeCompanySqlDeleted = dcSqlCaptureOnSubsidiaryCompany('dcd');
    }

    if ($capture_scope_group) {
        if ($company_id <= 0) {
            jsonResponse(true, 'OK', []);
            return;
        }
    } elseif (
        !$capture_scope_group
        && $company_id > 0
        && dcCompanyIdIsGroupEntity($pdo, $company_id)
        && empty($scopeCtx['dual_tenant'])
    ) {
        jsonResponse(true, 'OK', []);
        return;
    }

    // $scopeProcessFilter set above

    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $process_param = isset($_GET['process']) && $_GET['process'] !== '' ? trim((string) $_GET['process']) : null;
    $process_id = null;
    $process_name = null;
    // 优先按唯一 process.id 精确过滤，避免同 process_id(代码) 下多条 process 混在一起
    if ($process_param !== null && $process_param !== '') {
        if (preg_match('/^\d+$/', $process_param)) {
            $process_id = (int) $process_param;
            try {
                dcAssertProcessIdInCaptureScope(
                    $pdo,
                    $process_id,
                    (int) $company_id,
                    (bool) $capture_scope_group
                );
            } catch (Exception $e) {
                jsonResponse(true, 'OK', []);
                return;
            }
            $stmt = $pdo->prepare('SELECT process_id FROM process WHERE id = ? AND company_id = ? LIMIT 1');
            $stmt->execute([$process_id, $company_id]);
            $process_name = (string) ($stmt->fetchColumn() ?: '');
            if ($process_name === '') {
                jsonResponse(true, 'OK', []);
                return;
            }
        } else {
            // 兼容旧前端：传 process_id 或 "CODE (DESC)" 文本
            $process_name = $process_param;
            if (strpos($process_name, '(') !== false) {
                $process_name = trim(explode('(', $process_name)[0]);
            }
            if ($process_name === '') {
                $process_name = null;
            } else {
                $resolvedPid = dcResolveProcessIdByCode(
                    $pdo,
                    (int) $company_id,
                    $process_name,
                    (bool) $capture_scope_group
                );
                if ($resolvedPid === null) {
                    jsonResponse(true, 'OK', []);
                    return;
                }
                $process_id = $resolvedPid;
            }
        }
    }

    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));

    $results = fetchCaptureRecords(
        $pdo,
        $scopeCtx,
        $date_from_db,
        $date_to_db,
        $process_id,
        $process_name,
        $scopeProcessFilter
    );
    $deletedResults = [];
    try {
        $deletedResults = fetchDeletedRecords(
            $pdo,
            $company_id,
            $date_from_db,
            $date_to_db,
            $process_id,
            $process_name,
            $scopeProcessFilter,
            $scopeCompanySqlDeleted
        );
    } catch (Exception $e) {
        error_log('查询已删除记录失败: ' . $e->getMessage());
    }

    $formattedResults = formatAndMergeResults($results, $deletedResults, $process_name);
    if (empty($formattedResults)) {
        jsonResponse(true, 'No data found', []);
        return;
    }
    jsonResponse(true, 'OK', $formattedResults);
} catch (PDOException $e) {
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}