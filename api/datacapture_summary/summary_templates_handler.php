<?php
/**
 * Summary template CRUD handlers (extracted from summary_api.php).
 */
require_once __DIR__ . '/summary_api_lib.php';

function dcSummaryApiHandleSaveTemplate(): void
{
    global $pdo, $company_id, $capture_scope_ctx, $capture_scope_group, $scopeParams, $groupIdForAccess;

        // Handle save template action (auto-save when formula is saved)
        try {
            $jsonData = file_get_contents('php://input');
            $row = json_decode($jsonData, true);
        
            if (!$row) {
                throw new Exception('Invalid JSON data');
            }
        
            // Validate required fields
            if (empty($row['id_product']) || empty($row['account_id'])) {
                throw new Exception('Missing required fields: id_product or account_id');
            }
        
            // Prepare template payload
            $templatePayload = [
                'product_type' => $row['product_type'] ?? 'main',
                'id_product' => $row['id_product'],
                'parent_id_product' => $row['parent_id_product'] ?? null,
                'id_product_main' => $row['id_product_main'] ?? null,
                'id_product_sub' => $row['id_product_sub'] ?? null,
                'description' => $row['description'] ?? null,
                'description_sub' => $row['description_sub'] ?? null,
                'account_id' => $row['account_id'],
                'account_display' => $row['account_display'] ?? null,
                'currency_id' => $row['currency_id'] ?? null,
                'currency_display' => $row['currency_display'] ?? null,
                'source_columns' => $row['source_columns'] ?? '',
                'formula_operators' => $row['formula_operators'] ?? '',
                'source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1', // Default to '1' (multiplier)
                'enable_source_percent' => isset($row['enable_source_percent']) ? (int)$row['enable_source_percent'] : 1,
                'input_method' => $row['input_method'] ?? null,
                'enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
                'batch_selection' => isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0,
                'columns_display' => $row['columns_display'] ?? null,
                'formula_display' => $row['formula_display'] ?? null,
                'last_source_value' => $row['last_source_value'] ?? null,
                'last_processed_amount' => isset($row['last_processed_amount']) ? $row['last_processed_amount'] : 0,
                'template_key' => $row['template_key'] ?? null,
                'process_id' => isset($row['process_id']) && is_numeric($row['process_id']) ? (int)$row['process_id'] : null,
                'data_capture_id' => isset($row['data_capture_id']) && !empty($row['data_capture_id']) ? (int)$row['data_capture_id'] : null,
                // Preserve row position in summary table if provided
                'row_index' => isset($row['row_index']) && $row['row_index'] !== null ? (int)$row['row_index'] : null,
                'sub_order' => isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null,
                // Pass template_id and formula_variant for editing existing templates
                'template_id' => isset($row['template_id']) && !empty($row['template_id']) ? (int)$row['template_id'] : null,
                'formula_variant' => isset($row['formula_variant']) && $row['formula_variant'] !== null && $row['formula_variant'] !== '' ? (int)$row['formula_variant'] : null,
            ];

            if (!empty($templatePayload['process_id'])) {
                dcAssertProcessIdInCaptureScope(
                    $pdo,
                    (int) $templatePayload['process_id'],
                    (int) $company_id,
                    (bool) $capture_scope_group
                );
            }
        
            $templateResult = saveTemplateRow($pdo, $templatePayload, $company_id);

            if ($templateResult !== null) {
                backfillTemplateScope(
                    $pdo,
                    (int) $company_id,
                    resolveTemplateScopeInsertForSave($pdo, (int) $company_id)
                );
            }
        
            // Handle both old format (string) and new format (array) for backward compatibility
            $templateKey = is_array($templateResult) ? $templateResult['template_key'] : $templateResult;
            $templateId = is_array($templateResult) ? $templateResult['template_id'] : null;
            $formulaVariant = is_array($templateResult) ? $templateResult['formula_variant'] : null;
        
            // 显式同步到所有 Multi-Process（Copy From 源账号修改 Formula 后，同步到 sync_source_process_id 指向该源的流程）
            $processIdForSync = isset($templatePayload['process_id']) && $templatePayload['process_id'] > 0 ? (int)$templatePayload['process_id'] : null;
            $formulaVariantForSync = $formulaVariant !== null ? $formulaVariant : (isset($templatePayload['formula_variant']) && $templatePayload['formula_variant'] !== '' ? (int)$templatePayload['formula_variant'] : null);
            if ($processIdForSync && $templateResult !== null && $formulaVariantForSync !== null) {
                $syncTemplateData = [
                    'id_product' => $templatePayload['id_product'],
                    'account_id' => $templatePayload['account_id'],
                    'product_type' => $templatePayload['product_type'] ?? 'main',
                    'formula_variant' => $formulaVariantForSync,
                    'source_columns' => $templatePayload['source_columns'] ?? '',
                    'formula_operators' => $templatePayload['formula_operators'] ?? '',
                    'source_percent' => isset($templatePayload['source_percent']) && $templatePayload['source_percent'] !== '' ? (string)$templatePayload['source_percent'] : '1',
                    'enable_source_percent' => (isset($templatePayload['source_percent']) && $templatePayload['source_percent'] !== '' && $templatePayload['source_percent'] !== '0') ? 1 : 0,
                    'input_method' => $templatePayload['input_method'] ?? null,
                    'enable_input_method' => isset($templatePayload['enable_input_method']) ? (int)$templatePayload['enable_input_method'] : 0,
                    'columns_display' => $templatePayload['columns_display'] ?? null,
                    'formula_display' => $templatePayload['formula_display'] ?? null,
                    'description' => $templatePayload['description'] ?? null,
                    'account_display' => $templatePayload['account_display'] ?? null,
                    'currency_id' => $templatePayload['currency_id'] ?? null,
                    'currency_display' => $templatePayload['currency_display'] ?? null,
                    'sub_order' => isset($templatePayload['sub_order']) && $templatePayload['sub_order'] !== null && $templatePayload['sub_order'] !== '' ? (float)$templatePayload['sub_order'] : null,
                    'template_key' => $templatePayload['template_key'] ?? null,
                    'parent_id_product' => $templatePayload['parent_id_product'] ?? null,
                    'batch_selection' => isset($templatePayload['batch_selection']) ? (int)$templatePayload['batch_selection'] : 0,
                    'last_source_value' => $templatePayload['last_source_value'] ?? null,
                    'last_processed_amount' => isset($templatePayload['last_processed_amount']) ? $templatePayload['last_processed_amount'] : 0,
                    'row_index' => isset($templatePayload['row_index']) ? (int)$templatePayload['row_index'] : null,
                    'data_capture_id' => isset($templatePayload['data_capture_id']) ? (int)$templatePayload['data_capture_id'] : null,
                ];
                syncFormulaToMultiUseProcesses($pdo, $processIdForSync, $syncTemplateData, $company_id);
            }
        
            echo json_encode([
                'success' => true,
                'message' => 'Template saved successfully',
                'template_key' => $templateKey, // Return the computed template_key so frontend can update DOM
                'template_id' => $templateId, // Return template ID for precise deletion
                'formula_variant' => $formulaVariant // Return formula_variant for precise deletion
            ]);
        } catch (Exception $e) {
            error_log('Template Save Error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
        exit;
}

function dcSummaryApiHandleDeleteTemplate(): void
{
    global $pdo, $company_id, $capture_scope_ctx, $capture_scope_group, $scopeParams, $groupIdForAccess;

        // Handle delete template action (when row is deleted)
        try {
            $jsonData = file_get_contents('php://input');
            $data = json_decode($jsonData, true);
        
            if (!$data) {
                throw new Exception('Invalid JSON data');
            }
        
            // Validate required fields
            if (empty($data['template_key']) || empty($data['product_type'])) {
                throw new Exception('Missing required fields: template_key or product_type');
            }
        
            $productType = $data['product_type'];
            $templateKey = $data['template_key'];
            $templateId = isset($data['template_id']) && !empty($data['template_id']) ? (int)$data['template_id'] : null;
            $formulaVariant = isset($data['formula_variant']) && $data['formula_variant'] !== null && $data['formula_variant'] !== '' ? (int)$data['formula_variant'] : null;
            $sourceProcessId = isset($data['process_id']) && is_numeric($data['process_id']) ? (int)$data['process_id'] : null;
        
            $companyId = $company_id;
        
            // 删除前先取出行数据，用于同步删除 B_ID/C_ID 的对应行
            $rowForSync = null;
            if ($templateId) {
                $sel = $pdo->prepare("SELECT id_product, account_id, product_type, formula_variant, sub_order, process_id FROM data_capture_templates WHERE id = ? AND company_id = ? LIMIT 1");
                $sel->execute([$templateId, $companyId]);
                $rowForSync = $sel->fetch(PDO::FETCH_ASSOC);
            } elseif ($sourceProcessId && $templateKey && $formulaVariant !== null) {
                $sel = $pdo->prepare("SELECT id_product, account_id, product_type, formula_variant, sub_order, process_id FROM data_capture_templates WHERE company_id = ? AND process_id = ? AND template_key = ? AND product_type = ? AND formula_variant = ? LIMIT 1");
                $sel->execute([$companyId, $sourceProcessId, $templateKey, $productType, $formulaVariant]);
                $rowForSync = $sel->fetch(PDO::FETCH_ASSOC);
            }
        
            if ($templateId) {
                $sql = "
                    DELETE FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND id = :template_id
                ";
                $stmt = $pdo->prepare($sql);
                $params = [
                    ':company_id' => $companyId,
                    ':template_id' => $templateId
                ];
            } else if ($formulaVariant !== null) {
                $sql = "
                    DELETE FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND product_type = :product_type 
                      AND template_key = :template_key
                      AND formula_variant = :formula_variant
                ";
                $stmt = $pdo->prepare($sql);
                $params = [
                    ':company_id' => $companyId,
                    ':product_type' => $productType,
                    ':template_key' => $templateKey,
                    ':formula_variant' => $formulaVariant
                ];
                if ($sourceProcessId) {
                    $sql .= " AND process_id = :process_id";
                    $params[':process_id'] = $sourceProcessId;
                }
                $stmt = $pdo->prepare($sql);
            } else {
                // 无 template_id 且无 formula_variant 时，不能按 template_key+product_type 批量删除，否则会误删同 key 的其他行（如 main 与 sub、或同 id_product 多 account）
                // 先查询匹配的行数；仅当恰好 1 条时按该行 id 删除，保证「没有勾选 delete 的数据都保留」
                $selSql = "
                    SELECT id, id_product, account_id, product_type, formula_variant, sub_order, process_id 
                    FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND product_type = :product_type 
                      AND template_key = :template_key
                ";
                $selParams = [
                    ':company_id' => $companyId,
                    ':product_type' => $productType,
                    ':template_key' => $templateKey
                ];
                if ($sourceProcessId) {
                    $selSql .= " AND process_id = :process_id";
                    $selParams[':process_id'] = $sourceProcessId;
                }
                $selStmt = $pdo->prepare($selSql);
                $selStmt->execute($selParams);
                $matchingRows = $selStmt->fetchAll(PDO::FETCH_ASSOC);
                $matchCount = count($matchingRows);
                if ($matchCount > 1) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Multiple rows match (template_key + product_type). Please delete by selecting the specific row with template_id.',
                        'deleted_count' => 0
                    ]);
                    exit;
                }
                if ($matchCount === 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Template not found (may have been already deleted)',
                        'deleted_count' => 0
                    ]);
                    exit;
                }
                $singleRow = $matchingRows[0];
                $rowForSync = $singleRow;
                $templateId = (int)$singleRow['id'];
                $sql = "
                    DELETE FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND id = :template_id
                ";
                $stmt = $pdo->prepare($sql);
                $params = [
                    ':company_id' => $companyId,
                    ':template_id' => $templateId
                ];
            }
        
            $stmt->execute($params);
        
            $deletedCount = $stmt->rowCount();
        
            // 删除同步：A_ID 删除后，同步删除所有 sync_source_process_id = A_ID 的 process 中对应行
            // 优先用请求的 process_id；若未传（如按 template_id 删除），则用 $rowForSync['process_id'] 作为源
            $effectiveSourceProcessId = $sourceProcessId !== null
                ? $sourceProcessId
                : (isset($rowForSync['process_id']) && $rowForSync['process_id'] !== null && $rowForSync['process_id'] !== '' ? (int)$rowForSync['process_id'] : null);
            if ($deletedCount > 0 && $effectiveSourceProcessId !== null && $rowForSync) {
                $subOrder = isset($rowForSync['sub_order']) && $rowForSync['sub_order'] !== null && $rowForSync['sub_order'] !== '' ? (float)$rowForSync['sub_order'] : null;
                syncDeleteTemplateToMultiUseProcesses(
                    $pdo,
                    $effectiveSourceProcessId,
                    $rowForSync['id_product'],
                    $rowForSync['account_id'],
                    $rowForSync['product_type'],
                    (int)$rowForSync['formula_variant'],
                    $subOrder,
                    $companyId
                );
            }
        
            if ($deletedCount > 0) {
                if ($templateId) {
                    error_log("Deleted template by ID: template_id=$templateId");
                } else if ($formulaVariant) {
                    error_log("Deleted template by key+variant: product_type=$productType, template_key=$templateKey, formula_variant=$formulaVariant");
                } else {
                    error_log("Deleted template by key: product_type=$productType, template_key=$templateKey");
                }
                echo json_encode([
                    'success' => true,
                    'message' => 'Template deleted successfully',
                    'deleted_count' => $deletedCount
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Template not found (may have been already deleted)',
                    'deleted_count' => 0
                ]);
            }
        } catch (Exception $e) {
            error_log('Template Delete Error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
        exit;
}

function dcSummaryApiHandleFetchTemplates(): void
{
    global $pdo, $company_id, $capture_scope_ctx, $capture_scope_group, $scopeParams, $groupIdForAccess;

        try {
            $ids = [];
            $processId = null;
            $captureId = null;
            $payload = [];

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $jsonData = file_get_contents('php://input');
                $payload = json_decode($jsonData, true);
                if (!is_array($payload)) {
                    $payload = [];
                }
                if (isset($payload['idProducts']) && is_array($payload['idProducts'])) {
                    $ids = array_values(array_filter(array_map('trim', $payload['idProducts'])));
                }
                if (isset($payload['processId'])) {
                    // processId should be process.id (int), not process.process_id (string)
                    $processIdValue = $payload['processId'];
                    if (is_numeric($processIdValue)) {
                        $processId = (int)$processIdValue;
                    } elseif (is_string($processIdValue) && trim($processIdValue) !== '') {
                        $processId = (int)trim($processIdValue);
                    }
                }
                if (isset($payload['captureId']) && $payload['captureId'] !== null && $payload['captureId'] !== '') {
                    $captureIdVal = $payload['captureId'];
                    if (is_numeric($captureIdVal)) {
                        $captureId = (int)$captureIdVal;
                    } elseif (is_string($captureIdVal) && trim($captureIdVal) !== '') {
                        $captureId = (int)trim($captureIdVal);
                    }
                }
            } elseif (!empty($_GET['ids'])) {
                $ids = array_values(array_filter(array_map('trim', explode(',', $_GET['ids']))));
            }

            if ($processId === null && !empty($_GET['processId'])) {
                // processId should be process.id (int)
                $getProcessId = $_GET['processId'];
                if (is_numeric($getProcessId)) {
                    $processId = (int)$getProcessId;
                } elseif (is_string($getProcessId) && trim($getProcessId) !== '') {
                    $processId = (int)trim($getProcessId);
                }
            }
            if (!empty($_GET['captureId']) && is_numeric($_GET['captureId'])) {
                $captureId = (int)$_GET['captureId'];
            }

            if (empty($ids)) {
                throw new Exception('No id products provided');
            }

            if ($processId === null) {
                throw new Exception('Process ID is required');
            }

            $processCompanyId = !empty($capture_scope_ctx)
                ? dcCaptureProcessCompanyId($capture_scope_ctx)
                : (int) $company_id;
            dcAssertProcessIdInCaptureScope($pdo, (int) $processId, (int) $processCompanyId, (bool) $capture_scope_group);

            // 在 Data Capture 选择的 Process 下设置的 formula 只在该 Process 显示；若该 Process 有 sync 到其他 Process 则同步显示
            // Summary 的 formula 仅来自 Maintenance（data_capture_templates）；Process 在 Maintenance 无记录则不显示 formula
            $rawSubRowsFromSql = [];
            $templates = fetchTemplates($pdo, $ids, $processId, $rawSubRowsFromSql);

            if ($captureId !== null && $captureId > 0 && $company_id && empty($capture_scope_group)) {
                $templates = mergeDetailOnlyTemplates($pdo, (int)$company_id, $captureId, $ids, $templates);
            }

            // 用 account 表统一解析 account_display，与 Maintenance - Formula 的 Account 列一致
            if (!empty($capture_scope_group)) {
                $groupCodeForTpl = dcNormalizeGroupId(
                    $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ($groupIdForAccess ?? '')
                );
                if ($groupCodeForTpl !== '') {
                    resolveAccountDisplayInTemplatesForGroup($pdo, $groupCodeForTpl, $templates);
                }
            } elseif ($company_id) {
                resolveAccountDisplayInTemplates($pdo, (int)$company_id, $templates);
            }

            $subsByParent = buildSubsByParentForApi($templates);
            $debug = false;
            if (isset($payload['debug']) && ($payload['debug'] === true || $payload['debug'] === 1 || $payload['debug'] === '1')) {
                $debug = true;
            } elseif (isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true')) {
                $debug = true;
            }

            $response = [
                'success' => true,
                'templates' => $templates,
                'subsByParent' => $subsByParent,
            ];
            if ($debug) {
                $response['diagnostics'] = buildTemplateFetchDiagnostics($templates, $subsByParent, $rawSubRowsFromSql);
            }

            echo json_encode($response);
        } catch (Exception $e) {
            error_log('Template Fetch Error: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ]);
        }
        exit;
}

function dcSummaryTemplatesApiActions(): array
{
    return ['save_template', 'delete_template', 'templates'];
}

function dcDispatchSummaryTemplatesApi(string $action): void
{
    switch ($action) {
        case 'save_template':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
                return;
            }
            dcSummaryApiHandleSaveTemplate();
            break;
        case 'delete_template':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed', 'data' => null]);
                return;
            }
            dcSummaryApiHandleDeleteTemplate();
            break;
        case 'templates':
            dcSummaryApiHandleFetchTemplates();
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
            break;
    }
}
