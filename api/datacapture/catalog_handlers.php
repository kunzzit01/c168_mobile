<?php
/**
 * Data Capture form catalog handlers (process picker form data + descriptions).
 */
require_once __DIR__ . '/../processes/addprocess_lib.php';

function dcCatalogApiActions(): array
{
    return ['load', 'add_description', 'delete_description'];
}

function dcHandleLoadFormCatalog(): void
{
    global $pdo, $company_id;

    $currencies = getCurrenciesByCompany($pdo, (int) $company_id);
    $processes = getProcessesForForm($pdo, (int) $company_id);
    $descriptions = getDescriptionsByCompany($pdo, (int) $company_id);
    $days = getDays($pdo);
    $existingProcesses = getExistingProcessesForCopy($pdo, (int) $company_id);
    $payload = [
        'currencies' => $currencies,
        'processes' => $processes,
        'descriptions' => $descriptions,
        'days' => $days,
        'existingProcesses' => $existingProcesses,
    ];
    echo json_encode(
        array_merge(['success' => true, 'message' => 'OK', 'data' => $payload], $payload),
        JSON_UNESCAPED_UNICODE
    );
}

function dcHandleAddDescription(): void
{
    global $pdo, $company_id;

    if (is_partnership_audit_read_only_active($pdo)) {
        jsonResponse(false, '只读账号无法执行此操作', null);
        return;
    }

    $descriptionName = trim($_POST['description_name'] ?? '');
    if ($descriptionName === '') {
        jsonResponse(false, 'Description name is required', null);
        return;
    }
    if (descriptionExistsForCompany($pdo, (int) $company_id, $descriptionName)) {
        jsonResponse(false, 'Description name already exists for this company', ['duplicate' => true]);
        return;
    }
    $descriptionId = insertDescription($pdo, (int) $company_id, $descriptionName);
    jsonResponse(true, 'Description added successfully', ['description_id' => $descriptionId]);
}

function dcHandleDeleteDescription(): void
{
    global $pdo, $company_id;

    if (is_partnership_audit_read_only_active($pdo)) {
        jsonResponse(false, '只读账号无法执行此操作', null);
        return;
    }

    $descriptionId = isset($_POST['description_id']) ? (int) $_POST['description_id'] : 0;
    if (!$descriptionId) {
        jsonResponse(false, 'Description ID is required', null);
        return;
    }
    $description = getDescriptionById($pdo, $descriptionId);
    if (!$description) {
        jsonResponse(false, 'Description not found', null);
        return;
    }
    if ((int) $description['company_id'] !== (int) $company_id) {
        jsonResponse(false, '无权限删除该描述', null);
        return;
    }
    if (getProcessUsageCountForDescription($pdo, $descriptionId, (int) $company_id) > 0) {
        jsonResponse(false, '该描述正在被流程使用，无法删除', null);
        return;
    }
    deleteDescription($pdo, $descriptionId, (int) $company_id);
    jsonResponse(true, 'Description deleted successfully', null);
}

function dcDispatchCatalogApi(string $action): void
{
    switch ($action) {
        case 'load':
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
                return;
            }
            dcHandleLoadFormCatalog();
            break;
        case 'add_description':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
                return;
            }
            dcHandleAddDescription();
            break;
        case 'delete_description':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
                return;
            }
            dcHandleDeleteDescription();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
            break;
    }
}
