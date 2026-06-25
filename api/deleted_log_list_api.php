<?php
/**
 * Deleted Log 列表（JSON），与历史 PHP 页面筛选 / 分页 / 行展示逻辑一致（前端为 SPA /deleted-log）。
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/session_check.php';
require_once __DIR__ . '/deleted_log/deleted_log.php';
require_once __DIR__ . '/deleted_log/deleted_log_display.php';
require_once __DIR__ . '/deleted_log/deleted_log_entry_sources.php';
require_once __DIR__ . '/deleted_log/deleted_log_page_scope.php';
require_once __DIR__ . '/api_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Method not allowed', 405);
    exit;
}

$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$userType = strtolower((string) ($_SESSION['user_type'] ?? ''));
$canAccess = in_array($role, ['admin', 'owner', 'manager', 'supervisor'], true)
    || $userType === 'owner';
if (!$canAccess) {
    api_error('Forbidden', 403);
    exit;
}

$scope = deleted_log_page_company_scope($pdo);
if ($scope['mode'] === 'none') {
    api_error('Forbidden', 403);
    exit;
}

$moduleMap = [
    'accounts' => 'Accounts',
    'transactions' => 'Transactions',
    'ownership' => 'Ownership',
    'data_capture' => 'Data Capture',
    'bankprocess' => 'Bank Process',
    'maintenance' => 'Maintenance',
];

$moduleToTables = [
    'accounts' => ['account', 'account_company', 'account_currency', 'account_link', 'currency'],
    'transactions' => ['transactions', 'transaction_entry'],
    'ownership' => ['company_ownership', 'group_ownership'],
    'data_capture' => ['data_captures', 'data_capture_details', 'submitted_processes'],
    'bankprocess' => ['bank_process', 'process'],
    'maintenance' => ['maintenance_marquee', 'data_capture_templates'],
];

$scopeMode = $scope['mode'];
$companyScopeMulti = ($scopeMode === 'in');
$companyFilter = $companyScopeMulti ? '' : (string) ($scope['id'] ?? '');
$scopeCompanyIds = $companyScopeMulti ? $scope['ids'] : [(string) ($scope['id'] ?? '')];

$scopeHintHtml = '';
if ($scopeMode === 'all') {
    $scopeHintHtml = 'Admin / Owner：可查看<strong>全部公司</strong>的删除记录（与当前侧栏所选公司无关）。';
} elseif ($scopeMode === 'in') {
    $scopeHintHtml = '已合并显示您可访问公司、以及<strong>相同 GroupID</strong>下其他公司的删除记录（与当前侧栏选哪一家公司无关）。';
}

$where = [];
$params = [];
if ($scopeMode === 'one') {
    $where[] = 'd.`company_id` = ?';
    $params[] = $companyFilter;
} elseif ($scopeMode === 'in') {
    $phC = implode(',', array_fill(0, count($scopeCompanyIds), '?'));
    $where[] = 'd.`company_id` IN (' . $phC . ')';
    $params = array_merge($params, $scopeCompanyIds);
}

$filterUser = isset($_GET['user']) ? trim((string) $_GET['user']) : '';
$filterModule = isset($_GET['module']) ? trim((string) $_GET['module']) : '';
$filterEntry = isset($_GET['entry']) ? trim((string) $_GET['entry']) : '';
$entryTabDefs = deleted_log_entry_source_definitions();
if ($filterEntry !== '' && !array_key_exists($filterEntry, $entryTabDefs)) {
    $filterEntry = '';
}
$searchQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$pageNum = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

if ($filterUser !== '') {
    $where[] = 'd.`user` = ?';
    $params[] = $filterUser;
}

if ($filterModule !== '' && isset($moduleToTables[$filterModule])) {
    $tbls = $moduleToTables[$filterModule];
    $in = implode(',', array_fill(0, count($tbls), '?'));
    $where[] = "d.`table_name` IN ($in)";
    $params = array_merge($params, $tbls);
}

if ($filterEntry !== '') {
    $entryDef = deleted_log_entry_source_for_key($filterEntry);
    if ($entryDef !== null && !empty($entryDef['pages'])) {
        $pageList = $entryDef['pages'];
        $inPg = implode(',', array_fill(0, count($pageList), '?'));
        $where[] = 'd.`page` IN (' . $inPg . ')';
        $params = array_merge($params, $pageList);
    }
}

if ($searchQ !== '') {
    $where[] = '(d.`user` LIKE ? OR d.`page` LIKE ? OR d.`record_id` LIKE ? OR d.`ip_address` LIKE ? OR d.`table_name` LIKE ?)';
    $like = '%' . $searchQ . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

$rows = [];
$total = 0;
$userDistinct = [];

try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM `deleted_logs` d WHERE $whereSql"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $dataSql = "
        SELECT d.*, c.`company_id` AS company_code
        FROM `deleted_logs` d
        LEFT JOIN `company` c ON c.`id` = CAST(d.`company_id` AS UNSIGNED)
        WHERE $whereSql
        ORDER BY d.`created_at` DESC
        LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $udSql = 'SELECT DISTINCT d.`user` FROM `deleted_logs` d WHERE ';
    if ($scopeMode === 'one') {
        $udSql .= 'd.`company_id` = ? AND ';
        $udParams = [$companyFilter];
    } elseif ($scopeMode === 'in') {
        $udPh = implode(',', array_fill(0, count($scopeCompanyIds), '?'));
        $udSql .= 'd.`company_id` IN (' . $udPh . ') AND ';
        $udParams = $scopeCompanyIds;
    } else {
        $udParams = [];
    }
    $udSql .= "d.`user` IS NOT NULL AND d.`user` <> '' ORDER BY d.`user` ASC";
    $ud = $pdo->prepare($udSql);
    $ud->execute($udParams);
    $userDistinct = $ud->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    error_log('deleted_log_list_api: ' . $e->getMessage());
    api_error('Query failed', 500);
    exit;
}

$accountIdResolveMap = deleted_log_display_resolve_account_ids($pdo, $rows);

$totalPages = max(1, (int) ceil($total / $perPage));
if ($pageNum > $totalPages) {
    $pageNum = $totalPages;
}

$entryTabs = [];
foreach ($entryTabDefs as $tabKey => $tabMeta) {
    $entryTabs[] = [
        'key' => $tabKey,
        'label' => (string) ($tabMeta['label'] ?? ''),
        'hint' => (string) ($tabMeta['hint'] ?? ''),
        'active' => ($filterEntry === $tabKey),
    ];
}

$moduleOptions = [];
foreach ($moduleMap as $key => $label) {
    $moduleOptions[] = ['value' => $key, 'label' => $label];
}

$outRows = [];
foreach ($rows as $r) {
    $tbl = (string) ($r['table_name'] ?? '');
    $created = $r['created_at'] ?? '';
    $act = strtoupper((string) ($r['action_type'] ?? ''));
    $canRestoreRow = ($act !== 'RESTORE');
    $payload = $r['deleted_data'] ?? '';
    $decodedPayload = deleted_log_display_decode_payload($payload);
    if (is_array($payload)) {
        $jsonPretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        $jsonPretty = is_array($decodedPayload)
            ? json_encode($decodedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : (string) $payload;
    }
    $companyShow = $r['company_code'] ?? ($r['company_id'] ?? '');
    $accShow = deleted_log_display_acc_id($tbl, $decodedPayload, $accountIdResolveMap);
    $summary = deleted_log_display_summary($tbl, (string) ($r['page'] ?? ''), $decodedPayload, $accShow);
    if ($act === 'RESTORE') {
        $summary = '已从日志还原 · ' . $summary;
    } elseif ($act !== '' && $act !== 'DELETE') {
        $summary = $act . ' · ' . $summary;
    }

    $outRows[] = [
        'id' => (int) ($r['id'] ?? 0),
        'created_at' => (string) $created,
        'user' => (string) ($r['user'] ?? ''),
        'company' => (string) $companyShow,
        'acc_id' => $accShow,
        'summary' => $summary,
        'ip_address' => (string) ($r['ip_address'] ?? ''),
        'json_pretty' => $jsonPretty,
        'can_restore' => $canRestoreRow,
    ];
}

$sidebarCompanyIdJs = trim((string) ($_SESSION['company_id'] ?? ''));

api_success([
    'scope_hint_html' => $scopeHintHtml,
    'entry_tabs' => $entryTabs,
    'module_map' => $moduleMap,
    'module_options' => $moduleOptions,
    'users_distinct' => array_map('strval', $userDistinct),
    'filters' => [
        'user' => $filterUser,
        'module' => $filterModule,
        'entry' => $filterEntry,
        'q' => $searchQ,
        'p' => $pageNum,
    ],
    'pagination' => [
        'page' => $pageNum,
        'total_pages' => $totalPages,
        'total' => $total,
        'per_page' => $perPage,
    ],
    'sidebar_company_id' => $sidebarCompanyIdJs,
    'rows' => $outRows,
], '');