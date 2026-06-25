<?php
/**
 * 流程/工艺添加与表单数据 API（规范化版）
 * 路径：api/processes/addprocess_api.php
 * 统一响应格式：{ success: bool, message: string, data: mixed }
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../includes/partnership_audit_readonly.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/addprocess_lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
}

// ---------- 主入口：鉴权 ----------
if (!isset($_SESSION['user_id'])) {
    jsonResponse(false, '用户未登录', null);
    exit;
}

$companyId = null;
if (isset($_POST['company_id']) && $_POST['company_id'] !== '') {
    $companyId = (int)$_POST['company_id'];
} elseif (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
    $companyId = (int)$_GET['company_id'];
} elseif (isset($_SESSION['company_id'])) {
    $companyId = (int)$_SESSION['company_id'];
}

if (!$companyId) {
    jsonResponse(false, '缺少公司信息', null);
    exit;
}

try {
    validateCompanyAccessProcess($pdo, $companyId);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null);
    exit;
}

// ---------- 路由 ----------
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_partnership_audit_read_only_active($pdo)) {
        jsonResponse(false, '只读账号无法执行此操作', null);
        exit;
    }

    // GET: copy_from
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'copy_from') {
        $processIdParam = isset($_GET['process_id']) ? trim($_GET['process_id']) : '';
        if ($processIdParam === '') {
            jsonResponse(false, 'process_id is required', null);
            exit;
        }
        $process = getProcessForCopyFrom($pdo, $processIdParam, $companyId);
        if (!$process) {
            jsonResponse(false, 'Process not found', null);
            exit;
        }
        $currencyId = null;
        if (!empty($process['currency_id']) && (int)$process['currency_company_id'] === $companyId) {
            $currencyId = $process['currency_id'];
        }
        $data = [
            'currency_id' => $currencyId,
            'currency_code' => $process['currency_code'],
            'currency_warning' => !empty($process['currency_id']) && (int)$process['currency_company_id'] !== $companyId ? 'Currency does not belong to current company' : null,
            'description_id' => $process['description_id'],
            'description_name' => $process['description_name'],
            'remove_word' => $process['remove_word'],
            'replace_word_from' => $process['replace_word_from'],
            'replace_word_to' => $process['replace_word_to'],
            'replace_word' => $process['replace_word_from'] . ' == ' . $process['replace_word_to'],
            'remark' => $process['remark'],
            'day_use' => $process['day_ids'],
            'source_process_id' => $process['process_id']
        ];
        jsonResponse(true, 'OK', $data);
        exit;
    }

    // POST: Bank
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['permission']) && $_POST['permission'] === 'Bank') {
        $country = trim($_POST['country'] ?? '');
        $bank = trim($_POST['bank'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($country === '' || $bank === '' || $type === '' || $name === '') {
            jsonResponse(false, 'Country, Bank, Type and Name are required', null);
            exit;
        }
        $day_start_frequency = trim($_POST['day_start_frequency'] ?? '1st_of_every_month');
        if (!in_array($day_start_frequency, ['1st_of_every_month', 'monthly', 'week', 'day', 'once'], true)) {
            $day_start_frequency = '1st_of_every_month';
        }
        if ($day_start_frequency === 'once' || $day_start_frequency === 'week' || $day_start_frequency === 'day') {
            $_POST['day_end'] = '';
            if ($day_start_frequency === 'week' || $day_start_frequency === 'day') {
                $_POST['contract'] = '';
            }
        }
        $currentUserId = null;
        $createdByType = 'user';
        $createdByOwnerId = null;
        if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
            $createdByType = 'owner';
            $createdByOwnerId = $_SESSION['owner_id'] ?? null;
        } else {
            $currentUserId = getCurrentUserId($pdo);
        }
        $params = [
            'company_id' => $companyId,
            'country' => $country,
            'bank' => $bank,
            'type' => $type,
            'name' => $name,
            'card_merchant_id' => (isset($_POST['card_merchant_id']) && $_POST['card_merchant_id'] !== '') ? (int)$_POST['card_merchant_id'] : null,
            'customer_id' => (isset($_POST['customer_id']) && $_POST['customer_id'] !== '') ? (int)$_POST['customer_id'] : null,
            'profit_account_id' => (isset($_POST['profit_account_id']) && $_POST['profit_account_id'] !== '') ? (int)$_POST['profit_account_id'] : null,
            'contract' => trim($_POST['contract'] ?? ''),
            'insurance' => money_optional($_POST['insurance'] ?? null),
            'sop' => trim($_POST['sop'] ?? ''),
            'remark' => trim($_POST['remark'] ?? ''),
            'cost' => money_optional($_POST['cost'] ?? null),
            'price' => money_optional($_POST['price'] ?? null),
            'profit' => money_optional($_POST['profit'] ?? null),
            'profit_sharing' => trim($_POST['profit_sharing'] ?? ''),
            'day_start' => trim($_POST['day_start'] ?? '') ?: null,
            'day_start_frequency' => $day_start_frequency,
            'day_end' => trim($_POST['day_end'] ?? '') ?: null,
            'created_by' => $currentUserId,
            'created_by_type' => $createdByType,
            'created_by_owner_id' => $createdByOwnerId
        ];
        $id = insertBankProcess($pdo, $params);
        ensureCountryBank($pdo, $companyId, $country, $bank);
        $data = ['created_processes' => [['id' => $id, 'process_id' => $name, 'description_id' => null]]];
        jsonResponse(true, 'Bank process added successfully', $data);
        exit;
    }

    // POST: add_description
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_description') {
        $descriptionName = trim($_POST['description_name'] ?? '');
        if ($descriptionName === '') {
            jsonResponse(false, 'Description name is required', null);
            exit;
        }
        if (descriptionExistsForCompany($pdo, $companyId, $descriptionName)) {
            jsonResponse(false, 'Description name already exists for this company', ['duplicate' => true]);
            exit;
        }
        $descriptionId = insertDescription($pdo, $companyId, $descriptionName);
        jsonResponse(true, 'Description added successfully', ['description_id' => $descriptionId]);
        exit;
    }

    // POST: delete_description
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_description') {
        $descriptionId = isset($_POST['description_id']) ? (int)$_POST['description_id'] : 0;
        if (!$descriptionId) {
            jsonResponse(false, 'Description ID is required', null);
            exit;
        }
        $description = getDescriptionById($pdo, $descriptionId);
        if (!$description) {
            jsonResponse(false, 'Description not found', null);
            exit;
        }
        if ((int)$description['company_id'] !== $companyId) {
            jsonResponse(false, '无权限删除该描述', null);
            exit;
        }
        if (getProcessUsageCountForDescription($pdo, $descriptionId, $companyId) > 0) {
            jsonResponse(false, '该描述正在被流程使用，无法删除', null);
            exit;
        }
        deleteDescription($pdo, $descriptionId, $companyId);
        jsonResponse(true, 'Description deleted successfully', null);
        exit;
    }

    // POST: 添加 process（主流程）
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $processIds = [];
        if (!empty($_POST['selected_processes'])) {
            $raw = $_POST['selected_processes'];
            $selectedProcesses = is_array($raw) ? $raw : json_decode($raw, true);
            if (is_array($selectedProcesses) && !empty($selectedProcesses)) {
                $processIds = array_values($selectedProcesses);
            }
        }
        if (empty($processIds) && !empty($_POST['process_id'])) {
            $processIds = [trim($_POST['process_id'])];
        }

        $descriptionIds = [];
        if (!empty($_POST['selected_descriptions'])) {
            $selectedDescriptions = json_decode($_POST['selected_descriptions'], true);
            if (is_array($selectedDescriptions) && !empty($selectedDescriptions)) {
                $placeholders = str_repeat('?,', count($selectedDescriptions) - 1) . '?';
                $stmt = $pdo->prepare("SELECT id FROM description WHERE name IN ($placeholders) AND company_id = ?");
                $stmt->execute(array_merge($selectedDescriptions, [$companyId]));
                $descriptionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } elseif (!empty($_POST['description_id'])) {
            $descriptionIds = [$_POST['description_id']];
        }

        $currencyId = $_POST['currency_id'] ?? '';
        $removeWord = $_POST['remove_word'] ?? '';
        $replaceWordFrom = $_POST['replace_word_from'] ?? '';
        $replaceWordTo = $_POST['replace_word_to'] ?? '';
        $remark = $_POST['remark'] ?? '';
        $dayUse = $_POST['day_use'] ?? '';
        $copyFromProcessId = $_POST['copy_from'] ?? '';

        if (empty($processIds)) {
            jsonResponse(false, 'At least one process ID must be selected', null);
            exit;
        }
        if (empty($descriptionIds)) {
            jsonResponse(false, 'At least one description must be selected', null);
            exit;
        }
        if (empty($currencyId)) {
            jsonResponse(false, 'Currency must be selected', null);
            exit;
        }

        $dayIds = !empty($dayUse) ? array_filter(array_map('trim', explode(',', $dayUse))) : [];
        $copyFromProcessDbId = resolveCopyFromProcessId($pdo, $copyFromProcessId, $companyId);
        $sourceTemplates = [];
        if ($copyFromProcessDbId !== null) {
            $sourceTemplates = getSourceTemplatesForCopy($pdo, $copyFromProcessDbId, $companyId);
        }
        if (empty($sourceTemplates) && $copyFromProcessId !== '' && $copyFromProcessId !== null) {
            $sourceTemplates = getSourceTemplatesForCopy($pdo, $copyFromProcessId, $companyId);
        }

        $currentUserId = null;
        $createdByType = 'user';
        $createdByOwnerId = null;
        if (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
            $createdByType = 'owner';
            $createdByOwnerId = $_SESSION['owner_id'] ?? null;
        } else {
            $currentUserId = getCurrentUserId($pdo);
        }

        $createdProcesses = [];
        $errors = [];
        $copiedTemplatesCount = 0;
        $pdo->beginTransaction();
        try {
            foreach ($processIds as $processId) {
                foreach ($descriptionIds as $descriptionId) {
                    if (processExists($pdo, $processId, $descriptionId, $companyId)) {
                        $errors[] = "Process already exists for process_id $processId and description $descriptionId";
                        continue;
                    }
                    $row = [
                        'process_id' => $processId,
                        'description_id' => $descriptionId,
                        'currency_id' => $currencyId,
                        'remove_word' => $removeWord,
                        'replace_word_from' => $replaceWordFrom,
                        'replace_word_to' => $replaceWordTo,
                        'remark' => $remark,
                        'created_by' => $currentUserId,
                        'created_by_type' => $createdByType,
                        'created_by_owner_id' => $createdByOwnerId,
                        'dts_created' => date('Y-m-d H:i:s'),
                        'company_id' => $companyId,
                        'sync_source_process_id' => $copyFromProcessDbId
                    ];
                    $newProcessId = insertProcess($pdo, $row);
                    insertProcessDays($pdo, (int)$newProcessId, $dayIds);
                    $createdProcesses[] = ['id' => (int)$newProcessId, 'process_id' => $processId, 'description_id' => $descriptionId];
                    if (!empty($sourceTemplates)) {
                        $copiedTemplatesCount += copyTemplatesToNewProcess($pdo, $companyId, (int)$newProcessId, $sourceTemplates);
                    }
                }
            }
            assignNewProcessesToRestrictedUsers($pdo, $companyId, $createdProcesses);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        $message = "Successfully created " . count($createdProcesses) . " process(es)";
        if ($copiedTemplatesCount > 0) {
            $message .= " and copied " . $copiedTemplatesCount . " template(s)";
        } elseif ($copyFromProcessId !== '') {
            $message .= ". Note: No templates were copied from source process.";
        }
        if (!empty($errors)) {
            $message .= ". " . count($errors) . " process(es) were skipped due to conflicts.";
        }
        $data = [
            'created_processes' => $createdProcesses,
            'copied_templates_count' => $copiedTemplatesCount,
            'copy_from_used' => $copyFromProcessId !== '',
            'sync_source_set' => $copyFromProcessDbId !== null,
            'source_templates_found' => count($sourceTemplates),
            'errors' => $errors
        ];
        jsonResponse(true, $message, $data);
        exit;
    }

    // GET: 表单数据（兼容前端 result.currencies / result.descriptions 等）
    $currencies = getCurrenciesByCompany($pdo, $companyId);
    $processes = getProcessesForForm($pdo, $companyId);
    $descriptions = getDescriptionsByCompany($pdo, $companyId);
    $days = getDays($pdo);
    $existingProcesses = getExistingProcessesForCopy($pdo, $companyId);
    $payload = [
        'currencies' => $currencies,
        'processes' => $processes,
        'descriptions' => $descriptions,
        'days' => $days,
        'existingProcesses' => $existingProcesses
    ];
    echo json_encode(array_merge(
        ['success' => true, 'message' => 'OK', 'data' => $payload],
        $payload
    ), JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null);
}
