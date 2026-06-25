<?php
/**
 * Summary submit handler (extracted from summary_api.php).
 */
require_once __DIR__ . '/summary_api_lib.php';

function dcSummaryApiHandleSubmit(): void
{
    global $pdo, $company_id, $capture_scope_ctx, $capture_scope_group, $scopeParams, $groupIdForAccess;

        $immediateAckMode = false;
        $queueJobId = null;
        try {
            // Check PHP configuration limits first
            $postMaxSize = ini_get('post_max_size');
            $postMaxSizeBytes = return_bytes($postMaxSize);
            $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        
            // Get all relevant PHP configuration values for error reporting
            $uploadMaxFilesize = ini_get('upload_max_filesize');
            $maxInputVars = ini_get('max_input_vars');
            $memoryLimit = ini_get('memory_limit');
        
            // Check if Content-Length exceeds post_max_size (before reading data)
            if ($contentLength > 0 && $contentLength > $postMaxSizeBytes) {
                $configInfo = "\n\n当前 PHP 配置：\n";
                $configInfo .= "- post_max_size: $postMaxSize\n";
                $configInfo .= "- upload_max_filesize: $uploadMaxFilesize\n";
                $configInfo .= "- max_input_vars: $maxInputVars\n";
                $configInfo .= "- memory_limit: $memoryLimit\n";
                $configInfo .= "\n实际数据大小: " . round($contentLength / 1024 / 1024, 2) . " MB";
                throw new Exception("数据太大（" . round($contentLength / 1024 / 1024, 2) . " MB），超过了 PHP post_max_size 限制（$postMaxSize）。" . $configInfo);
            }
        
            // IMPORTANT: For JSON requests (application/json), data is NOT in $_POST
            // It's only available via php://input, so we should NOT check $_POST for JSON requests
            // Only check for truncation if Content-Length exceeds post_max_size
            // For JSON requests, empty $_POST is normal and expected
        
            // Check if Content-Length exceeds post_max_size (this is the real check)
            // If it does, PHP will truncate the data before we can read it
            if ($contentLength > 0 && $contentLength > $postMaxSizeBytes) {
                $configInfo = "\n\n当前 PHP 配置：\n";
                $configInfo .= "- post_max_size: $postMaxSize (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)\n";
                $configInfo .= "- upload_max_filesize: $uploadMaxFilesize\n";
                $configInfo .= "- max_input_vars: $maxInputVars\n";
                $configInfo .= "- memory_limit: $memoryLimit\n";
                $configInfo .= "\n数据大小信息：\n";
                $configInfo .= "- Content-Length (请求头): " . round($contentLength / 1024 / 1024, 2) . " MB (" . round($contentLength / 1024, 2) . " KB)\n";
                $configInfo .= "\n⚠️ Content-Length (" . round($contentLength / 1024 / 1024, 2) . " MB) 超过了 post_max_size (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)";
                $configInfo .= "\n\n解决方案：\n";
                $configInfo .= "1. 检查 .htaccess 文件是否在网站根目录，且包含：php_value post_max_size 64M\n";
                $configInfo .= "2. 如果 .htaccess 不生效，通过 php.ini 或控制面板修改配置\n";
                $configInfo .= "3. 访问 check_php_config.php 查看当前配置状态\n";
                $configInfo .= "4. 如果数据确实很大，考虑分批提交";
            
                throw new Exception("数据太大（" . round($contentLength / 1024 / 1024, 2) . " MB），超过了 PHP post_max_size 限制（$postMaxSize）。" . $configInfo);
            }
        
            // Get POST data (php://input can only be read once)
            $jsonData = file_get_contents('php://input');
            $inputSize = strlen($jsonData);
        
            // Log data size for debugging
            error_log("Submit request - Input size: " . round($inputSize / 1024 / 1024, 2) . " MB, Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB, post_max_size: $postMaxSize");
        
            // Check if data exceeds post_max_size
            if ($inputSize > $postMaxSizeBytes) {
                $configInfo = "\n\n当前 PHP 配置：\n";
                $configInfo .= "- post_max_size: $postMaxSize (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)\n";
                $configInfo .= "- upload_max_filesize: $uploadMaxFilesize\n";
                $configInfo .= "- max_input_vars: $maxInputVars\n";
                $configInfo .= "- memory_limit: $memoryLimit\n";
                $configInfo .= "\n实际数据大小: " . round($inputSize / 1024 / 1024, 2) . " MB (" . round($inputSize / 1024, 2) . " KB)";
                $configInfo .= "\n\n解决方案：\n";
                $configInfo .= "1. 检查网站根目录的 .htaccess 文件是否包含：php_value post_max_size 64M\n";
                $configInfo .= "2. 如果 .htaccess 不生效，联系服务器管理员修改 php.ini\n";
                $configInfo .= "3. 访问 check_php_config.php 查看当前配置状态";
                throw new Exception("数据太大（" . round($inputSize / 1024 / 1024, 2) . " MB），超过了 PHP post_max_size 限制（$postMaxSize）。" . $configInfo);
            }
        
            if (empty($jsonData)) {
                $configInfo = "\n\n当前 PHP 配置：\n";
                $configInfo .= "- post_max_size: $postMaxSize\n";
                $configInfo .= "- Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB\n";
                $configInfo .= "\n这通常意味着数据在传输过程中被截断了。";
                throw new Exception('没有接收到数据。可能是数据太大超过了 PHP post_max_size 限制（' . $postMaxSize . '）。' . $configInfo);
            }
        
            $data = json_decode($jsonData, true);
        
            if (!$data) {
                $jsonError = json_last_error_msg();
                // Check if JSON was truncated (incomplete JSON usually means data was cut off)
                if (json_last_error() === JSON_ERROR_SYNTAX && $contentLength > $inputSize) {
                    $configInfo = "\n\n当前 PHP 配置：\n";
                    $configInfo .= "- post_max_size: $postMaxSize (" . round($postMaxSizeBytes / 1024 / 1024, 2) . " MB)\n";
                    $configInfo .= "- Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB\n";
                    $configInfo .= "- 实际接收: " . round($inputSize / 1024 / 1024, 2) . " MB\n";
                    $configInfo .= "\n数据被截断，说明超过了 post_max_size 限制。";
                    throw new Exception("数据太大，超过了 PHP post_max_size 限制（$postMaxSize）。数据被截断导致 JSON 解析失败。" . $configInfo);
                }
                $configInfo = "\n\n当前 PHP 配置：\n";
                $configInfo .= "- post_max_size: $postMaxSize\n";
                $configInfo .= "- Content-Length: " . round($contentLength / 1024 / 1024, 2) . " MB\n";
                throw new Exception('无效的 JSON 数据: ' . $jsonError . '。可能是数据太大导致数据被截断。' . $configInfo);
            }
        
            // Validate required fields
            if (!isset($data['captureDate']) || !isset($data['processId']) || !isset($data['currencyId'])) {
                throw new Exception('Missing required fields: captureDate, processId, or currencyId');
            }
        
            if (!isset($data['summaryRows']) || !is_array($data['summaryRows']) || count($data['summaryRows']) === 0) {
                throw new Exception('No summary rows to submit');
            }

            $groupCodeSubmit = dcNormalizeGroupId(
                $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ($groupIdForAccess ?? '')
            );
            if (!empty($data['groupOnlyCapture']) && !empty($data['captureSelectedGroup'])) {
                $capture_scope_group = true;
                $groupCodeSubmit = dcNormalizeGroupId((string) $data['captureSelectedGroup']);
            }

            if ($capture_scope_group) {
                if ($groupCodeSubmit === '') {
                    $groupCodeSubmit = dcNormalizeGroupId(
                        $scopeParams['view_group'] ?? $scopeParams['group_id'] ?? ($groupIdForAccess ?? '')
                    );
                }
                if ($groupCodeSubmit !== '') {
                    $resolvedGroupCompanyId = dcResolveGroupCaptureCompanyId($pdo, $groupCodeSubmit);
                    if ($resolvedGroupCompanyId > 0) {
                        $company_id = $resolvedGroupCompanyId;
                    }
                }
            }

            $companyId = (int) $company_id;
            $processCompanyId = !empty($capture_scope_ctx)
                ? dcCaptureProcessCompanyId($capture_scope_ctx)
                : $companyId;
            $scopeInsert = !empty($capture_scope_ctx)
                ? dcCaptureScopeInsertValues($capture_scope_ctx)
                : ['company_id' => $companyId, 'scope_type' => null, 'scope_id' => null];
            $useCaptureScopeColumns = !empty($capture_scope_ctx['dual_tenant']);

            dcAssertProcessIdInCaptureScope(
                $pdo,
                (int) $data['processId'],
                (int) $processCompanyId,
                (bool) $capture_scope_group
            );

            // 可选：前端要求“立即回成功”，后端继续处理
            $immediateAckMode = !empty($data['immediateAck']);
            if ($immediateAckMode) {
                ensureSummarySubmitQueueTable($pdo);
                $queueStmt = $pdo->prepare("
                    INSERT INTO data_capture_submit_queue (company_id, user_id, status, request_json, rows_count)
                    VALUES (:company_id, :user_id, 'processing', :request_json, :rows_count)
                ");
                $queueStmt->execute([
                    ':company_id' => $companyId,
                    ':user_id' => (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null),
                    ':request_json' => $jsonData,
                    ':rows_count' => count($data['summaryRows'])
                ]);
                $queueJobId = (int)$pdo->lastInsertId();

                echo json_encode([
                    'success' => true,
                    'queued' => true,
                    'jobId' => $queueJobId,
                    'message' => 'Data received. Processing in background.'
                ]);

                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    @ob_end_flush();
                    @flush();
                }
            }
        
            $resolvedCurrencyId = dcResolveCaptureCurrencyId(
                $pdo,
                (bool) $capture_scope_group,
                (int) $company_id,
                $groupCodeSubmit,
                $data['currencyId'] ?? null,
                $data['currencyCode'] ?? ($data['currencyName'] ?? null)
            );
            if ($resolvedCurrencyId === null) {
                throw new Exception(
                    !empty($capture_scope_group)
                        ? '所选币别不属于当前集团范围，请重新选择后再提交'
                        : '所选币别不属于当前公司，请重新选择正确的币别后再提交'
                );
            }
            $data['currencyId'] = $resolvedCurrencyId;
        
            // Get user ID from session (if available)
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        
            // 检查当前用户是 owner 还是 user
            $user_type = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' ? 'owner' : 'user';
        
            // Check if this is a batch append (has captureId)
            $captureId = isset($data['captureId']) && !empty($data['captureId']) ? (int)$data['captureId'] : null;
            $isBatchAppend = $captureId !== null;
        
            // Start transaction
            $pdo->beginTransaction();
        
            try {
                if (!$isBatchAppend) {
                    // Insert main capture record (first batch)
                    if ($useCaptureScopeColumns) {
                        $stmt = $pdo->prepare("
                            INSERT INTO data_captures (company_id, scope_type, scope_id, capture_date, process_id, currency_id, created_by, user_type, remark) 
                            VALUES (:company_id, :scope_type, :scope_id, :capture_date, :process_id, :currency_id, :created_by, :user_type, :remark)
                        ");
                        $stmt->execute([
                            ':company_id' => (int) ($scopeInsert['company_id'] ?? $companyId),
                            ':scope_type' => $scopeInsert['scope_type'],
                            ':scope_id' => $scopeInsert['scope_id'],
                            ':capture_date' => $data['captureDate'],
                            ':process_id' => $data['processId'],
                            ':currency_id' => $data['currencyId'],
                            ':created_by' => $userId,
                            ':user_type' => $user_type,
                            ':remark' => isset($data['remark']) && !empty($data['remark']) ? $data['remark'] : null,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO data_captures (company_id, capture_date, process_id, currency_id, created_by, user_type, remark) 
                            VALUES (:company_id, :capture_date, :process_id, :currency_id, :created_by, :user_type, :remark)
                        ");
                        $stmt->execute([
                            ':company_id' => $companyId,
                            ':capture_date' => $data['captureDate'],
                            ':process_id' => $data['processId'],
                            ':currency_id' => $data['currencyId'],
                            ':created_by' => $userId,
                            ':user_type' => $user_type,
                            ':remark' => isset($data['remark']) && !empty($data['remark']) ? $data['remark'] : null,
                        ]);
                    }
                
                    // Get the inserted capture ID
                    $captureId = $pdo->lastInsertId();
                } else {
                    // Verify capture exists and belongs to same process/date/currency/company
                    if ($useCaptureScopeColumns) {
                        $stmt = $pdo->prepare("
                            SELECT id FROM data_captures 
                            WHERE id = :capture_id 
                              AND scope_type = :scope_type
                              AND scope_id = :scope_id
                              AND capture_date = :capture_date 
                              AND process_id = :process_id 
                              AND currency_id = :currency_id
                        ");
                        $stmt->execute([
                            ':capture_id' => $captureId,
                            ':scope_type' => $scopeInsert['scope_type'],
                            ':scope_id' => $scopeInsert['scope_id'],
                            ':capture_date' => $data['captureDate'],
                            ':process_id' => $data['processId'],
                            ':currency_id' => $data['currencyId'],
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT id FROM data_captures 
                            WHERE id = :capture_id 
                              AND company_id = :company_id
                              AND capture_date = :capture_date 
                              AND process_id = :process_id 
                              AND currency_id = :currency_id
                        ");
                        $stmt->execute([
                            ':capture_id' => $captureId,
                            ':company_id' => $companyId,
                            ':capture_date' => $data['captureDate'],
                            ':process_id' => $data['processId'],
                            ':currency_id' => $data['currencyId'],
                        ]);
                    }
                
                    if (!$stmt->fetch()) {
                        throw new Exception('Invalid capture ID for batch append');
                    }
                }
            
                // Insert detail records
                // Check for duplicates before inserting to prevent duplicate data
                // For 'main' type: check id_product_main, account_id, currency_id, formula_variant (id_product_sub should be NULL or empty)
                // For 'sub' type: check id_product_sub, id_product_main (as parent), account_id, currency_id, formula_variant
                // Use COALESCE to handle NULL values properly in comparison
                $checkStmtMain = $pdo->prepare("
                    SELECT id FROM data_capture_details 
                    WHERE company_id = :company_id
                      AND capture_id = :capture_id 
                      AND product_type = 'main'
                      AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                      AND COALESCE(id_product_sub, '') = ''
                      AND account_id = :account_id
                      AND currency_id = :currency_id
                      AND formula_variant = :formula_variant
                    LIMIT 1
                ");
            
                $checkStmtSub = $pdo->prepare("
                    SELECT id FROM data_capture_details 
                    WHERE company_id = :company_id
                      AND capture_id = :capture_id 
                      AND product_type = 'sub'
                      AND COALESCE(id_product_sub, '') = COALESCE(:id_product_sub, '')
                      AND COALESCE(id_product_main, '') = COALESCE(:id_product_main, '')
                      AND account_id = :account_id
                      AND currency_id = :currency_id
                      AND formula_variant = :formula_variant
                    LIMIT 1
                ");
            
                // ⚠️ 重要说明（避免误会「数据乱了」）：
                // data_capture_details 表里有一个自增主键列 id_product（AUTO_INCREMENT），
                // 它只是「这一条明细记录本身」的 ID，不是产品编号。
                //
                // 真正的产品相关字段是：
                // - 主产品编号：id_product_main
                // - 主产品描述：description_main
                // - 子产品编号：id_product_sub
                // - 子产品描述：description_sub
                // - 产品类型：product_type（'main' / 'sub'）
                //
                // 也就是说：
                // - 你在界面上看到的「产品代码」会存到 id_product_main / id_product_sub
                // - 数据库里中间那一列递增的 172 / 173 等，是这张表自己的主键，不要拿来当产品号看
                //
                // 如果以后真的需要一个「业务上的产品 ID」列，可以另外加字段，例如：
                //   ALTER TABLE data_capture_details ADD COLUMN business_product_id VARCHAR(255) NULL AFTER product_type;
                // 然后在下面的 INSERT 里一并写入。
            
                // Ensure display_order column exists to preserve row ordering
                if (!summaryApiHasDisplayOrder($pdo)) { // static 缓存，不重复 SHOW
                    try {
                        $pdo->exec("ALTER TABLE data_capture_details ADD COLUMN display_order INT NULL AFTER rate");
                        error_log('Added display_order column to data_capture_details');
                    } catch (Exception $columnException) {
                        error_log('display_order column check warning: ' . $columnException->getMessage());
                    }
                }
            
                $detailCompanyId = (int) ($scopeInsert['company_id'] ?? $companyId);
                $useDetailScopeColumns = $useCaptureScopeColumns
                    && tenant_table_has_scope_columns($pdo, 'data_capture_details');
                if ($useDetailScopeColumns) {
                    $stmt = $pdo->prepare("
                        INSERT INTO data_capture_details 
                        (company_id, scope_type, scope_id, capture_id, id_product_main, description_main, id_product_sub, description_sub, product_type, formula_variant, id_product, account_id, currency_id, columns_value, source_value, source_percent, enable_source_percent, formula, processed_amount, rate, display_order) 
                        VALUES 
                        (:company_id, :scope_type, :scope_id, :capture_id, :id_product_main, :description_main, :id_product_sub, :description_sub, :product_type, :formula_variant, :id_product, :account_id, :currency_id, :columns_value, :source_value, :source_percent, :enable_source_percent, :formula, :processed_amount, :rate, :display_order)
                    ");
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO data_capture_details 
                        (company_id, capture_id, id_product_main, description_main, id_product_sub, description_sub, product_type, formula_variant, id_product, account_id, currency_id, columns_value, source_value, source_percent, enable_source_percent, formula, processed_amount, rate, display_order) 
                        VALUES 
                        (:company_id, :capture_id, :id_product_main, :description_main, :id_product_sub, :description_sub, :product_type, :formula_variant, :id_product, :account_id, :currency_id, :columns_value, :source_value, :source_percent, :enable_source_percent, :formula, :processed_amount, :rate, :display_order)
                    ");
                }
            
                // 同一 capture 下相同 id_product_main 按顺序：第一条为 main，后续均为 sub
                $mainSeenForIdProductMain = [];
                if ($isBatchAppend) {
                    $existMainStmt = $pdo->prepare("
                        SELECT DISTINCT COALESCE(TRIM(id_product_main), '') AS id_product_main
                        FROM data_capture_details
                        WHERE capture_id = ? AND company_id = ? AND product_type = 'main' AND COALESCE(id_product_main, '') != ''
                    ");
                    $existMainStmt->execute([$captureId, $companyId]);
                    while ($r = $existMainStmt->fetch(PDO::FETCH_ASSOC)) {
                        $mainSeenForIdProductMain[$r['id_product_main']] = true;
                    }
                }
            
                // Track display_order to preserve row order from frontend
                $displayOrder = 0;
                // Performance optimization:
                // Build in-memory formula_variant maps to avoid per-row SQL lookups.
                // Key format:
                // - main formula key: "<id_product_main>|<account_id>|<formula>"
                // - main max key: "<id_product_main>|<account_id>"
                // - sub formula key: "<id_product_sub>|<id_product_main>|<account_id>|<formula>"
                // - sub max key: "<id_product_sub>|<id_product_main>|<account_id>"
                $variantByFormulaMain = [];
                $variantMaxMain = [];
                $variantByFormulaSub = [];
                $variantMaxSub = [];

                if ($isBatchAppend) {
                    $variantSeedStmt = $pdo->prepare("
                        SELECT
                            product_type,
                            COALESCE(id_product_main, '') AS id_product_main,
                            COALESCE(id_product_sub, '') AS id_product_sub,
                            account_id,
                            COALESCE(formula, '') AS formula,
                            COALESCE(formula_variant, 0) AS formula_variant
                        FROM data_capture_details
                        WHERE company_id = ? AND capture_id = ?
                    ");
                    $variantSeedStmt->execute([$companyId, $captureId]);
                    while ($seed = $variantSeedStmt->fetch(PDO::FETCH_ASSOC)) {
                        $seedType = trim((string)($seed['product_type'] ?? 'main'));
                        $seedMain = trim((string)($seed['id_product_main'] ?? ''));
                        $seedSub = trim((string)($seed['id_product_sub'] ?? ''));
                        $seedAccountId = (int)($seed['account_id'] ?? 0);
                        $seedFormula = (string)($seed['formula'] ?? '');
                        $seedVariant = (int)($seed['formula_variant'] ?? 0);

                        if ($seedType === 'sub') {
                            $formulaKey = $seedSub . '|' . $seedMain . '|' . $seedAccountId . '|' . $seedFormula;
                            $maxKey = $seedSub . '|' . $seedMain . '|' . $seedAccountId;
                            if (!isset($variantByFormulaSub[$formulaKey])) {
                                $variantByFormulaSub[$formulaKey] = $seedVariant;
                            }
                            if (!isset($variantMaxSub[$maxKey]) || $seedVariant > $variantMaxSub[$maxKey]) {
                                $variantMaxSub[$maxKey] = $seedVariant;
                            }
                        } else {
                            $formulaKey = $seedMain . '|' . $seedAccountId . '|' . $seedFormula;
                            $maxKey = $seedMain . '|' . $seedAccountId;
                            if (!isset($variantByFormulaMain[$formulaKey])) {
                                $variantByFormulaMain[$formulaKey] = $seedVariant;
                            }
                            if (!isset($variantMaxMain[$maxKey]) || $seedVariant > $variantMaxMain[$maxKey]) {
                                $variantMaxMain[$maxKey] = $seedVariant;
                            }
                        }
                    }
                }

                foreach ($data['summaryRows'] as $row) {
                    // Validate row data
                    if (!isset($row['accountId'])) {
                        throw new Exception('Missing required row data: accountId');
                    }

                    dcAssertAccountIdInCaptureScope(
                        $pdo,
                        (int) $row['accountId'],
                        (bool) $capture_scope_group,
                        (int) $company_id,
                        $groupCodeSubmit
                    );
                
                    // Validate that at least one of main or sub is provided
                    if (empty($row['idProductMain']) && empty($row['idProductSub'])) {
                        throw new Exception('Missing required row data: idProductMain or idProductSub');
                    }
                
                    // Get display_order from row data, or use auto-incrementing counter
                    // This preserves the exact order from the frontend summary table
                    $rowDisplayOrder = isset($row['displayOrder']) && $row['displayOrder'] !== null ? (int)$row['displayOrder'] : $displayOrder;
                    $displayOrder++;
                
                    // Determine product_type: 同一 id_product_main 下第一条为 main，其余为 sub；仅 id_product_sub 有值且 main 空时为 sub
                    $productType = 'main';
                    if (empty($row['idProductMain']) && !empty($row['idProductSub'])) {
                        $productType = 'sub';
                    } elseif (!empty($row['idProductMain'])) {
                        $key = trim((string)$row['idProductMain']);
                        if (isset($mainSeenForIdProductMain[$key])) {
                            $productType = 'sub';
                        } else {
                            $productType = 'main';
                            $mainSeenForIdProductMain[$key] = true;
                        }
                    } else {
                        $productType = $row['productType'] ?? 'main';
                    }

                    $normalizedIdProductMain = trim((string)($row['idProductMain'] ?? ''));
                    $normalizedIdProductSub = trim((string)($row['idProductSub'] ?? ''));
                    $normalizedIdProduct = $productType === 'sub'
                        ? ($normalizedIdProductSub !== '' ? $normalizedIdProductSub : $normalizedIdProductMain)
                        : ($normalizedIdProductMain !== '' ? $normalizedIdProductMain : $normalizedIdProductSub);
                    if ($normalizedIdProduct === '') {
                        throw new Exception('Missing required row data: id_product');
                    }
                
                    // Check for duplicate before inserting
                    // 注意：
                    // - 首次提交（$isBatchAppend === false）时，同一个 capture 还没有明细记录，
                    //   此时不需要做「重复检查」，前端 Summary 中的每一行都应当各自插入一条记录。
                    // - 只有在追加批次（$isBatchAppend === true，带 captureId 再次提交）时，
                    //   才根据 product/account/currency/formula_variant 判断是否更新已有记录，避免重复。
                    $existingRecord = false;
                    $rowCurrencyId = dcResolveCaptureCurrencyId(
                        $pdo,
                        (bool) $capture_scope_group,
                        (int) $company_id,
                        $groupCodeSubmit,
                        $row['currencyId'] ?? null,
                        $row['currencyCode'] ?? null
                    );
                    if ($rowCurrencyId === null) {
                        $rowCurrencyId = $data['currencyId'];
                        error_log(
                            'Row currency_id fallback to capture currency. account_id='
                            . ($row['accountId'] ?? '')
                            . ' scope=' . (!empty($capture_scope_group) ? 'group' : 'company')
                        );
                    }

                    // Get formula_variant from row data
                    // If formulaVariant is provided and not null, use it; otherwise generate a new one
                    $formulaVariant = null;
                    if (isset($row['formulaVariant']) && $row['formulaVariant'] !== null && $row['formulaVariant'] !== '') {
                        $formulaVariant = (int)$row['formulaVariant'];
                    }
                
                    // If formula_variant not provided or is null, find the next available variant for this id_product and account_id
                    if ($formulaVariant === null) {
                        $formula = (string)($row['formula'] ?? '');
                        if ($productType === 'main') {
                            $keyMain = trim((string)($row['idProductMain'] ?? ''));
                            $keyAccountId = (int)($row['accountId'] ?? 0);
                            $formulaKey = $keyMain . '|' . $keyAccountId . '|' . $formula;
                            $maxKey = $keyMain . '|' . $keyAccountId;

                            if (isset($variantByFormulaMain[$formulaKey])) {
                                $formulaVariant = (int)$variantByFormulaMain[$formulaKey];
                            } else {
                                $next = (isset($variantMaxMain[$maxKey]) ? (int)$variantMaxMain[$maxKey] : 0) + 1;
                                $formulaVariant = $next;
                                $variantByFormulaMain[$formulaKey] = $formulaVariant;
                                $variantMaxMain[$maxKey] = $formulaVariant;
                            }
                        } else {
                            $keySub = trim((string)($row['idProductSub'] ?? ''));
                            $keyMain = trim((string)($row['parentIdProduct'] ?? $row['idProductMain'] ?? ''));
                            $keyAccountId = (int)($row['accountId'] ?? 0);
                            $formulaKey = $keySub . '|' . $keyMain . '|' . $keyAccountId . '|' . $formula;
                            $maxKey = $keySub . '|' . $keyMain . '|' . $keyAccountId;

                            if (isset($variantByFormulaSub[$formulaKey])) {
                                $formulaVariant = (int)$variantByFormulaSub[$formulaKey];
                            } else {
                                $next = (isset($variantMaxSub[$maxKey]) ? (int)$variantMaxSub[$maxKey] : 0) + 1;
                                $formulaVariant = $next;
                                $variantByFormulaSub[$formulaKey] = $formulaVariant;
                                $variantMaxSub[$maxKey] = $formulaVariant;
                            }
                        }
                    }

                    // 只有在 batch append 模式下才检查并更新已有记录；
                    // 首次提交时，一律走 INSERT，让 Summary 里的所有行都各自落一条明细。
                    if ($isBatchAppend) {
                        if ($productType === 'main') {
                            $idProductMain = $row['idProductMain'] ?? null;
                            $checkStmtMain->execute([
                                ':company_id' => $companyId,
                                ':capture_id' => $captureId,
                                ':id_product_main' => $idProductMain,
                                ':account_id' => $row['accountId'],
                                ':currency_id' => $rowCurrencyId,
                                ':formula_variant' => $formulaVariant,
                            ]);
                            $existingRecord = $checkStmtMain->fetch();
                        } else {
                            // sub type - use parentIdProduct as id_product_main for checking
                            $idProductSub = $row['idProductSub'] ?? null;
                            $parentIdProduct = $row['parentIdProduct'] ?? $row['idProductMain'] ?? null;
                        
                            // Debug log for sub type duplicate check
                            error_log("Checking duplicate sub: capture_id=$captureId, id_product_sub=" . ($idProductSub ?? 'NULL') . ", parent_id_product=" . ($parentIdProduct ?? 'NULL') . ", account_id=" . $row['accountId'] . ", formula_variant=$formulaVariant");
                        
                            $checkStmtSub->execute([
                                ':company_id' => $companyId,
                                ':capture_id' => $captureId,
                                ':id_product_sub' => $idProductSub,
                                ':id_product_main' => $parentIdProduct,
                                ':account_id' => $row['accountId'],
                                ':currency_id' => $rowCurrencyId,
                                ':formula_variant' => $formulaVariant,
                            ]);
                            $existingRecord = $checkStmtSub->fetch();
                        }
                    }
                
                    if ($isBatchAppend && $existingRecord) {
                        // Skip duplicate record - update existing record instead of inserting
                        $existingId = $existingRecord['id'];
                        error_log("Found duplicate data_capture_details record (ID: $existingId): capture_id=$captureId, product_type=$productType, id_product_main=" . ($row['idProductMain'] ?? 'NULL') . ", id_product_sub=" . ($row['idProductSub'] ?? 'NULL') . ", account_id=" . $row['accountId'] . " - Updating existing record instead of inserting");
                    
                        // Get rate value: use rateValue if it exists (from Rate Value column or global rateInput)
                        // Priority: Rate Value column > Global rateInput (if checkbox checked)
                        $rateValue = null;
                        if (isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null) {
                            // Rate Value column has value, use it
                            $rateValueStr = (string)$row['rateValue'];
                            // Handle formats like "*3", "/2", or plain numbers
                            if (strpos($rateValueStr, '*') === 0) {
                                $rateValue = (float)substr($rateValueStr, 1);
                            } else if (strpos($rateValueStr, '/') === 0) {
                                $rateValue = (float)substr($rateValueStr, 1);
                            } else {
                                $rateValue = (float)$rateValueStr;
                            }
                        } else if (isset($row['rateChecked']) && $row['rateChecked']) {
                            // Fallback: if checkbox checked but no Rate Value, use global rateInput (backward compatibility)
                            $rateValue = isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null ? (float)$row['rateValue'] : null;
                        }
                    
                        // Get display_order for update
                        $rowDisplayOrderForUpdate = isset($row['displayOrder']) && $row['displayOrder'] !== null ? (int)$row['displayOrder'] : null;
                    
                        // Update existing record instead of skipping
                        $updateStmt = $pdo->prepare("
                            UPDATE data_capture_details SET
                                description_main = :description_main,
                                description_sub = :description_sub,
                                id_product = :id_product,
                                columns_value = :columns_value,
                                source_value = :source_value,
                                source_percent = :source_percent,
                                enable_source_percent = :enable_source_percent,
                                formula = :formula,
                                processed_amount = :processed_amount,
                                rate = :rate,
                                display_order = :display_order
                            WHERE id = :id
                        ");
                    
                        $updateStmt->execute([
                            ':id' => $existingId,
                            ':description_main' => $row['descriptionMain'] ?? null,
                            ':description_sub' => $row['descriptionSub'] ?? null,
                            ':id_product' => $normalizedIdProduct,
                            ':columns_value' => $row['columns'] ?? '',
                            ':source_value' => $row['source'] ?? '',
                            // source_percent: default to '1' (multiplier, 1 = multiply by 1), auto-enable if has value
                            ':source_percent' => isset($row['sourcePercent']) && $row['sourcePercent'] !== '' ? (string)$row['sourcePercent'] : '1',
                            ':enable_source_percent' => (isset($row['sourcePercent']) && $row['sourcePercent'] !== '' && $row['sourcePercent'] !== '0') ? 1 : 0,
                            ':formula' => $row['formula'] ?? '',
                            ':processed_amount' => $row['processedAmount'] ?? 0,
                            ':rate' => $rateValue,
                            ':display_order' => $rowDisplayOrderForUpdate
                        ]);
                    
                        continue; // Skip insert, already updated
                    }
                
                    // Get rate value: use rateValue if it exists (from Rate Value column or global rateInput)
                    // Priority: Rate Value column > Global rateInput (if checkbox checked)
                    $rateValue = null;
                    if (isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null) {
                        // Rate Value column has value, use it
                        $rateValueStr = (string)$row['rateValue'];
                        // Handle formats like "*3", "/2", or plain numbers
                        if (strpos($rateValueStr, '*') === 0) {
                            $rateValue = (float)substr($rateValueStr, 1);
                        } else if (strpos($rateValueStr, '/') === 0) {
                            $rateValue = (float)substr($rateValueStr, 1);
                        } else {
                            $rateValue = (float)$rateValueStr;
                        }
                    } else if (isset($row['rateChecked']) && $row['rateChecked']) {
                        // Fallback: if checkbox checked but no Rate Value, use global rateInput (backward compatibility)
                        $rateValue = isset($row['rateValue']) && $row['rateValue'] !== '' && $row['rateValue'] !== null ? (float)$row['rateValue'] : null;
                    }
                
                    $detailParams = [
                        ':company_id' => $detailCompanyId,
                        ':capture_id' => $captureId,
                        ':id_product_main' => $normalizedIdProductMain !== '' ? $normalizedIdProductMain : null,
                        ':description_main' => $row['descriptionMain'] ?? null,
                        ':id_product_sub' => $normalizedIdProductSub !== '' ? $normalizedIdProductSub : null,
                        ':description_sub' => $row['descriptionSub'] ?? null,
                        ':product_type' => $productType,
                        ':formula_variant' => $formulaVariant,
                        ':id_product' => $normalizedIdProduct,
                        ':account_id' => $row['accountId'],
                        ':currency_id' => $rowCurrencyId,
                        ':columns_value' => $row['columns'] ?? '',
                        ':source_value' => $row['source'] ?? '',
                        ':source_percent' => isset($row['sourcePercent']) && $row['sourcePercent'] !== '' ? (string)$row['sourcePercent'] : '1',
                        ':enable_source_percent' => (isset($row['sourcePercent']) && $row['sourcePercent'] !== '' && $row['sourcePercent'] !== '0') ? 1 : 0,
                        ':formula' => $row['formula'] ?? '',
                        ':processed_amount' => $row['processedAmount'] ?? 0,
                        ':rate' => $rateValue,
                        ':display_order' => $rowDisplayOrder,
                    ];
                    if ($useDetailScopeColumns) {
                        $detailParams[':scope_type'] = $scopeInsert['scope_type'];
                        $detailParams[':scope_id'] = $scopeInsert['scope_id'];
                    }
                    $stmt->execute($detailParams);
                }

                // ⚠ 这里开始不再在 Submit 时写入 / 更新 data_capture_templates，
                // Maintenance - Formula 的模板完全由 Edit Formula 弹窗里的 Save（action=save_template）维护。
            
                // Commit transaction
                $pdo->commit();

                // Company scope: write submitted_processes in same request as data_captures (avoids second POST + scope drift).
                if (!$isBatchAppend && $userId) {
                    $submittedResult = dcSaveSubmittedProcessRecord(
                        $pdo,
                        (int) $userId,
                        $user_type,
                        (int) $data['processId'],
                        (string) $data['captureDate'],
                        is_array($capture_scope_ctx) ? $capture_scope_ctx : [],
                        $companyId,
                        (bool) $capture_scope_group
                    );
                    if (!$submittedResult['success'] && empty($submittedResult['skipped'])) {
                        error_log(
                            'submit: submitted_processes save failed: '
                            . ($submittedResult['error'] ?? 'unknown')
                        );
                    }
                }
            
                // Log success
                error_log("Data capture submitted successfully - Capture ID: $captureId, Rows: " . count($data['summaryRows']));
            
                if ($queueJobId) {
                    $qDoneStmt = $pdo->prepare("
                        UPDATE data_capture_submit_queue
                        SET status = 'success', capture_id = :capture_id, finished_at = NOW(), error_message = NULL
                        WHERE id = :id
                    ");
                    $qDoneStmt->execute([
                        ':capture_id' => $captureId,
                        ':id' => $queueJobId
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'captureId' => $captureId,
                        'message' => 'Data submitted successfully',
                        'rowsInserted' => count($data['summaryRows'])
                    ]);
                }
            
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw $e;
            }
        
        } catch (Exception $e) {
            error_log("Submit Error: " . $e->getMessage());
            if ($queueJobId) {
                try {
                    ensureSummarySubmitQueueTable($pdo);
                    $qFailStmt = $pdo->prepare("
                        UPDATE data_capture_submit_queue
                        SET status = 'failed', finished_at = NOW(), error_message = :error_message
                        WHERE id = :id
                    ");
                    $qFailStmt->execute([
                        ':error_message' => mb_substr($e->getMessage(), 0, 1000),
                        ':id' => $queueJobId
                    ]);
                } catch (Exception $qe) {
                    error_log("Submit queue update failed: " . $qe->getMessage());
                }
                // 已经提前响应给前端，这里不再二次输出
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null
                ]);
            }
        }
    
}
