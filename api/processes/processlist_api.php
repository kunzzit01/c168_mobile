<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/../includes/ensure_bank_process_day_end_monthly_cap_column.php';
require_once __DIR__ . '/../includes/process_modified_by.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
}

header('Content-Type: application/json');

/** 统一 JSON 响应：success, message, data；失败时同时返回 error（与 message 相同）以兼容旧前端 */
function jsonResponse(bool $success, string $message = '', $data = null): void
{
    $payload = ['success' => $success, 'message' => $message];
    if (!$success) {
        $payload['error'] = $message;
    }
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload);
}

function bankProcessHasColumn(PDO $pdo, string $column): bool
{
    $cache = &$GLOBALS['__bank_process_column_exists_cache'];
    if (!is_array($cache)) {
        $cache = [];
    }
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM bank_process LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        $cache[$column] = false;
    }
    return $cache[$column];
}

function getBankProcessIssueFlagSql(string $tableAlias, bool $hasIssueFlagColumn, bool $hasFlagColumn): string
{
    if ($hasIssueFlagColumn && $hasFlagColumn) {
        return "COALESCE(NULLIF($tableAlias.`flag`, ''), NULLIF($tableAlias.`issue_flag`, ''))";
    }
    if ($hasFlagColumn) {
        return "$tableAlias.`flag`";
    }
    if ($hasIssueFlagColumn) {
        return "$tableAlias.`issue_flag`";
    }
    return "NULL";
}

function normalizeBankIssueFlagValue($value): ?string
{
    $normalized = strtolower(trim((string)$value));
    $normalized = str_replace([' ', '-'], '_', $normalized);
    if (in_array($normalized, ['official', 'e_invoice', 'block'], true)) {
        return $normalized;
    }
    return null;
}

// 获取当前登录用户的数值 ID
function getCurrentUserId(PDO $pdo) {
    // 检查是否是 owner 登录
    $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
    $currentCompanyId = $_SESSION['company_id'] ?? null;
    
    // 如果不是 owner，尝试从 session 获取 user_id
    if (!$isOwner && isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        // 验证用户 ID 是否存在于数据库中
        $stmt = $pdo->prepare("SELECT id FROM user WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return $userId;
        }
    }
    
    // 如果 session 中有 login_id，尝试通过 login_id 查找（仅当不是 owner 时）
    if (!$isOwner && !empty($_SESSION['login_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM user WHERE login_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['login_id']]);
        $userId = $stmt->fetchColumn();
        if ($userId) {
            return (int)$userId;
        }
    }
    
    // 如果是 owner 或者找不到用户，尝试获取该公司下的第一个有效用户
    if ($currentCompanyId) {
        try {
            // 使用 user_company_map 来查找属于该公司的用户
            $stmt = $pdo->prepare("
                SELECT u.id 
                FROM user u
                INNER JOIN user_company_map ucm ON u.id = ucm.user_id
                WHERE ucm.company_id = ? AND u.status = 'active' 
                ORDER BY u.id ASC 
                LIMIT 1
            ");
            $stmt->execute([$currentCompanyId]);
            $fallbackId = $stmt->fetchColumn();
            if ($fallbackId) {
                return (int)$fallbackId;
            }
            
            // 如果该公司没有 active 用户，尝试获取该公司的任何用户
            $stmt = $pdo->prepare("
                SELECT u.id 
                FROM user u
                INNER JOIN user_company_map ucm ON u.id = ucm.user_id
                WHERE ucm.company_id = ? 
                ORDER BY u.id ASC 
                LIMIT 1
            ");
            $stmt->execute([$currentCompanyId]);
            $fallbackId = $stmt->fetchColumn();
            if ($fallbackId) {
                return (int)$fallbackId;
            }
        } catch (Exception $e) {
            error_log("getCurrentUserId error (company-specific): " . $e->getMessage());
        }
    }
    
    // 如果都找不到，尝试获取数据库中的第一个有效用户（全局）
    try {
        $stmt = $pdo->query("SELECT id FROM user WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        $fallbackId = $stmt->fetchColumn();
        if ($fallbackId) {
            return (int)$fallbackId;
        }
        
        // 如果连 active 用户都没有，尝试获取任何用户
        $stmt = $pdo->query("SELECT id FROM user ORDER BY id ASC LIMIT 1");
        $fallbackId = $stmt->fetchColumn();
        if ($fallbackId) {
            return (int)$fallbackId;
        }
    } catch (Exception $e) {
        error_log("getCurrentUserId error: " . $e->getMessage());
    }
    
    throw new Exception("无法获取有效的用户 ID。请确保已登录并且 user 表中有有效的用户记录。");
}

/** 检查当前用户是否有权访问指定公司（owner 查 company，普通用户查 user_company_map；集团登录加 scope 围栏） */
function checkCompanyAccess(PDO $pdo, int $requestedCompanyId): bool
{
    try {
        $viewGroup = isset($_GET['group_id']) ? gc_normalize_view_group((string) $_GET['group_id']) : null;
        if ($viewGroup === null && gc_is_group_login()) {
            $viewGroup = gc_session_login_identifier();
        }
        gc_assert_api_company_access($pdo, $requestedCompanyId, $viewGroup);
        return true;
    } catch (RuntimeException $e) {
        return false;
    }
}

/**
 * 获取与指定 process 同组的其它流程（双向）。
 * 组规则：
 * - 若当前是 copy_from 子流程，则锚点为其 sync_source_process_id；
 * - 否则锚点为当前流程 id；
 * - 组成员为：锚点本身 + 所有 sync_source_process_id=锚点 的流程，排除当前流程。
 */
function getLinkedProcessIdsForSync(PDO $pdo, int $companyId, int $processId): array
{
    $currentStmt = $pdo->prepare("
        SELECT id, sync_source_process_id
        FROM process
        WHERE id = ? AND company_id = ?
        LIMIT 1
    ");
    $currentStmt->execute([$processId, $companyId]);
    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        return [];
    }

    $anchorId = !empty($current['sync_source_process_id'])
        ? (int)$current['sync_source_process_id']
        : (int)$current['id'];

    $targetsStmt = $pdo->prepare("
        SELECT id
        FROM process
        WHERE company_id = ?
          AND (id = ? OR sync_source_process_id = ?)
          AND id <> ?
    ");
    $targetsStmt->execute([$companyId, $anchorId, $anchorId, $processId]);
    $rows = $targetsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ids = [];
    foreach ($rows as $row) {
        $pid = isset($row['id']) ? (int)$row['id'] : 0;
        if ($pid > 0) {
            $ids[] = $pid;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Parse profit_sharing text like "STAFF - 50, AA - 10.5" and return total amount.
 */
function parseProfitSharingTotal(?string $profitSharing): string
{
    if ($profitSharing === null) {
        return '0.00000000';
    }

    $text = trim($profitSharing);
    if ($text === '') {
        return '0.00000000';
    }

    $total = '0.00000000';
    if (preg_match_all('/-\s*(-?\d+(?:\.\d+)?)/', $text, $matches)) {
        foreach ($matches[1] as $num) {
            if (money_is_valid($num)) {
                $total = money_add($total, $num);
            }
        }
    }

    return $total;
}

// Handle different actions
$action = $_GET['action'] ?? '';

// --- BEGIN DATA-LEVEL CATEGORY PERMISSION VALIDATION ---
$req_company_id = $_GET['company_id'] ?? $_POST['company_id'] ?? $_SESSION['company_id'] ?? null;
if ($req_company_id) {
    // Actions that are strictly for 'Bank' category
    $bankOnlyActions = [
        'get_banks_by_country', 'get_countries', 'add_country', 'remove_country',
        'save_country_banks', 'remove_bank', 'get_selected_countries', 'save_selected_countries',
        'get_selected_banks', 'save_selected_banks', 'update_bank_process'
    ];

    if (in_array($action, $bankOnlyActions)) {
        if (!checkCompanyCategoryPermission($pdo, $req_company_id, 'Bank')) {
            jsonResponse(false, 'Unauthorized permission category');
            exit;
        }
    } else {
        $reqPermission = trim((string) ($_GET['permission'] ?? $_POST['permission'] ?? ''));
        if ($reqPermission === 'Bank') {
            if (!checkCompanyCategoryPermission($pdo, $req_company_id, 'Bank')) {
                jsonResponse(false, 'Unauthorized permission category');
                exit;
            }
        } elseif ($reqPermission === 'Games' || $reqPermission === 'Gambling') {
            if (!checkCompanyCategoryPermission($pdo, $req_company_id, 'Games')) {
                jsonResponse(false, 'Unauthorized permission category');
                exit;
            }
        } elseif (!checkCompanyGamesOrBankCategoryPermission($pdo, $req_company_id)) {
            // Default process list (incl. Data Capture / capture maintenance on bank-only companies).
            jsonResponse(false, 'Unauthorized permission category');
            exit;
        }
    }
}
// --- END DATA-LEVEL CATEGORY PERMISSION VALIDATION ---

if (isset($pdo) && $pdo instanceof PDO) {
    ensureBankProcessDayEndMonthlyCapEnabledColumn($pdo);
}

switch ($action) {
    case 'get_process':
        getProcess();
        break;
    case 'update_process':
        updateProcess();
        break;
    case 'get_banks_by_country':
        getBanksByCountry();
        break;
    case 'get_countries':
        getCountries();
        break;
    case 'add_country':
        addCountry();
        break;
    case 'remove_country':
        removeCountry();
        break;
    case 'save_country_banks':
        saveCountryBanks();
        break;
    case 'remove_bank':
        removeBank();
        break;
    case 'get_selected_countries':
        getSelectedCountries();
        break;
    case 'save_selected_countries':
        saveSelectedCountries();
        break;
    case 'get_selected_banks':
        getSelectedBanks();
        break;
    case 'save_selected_banks':
        saveSelectedBanks();
        break;
    default:
        getProcesses();
        break;
}

function getProcesses() {
    global $pdo;
    
    try {
        // Bank 类别：从 bank_process 表获取数据，不影响 Games 的 process 表
        if (isset($_GET['permission']) && $_GET['permission'] === 'Bank') {
            getBankProcesses();
            return;
        }

        // 获取 company_id，优先从 URL 参数获取，否则从 session 获取
        $requested_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);

        if (!$requested_company_id) {
            jsonResponse(false, '缺少公司信息', null);
            return;
        }

        if (!checkCompanyAccess($pdo, $requested_company_id)) {
            jsonResponse(false, '您没有权限访问此公司的数据', null);
            return;
        }
        $targetCompanyId = $requested_company_id;
        
        $searchTerm = $_GET['search'] ?? '';
        $showInactive = isset($_GET['showInactive']) && $_GET['showInactive'] == '1';
        $showOfficial = isset($_GET['showOfficial']) && $_GET['showOfficial'] == '1';
        $showEInvoice = isset($_GET['showEInvoice']) && $_GET['showEInvoice'] == '1';
        $showAll = isset($_GET['showAll']) && $_GET['showAll'] == '1';
        
        $hasTxnProcessId = false;
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'process_id'");
            $hasTxnProcessId = $colStmt && $colStmt->rowCount() > 0;
        } catch (PDOException $e) { /* ignore */ }
        
        $sql = "SELECT 
                    p.id,
                    p.process_id,
                    d.name as description_name,
                    c.code as currency_code,
                    p.remove_word,
                    GROUP_CONCAT(day.day_name ORDER BY day.id SEPARATOR ',') as day_names,
                    p.replace_word_from,
                    p.replace_word_to,
                    p.remark,
                    p.dts_modified,
                    " . processModifiedByLoginSql() . " as modified_by_login,
                    p.dts_created,
                    COALESCE(u_created.login_id, o_created.owner_code) as created_by_login,
                    p.status" .
                    ($hasTxnProcessId ? ", (SELECT COUNT(*) FROM transactions t WHERE t.process_id = p.id) AS has_transactions" : "") . "
                FROM process p
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN currency c ON p.currency_id = c.id
                LEFT JOIN process_day pd ON p.id = pd.process_id
                LEFT JOIN day ON pd.day_id = day.id
                LEFT JOIN user u_modified ON p.modified_by = u_modified.id AND (p.modified_by_type IS NULL OR p.modified_by_type = 'user')
                LEFT JOIN owner o_modified ON p.modified_by_owner_id = o_modified.id AND p.modified_by_type = 'owner'
                LEFT JOIN user u_created ON p.created_by = u_created.id
                LEFT JOIN owner o_created ON p.created_by_owner_id = o_created.id
                WHERE 1=1";
        
        $conditions = [];
        $params = [];
        
        // 添加 company_id 过滤
        $conditions[] = "p.company_id = ?";
        $params[] = $targetCompanyId;
        
        if (!empty($searchTerm)) {
            $conditions[] = "(p.process_id LIKE ? OR d.name LIKE ?)";
            $params[] = "%$searchTerm%";
            $params[] = "%$searchTerm%";
        }
        
        // 根据 showAll / showInactive 过滤状态：
        // - 默认 / 仅分页：active
        // - showInactive：inactive（分页）
        // - showAll：全部 active（不分页由前端控制）
        // - showAll + showInactive：全部 inactive
        if ($showAll && $showInactive) {
            $conditions[] = "p.status = 'inactive'";
        } elseif ($showAll) {
            $conditions[] = "p.status = 'active'";
        } elseif ($showInactive) {
            $conditions[] = "p.status = 'inactive'";
        } else {
            $conditions[] = "p.status = 'active'";
        }
        
        if (!empty($conditions)) {
            $baseSql = $sql . ' AND ' . implode(' AND ', $conditions);
        } else {
            $baseSql = $sql;
        }
        
        // 权限过滤 - 使用请求的公司 id（与 p.company_id 一致），避免 session 仍为上一家公司时返回空列表
        list($baseSql, $params) = filterProcessesByPermissions($pdo, $baseSql, $params, $targetCompanyId);
        
        // 添加 GROUP BY 和 ORDER BY
        $baseSql .= " GROUP BY p.id ORDER BY p.dts_created DESC";
        $sql = $baseSql;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理数据格式以匹配前端期望
        $formattedProcesses = [];
        foreach ($processes as $process) {
            $formattedProcesses[] = [
                'id' => $process['id'],
                'process_name' => $process['process_id'],
                'description' => $process['description_name'],
                'status' => $process['status'],
                'currency' => $process['currency_code'],
                'day_use' => $process['day_names'],
                'dts_modified' => $process['dts_modified'],
                'modified_by' => $process['modified_by_login'],
                'dts_created' => $process['dts_created'],
                'created_by' => $process['created_by_login'],
                'remove_word' => $process['remove_word'],
                'replace_word' => $process['replace_word_from'] . ' == ' . $process['replace_word_to'],
                'remarks' => $process['remark'],
                'has_transactions' => $hasTxnProcessId && ((int)($process['has_transactions'] ?? 0)) > 0,
            ];
        }
        
        jsonResponse(true, '', $formattedProcesses);
    } catch (PDOException $e) {
        error_log("Error fetching processes: " . $e->getMessage());
        jsonResponse(false, 'Failed to fetch processes: ' . $e->getMessage(), null);
    }
}

function getProcess() {
    global $pdo;
    
    try {
        // Bank 类别：从 bank_process 表获取单条记录
        if (isset($_GET['permission']) && $_GET['permission'] === 'Bank') {
            getBankProcess();
            return;
        }

        // 获取当前用户的 company_id
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        
        if (!$currentCompanyId) {
            jsonResponse(false, 'User company_id not found in session', null);
            return;
        }
        $processId = $_GET['id'] ?? '';
        if (empty($processId)) {
            jsonResponse(false, 'Process ID is required', null);
            return;
        }
        
        $base = "SELECT 
                    p.id,
                    p.process_id,
                    p.description_id,
                    p.currency_id,
                    c.company_id AS currency_company_id,
                    p.remove_word,
                    p.replace_word_from,
                    p.replace_word_to,
                    p.remark,
                    p.status,
                    p.dts_modified,
                    p.dts_created,
                    d.name as description_name,
                    c.code as currency_code,
                    GROUP_CONCAT(pd.day_id ORDER BY pd.day_id SEPARATOR ',') as day_ids,
                    GROUP_CONCAT(day.day_name ORDER BY day.id SEPARATOR ',') as day_names,
                    " . processModifiedByLoginSql() . " as modified_by_login,
                    COALESCE(u_created.login_id, o_created.owner_code) as created_by_login
                FROM process p
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN currency c ON p.currency_id = c.id
                LEFT JOIN process_day pd ON p.id = pd.process_id
                LEFT JOIN day ON pd.day_id = day.id
                LEFT JOIN user u_modified ON p.modified_by = u_modified.id AND (p.modified_by_type IS NULL OR p.modified_by_type = 'user')
                LEFT JOIN owner o_modified ON p.modified_by_owner_id = o_modified.id AND p.modified_by_type = 'owner'
                LEFT JOIN user u_created ON p.created_by = u_created.id
                LEFT JOIN owner o_created ON p.created_by_owner_id = o_created.id
                WHERE p.id = ? AND p.company_id = ?
                GROUP BY p.id";

        // 权限过滤
        list($sql, $params) = filterProcessesByPermissions($pdo, $base, [$processId, $currentCompanyId]);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($process) {
            // 检查 currency 是否属于当前公司
            $currencyId = null;
            if ($process['currency_id'] && $process['currency_company_id'] == $currentCompanyId) {
                $currencyId = $process['currency_id'];
            }
            
            // 格式化数据以匹配前端期望
            $formattedProcess = [
                'id' => $process['id'],
                'process_name' => $process['process_id'],
                'process_id' => $process['process_id'],
                'description_id' => $process['description_id'],
                'description_names' => $process['description_name'] ? [$process['description_name']] : [],
                'currency_id' => $currencyId, // 只有属于当前公司的 currency 才返回 ID
                'currency_code' => $process['currency_code'], // 返回货币代码用于自动匹配
                'currency_warning' => $process['currency_id'] && $process['currency_company_id'] != $currentCompanyId ? 'Currency does not belong to current company' : null,
                'status' => $process['status'],
                'remove_word' => $process['remove_word'],
                'replace_word_from' => $process['replace_word_from'],
                'replace_word_to' => $process['replace_word_to'],
                'replace_word' => $process['replace_word_from'] . ' == ' . $process['replace_word_to'],
                'remarks' => $process['remark'],
                'day_use' => $process['day_ids'],
                'day_names' => $process['day_names'],
                'dts_modified' => $process['dts_modified'],
                'modified_by' => $process['modified_by_login'],
                'dts_created' => $process['dts_created'],
                'created_by' => $process['created_by_login']
            ];
            
            jsonResponse(true, '', $formattedProcess);
        } else {
            jsonResponse(false, 'Process not found', null);
        }
    } catch (PDOException $e) {
        error_log("Error fetching process: " . $e->getMessage());
        jsonResponse(false, 'Failed to fetch process: ' . $e->getMessage(), null);
    }
}

function updateProcess() {
    global $pdo;
    
    try {
        if (is_partnership_audit_read_only_active($pdo)) {
            jsonResponse(false, '只读账号无法执行此操作', null);
            return;
        }

        // Bank 类别：更新 bank_process 表
        if (isset($_POST['permission']) && $_POST['permission'] === 'Bank') {
            updateBankProcess();
            return;
        }

        // 获取当前用户的 company_id
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        
        if (!$currentCompanyId) {
            jsonResponse(false, 'User company_id not found in session', null);
            return;
        }
        $id = $_POST['id'] ?? '';
        $processId = $_POST['process_name'] ?? '';  // 前端发送的是 process_name，但数据库字段是 process_id
        $description = $_POST['description'] ?? '';
        $currencyId = $_POST['currency_id'] ?? '';
        $removeWord = $_POST['remove_word'] ?? '';
        $replaceWordFrom = $_POST['replace_word_from'] ?? '';
        $replaceWordTo = $_POST['replace_word_to'] ?? '';
        $remark = $_POST['remark'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $dayUse = $_POST['day_use'] ?? '';
        $selectedDescriptions = $_POST['selected_descriptions'] ?? '';
        
        if (empty($id)) {
            jsonResponse(false, 'Process ID is required', null);
            return;
        }
        // 验证 process 是否属于当前用户的 company_id
        $checkStmt = $pdo->prepare("SELECT id, company_id FROM process WHERE id = ?");
        $checkStmt->execute([$id]);
        $process = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$process) {
            jsonResponse(false, 'Process not found', null);
            return;
        }
        if ($process['company_id'] != $currentCompanyId) {
            jsonResponse(false, 'You do not have permission to update this process', null);
            return;
        }
        if (empty($processId) || empty($currencyId)) {
            jsonResponse(false, 'Process Name and Currency are required', null);
            return;
        }
        
        // 开始事务
        $pdo->beginTransaction();
        
        try {
            $targetProcessId = (int)$id;
            $linkedProcessIds = getLinkedProcessIdsForSync($pdo, (int)$currentCompanyId, $targetProcessId);

            $modifier = resolveProcessModifierFromSession($pdo, true);
            $currentUserId = $modifier['modified_by'];
            $modifiedByType = $modifier['modified_by_type'];
            $modifiedByOwnerId = $modifier['modified_by_owner_id'];

            // 更新process基本信息
            $updateSql = "UPDATE process SET 
                            process_id = ?,
                            currency_id = ?,
                            remove_word = ?,
                            replace_word_from = ?,
                            replace_word_to = ?,
                            remark = ?,
                            status = ?,
                            dts_modified = NOW(),
                            modified_by = ?,
                            modified_by_type = ?,
                            modified_by_owner_id = ?
                          WHERE id = ? AND company_id = ?";
            
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute([
                $processId,
                $currencyId,
                $removeWord,
                $replaceWordFrom,
                $replaceWordTo,
                $remark,
                $status,
                $currentUserId,
                $modifiedByType,
                $modifiedByOwnerId,
                $id,
                $currentCompanyId
            ]);

            if (!empty($linkedProcessIds)) {
                $syncSql = "UPDATE process SET
                                currency_id = ?,
                                remove_word = ?,
                                replace_word_from = ?,
                                replace_word_to = ?,
                                remark = ?,
                                status = ?,
                                dts_modified = NOW(),
                                modified_by = ?,
                                modified_by_type = ?,
                                modified_by_owner_id = ?
                            WHERE id = ? AND company_id = ?";
                $syncStmt = $pdo->prepare($syncSql);
                foreach ($linkedProcessIds as $linkedId) {
                    $syncStmt->execute([
                        $currencyId,
                        $removeWord,
                        $replaceWordFrom,
                        $replaceWordTo,
                        $remark,
                        $status,
                        $currentUserId,
                        $modifiedByType,
                        $modifiedByOwnerId,
                        $linkedId,
                        $currentCompanyId
                    ]);
                }
            }
            
            // 处理选中的描述 - 只取第一个描述
            $descriptionId = null;
            if (!empty($selectedDescriptions)) {
                $selectedDescriptionsArray = json_decode($selectedDescriptions, true);
                if (is_array($selectedDescriptionsArray) && !empty($selectedDescriptionsArray)) {
                    // 只取第一个描述
                    $firstDescription = $selectedDescriptionsArray[0];
                    
                    // 获取描述的ID - 添加 company_id 过滤以确保选择正确的描述
                    $stmt = $pdo->prepare("SELECT id FROM description WHERE name = ? AND company_id = ? LIMIT 1");
                    $stmt->execute([$firstDescription, $currentCompanyId]);
                    $descriptionId = $stmt->fetchColumn();
                    
                    // 更新process表的description_id字段
                    if ($descriptionId) {
                        $updateDescSql = 'UPDATE process SET description_id = ?'
                            . processModifiedBySqlSuffix()
                            . ' WHERE id = ?';
                        $stmt = $pdo->prepare($updateDescSql);
                        $stmt->execute(array_merge(
                            [$descriptionId],
                            processModifiedByBindParams($modifier),
                            [$id]
                        ));

                        if (!empty($linkedProcessIds)) {
                            $updateLinkedDescSql = 'UPDATE process SET description_id = ?'
                                . processModifiedBySqlSuffix()
                                . ' WHERE id = ? AND company_id = ?';
                            $updateLinkedDescStmt = $pdo->prepare($updateLinkedDescSql);
                            foreach ($linkedProcessIds as $linkedId) {
                                $updateLinkedDescStmt->execute(array_merge(
                                    [$descriptionId],
                                    processModifiedByBindParams($modifier),
                                    [$linkedId, $currentCompanyId]
                                ));
                            }
                        }
                    }
                }
            }
            
            // 更新day关联
            // 先删除现有的day关联
            $dayIds = !empty($dayUse) ? array_filter(array_map('trim', explode(',', $dayUse))) : [];
            $deleteDaySql = "DELETE FROM process_day WHERE process_id = ?";
            $stmt = $pdo->prepare($deleteDaySql);
            $stmt->execute([$id]);
            
            // 添加新的day关联
            if (!empty($dayIds)) {
                $insertDaySql = "INSERT INTO process_day (process_id, day_id) VALUES (?, ?)";
                $stmt = $pdo->prepare($insertDaySql);
                
                foreach ($dayIds as $dayId) {
                    if (!empty($dayId)) {
                        $stmt->execute([$id, $dayId]);
                    }
                }
            }

            if (!empty($linkedProcessIds)) {
                $deleteLinkedDayStmt = $pdo->prepare("DELETE FROM process_day WHERE process_id = ?");
                $insertLinkedDayStmt = null;
                if (!empty($dayIds)) {
                    $insertLinkedDayStmt = $pdo->prepare("INSERT INTO process_day (process_id, day_id) VALUES (?, ?)");
                }
                foreach ($linkedProcessIds as $linkedId) {
                    $deleteLinkedDayStmt->execute([$linkedId]);
                    if ($insertLinkedDayStmt) {
                        foreach ($dayIds as $dayId) {
                            if (!empty($dayId)) {
                                $insertLinkedDayStmt->execute([$linkedId, $dayId]);
                            }
                        }
                    }
                }
            }
            
            $pdo->commit();
            jsonResponse(true, 'Process updated successfully!', null);
        } catch (Exception $e) {
            // 回滚事务
            $pdo->rollback();
            throw $e;
        }
        
    } catch (PDOException $e) {
        error_log("Error updating process: " . $e->getMessage());
        jsonResponse(false, 'Failed to update process: ' . $e->getMessage(), null);
    } catch (Exception $e) {
        error_log("Error updating process: " . $e->getMessage());
        jsonResponse(false, 'Failed to update process: ' . $e->getMessage(), null);
    }
}

/**
 * Bank 类别：从 bank_process 表获取列表，不影响 Games 的 process 表
 */
function getBankProcesses() {
    global $pdo;
    try {
        $requested_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$requested_company_id) {
            jsonResponse(false, '缺少公司信息', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $requested_company_id)) {
            jsonResponse(false, '您没有权限访问此公司的数据', null);
            return;
        }
        $targetCompanyId = $requested_company_id;
        $searchTerm = $_GET['search'] ?? '';
        $showInactive = isset($_GET['showInactive']) && $_GET['showInactive'] == '1';
        $showAll = isset($_GET['showAll']) && $_GET['showAll'] == '1';
        $showOfficial = isset($_GET['showOfficial']) && $_GET['showOfficial'] == '1';
        $showEInvoice = isset($_GET['showEInvoice']) && $_GET['showEInvoice'] == '1';
        $showBlock = isset($_GET['showBlock']) && $_GET['showBlock'] == '1';

        // static 缓存：每次请求只查一次 schema，避免重复 SHOW COLUMNS/TABLES
        static $bankSchema = null;
        if ($bankSchema === null) {
            $hasSourceBankProcessId = false;
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
                $hasSourceBankProcessId = $colStmt && $colStmt->rowCount() > 0;
            } catch (PDOException $e) { /* ignore */ }

            $hasResendPendingTable = false;
            try {
                $rt = $pdo->query("SHOW TABLES LIKE 'bank_process_maintenance_resend_pending'");
                $hasResendPendingTable = $rt && $rt->rowCount() > 0;
            } catch (PDOException $e) { /* ignore */ }

            $hasResendDailyGuardTable = false;
            try {
                $gt = $pdo->query("SHOW TABLES LIKE 'bank_process_accounting_resend_daily_guard'");
                $hasResendDailyGuardTable = $gt && $gt->rowCount() > 0;
            } catch (PDOException $e) { /* ignore */ }

            $bankSchema = [
                'has_source_bank_process_id'  => $hasSourceBankProcessId,
                'has_resend_pending_table'    => $hasResendPendingTable,
                'has_resend_daily_guard_table'=> $hasResendDailyGuardTable,
            ];
        }
        $hasSourceBankProcessId = $bankSchema['has_source_bank_process_id'];
        $hasResendPendingTable  = $bankSchema['has_resend_pending_table'];
        $hasResendDailyGuardTable = $bankSchema['has_resend_daily_guard_table'];

        // bankProcessHasColumn() 内部已有 static 缓存，两次调用只查一次 SHOW COLUMNS
        $hasIssueFlagColumn = bankProcessHasColumn($pdo, 'issue_flag');
        $hasFlagColumn = bankProcessHasColumn($pdo, 'flag');
        $hasAnyIssueFlagColumn = $hasIssueFlagColumn || $hasFlagColumn;
        $hasDayEndMonthlyCapColumn = bankProcessHasColumn($pdo, 'day_end_monthly_cap_enabled');
        $dayEndMonthlyCapSelect = $hasDayEndMonthlyCapColumn ? "bp.day_end_monthly_cap_enabled" : "0 AS day_end_monthly_cap_enabled";
        $hasTxnSubquery = $hasSourceBankProcessId
            ? "(SELECT COUNT(*) FROM transactions t WHERE t.source_bank_process_id = bp.id AND t.company_id = bp.company_id)"
            : "(SELECT COUNT(*) FROM process_accounting_posted pap WHERE pap.process_id = bp.id AND pap.company_id = bp.company_id)";
        $resendPendingSelect = $hasResendPendingTable
            ? "(EXISTS (SELECT 1 FROM bank_process_maintenance_resend_pending rp WHERE rp.company_id = bp.company_id AND rp.bank_process_id = bp.id)) AS maintenance_resend_pending"
            : "0 AS maintenance_resend_pending";
        $resendTodayDayStartLockedSelect = $hasResendDailyGuardTable && $hasSourceBankProcessId
            ? "(EXISTS (
                SELECT 1 FROM bank_process_accounting_resend_daily_guard rg
                INNER JOIN transactions t ON t.source_bank_process_id = bp.id
                  AND DATE(t.transaction_date) = rg.resend_day_start
                INNER JOIN account a ON t.account_id = a.id
                INNER JOIN account_company ac ON a.id = ac.account_id AND ac.company_id = bp.company_id
                WHERE rg.company_id = bp.company_id
                  AND rg.bank_process_id = bp.id
                  AND rg.guard_date = CURDATE()
                LIMIT 1
              )) AS resend_today_day_start_locked"
            : ($hasResendDailyGuardTable
                ? "(EXISTS (SELECT 1 FROM bank_process_accounting_resend_daily_guard rg WHERE rg.company_id = bp.company_id AND rg.bank_process_id = bp.id AND rg.guard_date = CURDATE())) AS resend_today_day_start_locked"
                : "0 AS resend_today_day_start_locked");
        $resendGuardDayStartsTodaySelect = $hasResendDailyGuardTable && $hasSourceBankProcessId
            ? "(SELECT GROUP_CONCAT(DISTINCT DATE_FORMAT(rg2.resend_day_start, '%Y-%m-%d') ORDER BY rg2.resend_day_start SEPARATOR ',')
                FROM bank_process_accounting_resend_daily_guard rg2
               WHERE rg2.company_id = bp.company_id
                 AND rg2.bank_process_id = bp.id
                 AND rg2.guard_date = CURDATE()
                 AND EXISTS (
                   SELECT 1 FROM transactions t2
                   INNER JOIN account a2 ON t2.account_id = a2.id
                   INNER JOIN account_company ac2 ON a2.id = ac2.account_id AND ac2.company_id = bp.company_id
                   WHERE t2.source_bank_process_id = bp.id
                     AND DATE(t2.transaction_date) = rg2.resend_day_start
                 )) AS resend_guard_day_starts_today"
            : ($hasResendDailyGuardTable
                ? "(SELECT GROUP_CONCAT(DISTINCT DATE_FORMAT(rg2.resend_day_start, '%Y-%m-%d') ORDER BY rg2.resend_day_start SEPARATOR ',')
                    FROM bank_process_accounting_resend_daily_guard rg2
                   WHERE rg2.company_id = bp.company_id
                     AND rg2.bank_process_id = bp.id
                     AND rg2.guard_date = CURDATE()) AS resend_guard_day_starts_today"
                : "'' AS resend_guard_day_starts_today");
        $issueFlagSql = getBankProcessIssueFlagSql('bp', $hasIssueFlagColumn, $hasFlagColumn);
        $issueFlagSelect = $hasAnyIssueFlagColumn ? $issueFlagSql . " AS issue_flag" : "NULL AS issue_flag";
        $normalizedIssueFlagSql = $hasAnyIssueFlagColumn
            ? "LOWER(REPLACE(REPLACE(TRIM(COALESCE($issueFlagSql, '')), '-', '_'), ' ', '_'))"
            : "''";
        $defaultVisibleClause = $hasAnyIssueFlagColumn
            ? "(bp.status = 'active' AND (" . $normalizedIssueFlagSql . " = '' OR " . $normalizedIssueFlagSql . " NOT IN ('official', 'e_invoice', 'block')))"
            : "bp.status = 'active'";

        $sql = "SELECT 
                    bp.id,
                    bp.country,
                    bp.bank,
                    bp.type,
                    bp.name,
                    bp.card_merchant_id,
                    bp.customer_id,
                    bp.contract,
                    bp.insurance,
                    bp.remark,
                    bp.cost,
                    bp.price,
                    bp.profit,
                    bp.profit_sharing,
                    bp.day_start,
                    bp.day_start_frequency,
                    bp.day_end,
                    $dayEndMonthlyCapSelect,
                    bp.status,
                    $issueFlagSelect,
                    bp.dts_modified,
                    a_cm.name as card_merchant_name,
                    a_cm.account_id as card_merchant_account_id,
                    a_cust.account_id as customer_account,
                    $hasTxnSubquery AS has_transactions,
                    $resendPendingSelect,
                    $resendTodayDayStartLockedSelect,
                    $resendGuardDayStartsTodaySelect
                FROM bank_process bp
                LEFT JOIN account a_cm ON bp.card_merchant_id = a_cm.id
                LEFT JOIN account a_cust ON bp.customer_id = a_cust.id
                WHERE bp.company_id = ?";
        $params = [$targetCompanyId];
        if (!empty($searchTerm)) {
            // Bank 列表搜索需要覆盖页面上实际会看到的 Supplier / Card Owner / Customer 文本。
            // 这些列的显示值来自 bank_process 与关联 account 表的不同字段，
            // 统一在这里补齐，避免列表看得到但搜不到。
            $sql .= " AND (
                bp.country LIKE ?
                OR bp.bank LIKE ?
                OR bp.type LIKE ?
                OR bp.name LIKE ?
                OR a_cm.account_id LIKE ?
                OR a_cm.name LIKE ?
                OR a_cust.account_id LIKE ?
                OR a_cust.name LIKE ?
            )";
            $term = "%$searchTerm%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        $hasSpecificFilter = $showInactive || $showOfficial || $showEInvoice || $showBlock;
        if ($showAll) {
            // no additional filter
        } elseif (!$hasSpecificFilter) {
            $sql .= " AND $defaultVisibleClause";
        } else {
            $filterClauses = [];
            if ($showInactive) {
                $filterClauses[] = "bp.status = 'inactive'";
            }
            if ($showOfficial && $hasAnyIssueFlagColumn) {
                $filterClauses[] = $normalizedIssueFlagSql . " = 'official'";
            }
            if ($showEInvoice && $hasAnyIssueFlagColumn) {
                $filterClauses[] = $normalizedIssueFlagSql . " = 'e_invoice'";
            }
            if ($showBlock && $hasAnyIssueFlagColumn) {
                $filterClauses[] = $normalizedIssueFlagSql . " = 'block'";
            }

            if (empty($filterClauses)) {
                $sql .= " AND 1 = 0";
            } else {
                $sql .= " AND (" . implode(' OR ', array_unique($filterClauses)) . ")";
            }
        }
        $sql .= " ORDER BY bp.dts_created DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedProcesses = [];
        foreach ($rows as $r) {
            $storedProfit = isset($r['profit']) && $r['profit'] !== '' ? money_normalize($r['profit']) : '0.00000000';
            $profitSharingTotal = parseProfitSharingTotal($r['profit_sharing'] ?? null);
            $netProfit = money_sub($storedProfit, $profitSharingTotal);
            if (money_cmp($netProfit, '0') < 0) {
                $netProfit = '0.00000000';
            }
            $issueFlag = normalizeBankIssueFlagValue($r['issue_flag'] ?? null);
            $formattedProcesses[] = [
                'id' => $r['id'],
                'supplier' => $r['name'] ?? '',
                'country' => $r['country'] ?? '',
                'bank' => $r['bank'] ?? '',
                'types' => $r['type'] ?? '',
                'card_lower' => $r['card_merchant_account_id'] ?? '',
                'contract' => $r['contract'] ?? '',
                'insurance' => $r['insurance'] !== null && $r['insurance'] !== '' ? money_out($r['insurance']) : '',
                'customer' => $r['customer_account'] ?? '',
                'cost' => $r['cost'] !== null && $r['cost'] !== '' ? money_out($r['cost']) : '',
                'price' => $r['price'] !== null && $r['price'] !== '' ? money_out($r['price']) : '',
                'profit' => money_out($netProfit),
                'status' => $r['status'],
                'issue_flag' => $issueFlag,
                'remark' => $r['remark'] ?? '',
                'date' => $r['day_start'] ?? '',
                'day_start' => $r['day_start'] ?? null,
                'day_start_frequency' => $r['day_start_frequency'] ?? '1st_of_every_month',
                'day_end' => $r['day_end'] ?? null,
                'day_end_monthly_cap_enabled' => ((int)($r['day_end_monthly_cap_enabled'] ?? 0)) === 1 ? '1' : '0',
                'has_transactions' => ((int)($r['has_transactions'] ?? 0)) > 0,
                'maintenance_resend_pending' => ((int) ($r['maintenance_resend_pending'] ?? 0)) === 1,
                'resend_today_day_start_locked' => ((int) ($r['resend_today_day_start_locked'] ?? 0)) === 1,
                'resend_guard_day_starts_today' => isset($r['resend_guard_day_starts_today']) ? (string) $r['resend_guard_day_starts_today'] : '',
            ];
        }
        jsonResponse(true, '', $formattedProcesses);
    } catch (PDOException $e) {
        error_log("getBankProcesses: " . $e->getMessage());
        jsonResponse(false, 'Failed to fetch bank processes: ' . $e->getMessage(), null);
    }
}

/**
 * Bank 类别：从 bank_process 表获取单条记录（编辑用）
 */
function getBankProcess() {
    global $pdo;
    try {
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        if (!$currentCompanyId) {
            jsonResponse(false, 'User company_id not found in session', null);
            return;
        }
        $processId = $_GET['id'] ?? '';
        if (empty($processId)) {
            jsonResponse(false, 'Process ID is required', null);
            return;
        }
        $hasSopColumn = bankProcessHasColumn($pdo, 'sop');
        $hasDayEndTailSwitchCol = bankProcessHasColumn($pdo, 'day_end_monthly_cap_enabled');
        $hasIssueFlagColumn = bankProcessHasColumn($pdo, 'issue_flag');
        $hasFlagColumn = bankProcessHasColumn($pdo, 'flag');
        $hasAnyIssueFlagColumn = $hasIssueFlagColumn || $hasFlagColumn;
        $sopSelect = $hasSopColumn ? "bp.sop" : "NULL AS sop";
        $issueFlagSelect = $hasAnyIssueFlagColumn ? getBankProcessIssueFlagSql('bp', $hasIssueFlagColumn, $hasFlagColumn) . " AS issue_flag" : "NULL AS issue_flag";
        $dayEndMonthlyCapSelect = $hasDayEndTailSwitchCol ? "bp.day_end_monthly_cap_enabled" : "0 AS day_end_monthly_cap_enabled";
        $stmt = $pdo->prepare("SELECT 
                bp.id, bp.country, bp.bank, bp.type, bp.name,
                bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.contract, bp.insurance, bp.remark, $sopSelect,
                bp.cost, bp.price, bp.profit, bp.profit_sharing, bp.day_start, bp.day_start_frequency, bp.day_end, $dayEndMonthlyCapSelect, bp.status, $issueFlagSelect,
                bp.dts_modified, bp.dts_created,
                " . bankProcessModifiedByLoginSql() . " as modified_by_login,
                COALESCE(u_created.login_id, o_created.owner_code) as created_by_login,
                a_cm.account_id as card_merchant_account_id, a_cm.name as card_merchant_name, a_cust.account_id as customer_account, a_cust.name as customer_name,
                a_pa.account_id as profit_account_account_id, a_pa.name as profit_account_name
            FROM bank_process bp
            LEFT JOIN account a_cm ON bp.card_merchant_id = a_cm.id
            LEFT JOIN account a_cust ON bp.customer_id = a_cust.id
            LEFT JOIN account a_pa ON bp.profit_account_id = a_pa.id
            LEFT JOIN user u_modified ON bp.modified_by = u_modified.id AND (bp.modified_by_type IS NULL OR bp.modified_by_type = 'user')
            LEFT JOIN owner o_modified ON bp.modified_by_owner_id = o_modified.id AND bp.modified_by_type = 'owner'
            LEFT JOIN user u_created ON bp.created_by = u_created.id
            LEFT JOIN owner o_created ON bp.created_by_owner_id = o_created.id
            WHERE bp.id = ? AND bp.company_id = ?");
        $stmt->execute([$processId, $currentCompanyId]);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$process) {
            jsonResponse(false, 'Process not found', null);
            return;
        }
        $formatted = [
            'id' => $process['id'],
            'process_name' => $process['name'] ?: $process['bank'],
            'country' => $process['country'],
            'bank' => $process['bank'],
            'type' => $process['type'],
            'name' => $process['name'],
            'card_merchant_id' => $process['card_merchant_id'],
            'customer_id' => $process['customer_id'],
            'profit_account_id' => $process['profit_account_id'] ?? null,
            'profit_account_account_id' => $process['profit_account_account_id'] ?? '',
            'profit_account_name' => $process['profit_account_name'] ?? '',
            'card_merchant_account_id' => $process['card_merchant_account_id'] ?? '',
            'card_merchant_name' => $process['card_merchant_name'],
            'customer_name' => $process['customer_name'],
            'customer_account' => $process['customer_account'] ?? '',
            'contract' => $process['contract'],
            'insurance' => $process['insurance'] !== null && $process['insurance'] !== '' ? money_out($process['insurance']) : '',
            'sop' => $process['sop'] ?? '',
            'remark' => $process['remark'] ?? '',
            'cost' => $process['cost'] !== null && $process['cost'] !== '' ? money_out($process['cost']) : '',
            'price' => $process['price'] !== null && $process['price'] !== '' ? money_out($process['price']) : '',
            'profit' => $process['profit'] !== null && $process['profit'] !== '' ? money_out($process['profit']) : '',
            'profit_sharing' => $process['profit_sharing'],
            'day_start' => $process['day_start'],
            'day_start_frequency' => $process['day_start_frequency'] ?? '1st_of_every_month',
            'day_end' => $process['day_end'] ?? null,
            'day_end_monthly_cap_enabled' => ((int)($process['day_end_monthly_cap_enabled'] ?? 0)) === 1 ? '1' : '0',
            'status' => $process['status'],
            'issue_flag' => normalizeBankIssueFlagValue($process['issue_flag'] ?? null),
            'dts_modified' => $process['dts_modified'],
            'dts_created' => $process['dts_created'],
            'modified_by' => $process['modified_by_login'] ?? '',
            'created_by' => $process['created_by_login'] ?? '',
        ];
        jsonResponse(true, '', $formatted);
    } catch (PDOException $e) {
        error_log("getBankProcess: " . $e->getMessage());
        jsonResponse(false, 'Failed to fetch bank process: ' . $e->getMessage(), null);
    }
}

/**
 * Bank 类别：更新 bank_process 表
 */
function updateBankProcess() {
    global $pdo;
    try {
        $currentCompanyId = $_SESSION['company_id'] ?? null;
        if (!$currentCompanyId) {
            jsonResponse(false, 'User company_id not found in session', null);
            return;
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            jsonResponse(false, 'Process ID is required', null);
            return;
        }
        $checkStmt = $pdo->prepare("SELECT id, country, bank, type, name FROM bank_process WHERE id = ? AND company_id = ?");
        $checkStmt->execute([$id, $currentCompanyId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            jsonResponse(false, 'Process not found or no permission', null);
            return;
        }
        $country = $existing['country'] ?? null;
        $bank = $existing['bank'] ?? null;
        $type = $existing['type'] ?? null;
        $name = $existing['name'] ?? null;
        $card_merchant_id = !empty($_POST['card_merchant_id']) ? (int)$_POST['card_merchant_id'] : null;
        $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
        $profit_account_id = !empty($_POST['profit_account_id']) ? (int)$_POST['profit_account_id'] : null;
        $contract = $_POST['contract'] ?? null;
        $insurance = money_optional($_POST['insurance'] ?? null);
        $sop = trim($_POST['sop'] ?? '');
        $remark = trim($_POST['remark'] ?? '');
        $cost = money_optional($_POST['cost'] ?? null);
        $price = money_optional($_POST['price'] ?? null);
        $profit = money_optional($_POST['profit'] ?? null);
        $profit_sharing = $_POST['profit_sharing'] ?? null;
        $day_start_raw = $_POST['day_start'] ?? '';
        $day_start = trim((string)(is_array($day_start_raw) ? (string)end($day_start_raw) : $day_start_raw));
        $day_end_raw = $_POST['day_end'] ?? '';
        $day_end = trim((string)(is_array($day_end_raw) ? (string)end($day_end_raw) : $day_end_raw));
        $day_start_frequency_raw = $_POST['day_start_frequency'] ?? '1st_of_every_month';
        $day_start_frequency = trim((string)(is_array($day_start_frequency_raw) ? (string)end($day_start_frequency_raw) : $day_start_frequency_raw));
        if (!in_array($day_start_frequency, ['monthly', 'week', 'day', 'once', '1st_of_every_month'], true)) {
            $day_start_frequency = '1st_of_every_month';
        }
        if ($day_start_frequency === 'once' || $day_start_frequency === 'week' || $day_start_frequency === 'day') {
            $day_end = null;
            if ($day_start_frequency === 'week' || $day_start_frequency === 'day') {
                $contract = '';
            }
        }
        if ($day_start === '') {
            $day_start = null;
        }
        if ($day_end === '') {
            $day_end = null;
        }
        $day_end_cap_raw = $_POST['day_end_monthly_cap_enabled'] ?? null;
        if (is_array($day_end_cap_raw)) {
            $day_end_cap_raw = end($day_end_cap_raw);
        }
        $dayEndMonthlyCapEnabled = $day_end_cap_raw !== null && trim((string)$day_end_cap_raw) === '1';
        if ($day_start_frequency !== '1st_of_every_month' || $day_end === null) {
            $dayEndMonthlyCapEnabled = false;
        }
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active', 'inactive', 'waiting'], true)) {
            $status = 'active';
        }
        $modifier = resolveProcessModifierFromSession($pdo, true);
        $currentUserId = $modifier['modified_by'];
        $modifiedByType = $modifier['modified_by_type'];
        $modifiedByOwnerId = $modifier['modified_by_owner_id'];
        $hasSopColumn = bankProcessHasColumn($pdo, 'sop');
        $hasDayEndTailSwitchCol = bankProcessHasColumn($pdo, 'day_end_monthly_cap_enabled');
        $sql = "UPDATE bank_process SET 
            country=?, bank=?, type=?, name=?, card_merchant_id=?, customer_id=?, profit_account_id=?,
            contract=?, insurance=?, ";
        $params = [
            $country, $bank, $type, $name, $card_merchant_id, $customer_id, $profit_account_id,
            $contract, $insurance
        ];
        if ($hasSopColumn) {
            $sql .= "sop=?, ";
            $params[] = $sop;
        }
        $sql .= "remark=?, cost=?, price=?, profit=?, profit_sharing=?, day_start=?, day_end=?, day_start_frequency=?";
        if ($hasDayEndTailSwitchCol) {
            $sql .= ", day_end_monthly_cap_enabled=?";
        }
        $sql .= ", status=?,
            dts_modified=NOW(), modified_by=?, modified_by_type=?, modified_by_owner_id=?
            WHERE id=? AND company_id=?";
        array_push(
            $params,
            $remark, $cost, $price, $profit, $profit_sharing, $day_start, $day_end, $day_start_frequency
        );
        if ($hasDayEndTailSwitchCol) {
            $params[] = $dayEndMonthlyCapEnabled ? 1 : 0;
        }
        array_push(
            $params,
            $status,
            $currentUserId, $modifiedByType, $modifiedByOwnerId, $id, $currentCompanyId
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($country !== '' && $bank !== '') {
            try {
                $ins = $pdo->prepare("INSERT IGNORE INTO country_bank (company_id, country, bank) VALUES (?, ?, ?)");
                $ins->execute([$currentCompanyId, $country, $bank]);
            } catch (Exception $e) { /* ignore */ }
        }
        jsonResponse(true, 'Process updated successfully!', null);
    } catch (Exception $e) {
        error_log("updateBankProcess: " . $e->getMessage());
        jsonResponse(false, 'Failed to update process: ' . $e->getMessage(), null);
    }
}

/**
 * 按 Country 获取该 Country 下的 Bank 列表（用于 Bank 下拉联动）
 */
/**
 * Ensure account-list currency row exists for a bank-process country/currency code.
 * Keeps Add Account → Other Currency in sync when countries are added on Bank Process.
 */
function ensureCompanyCurrencyCode(PDO $pdo, int $companyId, string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, code FROM currency WHERE code = ? AND company_id = ?");
    $stmt->execute([$code, $companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['id' => (int) $row['id'], 'code' => (string) $row['code']];
    }
    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
    $stmt->execute([$code, $companyId]);
    return ['id' => (int) $pdo->lastInsertId(), 'code' => $code];
}

/**
 * Sync all given country codes into the currency table for the company.
 */
function ensureCompanyCurrencyCodes(PDO $pdo, int $companyId, array $codes): void
{
    foreach ($codes as $code) {
        ensureCompanyCurrencyCode($pdo, $companyId, (string) $code);
    }
}

/**
 * Remove matching currency row when a bank-process country is deleted.
 * Skips delete when any account in the company still links to the currency.
 *
 * @return array{deleted: bool, id: int|null, blocked: bool}
 */
function deleteCompanyCurrencyCode(PDO $pdo, int $companyId, string $code): array
{
    $code = strtoupper(trim($code));
    $result = ['deleted' => false, 'id' => null, 'blocked' => false];
    if ($code === '') {
        return $result;
    }

    $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
    $stmt->execute([$code, $companyId]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        return $result;
    }
    $id = (int) $id;
    $result['id'] = $id;

    try {
        $chk = $pdo->query("SHOW TABLES LIKE 'account_currency'");
        if ($chk && $chk->rowCount() > 0) {
            $chkAc = $pdo->query("SHOW TABLES LIKE 'account_company'");
            if ($chkAc && $chkAc->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT ac.account_id)
                    FROM account_currency ac
                    INNER JOIN account_company acc ON ac.account_id = acc.account_id
                    WHERE ac.currency_id = ? AND acc.company_id = ?
                ");
                $stmt->execute([$id, $companyId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $result['blocked'] = true;
                    return $result;
                }
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(DISTINCT account_id) FROM account_currency WHERE currency_id = ?");
                $stmt->execute([$id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $result['blocked'] = true;
                    return $result;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('deleteCompanyCurrencyCode usage check: ' . $e->getMessage());
    }

    $stmt = $pdo->prepare("DELETE FROM currency WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    $result['deleted'] = $stmt->rowCount() > 0;
    return $result;
}

/**
 * Get all countries for the current company (from country_bank + company_countries).
 * Used to populate Country dropdown. Accepts company_id from GET to scope by selected company (like account-list currency).
 */
function getCountries() {
    global $pdo;
    try {
        $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $stmt = $pdo->prepare("
            SELECT DISTINCT country FROM (
                SELECT country FROM country_bank WHERE company_id = ?
                UNION
                SELECT country FROM company_countries WHERE company_id = ?
            ) t ORDER BY country ASC
        ");
        $stmt->execute([$companyId, $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        jsonResponse(true, '', array_values($rows));
    } catch (Exception $e) {
        error_log("getCountries: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), []);
    }
}

/**
 * Add a new country for the current company (persist so it survives refresh).
 * Accepts company_id from POST to add to the selected company only (like account-list currency).
 */
function addCountry() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed', null);
        return;
    }
    try {
        $companyId = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $country = isset($_POST['country']) ? trim((string)$_POST['country']) : '';
        if ($country === '') {
            jsonResponse(false, 'Country name is required', null);
            return;
        }
        $stmt = $pdo->prepare("INSERT IGNORE INTO company_countries (company_id, country) VALUES (?, ?)");
        $stmt->execute([$companyId, $country]);
        $currency = ensureCompanyCurrencyCode($pdo, $companyId, $country);
        jsonResponse(true, 'Saved', $currency);
    } catch (Exception $e) {
        error_log("addCountry: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), null);
    }
}

/**
 * Remove a user-added country row from company_countries (red X on Available list).
 * Countries that only appear from country_bank or account currencies are unchanged in DB here;
 * the client still drops them from the session list until the next full reload from those sources.
 */
function removeCountry() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed', null);
        return;
    }
    try {
        $companyId = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $country = isset($_POST['country']) ? trim((string)$_POST['country']) : '';
        if ($country === '') {
            jsonResponse(false, 'Country is required', null);
            return;
        }
        $stmt = $pdo->prepare("DELETE FROM company_countries WHERE company_id = ? AND country = ?");
        $stmt->execute([$companyId, $country]);
        $companyCountriesDeleted = (int) $stmt->rowCount();

        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'company_selected_countries'");
            if ($chk && $chk->rowCount() > 0) {
                $delSel = $pdo->prepare("DELETE FROM company_selected_countries WHERE company_id = ? AND country = ?");
                $delSel->execute([$companyId, $country]);
            }
        } catch (Throwable $e) {
            error_log('removeCountry selected countries: ' . $e->getMessage());
        }

        $currencyResult = deleteCompanyCurrencyCode($pdo, $companyId, $country);
        jsonResponse(true, 'Removed', [
            'deleted' => $companyCountriesDeleted,
            'currency_id' => $currencyResult['id'],
            'currency_deleted' => $currencyResult['deleted'],
            'currency_blocked' => $currencyResult['blocked'],
        ]);
    } catch (Exception $e) {
        error_log("removeCountry: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), null);
    }
}

/**
 * Remove a bank row from country_bank for the given company and country.
 */
function removeBank() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed', null);
        return;
    }
    try {
        $companyId = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $country = isset($_POST['country']) ? trim((string)$_POST['country']) : '';
        $bank = isset($_POST['bank']) ? trim((string)$_POST['bank']) : '';
        if ($country === '' || $bank === '') {
            jsonResponse(false, 'Country and bank are required', null);
            return;
        }
        $stmt = $pdo->prepare("DELETE FROM country_bank WHERE company_id = ? AND country = ? AND bank = ?");
        $stmt->execute([$companyId, $country, $bank]);
        jsonResponse(true, 'Removed', ['deleted' => (int) $stmt->rowCount()]);
    } catch (Exception $e) {
        error_log("removeBank: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), null);
    }
}

/**
 * Get banks for a country, scoped by company (GET company_id, else session).
 */
function getBanksByCountry() {
    global $pdo;
    try {
        $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $country = isset($_GET['country']) ? trim((string)$_GET['country']) : '';
        if ($country === '') {
            jsonResponse(true, '', []);
            return;
        }
        $stmt = $pdo->prepare("SELECT bank FROM country_bank WHERE company_id = ? AND country = ? ORDER BY bank ASC");
        $stmt->execute([$companyId, $country]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        jsonResponse(true, '', array_values($rows));
    } catch (Exception $e) {
        error_log("getBanksByCountry: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), []);
    }
}

/**
 * 保存 Country-Bank 关联（确保这些 bank 都 under 当前 country）。支持 POST company_id 指定公司。
 */
function saveCountryBanks() {
    global $pdo;
    try {
        $companyId = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $country = isset($_POST['country']) ? trim((string)$_POST['country']) : '';
        $banks = isset($_POST['banks']) ? $_POST['banks'] : [];
        if (!is_array($banks)) $banks = [];
        if ($country === '') {
            jsonResponse(true, 'No country', null);
            return;
        }
        foreach ($banks as $bank) {
            $bank = trim((string)$bank);
            if ($bank === '') continue;
            $stmt = $pdo->prepare("INSERT IGNORE INTO country_bank (company_id, country, bank) VALUES (?, ?, ?)");
            $stmt->execute([$companyId, $country, $bank]);
        }
        jsonResponse(true, 'Saved', null);
    } catch (Exception $e) {
        error_log("saveCountryBanks: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), null);
    }
}

/**
 * 获取该公司在下拉中显示的已选 Country 列表（持久化，登出/换设备后仍保持）
 */
function getSelectedCountries() {
    global $pdo;
    try {
        $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $tableExists = false;
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'company_selected_countries'");
            $tableExists = $chk && $chk->rowCount() > 0;
        } catch (Throwable $e) { /* ignore */ }
        if (!$tableExists) {
            jsonResponse(true, '', []);
            return;
        }
        $stmt = $pdo->prepare("SELECT country FROM company_selected_countries WHERE company_id = ? ORDER BY sort_order ASC, country ASC");
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        jsonResponse(true, '', array_values($rows));
    } catch (Exception $e) {
        error_log("getSelectedCountries: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), []);
    }
}

/**
 * 保存该公司在下拉中显示的已选 Country 列表（持久化）
 */
function saveSelectedCountries() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed', null);
        return;
    }
    try {
        $companyId = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $countries = isset($_POST['countries']) ? $_POST['countries'] : [];
        if (!is_array($countries)) $countries = [];
        $countries = array_values(array_unique(array_filter(array_map(function ($c) {
            return trim((string)$c);
        }, $countries))));

        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'company_selected_countries'");
            if (!$chk || $chk->rowCount() === 0) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS company_selected_countries (
                    company_id INT UNSIGNED NOT NULL,
                    country VARCHAR(100) NOT NULL,
                    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (company_id, country),
                    INDEX idx_company_selected_countries_company (company_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (Throwable $e) {
            error_log("saveSelectedCountries create table: " . $e->getMessage());
            jsonResponse(false, 'Database error', null);
            return;
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM company_selected_countries WHERE company_id = ?");
            $del->execute([$companyId]);
            $ins = $pdo->prepare("INSERT IGNORE INTO company_selected_countries (company_id, country, sort_order) VALUES (?, ?, ?)");
            foreach ($countries as $i => $country) {
                if ($country === '') continue;
                $ins->execute([$companyId, $country, $i]);
            }
            $pdo->commit();
            ensureCompanyCurrencyCodes($pdo, $companyId, $countries);
            jsonResponse(true, 'Saved', null);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        error_log("saveSelectedCountries: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), null);
    }
}

/**
 * 获取该公司每个 Country 在下拉中显示的已选 Bank 列表（持久化，登出/换设备后仍保持）
 * 返回 { "AA": ["b1","b2"], "ABC": ["b3"] }
 */
function getSelectedBanks() {
    global $pdo;
    try {
        $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $tableExists = false;
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'company_selected_banks'");
            $tableExists = $chk && $chk->rowCount() > 0;
        } catch (Throwable $e) { /* ignore */ }
        if (!$tableExists) {
            jsonResponse(true, '', (object)[]);
            return;
        }
        $stmt = $pdo->prepare("SELECT country, bank, sort_order FROM company_selected_banks WHERE company_id = ? ORDER BY country ASC, sort_order ASC, bank ASC");
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byCountry = [];
        foreach ($rows as $r) {
            $c = trim((string)($r['country'] ?? ''));
            $b = trim((string)($r['bank'] ?? ''));
            if ($c === '' || $b === '') continue;
            if (!isset($byCountry[$c])) $byCountry[$c] = [];
            $byCountry[$c][] = $b;
        }
        jsonResponse(true, '', $byCountry);
    } catch (Exception $e) {
        error_log("getSelectedBanks: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), []);
    }
}

/**
 * 保存该公司每个 Country 在下拉中显示的已选 Bank 列表（持久化）
 * POST: company_id, selected (JSON 对象 { "AA": ["b1","b2"], "ABC": ["b3"] })
 */
function saveSelectedBanks() {
    global $pdo;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Method not allowed', null);
        return;
    }
    try {
        $companyId = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : ($_SESSION['company_id'] ?? null);
        if (!$companyId) {
            jsonResponse(false, 'Company not found', null);
            return;
        }
        if (!checkCompanyAccess($pdo, $companyId)) {
            jsonResponse(false, '无权限访问该公司', null);
            return;
        }
        $selected = isset($_POST['selected']) ? $_POST['selected'] : null;
        if (is_string($selected)) {
            $decoded = json_decode($selected, true);
            $selected = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($selected)) $selected = [];

        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'company_selected_banks'");
            if (!$chk || $chk->rowCount() === 0) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS company_selected_banks (
                    company_id INT UNSIGNED NOT NULL,
                    country VARCHAR(100) NOT NULL,
                    bank VARCHAR(200) NOT NULL,
                    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (company_id, country, bank),
                    INDEX idx_company_selected_banks_company (company_id),
                    INDEX idx_company_selected_banks_country (company_id, country)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (Throwable $e) {
            error_log("saveSelectedBanks create table: " . $e->getMessage());
            jsonResponse(false, 'Database error', null);
            return;
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM company_selected_banks WHERE company_id = ?");
            $del->execute([$companyId]);
            $ins = $pdo->prepare("INSERT IGNORE INTO company_selected_banks (company_id, country, bank, sort_order) VALUES (?, ?, ?, ?)");
            foreach ($selected as $country => $banks) {
                $country = trim((string)$country);
                if ($country === '') continue;
                if (!is_array($banks)) $banks = [];
                foreach (array_values($banks) as $i => $bank) {
                    $bank = trim((string)$bank);
                    if ($bank === '') continue;
                    $ins->execute([$companyId, $country, $bank, $i]);
                }
            }
            $pdo->commit();
            jsonResponse(true, 'Saved', null);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        error_log("saveSelectedBanks: " . $e->getMessage());
        jsonResponse(false, $e->getMessage(), null);
    }
}