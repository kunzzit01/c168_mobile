<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../includes/money_decimal.php';
// Helper function to convert PHP ini size values to bytes
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}

/**
 * 根据 company_id 校验/解析 currency_id，必要时根据 currency_code 匹配。
 */
function resolveCompanyCurrencyId(PDO $pdo, int $companyId, $currencyId = null, ?string $currencyCode = null) {
    static $cacheById = [];
    static $cacheByCode = [];

    if ($currencyId !== null && $currencyId !== '') {
        $currencyId = (int)$currencyId;
        $cacheKey = $companyId . ':' . $currencyId;
        if (array_key_exists($cacheKey, $cacheById)) {
            return $cacheById[$cacheKey];
        }
        $stmt = $pdo->prepare("SELECT id, UPPER(code) AS code FROM currency WHERE company_id = ? AND id = ? LIMIT 1");
        $stmt->execute([$companyId, $currencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cacheById[$cacheKey] = (int)$row['id'];
            $cacheByCode[$companyId . ':' . $row['code']] = (int)$row['id'];
            return $cacheById[$cacheKey];
        }
        $cacheById[$cacheKey] = null;
    }

    if ($currencyCode) {
        $currencyCode = strtoupper(trim($currencyCode));
        $cacheCodeKey = $companyId . ':' . $currencyCode;
        if (array_key_exists($cacheCodeKey, $cacheByCode)) {
            return $cacheByCode[$cacheCodeKey];
        }
        $stmt = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? AND UPPER(code) = ? LIMIT 1");
        $stmt->execute([$companyId, $currencyCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cacheByCode[$cacheCodeKey] = (int)$row['id'];
            $cacheById[$companyId . ':' . (int)$row['id']] = (int)$row['id'];
            return (int)$row['id'];
        }
        $cacheByCode[$cacheCodeKey] = null;
    }

    return null;
}

/** data_capture_details.display_order 是否存在（请求内只查一次） */
function summaryApiHasDisplayOrder(PDO $pdo): bool
{
    static $v = null;
    if ($v === null) {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM data_capture_details LIKE 'display_order'");
            $v = $st && $st->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (Throwable $e) { $v = false; }
    }
    return $v;
}

function ensureTemplateSchema(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'product_type'");
        $hasProductType = $columnStmt && $columnStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hasProductType) {
            $pdo->exec("
                ALTER TABLE data_capture_templates
                ADD COLUMN product_type ENUM('main','sub') NOT NULL DEFAULT 'main' AFTER id_product,
                ADD COLUMN parent_id_product VARCHAR(255) NULL AFTER product_type,
                ADD COLUMN template_key VARCHAR(255) NOT NULL DEFAULT '' AFTER parent_id_product
            ");

            try {
                $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX id_product");
            } catch (Exception $e) {
                error_log('Template schema drop index warning: ' . $e->getMessage());
            }

            // Drop old unique index if exists
            try {
                $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX template_unique");
            } catch (Exception $e) {
                error_log('Template schema drop old unique index warning: ' . $e->getMessage());
            }

            // Add new unique index that includes process_id to prevent duplicates within same process
            // For templates (data_capture_id IS NULL), uniqueness is based on (process_id, product_type, template_key)
            // For capture-specific templates (data_capture_id IS NOT NULL), they can coexist with general templates
            try {
                $pdo->exec("ALTER TABLE data_capture_templates ADD UNIQUE KEY template_unique (process_id, product_type, template_key, data_capture_id)");
            } catch (Exception $e) {
                error_log('Template schema add index warning: ' . $e->getMessage());
            }

            $pdo->exec("
                UPDATE data_capture_templates
                SET product_type = 'main',
                    template_key = CASE WHEN template_key = '' THEN id_product ELSE template_key END
            ");
        } else {
            $indexStmt = $pdo->query("SHOW INDEX FROM data_capture_templates WHERE Key_name = 'template_unique'");
            $hasTemplateIndex = $indexStmt && $indexStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hasTemplateIndex) {
                // Drop old unique index if exists (in case it has different columns)
                try {
                    $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX template_unique");
                } catch (Exception $e) {
                    error_log('Template schema drop old unique index warning: ' . $e->getMessage());
                }
                
                // Add new unique index that includes process_id to prevent duplicates within same process
                try {
                    $pdo->exec("ALTER TABLE data_capture_templates ADD UNIQUE KEY template_unique (process_id, product_type, template_key, data_capture_id)");
                } catch (Exception $e) {
                    error_log('Template schema add index warning: ' . $e->getMessage());
                }
            } else {
                // Check if the index has the correct columns
                $indexStmt = $pdo->query("SHOW INDEX FROM data_capture_templates WHERE Key_name = 'template_unique'");
                $indexColumns = [];
                while ($row = $indexStmt->fetch(PDO::FETCH_ASSOC)) {
                    $indexColumns[] = $row['Column_name'];
                }
                
                // If index doesn't include process_id or data_capture_id, recreate it
                if (!in_array('process_id', $indexColumns) || !in_array('data_capture_id', $indexColumns)) {
                    try {
                        $pdo->exec("ALTER TABLE data_capture_templates DROP INDEX template_unique");
                        $pdo->exec("ALTER TABLE data_capture_templates ADD UNIQUE KEY template_unique (process_id, product_type, template_key, data_capture_id)");
                        error_log('Template schema: Recreated unique index with process_id and data_capture_id');
                    } catch (Exception $e) {
                        error_log('Template schema recreate index warning: ' . $e->getMessage());
                    }
                }
            }
        }
        
        // Ensure process_id column is INT(11) to store process.id (not process.process_id)
        try {
            $processIdColumnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'process_id'");
            $processIdColumn = $processIdColumnStmt ? $processIdColumnStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($processIdColumn && stripos($processIdColumn['Type'] ?? '', 'int') === false) {
                // If column exists but is not INT, we need to migrate it
                // This should be done via the migration script first
                error_log('Template schema: process_id column should be INT(11), but found: ' . ($processIdColumn['Type'] ?? 'unknown'));
                error_log('Please run migrate_data_capture_templates_process_id_to_int.sql migration script first');
            }
        } catch (Exception $columnException) {
            error_log('Template schema process_id check warning: ' . $columnException->getMessage());
        }

        // Ensure row_index column exists to preserve row ordering in summary table
        try {
            $rowIndexColumnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'row_index'");
            $hasRowIndex = $rowIndexColumnStmt && $rowIndexColumnStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hasRowIndex) {
                $pdo->exec("ALTER TABLE data_capture_templates ADD COLUMN row_index INT NULL AFTER data_capture_id");
                error_log('Template schema: Added row_index column to data_capture_templates');
            }
        } catch (Exception $columnException) {
            error_log('Template schema row_index alteration warning: ' . $columnException->getMessage());
        }

        // Ensure data_capture_details.rate supports at least 8 decimal places
        // so Payment History can display the same precision as Data Summary Rate Value.
        try {
            $rateColumnStmt = $pdo->query("SHOW COLUMNS FROM data_capture_details LIKE 'rate'");
            $rateColumn = $rateColumnStmt ? $rateColumnStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($rateColumn) {
                $rateType = strtolower((string)($rateColumn['Type'] ?? ''));
                $needsUpgrade = false;

                // Examples: decimal(10,4), decimal(15,6)
                if (preg_match('/decimal\(\s*\d+\s*,\s*(\d+)\s*\)/i', $rateType, $matches)) {
                    $scale = (int)$matches[1];
                    $needsUpgrade = $scale < 8;
                } elseif ($rateType !== '' && strpos($rateType, 'decimal') !== 0) {
                    // Non-decimal numeric type: normalize to decimal for stable precision.
                    $needsUpgrade = true;
                }

                if ($needsUpgrade) {
                    $pdo->exec("ALTER TABLE data_capture_details MODIFY COLUMN rate DECIMAL(25,8) NULL");
                    error_log('Template schema: Upgraded data_capture_details.rate to DECIMAL(25,8)');
                }
            }
        } catch (Exception $columnException) {
            error_log('Template schema rate precision alteration warning: ' . $columnException->getMessage());
        }
    } catch (Exception $e) {
        error_log('Template schema ensure error: ' . $e->getMessage());
    }
}

/**
 * 确保 data_capture_summary_state 表存在，用于服务端持久化 Summary 行顺序与公式/ Rate 等，避免仅依赖 localStorage 导致刷新后顺序不稳或数据丢失。
 */
function ensureSummaryStateTable(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS data_capture_summary_state (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                process_key VARCHAR(255) NOT NULL,
                state_json LONGTEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_company_process (company_id, process_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('Summary state table ensure error: ' . $e->getMessage());
    }
}

/** Ensure data_capture_summary_state has scope_type / scope_id (idempotent). */
function dcEnsureSummaryStateScopeColumns(PDO $pdo): bool
{
    static $checked = false;
    static $hasScope = false;
    if ($checked) {
        return $hasScope;
    }
    $checked = true;
    try {
        ensureSummaryStateTable($pdo);
        if ($pdo->query("SHOW COLUMNS FROM data_capture_summary_state LIKE 'scope_type'")->rowCount() > 0) {
            $hasScope = true;
            return true;
        }
        $pdo->exec("
            ALTER TABLE data_capture_summary_state
              ADD COLUMN scope_type ENUM('company','group') NOT NULL DEFAULT 'company' AFTER company_id,
              ADD COLUMN scope_id BIGINT UNSIGNED NULL AFTER scope_type
        ");
        $pdo->exec("
            UPDATE data_capture_summary_state
            SET scope_type = 'company', scope_id = company_id
            WHERE scope_id IS NULL OR scope_id = 0
        ");
        try {
            $pdo->exec("ALTER TABLE data_capture_summary_state DROP INDEX uk_company_process");
        } catch (Exception $dropException) {
            error_log('Summary state drop legacy unique key: ' . $dropException->getMessage());
        }
        try {
            $pdo->exec("
                ALTER TABLE data_capture_summary_state
                ADD UNIQUE KEY uk_company_process_scope (company_id, process_key, scope_type, scope_id)
            ");
        } catch (Exception $addException) {
            error_log('Summary state add scoped unique key: ' . $addException->getMessage());
        }
        $hasScope = true;
    } catch (Exception $e) {
        error_log('dcEnsureSummaryStateScopeColumns: ' . $e->getMessage());
    }
    return $hasScope;
}

/** Scope bind values for summary state read/write. */
function resolveSummaryStateScopeBind(?array $captureScopeCtx, int $companyId): array
{
    if (is_array($captureScopeCtx) && $captureScopeCtx !== []) {
        $ctx = $captureScopeCtx;
        $ctx['dual_tenant'] = true;
        $insert = dcCaptureScopeInsertValues($ctx);
        return [
            'scope_type' => (string) ($insert['scope_type'] ?? 'company'),
            'scope_id' => (int) ($insert['scope_id'] ?? $companyId),
        ];
    }

    return [
        'scope_type' => 'company',
        'scope_id' => $companyId,
    ];
}

/**
 * 快速提交队列（用于“先立即回前端，再后台处理”）。
 */
function ensureSummarySubmitQueueTable(PDO $pdo) {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS data_capture_submit_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                company_id INT NOT NULL,
                user_id INT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'processing',
                request_json LONGTEXT NOT NULL,
                capture_id INT NULL,
                rows_count INT NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL,
                INDEX idx_company_status (company_id, status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('Submit queue table ensure error: ' . $e->getMessage());
    }
}

function computeTemplateKey(array $row): string {
    $productType = $row['product_type'] ?? 'main';

    if ($productType === 'sub') {
        $parent = trim((string)($row['parent_id_product'] ?? $row['id_product_main'] ?? ''));
        $subId = trim((string)($row['id_product_sub'] ?? $row['id_product'] ?? ''));
        $description = trim((string)($row['description_sub'] ?? $row['description'] ?? ''));
        $accountId = trim((string)($row['account_id'] ?? ''));
        $subOrder = isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (string)$row['sub_order'] : '';

        if ($subId === '' && $parent === '') {
            $parent = 'sub';
        }

        // 与 main 一致：sub 的 template_key 使用 parent_id_product，并加上 account_id 区分同 parent 下多 account（避免 2 条 sub 共用一个 key 互相覆盖或产生重复）
        $baseKey = $parent !== '' ? $parent : ($subId !== '' ? $subId : '');
        $accountId = trim((string)($row['account_id'] ?? ''));
        if ($baseKey !== '') {
            $key = $accountId !== '' ? $baseKey . '_' . $accountId : $baseKey;
            if ($subOrder !== '') {
                $key .= '_so' . $subOrder;
            }
            return substr($key, 0, 250);
        }

        // 无 parent/sub 时用长格式保证唯一
        $keyParts = [$parent, $subId !== '' ? $subId : $parent, $description, $accountId, $subOrder];
        $key = implode('::', array_map(static function ($part) {
            return trim((string)$part);
        }, $keyParts));
        if ($key === '::::' || $key === ':::::') {
            $key = 'sub-' . md5(json_encode($row));
        }
        return substr($key, 0, 250);
    }

    $idProduct = trim((string)($row['id_product'] ?? $row['id_product_main'] ?? ''));
    if ($idProduct === '') {
        $idProduct = 'main-' . md5(json_encode($row));
    }

    return substr($idProduct, 0, 250);
}

function summary_money_value(array $row, string $key, string $default = '0'): string
{
    if (!array_key_exists($key, $row) || $row[$key] === null || trim((string)$row[$key]) === '') {
        return money_normalize($default);
    }
    return money_normalize($row[$key]);
}

ensureTemplateSchema($pdo);

/**
 * 获取与当前 process 处于同一 copy/sync 组的其它流程（双向）。
 * 规则：
 * - 若当前是子流程（sync_source_process_id 有值），锚点为其源流程；
 * - 若当前是源流程（存在子流程指向它），锚点为自己；
 * - 同步目标为：源流程 + 全部同源子流程，排除当前流程自身。
 */
function getLinkedProcessTargets(PDO $pdo, int $processId, int $companyId): array
{
    $currentStmt = $pdo->prepare("
        SELECT id, process_id, sync_source_process_id
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

    $targetStmt = $pdo->prepare("
        SELECT id, process_id
        FROM process
        WHERE company_id = ?
          AND (id = ? OR sync_source_process_id = ?)
          AND id <> ?
    ");
    $targetStmt->execute([$companyId, $anchorId, $anchorId, $processId]);

    return $targetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * 同步 Formula 到所有关联的 Multi-use Processes
 * 当源 Process 的 Formula 更新时，自动同步到所有 sync_source_process_id 指向该源 Process 的 Processes
 */
function syncFormulaToMultiUseProcesses(PDO $pdo, int $sourceProcessId, array $templateData, int $companyId) {
    global $capture_scope_group;
    if (!empty($capture_scope_group)) {
        return;
    }
    try {
        $syncedProcesses = getLinkedProcessTargets($pdo, $sourceProcessId, $companyId);
        
        if (empty($syncedProcesses)) {
            return; // 没有需要同步的 Processes
        }
        
        error_log("Syncing formula to " . count($syncedProcesses) . " multi-use processes for source process ID: $sourceProcessId");
        
        // 为每个关联的 Process 同步 Formula
        foreach ($syncedProcesses as $syncedProcess) {
            $targetProcessId = $syncedProcess['id'];
            $targetProcessCode = $syncedProcess['process_id'];
            
            try {
                // 查找目标 Process 中对应的 template（基于 id_product, account_id, product_type, formula_variant；sub 行另加 sub_order）
                $productType = $templateData['product_type'] ?? 'main';
                $subOrder = isset($templateData['sub_order']) && $templateData['sub_order'] !== null && $templateData['sub_order'] !== '' ? (float)$templateData['sub_order'] : null;
                $hasSubOrder = $productType === 'sub' && $subOrder !== null;
                $sql = "
                    SELECT id FROM data_capture_templates 
                    WHERE process_id = ? 
                      AND company_id = ?
                      AND id_product = ?
                      AND account_id = ?
                      AND product_type = ?
                      AND formula_variant = ?
                " . ($hasSubOrder ? " AND (COALESCE(sub_order, 0) = COALESCE(?, 0))" : "") . "
                    LIMIT 1
                ";
                $findTemplateStmt = $pdo->prepare($sql);
                $params = [
                    $targetProcessId,
                    $companyId,
                    $templateData['id_product'],
                    $templateData['account_id'],
                    $productType,
                    $templateData['formula_variant']
                ];
                if ($hasSubOrder) {
                    $params[] = $subOrder;
                }
                $findTemplateStmt->execute($params);
                $targetTemplate = $findTemplateStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($targetTemplate) {
                    // 更新已存在的 template（Source、Rate、Formula 等全部覆盖）
                    $updateStmt = $pdo->prepare("
                        UPDATE data_capture_templates SET
                            source_columns = ?,
                            formula_operators = ?,
                            source_percent = ?,
                            enable_source_percent = ?,
                            input_method = ?,
                            enable_input_method = ?,
                            batch_selection = COALESCE(?, batch_selection),
                            columns_display = ?,
                            formula_display = ?,
                            description = ?,
                            account_display = ?,
                            currency_id = ?,
                            currency_display = ?,
                            last_source_value = COALESCE(?, last_source_value),
                            last_processed_amount = COALESCE(?, last_processed_amount),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $updateStmt->execute([
                        $templateData['source_columns'],
                        $templateData['formula_operators'],
                        $templateData['source_percent'],
                        $templateData['enable_source_percent'],
                        $templateData['input_method'],
                        $templateData['enable_input_method'],
                        isset($templateData['batch_selection']) ? (int)$templateData['batch_selection'] : null,
                        $templateData['columns_display'],
                        $templateData['formula_display'],
                        $templateData['description'],
                        $templateData['account_display'],
                        $templateData['currency_id'],
                        $templateData['currency_display'],
                        isset($templateData['last_source_value']) ? $templateData['last_source_value'] : null,
                        isset($templateData['last_processed_amount']) ? money_normalize($templateData['last_processed_amount']) : null,
                        $targetTemplate['id']
                    ]);
                    error_log("Updated template ID {$targetTemplate['id']} for process $targetProcessCode (ID: $targetProcessId)");
                } else {
                    // 新增同步：目标无该 Id_Product 行则插入对应 template
                    $insStmt = $pdo->prepare("
                        INSERT INTO data_capture_templates (
                            company_id, process_id, id_product, product_type, parent_id_product,
                            template_key, description, account_id, account_display,
                            currency_id, currency_display, source_columns, formula_operators,
                            source_percent, enable_source_percent, input_method, enable_input_method,
                            batch_selection, columns_display, formula_display,
                            last_source_value, last_processed_amount, row_index, sub_order, formula_variant, data_capture_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $templateKey = isset($templateData['template_key']) && $templateData['template_key'] !== '' ? $templateData['template_key'] : null;
                    if ($templateKey === null && !empty($templateData['id_product'])) {
                        $templateKey = $templateData['id_product'] . '_' . ($templateData['account_id'] ?? '') . '_' . ($templateData['formula_variant'] ?? 0);
                    }
                    $insStmt->execute([
                        $companyId,
                        $targetProcessId,
                        $templateData['id_product'],
                        $productType,
                        isset($templateData['parent_id_product']) ? $templateData['parent_id_product'] : null,
                        $templateKey,
                        isset($templateData['description']) ? $templateData['description'] : null,
                        $templateData['account_id'],
                        isset($templateData['account_display']) ? $templateData['account_display'] : null,
                        isset($templateData['currency_id']) ? $templateData['currency_id'] : null,
                        isset($templateData['currency_display']) ? $templateData['currency_display'] : null,
                        $templateData['source_columns'],
                        $templateData['formula_operators'],
                        isset($templateData['source_percent']) ? $templateData['source_percent'] : '1',
                        isset($templateData['enable_source_percent']) ? (int)$templateData['enable_source_percent'] : 1,
                        isset($templateData['input_method']) ? $templateData['input_method'] : null,
                        isset($templateData['enable_input_method']) ? (int)$templateData['enable_input_method'] : 0,
                        isset($templateData['batch_selection']) ? (int)$templateData['batch_selection'] : 0,
                        isset($templateData['columns_display']) ? $templateData['columns_display'] : null,
                        isset($templateData['formula_display']) ? $templateData['formula_display'] : null,
                        isset($templateData['last_source_value']) ? $templateData['last_source_value'] : null,
                        isset($templateData['last_processed_amount']) ? money_normalize($templateData['last_processed_amount']) : money_normalize('0'),
                        isset($templateData['row_index']) ? (int)$templateData['row_index'] : null,
                        $subOrder,
                        $templateData['formula_variant'],
                        isset($templateData['data_capture_id']) ? (int)$templateData['data_capture_id'] : null
                    ]);
                    error_log("Inserted new template for process $targetProcessCode (ID: $targetProcessId) - id_product={$templateData['id_product']}");
                }
            } catch (Exception $e) {
                error_log("Error syncing formula to process $targetProcessCode (ID: $targetProcessId): " . $e->getMessage());
                // 继续同步其他 Processes，不中断
            }
        }
    } catch (Exception $e) {
        error_log("Error in syncFormulaToMultiUseProcesses: " . $e->getMessage());
        // 不抛出异常，避免影响主流程
    }
}

/**
 * A_ID 删除某行时，同步删除所有 sync_source_process_id = A_ID 的 process 中对应行（按 id_product/account_id/product_type/formula_variant/sub_order 匹配）
 */
function syncDeleteTemplateToMultiUseProcesses(PDO $pdo, int $sourceProcessId, string $idProduct, $accountId, string $productType, $formulaVariant, $subOrder, int $companyId) {
    global $capture_scope_group;
    if (!empty($capture_scope_group)) {
        return;
    }
    try {
        $syncedProcesses = getLinkedProcessTargets($pdo, $sourceProcessId, $companyId);
        if (empty($syncedProcesses)) {
            return;
        }
        $hasSubOrder = $productType === 'sub' && $subOrder !== null && $subOrder !== '';
        $sql = "
            DELETE FROM data_capture_templates 
            WHERE process_id = ? AND company_id = ?
              AND id_product = ? AND account_id = ?
              AND product_type = ? AND formula_variant = ?
        " . ($hasSubOrder ? " AND (COALESCE(sub_order, 0) = COALESCE(?, 0))" : "");
        $delStmt = $pdo->prepare($sql);
        foreach ($syncedProcesses as $synced) {
            $targetProcessId = $synced['id'];
            $params = [$targetProcessId, $companyId, $idProduct, $accountId, $productType, $formulaVariant];
            if ($hasSubOrder) {
                $params[] = $subOrder;
            }
            $delStmt->execute($params);
            $n = $delStmt->rowCount();
            if ($n > 0) {
                error_log("Sync delete: removed template for process {$synced['process_id']} (ID: $targetProcessId)");
            }
        }
    } catch (Exception $e) {
        error_log("Error in syncDeleteTemplateToMultiUseProcesses: " . $e->getMessage());
    }
}

/**
 * Scope columns for data_capture_templates — aligns with Submit / Formula Maintenance ledger filter.
 *
 * @return array{scope_type: ?string, scope_id: ?int}|null null when table has no scope columns
 */
function resolveTemplateScopeInsertForSave(PDO $pdo, int $companyId): ?array
{
    if (!tenant_table_has_scope_columns($pdo, 'data_capture_templates')) {
        return null;
    }

    global $capture_scope_ctx;
    $scopeCtx = is_array($capture_scope_ctx) && $capture_scope_ctx !== []
        ? $capture_scope_ctx
        : [
            'company_id' => $companyId,
            'is_group_scope' => false,
        ];
    $scopeCtx['dual_tenant'] = true;

    $insert = dcCaptureScopeInsertValues($scopeCtx);

    return [
        'scope_type' => $insert['scope_type'],
        'scope_id' => $insert['scope_id'] !== null ? (int) $insert['scope_id'] : null,
    ];
}

/** Backfill templates saved before scope columns were populated (company or group ledger). */
function backfillTemplateScope(PDO $pdo, int $companyId, ?array $scopeInsert): void
{
    if ($scopeInsert === null) {
        return;
    }

    $scopeType = (string) ($scopeInsert['scope_type'] ?? '');
    $scopeId = (int) ($scopeInsert['scope_id'] ?? 0);
    if ($scopeId <= 0 || !in_array($scopeType, ['company', 'group'], true)) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE data_capture_templates
            SET scope_type = :scope_type,
                scope_id = :scope_id
            WHERE company_id = :company_id
              AND (scope_type IS NULL OR TRIM(scope_type) = '')
              AND (scope_id IS NULL OR scope_id = 0)
        ");
        $stmt->execute([
            ':scope_type' => $scopeType,
            ':scope_id' => $scopeId,
            ':company_id' => $companyId,
        ]);
    } catch (Exception $e) {
        error_log('Template scope backfill warning: ' . $e->getMessage());
    }
}

function saveTemplateRow(PDO $pdo, array $row, int $companyId) {
    // Ensure required keys exist
    if (empty($row['id_product']) || empty($row['account_id'])) {
        return null;
    }

    $productType = $row['product_type'] ?? 'main';
    $parentIdProduct = $row['parent_id_product'] ?? null;

    if ($productType === 'sub' && !$parentIdProduct) {
        $parentIdProduct = $row['id_product_main'] ?? null;
    }

    $templateKey = $row['template_key'] ?? computeTemplateKey(array_merge($row, [
        'product_type' => $productType,
        'parent_id_product' => $parentIdProduct,
    ]));
    
    // process_id should be process.id (int), not process.process_id (varchar string)
    $processId = null;
    if (isset($row['process_id'])) {
        $processIdValue = $row['process_id'];
        // Convert to integer (process.id)
        if (is_numeric($processIdValue)) {
            $processId = (int)$processIdValue;
        } elseif (is_string($processIdValue) && trim($processIdValue) !== '') {
            // If it's a string (process.process_id like 'KKKAB'), try to find process.id
            // This is for backward compatibility during migration
            global $capture_scope_group;
            $resolvedPid = dcResolveProcessIdByCode(
                $pdo,
                (int) $companyId,
                trim($processIdValue),
                (bool) $capture_scope_group
            );
            if ($resolvedPid !== null) {
                $processId = $resolvedPid;
                error_log("Converted process_id from string '{$processIdValue}' to int {$processId}");
            } else {
                error_log("Warning: Could not find process.id for process_id '{$processIdValue}'");
            }
        }
    }
    $hasProcessId = $processId !== null && $processId > 0;
    $templateScopeInsert = resolveTemplateScopeInsertForSave($pdo, $companyId);
    $dataCaptureId = isset($row['data_capture_id']) && !empty($row['data_capture_id']) ? (int)$row['data_capture_id'] : null;
    
    // Get formula_display to determine formula_variant
    $formulaDisplay = $row['formula_display'] ?? '';
    $batchSelection = isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0;
    
    // Get sub_order for sub rows (used to distinguish multiple sub rows with same account)
    $subOrder = isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null;
    
    // 如果提供了 template_id，优先使用它来查找现有模板（编辑模式）
    $templateId = isset($row['template_id']) && !empty($row['template_id']) ? (int)$row['template_id'] : null;
    
    // Determine formula_variant: if provided, use it; otherwise find the next available variant
    $formulaVariant = isset($row['formula_variant']) && $row['formula_variant'] !== null && $row['formula_variant'] !== '' ? (int)$row['formula_variant'] : null;

    $rowIndexForHierarchy = isset($row['row_index']) && $row['row_index'] !== null && $row['row_index'] !== ''
        ? (int)$row['row_index']
        : null;

    // Sub：按 parent_id_product 层级定位已有行，避免 template_unique 不含 parent 时重复 INSERT
    if ($productType === 'sub' && $templateId === null && $parentIdProduct) {
        $hierarchyHit = findSubTemplateByHierarchy(
            $pdo,
            $companyId,
            $hasProcessId ? $processId : null,
            (string)$parentIdProduct,
            (int)$row['account_id'],
            $rowIndexForHierarchy,
            $subOrder
        );
        if ($hierarchyHit) {
            $templateId = (int)$hierarchyHit['id'];
            $formulaVariant = (int)$hierarchyHit['formula_variant'];
        }
    }
    
    // 如果提供了 template_id，直接使用它来查找现有模板并获取 formula_variant
    if ($templateId !== null) {
        $existingTemplateStmt = $pdo->prepare("
            SELECT formula_variant FROM data_capture_templates 
            WHERE id = :template_id
              AND company_id = :company_id
            LIMIT 1
        ");
        $existingTemplateStmt->execute([
            ':template_id' => $templateId,
            ':company_id' => $companyId
        ]);
        $existingTemplate = $existingTemplateStmt->fetch();
        if ($existingTemplate) {
            // 使用现有模板的 formula_variant
            $formulaVariant = (int)$existingTemplate['formula_variant'];
        }
    }
    
    // If formula_variant not provided, check if a record with same id_product, account_id, batch_selection, AND formula_display exists
    // If exists, use its formula_variant (update existing record)
    // If not exists, find the next available formula_variant (create new record)
    // This allows multiple rows with same id_product and account_id but different formulas (different formula_variant)
    if ($formulaVariant === null) {
        // First, try to find existing template with same id_product, account_id, batch_selection, AND formula_display
        // This handles the case where the same formula is being updated
        // For sub rows, also check sub_order to distinguish multiple sub rows with same account
        if ($productType === 'sub') {
            $existingTemplateStmt = $pdo->prepare("
                SELECT formula_variant FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'sub'
                  AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND batch_selection = :batch_selection
                  AND COALESCE(formula_display, '') = COALESCE(:formula_display, '')
                  AND (COALESCE(sub_order, 0) = COALESCE(:sub_order, 0))
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            
            $existingTemplateParams = [
                ':company_id' => $companyId,
                ':parent_id_product' => $parentIdProduct,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':batch_selection' => $batchSelection,
                ':formula_display' => $formulaDisplay,
                ':sub_order' => $subOrder
            ];
            
            if ($hasProcessId) {
                $existingTemplateParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $existingTemplateParams[':data_capture_id'] = $dataCaptureId;
            }
        } else {
            $existingTemplateStmt = $pdo->prepare("
                SELECT formula_variant FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'main'
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND batch_selection = :batch_selection
                  AND COALESCE(formula_display, '') = COALESCE(:formula_display, '')
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            
            $existingTemplateParams = [
                ':company_id' => $companyId,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':batch_selection' => $batchSelection,
                ':formula_display' => $formulaDisplay
            ];
            
            if ($hasProcessId) {
                $existingTemplateParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $existingTemplateParams[':data_capture_id'] = $dataCaptureId;
            }
        }
        
        $existingTemplateStmt->execute($existingTemplateParams);
        $existingTemplate = $existingTemplateStmt->fetch();
        
        if ($existingTemplate) {
            // Use existing formula_variant for the same batch_selection state AND formula_display
            // This means it's the same template, just being updated
            $formulaVariant = (int)$existingTemplate['formula_variant'];
        } else {
            // No existing template with same formula_display found
            // Find the next available formula_variant for this id_product and account_id
            // This allows multiple rows with same id_product and account_id but different formulas
            // For sub rows, also consider sub_order to distinguish multiple sub rows with same account
            if ($productType === 'sub') {
                $maxVariantStmt = $pdo->prepare("
                    SELECT MAX(formula_variant) as max_variant FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                      AND product_type = 'sub'
                      AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                      AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                      AND account_id = :account_id
                      AND (COALESCE(sub_order, 0) = COALESCE(:sub_order, 0))
                      AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ");
                
                $maxVariantParams = [
                    ':company_id' => $companyId,
                    ':parent_id_product' => $parentIdProduct,
                    ':id_product' => $row['id_product'],
                    ':account_id' => $row['account_id'],
                    ':sub_order' => $subOrder
                ];
                
                if ($hasProcessId) {
                    $maxVariantParams[':process_id'] = $processId;
                }
                if ($dataCaptureId) {
                    $maxVariantParams[':data_capture_id'] = $dataCaptureId;
                }
            } else {
                $maxVariantStmt = $pdo->prepare("
                    SELECT MAX(formula_variant) as max_variant FROM data_capture_templates 
                    WHERE company_id = :company_id
                      AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                      AND product_type = 'main'
                      AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                      AND account_id = :account_id
                      AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                ");
                
                $maxVariantParams = [
                    ':company_id' => $companyId,
                    ':id_product' => $row['id_product'],
                    ':account_id' => $row['account_id']
                ];
                
                if ($hasProcessId) {
                    $maxVariantParams[':process_id'] = $processId;
                }
                if ($dataCaptureId) {
                    $maxVariantParams[':data_capture_id'] = $dataCaptureId;
                }
            }
            
            $maxVariantStmt->execute($maxVariantParams);
            $maxVariantResult = $maxVariantStmt->fetch();
            $maxVariant = $maxVariantResult && $maxVariantResult['max_variant'] !== null ? (int)$maxVariantResult['max_variant'] : 0;
            $formulaVariant = $maxVariant + 1;
        }
    }
    
    // Check for duplicate before inserting/updating
    // Now includes formula_variant in the check
    // 如果提供了 template_id，优先使用它来查找现有记录（编辑模式）
    $existingRecord = null;
    if ($templateId !== null) {
        // 直接使用 template_id 查找现有记录
        $checkStmt = $pdo->prepare("
            SELECT id FROM data_capture_templates 
            WHERE id = :template_id
              AND company_id = :company_id
            LIMIT 1
        ");
        $checkStmt->execute([
            ':template_id' => $templateId,
            ':company_id' => $companyId
        ]);
        $existingRecord = $checkStmt->fetch();
    }
    
    // 同 (process, type, product, account) 且 同 formula、同 input_method 才视为同一条并更新；不同 formula 或不同 input_method 则保留为多条
    $formulaForMatch = trim((string)($row['formula_operators'] ?? $row['formula_display'] ?? ''));
    $inputMethodForMatch = trim((string)($row['input_method'] ?? ''));
    if (!$existingRecord && $dataCaptureId === null) {
        if ($productType === 'sub') {
            $anyStmt = $pdo->prepare("
                SELECT id, formula_variant FROM data_capture_templates 
                WHERE company_id = ? AND process_id " . ($hasProcessId ? "= ?" : "IS NULL") . "
                  AND product_type = 'sub' AND COALESCE(TRIM(parent_id_product), '') = COALESCE(TRIM(?), '')
                  AND COALESCE(TRIM(id_product), '') = COALESCE(TRIM(?), '') AND account_id = ?
                  AND COALESCE(TRIM(formula_operators), TRIM(formula_display), '') = ?
                  AND COALESCE(TRIM(input_method), '') = ?
                  AND (COALESCE(sub_order, 0) = COALESCE(?, 0))
                  AND (data_capture_id IS NULL OR data_capture_id = 0)
                ORDER BY updated_at DESC LIMIT 1
            ");
            $anyParams = [$companyId, $parentIdProduct, $row['id_product'], $row['account_id'], $formulaForMatch, $inputMethodForMatch, $subOrder];
            if ($hasProcessId) {
                array_splice($anyParams, 1, 0, [$processId]);
            }
            $anyStmt->execute($anyParams);
            $anyRow = $anyStmt->fetch(PDO::FETCH_ASSOC);
            if ($anyRow) {
                $existingRecord = ['id' => $anyRow['id']];
                $formulaVariant = (int)$anyRow['formula_variant'];
            }
        } else {
            $anyStmt = $pdo->prepare("
                SELECT id, formula_variant FROM data_capture_templates 
                WHERE company_id = ? AND process_id " . ($hasProcessId ? "= ?" : "IS NULL") . "
                  AND product_type = 'main' AND COALESCE(TRIM(id_product), '') = COALESCE(TRIM(?), '')
                  AND account_id = ?
                  AND COALESCE(TRIM(formula_operators), TRIM(formula_display), '') = ?
                  AND COALESCE(TRIM(input_method), '') = ?
                  AND (data_capture_id IS NULL OR data_capture_id = 0)
                ORDER BY updated_at DESC LIMIT 1
            ");
            $anyParams = [$companyId, $row['id_product'], $row['account_id'], $formulaForMatch, $inputMethodForMatch];
            if ($hasProcessId) {
                array_splice($anyParams, 1, 0, [$processId]);
            }
            $anyStmt->execute($anyParams);
            $anyRow = $anyStmt->fetch(PDO::FETCH_ASSOC);
            if ($anyRow) {
                $existingRecord = ['id' => $anyRow['id']];
                $formulaVariant = (int)$anyRow['formula_variant'];
            }
        }
    }
    
    // 如果没有通过 template_id 找到记录，使用原来的逻辑查找（按 formula_variant 精确匹配）
    if (!$existingRecord) {
        if ($productType === 'sub') {
            // For sub type, check by parent_id_product, id_product, account_id, formula_variant, sub_order, process_id, data_capture_id
            $checkStmt = $pdo->prepare("
                SELECT id FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'sub'
                  AND COALESCE(parent_id_product, '') = COALESCE(:parent_id_product, '')
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND formula_variant = :formula_variant
                  AND (COALESCE(sub_order, 0) = COALESCE(:sub_order, 0))
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                LIMIT 1
            ");
            
            $checkParams = [
                ':company_id' => $companyId,
                ':parent_id_product' => $parentIdProduct,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':formula_variant' => $formulaVariant,
                ':sub_order' => $subOrder
            ];
            
            if ($hasProcessId) {
                $checkParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $checkParams[':data_capture_id'] = $dataCaptureId;
            }
        } else {
            // For main type, check by id_product, account_id, formula_variant, process_id, data_capture_id
            $checkStmt = $pdo->prepare("
                SELECT id FROM data_capture_templates 
                WHERE company_id = :company_id
                  AND process_id " . ($hasProcessId ? "= :process_id" : "IS NULL") . "
                  AND product_type = 'main'
                  AND COALESCE(id_product, '') = COALESCE(:id_product, '')
                  AND account_id = :account_id
                  AND formula_variant = :formula_variant
                  AND data_capture_id " . ($dataCaptureId ? "= :data_capture_id" : "IS NULL") . "
                LIMIT 1
            ");
            
            $checkParams = [
                ':company_id' => $companyId,
                ':id_product' => $row['id_product'],
                ':account_id' => $row['account_id'],
                ':formula_variant' => $formulaVariant
            ];
            
            if ($hasProcessId) {
                $checkParams[':process_id'] = $processId;
            }
            if ($dataCaptureId) {
                $checkParams[':data_capture_id'] = $dataCaptureId;
            }
        }
        
        $checkStmt->execute($checkParams);
        $existingRecord = $checkStmt->fetch();
    }
    
    // If record exists, use UPDATE instead of INSERT to avoid duplicates
    if ($existingRecord) {
        $existingId = $existingRecord['id'];
        error_log("Found duplicate template record (ID: $existingId) - product_type=$productType, id_product=" . ($row['id_product'] ?? 'NULL') . ", account_id=" . ($row['account_id'] ?? 'NULL') . ", formula_variant=$formulaVariant, process_id=" . ($processId ?? 'NULL') . ", data_capture_id=" . ($dataCaptureId ?? 'NULL') . " - Updating instead of inserting");
        
        $scopeUpdateSql = $templateScopeInsert !== null
            ? "scope_type = :scope_type,\n                scope_id = :scope_id,\n                "
            : '';

        $stmt = $pdo->prepare("
            UPDATE data_capture_templates SET
                id_product = :id_product,
                parent_id_product = :parent_id_product,
                template_key = :template_key,
                description = :description,
                account_id = :account_id,
                account_display = :account_display,
                currency_id = :currency_id,
                currency_display = :currency_display,
                source_columns = :source_columns,
                formula_operators = :formula_operators,
                source_percent = :source_percent,
                enable_source_percent = :enable_source_percent,
                input_method = :input_method,
                enable_input_method = :enable_input_method,
                batch_selection = :batch_selection,
                columns_display = :columns_display,
                formula_display = :formula_display,
                last_source_value = :last_source_value,
                last_processed_amount = :last_processed_amount,
                process_id = :process_id,
                data_capture_id = :data_capture_id,
                row_index = :row_index,
                sub_order = :sub_order,
                formula_variant = :formula_variant,
                {$scopeUpdateSql}updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $updateParams = [
            ':id' => $existingId,
            ':id_product' => $row['id_product'],
            ':parent_id_product' => $parentIdProduct,
            ':template_key' => $templateKey, // Update template_key to keep it consistent
            ':description' => $row['description'] ?? null,
            ':account_id' => $row['account_id'],
            ':account_display' => $row['account_display'] ?? null,
            ':currency_id' => $row['currency_id'] ?? null,
            ':currency_display' => $row['currency_display'] ?? null,
            ':source_columns' => $row['source_columns'] ?? '',
            ':formula_operators' => $row['formula_operators'] ?? '',
            // source_percent: default to '1' (multiplier, 1 = multiply by 1), auto-enable if has value
            ':source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1',
            ':enable_source_percent' => (isset($row['source_percent']) && $row['source_percent'] !== '' && $row['source_percent'] !== '0') ? 1 : 0,
            ':input_method' => $row['input_method'] ?? null,
            ':enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
            ':batch_selection' => isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0,
            ':columns_display' => $row['columns_display'] ?? null,
            ':formula_display' => $row['formula_display'] ?? null,
            ':last_source_value' => $row['last_source_value'] ?? null,
            ':last_processed_amount' => summary_money_value($row, 'last_processed_amount'),
            ':process_id' => $processId,
            ':data_capture_id' => $dataCaptureId,
            ':row_index' => isset($row['row_index']) ? (int)$row['row_index'] : null,
            ':sub_order' => isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null,
            ':formula_variant' => $formulaVariant,
        ];
        if ($templateScopeInsert !== null) {
            $updateParams[':scope_type'] = $templateScopeInsert['scope_type'];
            $updateParams[':scope_id'] = $templateScopeInsert['scope_id'];
        }

        $stmt->execute($updateParams);
        
        // 如果当前 Process 是源 Process，同步 Formula 到所有关联的 Multi-use Processes
        if ($hasProcessId && $processId) {
            $syncTemplateData = [
                'id_product' => $row['id_product'],
                'account_id' => $row['account_id'],
                'product_type' => $productType,
                'formula_variant' => $formulaVariant,
                'source_columns' => $row['source_columns'] ?? '',
                'formula_operators' => $row['formula_operators'] ?? '',
                'source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1',
                'enable_source_percent' => (isset($row['source_percent']) && $row['source_percent'] !== '' && $row['source_percent'] !== '0') ? 1 : 0,
                'input_method' => $row['input_method'] ?? null,
                'enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
                'columns_display' => $row['columns_display'] ?? null,
                'formula_display' => $row['formula_display'] ?? null,
                'last_processed_amount' => summary_money_value($row, 'last_processed_amount'),
                'description' => $row['description'] ?? null,
                'account_display' => $row['account_display'] ?? null,
                'currency_id' => $row['currency_id'] ?? null,
                'currency_display' => $row['currency_display'] ?? null,
            ];
            syncFormulaToMultiUseProcesses($pdo, $processId, $syncTemplateData, $companyId);
        }
        
        return [
            'template_key' => $templateKey,
            'template_id' => $existingId,
            'formula_variant' => $formulaVariant
        ]; // Return template info after update
    }

    $scopeInsertColumns = $templateScopeInsert !== null ? "scope_type,\n            scope_id,\n            " : '';
    $scopeInsertValues = $templateScopeInsert !== null ? ":scope_type,\n            :scope_id,\n            " : '';
    $scopeDuplicateUpdate = $templateScopeInsert !== null
        ? "scope_type = VALUES(scope_type),\n            scope_id = VALUES(scope_id),\n            "
        : '';

    $stmt = $pdo->prepare("
        INSERT INTO data_capture_templates (
            company_id,
            {$scopeInsertColumns}id_product,
            product_type,
            parent_id_product,
            template_key,
            description,
            account_id,
            account_display,
            currency_id,
            currency_display,
            source_columns,
            formula_operators,
            source_percent,
            enable_source_percent,
            input_method,
            enable_input_method,
            batch_selection,
            columns_display,
            formula_display,
            last_source_value,
            last_processed_amount,
            process_id,
            data_capture_id,
            row_index,
            sub_order,
            formula_variant
        ) VALUES (
            :company_id,
            {$scopeInsertValues}:id_product,
            :product_type,
            :parent_id_product,
            :template_key,
            :description,
            :account_id,
            :account_display,
            :currency_id,
            :currency_display,
            :source_columns,
            :formula_operators,
            :source_percent,
            :enable_source_percent,
            :input_method,
            :enable_input_method,
            :batch_selection,
            :columns_display,
            :formula_display,
            :last_source_value,
            :last_processed_amount,
            :process_id,
            :data_capture_id,
            :row_index,
            :sub_order,
            :formula_variant
        )
        ON DUPLICATE KEY UPDATE
            description = VALUES(description),
            account_id = VALUES(account_id),
            account_display = VALUES(account_display),
            currency_id = VALUES(currency_id),
            currency_display = VALUES(currency_display),
            source_columns = VALUES(source_columns),
            formula_operators = VALUES(formula_operators),
            source_percent = VALUES(source_percent),
            enable_source_percent = VALUES(enable_source_percent),
            input_method = VALUES(input_method),
            enable_input_method = VALUES(enable_input_method),
            batch_selection = VALUES(batch_selection),
            columns_display = VALUES(columns_display),
            formula_display = VALUES(formula_display),
            last_source_value = VALUES(last_source_value),
            last_processed_amount = VALUES(last_processed_amount),
            parent_id_product = VALUES(parent_id_product),
            template_key = VALUES(template_key),
            product_type = VALUES(product_type),
            process_id = VALUES(process_id),
            data_capture_id = VALUES(data_capture_id),
            row_index = VALUES(row_index),
            sub_order = VALUES(sub_order),
            formula_variant = VALUES(formula_variant),
            {$scopeDuplicateUpdate}updated_at = CURRENT_TIMESTAMP
    ");

    $insertParams = [
        ':company_id' => $companyId,
        ':id_product' => $row['id_product'],
        ':product_type' => $productType,
        ':parent_id_product' => $parentIdProduct,
        ':template_key' => $templateKey,
        ':description' => $row['description'] ?? null,
        ':account_id' => $row['account_id'],
        ':account_display' => $row['account_display'] ?? null,
        ':currency_id' => $row['currency_id'] ?? null,
        ':currency_display' => $row['currency_display'] ?? null,
        ':source_columns' => $row['source_columns'] ?? '',
        ':formula_operators' => $row['formula_operators'] ?? '',
        ':source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1', // Store as string to preserve expressions like "1/2", default to '1' (multiplier)
        ':enable_source_percent' => isset($row['enable_source_percent']) ? (int)$row['enable_source_percent'] : 1,
        ':input_method' => $row['input_method'] ?? null,
        ':enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
        ':batch_selection' => isset($row['batch_selection']) ? (int)$row['batch_selection'] : 0,
        ':columns_display' => $row['columns_display'] ?? null,
        ':formula_display' => $row['formula_display'] ?? null,
        ':last_source_value' => $row['last_source_value'] ?? null,
        ':last_processed_amount' => summary_money_value($row, 'last_processed_amount'),
        ':process_id' => $processId,
        ':data_capture_id' => isset($row['data_capture_id']) && !empty($row['data_capture_id']) ? (int)$row['data_capture_id'] : null,
        ':row_index' => isset($row['row_index']) ? (int)$row['row_index'] : null,
        ':sub_order' => isset($row['sub_order']) && $row['sub_order'] !== null && $row['sub_order'] !== '' ? (float)$row['sub_order'] : null,
        ':formula_variant' => $formulaVariant,
    ];
    if ($templateScopeInsert !== null) {
        $insertParams[':scope_type'] = $templateScopeInsert['scope_type'];
        $insertParams[':scope_id'] = $templateScopeInsert['scope_id'];
    }

    $stmt->execute($insertParams);
    
    $templateId = $pdo->lastInsertId();
    
    // 如果当前 Process 是源 Process，同步 Formula 到所有关联的 Multi-use Processes
    if ($hasProcessId && $processId) {
        $syncTemplateData = [
            'id_product' => $row['id_product'],
            'account_id' => $row['account_id'],
            'product_type' => $productType,
            'formula_variant' => $formulaVariant,
            'source_columns' => $row['source_columns'] ?? '',
            'formula_operators' => $row['formula_operators'] ?? '',
            'source_percent' => isset($row['source_percent']) && $row['source_percent'] !== '' ? (string)$row['source_percent'] : '1',
            'enable_source_percent' => isset($row['enable_source_percent']) ? (int)$row['enable_source_percent'] : 1,
            'input_method' => $row['input_method'] ?? null,
            'enable_input_method' => isset($row['enable_input_method']) ? (int)$row['enable_input_method'] : 0,
            'columns_display' => $row['columns_display'] ?? null,
            'formula_display' => $row['formula_display'] ?? null,
            'last_processed_amount' => summary_money_value($row, 'last_processed_amount'),
            'description' => $row['description'] ?? null,
            'account_display' => $row['account_display'] ?? null,
            'currency_id' => $row['currency_id'] ?? null,
            'currency_display' => $row['currency_display'] ?? null,
        ];
        syncFormulaToMultiUseProcesses($pdo, $processId, $syncTemplateData, $companyId);
    }
    
    return [
        'template_key' => $templateKey,
        'template_id' => $templateId,
        'formula_variant' => $formulaVariant
    ]; // Return template info after insert
}

/**
 * Normalize id_product for use as template key (strip trailing " (description)").
 * Matches frontend normalizeIdProductText so that templates group under the same key.
 */
function normalizeIdProductForKey($text) {
    if ($text === null || $text === '') {
        return '';
    }
    $trimmed = trim((string)$text);
    if ($trimmed === '') {
        return '';
    }
    // Strip trailing " (anything)" to match frontend normalized key
    $normalized = preg_replace('/\s*\([^)]+\)\s*$/', '', $trimmed);
    return trim($normalized);
}

/**
 * Base part of id_product (before first "(") for grouping.
 * 与前端 normalizeIdProductText 一致，便于 Summary 用 ALLBET95MS 取到 ALLBET95MS(SV)MYR 等模板。
 */
function baseIdProductForKey($text) {
    if ($text === null || $text === '') {
        return '';
    }
    $trimmed = trim((string)$text);
    if ($trimmed === '') {
        return '';
    }
    $pos = strpos($trimmed, '(');
    return $pos > 0 ? trim(substr($trimmed, 0, $pos)) : $trimmed;
}

/**
 * Normalized key for template grouping: only trim trailing spaces, preserve colon (e.g. VM365-21:).
 * 与前端一致：id_product 完整进资料库、完整查找，不剔除末尾冒号。
 */
function baseIdProductForKeyNormalized($text) {
    $base = baseIdProductForKey($text);
    if ($base === '') {
        return '';
    }
    return trim($base);
}

/**
 * Merge (id_product, account_id) pairs from data_capture_details into templates
 * so that accounts that exist in details but have no template still get a row (synthetic template).
 * 修复：data_capture_details 有该账目但 data_capture_templates 没有时，仍能在 Summary 中显示。
 */
function mergeDetailOnlyTemplates(PDO $pdo, int $companyId, int $captureId, array $ids, array $templates) {
    $hasDisplayOrder = summaryApiHasDisplayOrder($pdo); // static 缓存，不重复 SHOW
    $orderBy = $hasDisplayOrder ? "ORDER BY COALESCE(display_order, 999), id" : "ORDER BY id";
    $cols = $hasDisplayOrder ? "id_product_main, id_product_sub, product_type, account_id, display_order, rate" : "id_product_main, id_product_sub, product_type, account_id, rate";
    $detailStmt = $pdo->prepare("
        SELECT $cols
        FROM data_capture_details
        WHERE company_id = ? AND capture_id = ?
        $orderBy
    ");
    $detailStmt->execute([$companyId, $captureId]);
    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

    $pairsByKey = [];
    $detailIndex = 0;
    foreach ($details as $row) {
        $accountId = isset($row['account_id']) ? trim((string)$row['account_id']) : '';
        if ($accountId === '') {
            continue;
        }
        $productType = $row['product_type'] ?? 'main';
        $idProductMain = isset($row['id_product_main']) ? trim((string)$row['id_product_main']) : '';
        $idProductSub  = isset($row['id_product_sub'])  ? trim((string)$row['id_product_sub'])  : '';
        if ($productType === 'main') {
            $idForKey = $idProductMain !== '' ? $idProductMain : $idProductSub;
        } else {
            $idForKey = $idProductMain !== '' ? $idProductMain : $idProductSub;
        }
        if ($idForKey === '') {
            continue;
        }
        // 与 fetchTemplates 一致：用完整 id 作 key，避免 GAMS(SV)HKD 与 GAMS(SV)MYR 混组
        $key = trim((string) $idForKey);
        if ($key === '') {
            $key = baseIdProductForKeyNormalized($idForKey);
            if ($key === '') {
                $key = $idForKey;
            }
        }
        if (!isset($pairsByKey[$key])) {
            $pairsByKey[$key] = [];
        }
        $displayOrder = $hasDisplayOrder && isset($row['display_order']) ? (int)$row['display_order'] : $detailIndex;
        $pairsByKey[$key][$accountId] = [
            'id_product' => $idForKey,
            'account_id' => $accountId,
            'display_order' => $displayOrder,
            'rate' => isset($row['rate']) && $row['rate'] !== null && $row['rate'] !== '' ? (string)$row['rate'] : null,
        ];
        $detailIndex++;
    }

    $accountIds = [];
    foreach ($pairsByKey as $pairs) {
        foreach ($pairs as $accId => $_) {
            if (is_numeric($accId)) {
                $accountIds[(int)$accId] = true;
            }
        }
    }
    $accountIds = array_keys($accountIds);
    $accountDisplayMap = [];
    if (!empty($accountIds)) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $accStmt = $pdo->prepare("
            SELECT a.id, a.account_id AS code, a.name
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ? AND a.id IN ($placeholders)
        ");
        $accStmt->execute(array_merge([$companyId], $accountIds));
        while ($row = $accStmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['id'];
            $code = $row['code'] ?? '';
            $name = $row['name'] ?? '';
            $accountDisplayMap[$id] = $code !== '' && $name !== '' ? ($code . ' [' . $name . ']') : ($code ?: (string)$id);
            $accountDisplayMap[(string)$id] = $accountDisplayMap[$id];
        }
    }

    $requestedKeys = [];
    foreach ($ids as $id) {
        $tid = trim((string) $id);
        if ($tid !== '') {
            $requestedKeys[$tid] = true;
        }
        $n = baseIdProductForKeyNormalized($tid);
        if ($n !== '' && $n !== $tid) {
            $requestedKeys[$n] = true;
        }
    }
    foreach ($pairsByKey as $key => $pairs) {
        $keyInRequest = isset($templates[$key]) || isset($requestedKeys[$key]);
        if (!$keyInRequest) {
            continue;
        }
        if (!isset($templates[$key])) {
            $templates[$key] = ['main' => null, 'subs' => [], 'allMains' => []];
        }
        $allMains = $templates[$key]['allMains'] ?? [];
        $existingAccountIds = [];
        foreach ($allMains as $m) {
            $aid = isset($m['account_id']) ? (string)$m['account_id'] : '';
            if ($aid !== '') {
                $existingAccountIds[$aid] = true;
            }
        }
        foreach ($pairs as $accId => $info) {
            if (isset($existingAccountIds[(string)$accId])) {
                continue;
            }
            $display = $accountDisplayMap[(int)$accId] ?? $accountDisplayMap[(string)$accId] ?? (string)$accId;
            $synthetic = [
                'id' => null,
                'id_product' => $info['id_product'],
                'product_type' => 'main',
                'parent_id_product' => null,
                'template_key' => $info['id_product'],
                'description' => '',
                'account_id' => $accId,
                'account_display' => $display,
                'currency_id' => null,
                'currency_display' => null,
                'source_columns' => '',
                'formula_operators' => '',
                'source_percent' => '1',
                'enable_source_percent' => 1,
                'input_method' => null,
                'enable_input_method' => 0,
                'batch_selection' => 0,
                'columns_display' => null,
                'formula_display' => '',
                'last_source_value' => null,
                'last_processed_amount' => 0,
                'process_id' => null,
                'data_capture_id' => null,
                'row_index' => $info['display_order'],
                'sub_order' => null,
                'formula_variant' => 1,
                'updated_at' => null,
                'rate' => $info['rate'] ?? null,
            ];
            $allMains[] = $synthetic;
            $existingAccountIds[(string)$accId] = true;
        }
        // 按 display_order（来自 data_capture_details）重排 allMains，避免从 Data Capture Submit 进入后 NO/API GSC 等行顺序错乱
        usort($allMains, function ($a, $b) use ($key, $pairs) {
            $aOrder = isset($pairs[(string)($a['account_id'] ?? '')]['display_order'])
                ? (int)$pairs[(string)($a['account_id'] ?? '')]['display_order']
                : (isset($a['row_index']) && $a['row_index'] !== null ? (int)$a['row_index'] : 999999);
            $bOrder = isset($pairs[(string)($b['account_id'] ?? '')]['display_order'])
                ? (int)$pairs[(string)($b['account_id'] ?? '')]['display_order']
                : (isset($b['row_index']) && $b['row_index'] !== null ? (int)$b['row_index'] : 999999);
            return $aOrder - $bOrder;
        });
        // 为来自 templates 的 main 行补充 rate（从 details 取），以便前端显示 Rate Value
        foreach ($allMains as &$m) {
            $accId = (string)($m['account_id'] ?? '');
            if ($accId !== '' && isset($pairs[$accId]['rate']) && $pairs[$accId]['rate'] !== null && $pairs[$accId]['rate'] !== '') {
                $m['rate'] = $pairs[$accId]['rate'];
            }
        }
        unset($m);
        $templates[$key]['allMains'] = $allMains;
        if ($templates[$key]['main'] === null && !empty($allMains)) {
            $templates[$key]['main'] = $allMains[0];
        }
    }
    return $templates;
}

/**
 * Apply account display labels from group ledger accounts (not subsidiary company).
 */
function resolveAccountDisplayInTemplatesForGroup(PDO $pdo, string $groupCode, array &$templates): void
{
    $accounts = dcSummaryLoadAccountsForGroup($pdo, $groupCode);
    if ($accounts === []) {
        return;
    }
    $map = [];
    foreach ($accounts as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $code = isset($row['account_id']) ? trim((string) $row['account_id']) : '';
        $name = isset($row['name']) ? trim((string) $row['name']) : '';
        $label = ($code !== '' && $name !== '') ? ($code . ' [' . $name . ']') : ($code !== '' ? $code : (string) $id);
        $map[$id] = $map[(string) $id] = $label;
    }
    foreach ($templates as $key => &$group) {
        if (!empty($group['main']['account_id'])) {
            $aid = $group['main']['account_id'];
            $sid = is_numeric($aid) ? (int) $aid : $aid;
            if (isset($map[$sid]) || isset($map[(string) $aid])) {
                $group['main']['account_display'] = $map[$sid] ?? $map[(string) $aid];
            }
        }
        if (!empty($group['allMains']) && is_array($group['allMains'])) {
            foreach ($group['allMains'] as $i => $m) {
                if (!empty($m['account_id'])) {
                    $aid = $m['account_id'];
                    $sid = is_numeric($aid) ? (int) $aid : $aid;
                    if (isset($map[$sid]) || isset($map[(string) $aid])) {
                        $templates[$key]['allMains'][$i]['account_display'] = $map[$sid] ?? $map[(string) $aid];
                    }
                }
            }
        }
        if (!empty($group['subs']) && is_array($group['subs'])) {
            foreach ($group['subs'] as $i => $s) {
                if (!empty($s['account_id'])) {
                    $aid = $s['account_id'];
                    $sid = is_numeric($aid) ? (int) $aid : $aid;
                    if (isset($map[$sid]) || isset($map[(string) $aid])) {
                        $templates[$key]['subs'][$i]['account_display'] = $map[$sid] ?? $map[(string) $aid];
                    }
                }
            }
        }
    }
    unset($group);
}

/**
 * 用 account 表解析模板中的 account_display，与 Maintenance - Formula 的 Account 列一致，避免 Summary 显示错误。
 */
function resolveAccountDisplayInTemplates(PDO $pdo, int $companyId, array &$templates) {
    $accountIds = [];
    foreach ($templates as $key => $group) {
        if (!empty($group['main']) && !empty($group['main']['account_id'])) {
            $aid = $group['main']['account_id'];
            $accountIds[(is_string($aid) ? $aid : (string)$aid)] = true;
        }
        foreach ($group['allMains'] ?? [] as $m) {
            if (!empty($m['account_id'])) {
                $aid = $m['account_id'];
                $accountIds[(is_string($aid) ? $aid : (string)$aid)] = true;
            }
        }
        foreach ($group['subs'] ?? [] as $s) {
            if (!empty($s['account_id'])) {
                $aid = $s['account_id'];
                $accountIds[(is_string($aid) ? $aid : (string)$aid)] = true;
            }
        }
    }
    $accountIds = array_keys($accountIds);
    if (empty($accountIds)) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $pdo->prepare("
        SELECT a.id, a.account_id AS code, a.name
        FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE ac.company_id = ? AND a.id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$companyId], $accountIds));
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $code = isset($row['code']) ? trim((string)$row['code']) : '';
        $name = isset($row['name']) ? trim((string)$row['name']) : '';
        $map[$id] = $map[(string)$id] = ($code !== '' && $name !== '') ? ($code . ' [' . $name . ']') : ($code !== '' ? $code : (string)$id);
    }
    foreach ($templates as $key => &$group) {
        if (!empty($group['main']['account_id'])) {
            $aid = $group['main']['account_id'];
            $sid = is_numeric($aid) ? (int)$aid : $aid;
            if (isset($map[$sid]) || isset($map[(string)$aid])) {
                $group['main']['account_display'] = $map[$sid] ?? $map[(string)$aid];
            }
        }
        if (isset($group['allMains'])) {
            foreach ($group['allMains'] as $i => $m) {
                if (!empty($m['account_id'])) {
                    $aid = $m['account_id'];
                    $sid = is_numeric($aid) ? (int)$aid : $aid;
                    if (isset($map[$sid]) || isset($map[(string)$aid])) {
                        $templates[$key]['allMains'][$i]['account_display'] = $map[$sid] ?? $map[(string)$aid];
                    }
                }
            }
        }
        if (isset($group['subs'])) {
            foreach ($group['subs'] as $i => $s) {
                if (!empty($s['account_id'])) {
                    $aid = $s['account_id'];
                    $sid = is_numeric($aid) ? (int)$aid : $aid;
                    if (isset($map[$sid]) || isset($map[(string)$aid])) {
                        $templates[$key]['subs'][$i]['account_display'] = $map[$sid] ?? $map[(string)$aid];
                    }
                }
            }
        }
    }
    unset($group);
}

/**
 * 层级唯一键：parent_id_product + account_id + row_index + sub_order（不含 formula_variant，避免同账户重复行）。
 */
function subTemplateHierarchyKey(array $sub): string {
    $parent = trim((string)($sub['parent_id_product'] ?? ''));
    $accountId = (int)($sub['account_id'] ?? 0);
    $rowIndex = isset($sub['row_index']) && $sub['row_index'] !== null && $sub['row_index'] !== ''
        ? (int)$sub['row_index']
        : -1;
    $subOrder = isset($sub['sub_order']) && $sub['sub_order'] !== null && $sub['sub_order'] !== ''
        ? (string)(float)$sub['sub_order']
        : '0';
    return strtolower($parent) . '|' . $accountId . '|' . $rowIndex . '|' . $subOrder;
}

/**
 * 同一 parent + account + row_index + sub_order 只保留一条；DB 优先于 account_link 继承副本。
 */
function dedupeTemplateGroupSubs(array $subs): array {
    if (count($subs) <= 1) {
        return $subs;
    }
    $byKey = [];
    foreach ($subs as $sub) {
        if (!is_array($sub)) {
            continue;
        }
        $key = subTemplateHierarchyKey($sub);
        if (!isset($byKey[$key])) {
            $byKey[$key] = $sub;
            continue;
        }
        $byKey[$key] = pickPreferredSubTemplateRow($byKey[$key], $sub);
    }
    return array_values($byKey);
}

function pickPreferredSubTemplateRow(array $existing, array $candidate): array {
    $existingInherited = !empty($existing['inherited_from_account_link'])
        || (isset($existing['id']) && is_string($existing['id']) && strpos((string)$existing['id'], 'inherit_') === 0);
    $candidateInherited = !empty($candidate['inherited_from_account_link'])
        || (isset($candidate['id']) && is_string($candidate['id']) && strpos((string)$candidate['id'], 'inherit_') === 0);
    if ($existingInherited && !$candidateInherited) {
        return $candidate;
    }
    if ($candidateInherited && !$existingInherited) {
        return $existing;
    }
    $existingId = isset($existing['id']) && is_numeric($existing['id']) ? (int)$existing['id'] : 0;
    $candidateId = isset($candidate['id']) && is_numeric($candidate['id']) ? (int)$candidate['id'] : 0;
    if ($candidateId > $existingId) {
        return $candidate;
    }
    if ($existingId > $candidateId) {
        return $existing;
    }
    $existingUpdated = $existing['updated_at'] ?? '';
    $candidateUpdated = $candidate['updated_at'] ?? '';
    return ($candidateUpdated > $existingUpdated) ? $candidate : $existing;
}

/**
 * 按精确 parent_id_product 分组 subs，供 Summary 单次套用（避免多 template key 重复 iterate）。
 */
function buildSubsByParentForApi(array $templates): array {
    $byParent = [];
    foreach ($templates as $group) {
        if (empty($group['subs']) || !is_array($group['subs'])) {
            continue;
        }
        foreach ($group['subs'] as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $parent = trim((string)($sub['parent_id_product'] ?? ''));
            if ($parent === '') {
                continue;
            }
            if (!isset($byParent[$parent])) {
                $byParent[$parent] = [];
            }
            $byParent[$parent][] = $sub;
        }
    }
    foreach ($byParent as $parent => $subs) {
        $byParent[$parent] = dedupeTemplateGroupSubs($subs);
    }
    return $byParent;
}

/**
 * Debug: 统计 API 层 sub 数量，确认重复发生在哪一层。
 */
function buildTemplateFetchDiagnostics(array $templates, array $subsByParent, array $rawRows = []): array {
    $subsPerParent = [];
    $totalSubsInGroups = 0;
    foreach ($templates as $key => $group) {
        $count = is_array($group['subs'] ?? null) ? count($group['subs']) : 0;
        $totalSubsInGroups += $count;
        if ($count > 0) {
            $subsPerParent[$key] = $count;
        }
    }
    $subsPerParentExact = [];
    $totalSubsExact = 0;
    foreach ($subsByParent as $parent => $subs) {
        $c = count($subs);
        $totalSubsExact += $c;
        $subsPerParentExact[$parent] = $c;
    }
    $rawSubCount = 0;
    $rawSubDupes = [];
    if (!empty($rawRows)) {
        $seen = [];
        foreach ($rawRows as $row) {
            if (($row['product_type'] ?? '') !== 'sub') {
                continue;
            }
            $rawSubCount++;
            $k = subTemplateHierarchyKey($row);
            if (!isset($seen[$k])) {
                $seen[$k] = [];
            }
            $seen[$k][] = (int)($row['id'] ?? 0);
        }
        foreach ($seen as $k => $ids) {
            if (count($ids) > 1) {
                $rawSubDupes[$k] = $ids;
            }
        }
    }
    return [
        'sql_sub_row_count' => $rawSubCount,
        'sql_duplicate_hierarchy_keys' => $rawSubDupes,
        'grouped_sub_count_by_template_key' => $subsPerParent,
        'total_subs_inside_template_groups' => $totalSubsInGroups,
        'subs_by_parent_exact' => $subsPerParentExact,
        'total_subs_after_dedupe' => $totalSubsExact,
    ];
}

/**
 * 按 parent_id_product + account_id + row_index (+ sub_order) 查找已保存的 sub 模板，防止重复 INSERT。
 */
function findSubTemplateByHierarchy(
    PDO $pdo,
    int $companyId,
    ?int $processId,
    string $parentIdProduct,
    int $accountId,
    ?int $rowIndex,
    ?float $subOrder
): ?array {
    $parentIdProduct = trim($parentIdProduct);
    if ($parentIdProduct === '' || $accountId <= 0) {
        return null;
    }
    $sql = "
        SELECT id, formula_variant
        FROM data_capture_templates
        WHERE company_id = :company_id
          AND product_type = 'sub'
          AND TRIM(COALESCE(parent_id_product, '')) = :parent_id_product
          AND account_id = :account_id
    ";
    $params = [
        ':company_id' => $companyId,
        ':parent_id_product' => $parentIdProduct,
        ':account_id' => $accountId,
    ];
    if ($processId !== null && $processId > 0) {
        $sql .= " AND process_id = :process_id";
        $params[':process_id'] = $processId;
    } else {
        $sql .= " AND (process_id IS NULL OR process_id = 0)";
    }
    if ($rowIndex !== null && $rowIndex >= 0 && $rowIndex < 999999) {
        $sql .= " AND row_index = :row_index";
        $params[':row_index'] = $rowIndex;
    }
    if ($subOrder !== null) {
        $sql .= " AND (COALESCE(sub_order, 0) = COALESCE(:sub_order, 0))";
        $params[':sub_order'] = $subOrder;
    }
    $sql .= " ORDER BY id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 把 Main Acc 的 Formula 动态派生一份给 Sub Acc，通过 account_link 表中的 unidirectional 映射。
 * Summary 页面不再调用：仅使用 data_capture_templates 中带 parent_id_product 的显式 sub 记录。
 */
function inheritFormulasToSubAccounts(PDO $pdo, int $companyId, array $templates): array {
    try {
        // 先检查是否存在 link_type，如果不存在直接退出（防止表结构过旧报错）
        $check_column_stmt = $pdo->query("SHOW COLUMNS FROM account_link LIKE 'link_type'");
        if ($check_column_stmt->rowCount() === 0) {
            return $templates;
        }

        // 查找所有 unidirectional 相关的连接关系
        $stmt = $pdo->prepare("
            SELECT account_id_1, account_id_2, source_account_id 
            FROM account_link 
            WHERE company_id = ? AND link_type = 'unidirectional'
        ");
        $stmt->execute([$companyId]);
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 建立结构：Main Acc => [Sub Acc 1, Sub Acc 2, ...]
        $inheritanceMap = [];
        foreach ($links as $link) {
            $source = (int)$link['source_account_id'];
            $acc1 = (int)$link['account_id_1'];
            $acc2 = (int)$link['account_id_2'];
            if ($source > 0) {
                $sub = ($acc1 === $source) ? $acc2 : $acc1;
                $inheritanceMap[$source][] = $sub;
            }
        }

        if (empty($inheritanceMap)) {
            return $templates;
        }

        // 提取一下所有受影响的 Sub Acc 的 Display Name
        $subAccountDisplayMap = [];
        foreach ($inheritanceMap as $source => $subs) {
            foreach ($subs as $sub) {
                $subAccountDisplayMap[$sub] = null;
            }
        }
        
        if (!empty($subAccountDisplayMap)) {
            $subIds = array_keys($subAccountDisplayMap);
            $placeholders = implode(',', array_fill(0, count($subIds), '?'));
            $accStmt = $pdo->prepare("SELECT id, account_id, name FROM account WHERE id IN ($placeholders)");
            $accStmt->execute($subIds);
            foreach ($accStmt->fetchAll(PDO::FETCH_ASSOC) as $accRow) {
                $display = trim((string)$accRow['account_id']);
                if (!empty($accRow['name'])) {
                    $display .= ' (' . trim((string)$accRow['name']) . ')';
                }
                $subAccountDisplayMap[(int)$accRow['id']] = $display;
            }
        }

        // 把 Main Acc 公式派生到 Sub Acc：写入 subs（勿写入 allMains，否则前端会按 main 套用并可能与已有 sub 模板重复）
        foreach ($templates as $mainKey => $templateGroup) {
            $allMains = $templateGroup['allMains'] ?? [];
            if (!isset($templates[$mainKey]['subs']) || !is_array($templates[$mainKey]['subs'])) {
                $templates[$mainKey]['subs'] = [];
            }
            $addedForSubAcc = [];

            foreach ($allMains as $t) {
                $accId = (int)$t['account_id'];
                if (!isset($inheritanceMap[$accId])) {
                    continue;
                }
                $parentIdProduct = trim((string)($t['id_product'] ?? $mainKey));
                foreach ($inheritanceMap[$accId] as $subAccId) {
                    $dedupKey = $subAccId . '_' . ($t['process_id'] ?? 0) . '_' . $parentIdProduct . '_' . ($t['row_index'] ?? '') . '_' . ($t['formula_variant'] ?? 0);
                    if (isset($addedForSubAcc[$dedupKey])) {
                        continue;
                    }

                    // 若已存在同 parent + account 的 sub 模板（含 DB 保存项），不再注入继承副本
                    $alreadyInSubs = false;
                    foreach ($templates[$mainKey]['subs'] as $existingSub) {
                        if ((int)($existingSub['account_id'] ?? 0) === (int)$subAccId
                            && trim((string)($existingSub['parent_id_product'] ?? '')) === $parentIdProduct) {
                            $alreadyInSubs = true;
                            break;
                        }
                    }
                    if ($alreadyInSubs) {
                        $addedForSubAcc[$dedupKey] = true;
                        continue;
                    }

                    $subT = $t;
                    $subT['product_type'] = 'sub';
                    $subT['account_id'] = $subAccId;
                    $subT['account_display'] = $subAccountDisplayMap[$subAccId] ?? $t['account_display'];
                    $subT['parent_id_product'] = $parentIdProduct;
                    $subT['inherited_from_account_link'] = true;
                    // 合成 id 仅供去重；前端按 subs 路径套用，不以 allMains 处理
                    $subT['id'] = 'inherit_' . (int)($t['id'] ?? 0) . '_' . (int)$subAccId;

                    $templates[$mainKey]['subs'][] = $subT;
                    $addedForSubAcc[$dedupKey] = true;
                }
            }
        }
    } catch (Exception $e) {
        error_log('inheritFormulasToSubAccounts Error: ' . $e->getMessage());
    }

    return $templates;
}

function fetchTemplates(PDO $pdo, array $ids, ?int $processId = null, ?array &$rawSubRowsOut = null) {
    global $company_id, $capture_scope_group, $capture_scope_ctx, $scopeParams;

    if (empty($ids) || $processId === null || $processId <= 0) {
        return [];
    }

    // 查询时同时匹配完整 id 与 base（括号前），便于库中既有完整也有简写时都能取到
    $expandedIds = [];
    foreach ($ids as $id) {
        $tid = trim((string) $id);
        if ($tid !== '' && !in_array($tid, $expandedIds, true)) {
            $expandedIds[] = $tid;
        }
        $base = $tid !== '' ? baseIdProductForKey($tid) : '';
        if ($base !== '' && !in_array($base, $expandedIds, true)) {
            $expandedIds[] = $base;
        }
    }
    $ids = array_values($expandedIds);

    // Build case-insensitive query to match all case variants
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lowerIds = array_map('strtolower', $ids);

    $ledgerSql = ' AND dct.company_id = ? ';
    $ledgerParams = [];

    if (!empty($capture_scope_ctx) && is_array($capture_scope_ctx)) {
        require_once __DIR__ . '/../formula_maintenance/formula_maintenance_scope.php';
        $ledger = formulaMaintenanceBuildTemplateLedgerFilter($pdo, $capture_scope_ctx, 'dct');
        $ledgerSql = $ledger['sql'];
        $ledgerParams = $ledger['params'];
    } elseif (!empty($capture_scope_group)) {
        $groupCode = dcNormalizeGroupId($scopeParams['view_group'] ?? $scopeParams['group_id'] ?? '');
        $templateCompanyId = $groupCode !== '' ? dcResolveGroupCaptureCompanyId($pdo, $groupCode) : 0;
        if ($templateCompanyId <= 0) {
            $templateCompanyId = (int) ($company_id ?? 0);
        }
        if ($templateCompanyId <= 0) {
            throw new Exception('缺少公司信息');
        }
        $ledgerSql = ' AND dct.company_id = ? ' . dcSqlCaptureOnGroupEntityCompany('dct');
        $ledgerParams = [$templateCompanyId];
    } else {
        $companyId = (int) ($company_id ?? 0);
        if ($companyId <= 0) {
            if (isset($_SESSION['company_id'])) {
                $companyId = (int) $_SESSION['company_id'];
            } else {
                throw new Exception('缺少公司信息');
            }
        }
        $ledgerSql = ' AND dct.company_id = ? ' . dcSqlCaptureOnSubsidiaryCompany('dct');
        $ledgerParams = [$companyId];
    }

    // 前端传的是 normalize 后的 id（如 ALLBET95MS、MY EARNINGS），库里有完整 id（如 ALLBET95MS(SV)MYR、MY EARNINGS : (RINGGIT...)），
    // 需同时按「前缀」匹配；括号前带 " : " 的 id 再按「去掉尾部空格和冒号」匹配，与前端一致。
    $stmt = $pdo->prepare("
        SELECT
            dct.id,
            dct.id_product,
            dct.product_type,
            dct.parent_id_product,
            dct.template_key,
            dct.description,
            dct.account_id,
            dct.account_display,
            dct.currency_id,
            dct.currency_display,
            dct.source_columns,
            dct.formula_operators,
            dct.source_percent,
            dct.enable_source_percent,
            dct.input_method,
            dct.enable_input_method,
            dct.batch_selection,
            dct.columns_display,
            dct.formula_display,
            dct.last_source_value,
            dct.last_processed_amount,
            dct.process_id,
            dct.data_capture_id,
            dct.row_index,
            dct.sub_order,
            dct.formula_variant,
            dct.updated_at
        FROM data_capture_templates dct
        WHERE dct.process_id = ?
          {$ledgerSql}
          AND (
            (dct.product_type = 'main' AND (
                LOWER(dct.id_product) IN ($placeholders)
                OR LOWER(TRIM(SUBSTRING(dct.id_product, 1, IF(LOCATE('(', dct.id_product) > 0, LOCATE('(', dct.id_product) - 1, LENGTH(dct.id_product))))) IN ($placeholders)
                OR LOWER(TRIM(TRIM(TRAILING ':' FROM TRIM(SUBSTRING(dct.id_product, 1, IF(LOCATE('(', dct.id_product) > 0, LOCATE('(', dct.id_product) - 1, LENGTH(dct.id_product))))))) IN ($placeholders)
            ))
            OR (dct.product_type = 'sub' AND (
                LOWER(dct.parent_id_product) IN ($placeholders)
                OR LOWER(TRIM(SUBSTRING(dct.parent_id_product, 1, IF(LOCATE('(', dct.parent_id_product) > 0, LOCATE('(', dct.parent_id_product) - 1, LENGTH(dct.parent_id_product))))) IN ($placeholders)
                OR LOWER(TRIM(TRIM(TRAILING ':' FROM TRIM(SUBSTRING(dct.parent_id_product, 1, IF(LOCATE('(', dct.parent_id_product) > 0, LOCATE('(', dct.parent_id_product) - 1, LENGTH(dct.parent_id_product))))))) IN ($placeholders)
            ))
          )
        ORDER BY CASE WHEN dct.row_index IS NULL THEN 1 ELSE 0 END,
                 dct.row_index ASC,
                 dct.process_id DESC,
                 CASE 
                     WHEN dct.product_type = 'main' THEN COALESCE(dct.id_product, '')
                     WHEN dct.product_type = 'sub' THEN COALESCE(dct.parent_id_product, '')
                     ELSE COALESCE(dct.id_product, '')
                 END ASC,
                 dct.product_type ASC,
                 CASE WHEN dct.sub_order IS NULL THEN 1 ELSE 0 END,
                 dct.sub_order ASC,
                 dct.formula_variant ASC,
                 dct.id ASC
    ");

    $params = array_merge([$processId], $ledgerParams, $lowerIds, $lowerIds, $lowerIds, $lowerIds, $lowerIds, $lowerIds);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rawSubRowsOut !== null) {
        $rawSubRowsOut = [];
        foreach ($results as $row) {
            if (($row['product_type'] ?? '') === 'sub') {
                $rawSubRowsOut[] = $row;
            }
        }
    }

    $templates = [];
    foreach ($results as $row) {
        // Formula 只绑定当前 process：不再 claim process_id IS NULL 的模板，避免在其他 process 呈现

        // Ensure source_percent is always a string to preserve decimal values and expressions
        // This is important because decimal fields might be returned as numbers, losing precision
        if (isset($row['source_percent'])) {
            $row['source_percent'] = (string)$row['source_percent'];
        }
        
        $productType = $row['product_type'] ?? 'main';

        if ($productType === 'sub') {
            $parentId = $row['parent_id_product'] ?? $row['id_product'];
            // 用完整 parent_id_product 作 key，避免 GAMS(SV)HKD 与 GAMS(SV)MYR 混在同一组（只检测 GAMS 会混掉）
            $parentKey = trim((string) $parentId);
            if ($parentKey === '') {
                $parentKey = baseIdProductForKeyNormalized($parentId);
                if ($parentKey === '') {
                    $parentKey = baseIdProductForKey($parentId);
                }
                if ($parentKey === '') {
                    $parentKey = $parentId;
                }
            }
            if (!isset($templates[$parentKey])) {
                $templates[$parentKey] = [
                    'main' => null,
                    'subs' => [],
                    'allMains' => [] // Store all main templates for this parent
                ];
            }
            // Check for duplicate sub templates (same id_product, account_id, batch_selection, formula_variant, AND sub_order)
            // Only remove duplicates if ALL these fields match, including formula_variant and sub_order
            // This allows multiple sub rows with same account but different sub_order or different formulas
            $isDuplicate = false;
            $currentSubOrder = isset($row['sub_order']) && $row['sub_order'] !== null ? (float)$row['sub_order'] : null;
            foreach ($templates[$parentKey]['subs'] as $index => $existingSub) {
                $existingSubOrder = isset($existingSub['sub_order']) && $existingSub['sub_order'] !== null ? (float)$existingSub['sub_order'] : null;
                if ($existingSub['id_product'] === $row['id_product'] 
                    && $existingSub['account_id'] === $row['account_id']
                    && (int)$existingSub['batch_selection'] === (int)$row['batch_selection']
                    && (int)($existingSub['formula_variant'] ?? 1) === (int)($row['formula_variant'] ?? 1)
                    && (($existingSubOrder === null && $currentSubOrder === null) || ($existingSubOrder !== null && $currentSubOrder !== null && abs($existingSubOrder - $currentSubOrder) < 0.0001))) {
                    // Found duplicate (same id_product, account_id, batch_selection, formula_variant, AND sub_order)
                    // Keep the one with latest updated_at
                    $existingUpdated = $existingSub['updated_at'] ?? '';
                    $currentUpdated = $row['updated_at'] ?? '';
                    if ($currentUpdated > $existingUpdated) {
                        // Replace with newer one
                        $templates[$parentKey]['subs'][$index] = $row;
                    }
                    $isDuplicate = true;
                    break;
                }
            }
            if (!$isDuplicate) {
                // Add sub templates for this process only (formula 仅绑定当前 process)
                // This allows multiple sub rows with same account but different formulas
                $templates[$parentKey]['subs'][] = $row;
            }
        } else {
            $idProduct = $row['id_product'];
            // 用完整 id_product 作 key，避免 GAMS(SV)HKD 与 GAMS(SV)MYR 混在同一组（不要只检测 GAMS 前面）
            $mainKey = trim((string) $idProduct);
            if ($mainKey === '') {
                $mainKey = baseIdProductForKeyNormalized($idProduct);
                if ($mainKey === '') {
                    $mainKey = baseIdProductForKey($idProduct);
                }
                if ($mainKey === '') {
                    $mainKey = $idProduct;
                }
            }
            if (!isset($templates[$mainKey])) {
                $templates[$mainKey] = [
                    'main' => null,
                    'subs' => [],
                    'allMains' => [] // Store all main templates for different process_id
                ];
            }
            
            // Store all main templates for current process only (formula 仅绑定当前 process)
            $templates[$mainKey]['allMains'][] = $row;
            
            // For backward compatibility, still set 'main' to the best default
            // But frontend should use 'allMains' to apply all templates
            // Priority: prefer template with process_id, then most recent
            if ($templates[$mainKey]['main'] === null) {
                $templates[$mainKey]['main'] = $row;
            } else {
                $existing = $templates[$mainKey]['main'];
                $existingProcessId = $existing['process_id'] ?? null;
                $currentProcessId = $row['process_id'] ?? null;
                
                // If existing is generic (NULL) and current is specific, use current
                if ($existingProcessId === null && $currentProcessId !== null) {
                    $templates[$mainKey]['main'] = $row;
                }
                // If both are specific or both are generic, prefer the one with more recent updated_at
                else if (($existingProcessId === null) === ($currentProcessId === null)) {
                    $existingUpdated = $existing['updated_at'] ?? '';
                    $currentUpdated = $row['updated_at'] ?? '';
                    if ($currentUpdated > $existingUpdated) {
                        $templates[$mainKey]['main'] = $row;
                    }
                }
                // Otherwise keep existing (existing is specific, current is generic)
            }
        }
    }

    // Summary 只使用 DB 中显式保存的 sub（含 parent_id_product），不再注入 account_link 虚拟继承行。
    // inheritFormulasToSubAccounts 曾导致「DB 一条 + inherit 一条」刷新后重复显示。

    foreach ($templates as $mainKey => $group) {
        if (!empty($group['subs']) && is_array($group['subs'])) {
            $templates[$mainKey]['subs'] = dedupeTemplateGroupSubs($group['subs']);
        }
    }

    return $templates;
}
