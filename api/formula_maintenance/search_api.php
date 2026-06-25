<?php
/**
 * Formula Maintenance Search API
 * 用于搜索和显示 Formula (data_capture_templates) 数据
 * 
 * 功能：
 * 1. 根据 Process 筛选 Formula 记录
 * 2. 根据公式名称（template_key 或 description）搜索
 * 3. 支持不选择 Process 时查询所有 Process
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/formula_maintenance_scope.php';

try {
    $scope = formulaMaintenanceResolveRequestScope($pdo, $_GET);
    $company_id = (int) $scope['company_id'];
    
    // 获取搜索参数
    $process_name = $_GET['process'] ?? null; // process.process_id (字符串，如 'L22KZMASTER')
    $search_filter = $_GET['search'] ?? null; // 搜索公式名称
    
    // 如果指定了 process_name，先查找对应的 process.id
    $process_id_filter = null;
    if ($process_name) {
        $stmt = $pdo->prepare("SELECT id FROM process WHERE process_id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$process_name, $company_id]);
        $process_id_filter = $stmt->fetchColumn();
        if (!$process_id_filter) {
            // 如果找不到对应的 process，返回空结果
            echo json_encode([
                'success' => true,
                'data' => [],
                'debug' => "Process '$process_name' not found for company_id $company_id"
            ]);
            return;
        }
    }
    
    // 构建查询条件
    $where_conditions = [];
    $params = [];
    
    // 添加Process筛选条件（可选：不选则取全部）
    // 如果选择了 Process，只显示该 Process 的记录（process_id 不为 NULL）
    if ($process_id_filter) {
        $where_conditions[] = "dct.process_id = ?";
        $params[] = $process_id_filter;
    }
    
    // 添加company_id过滤
    // 对于有 process_id 的记录，通过 process 表过滤
    // 对于 process_id 为 NULL 的记录，通过 account_company 表过滤
    $where_conditions[] = "(
        (dct.process_id IS NOT NULL AND p.company_id = ?) 
        OR 
        (dct.process_id IS NULL AND EXISTS (
            SELECT 1 FROM account_company ac 
            WHERE ac.account_id = a.id AND ac.company_id = ?
        ))
    )";
    $params[] = $company_id;
    $params[] = $company_id;
    
    // 添加搜索条件（搜索 template_key 或 description）
    if ($search_filter && trim($search_filter) !== '') {
        $where_conditions[] = "(dct.template_key LIKE ? OR dct.description LIKE ?)";
        $searchPattern = '%' . trim($search_filter) . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
    }
    
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // 查询 Formula 记录
    // 使用 LEFT JOIN 以包含 process_id 为 NULL 的记录
    $sql = "SELECT 
                dct.id,
                dct.template_key,
                dct.description,
                dct.process_id,
                p.process_id as process_name,
                DATE_FORMAT(dct.updated_at, '%d/%m/%Y %H:%i:%s') as date_updated,
                DATE_FORMAT(dct.created_at, '%d/%m/%Y %H:%i:%s') as date_created,
                dct.product_type
            FROM data_capture_templates dct
            LEFT JOIN process p ON dct.process_id = p.id
            LEFT JOIN account a ON dct.account_id = a.id
            $where_sql
            ORDER BY dct.updated_at DESC, dct.created_at DESC, dct.template_key";
    
    // 调试：记录查询SQL和参数
    error_log("Formula Search - Process Name: " . ($process_name ?? 'NULL'));
    error_log("Formula Search - Process ID Filter: " . ($process_id_filter ?? 'NULL'));
    error_log("Formula Search - Company ID: " . $company_id);
    error_log("Formula Search SQL: " . $sql);
    error_log("Formula Search Params: " . json_encode($params));
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 调试：记录查询结果数量
    error_log("Formula Search Results Count: " . count($results));
    
    // 如果没有结果，尝试查询总数据量用于调试
    if (count($results) === 0) {
        $debugStmt = $pdo->prepare("SELECT COUNT(*) as total FROM data_capture_templates");
        $debugStmt->execute();
        $totalCount = $debugStmt->fetch(PDO::FETCH_ASSOC)['total'];
        error_log("Formula Search - Total templates in database: " . $totalCount);
        
        if ($process_id_filter) {
            $debugStmt2 = $pdo->prepare("SELECT COUNT(*) as total FROM data_capture_templates WHERE process_id = ?");
            $debugStmt2->execute([$process_id_filter]);
            $processCount = $debugStmt2->fetch(PDO::FETCH_ASSOC)['total'];
            error_log("Formula Search - Templates with process_id $process_id_filter: " . $processCount);
        }
    }
    
    // 格式化数据
    $formattedResults = [];
    $rowNumber = 1;
    
    foreach ($results as $row) {
        // 确定公式名称：优先使用 description，如果没有则使用 template_key
        $formulaName = !empty($row['description']) ? $row['description'] : ($row['template_key'] ?? '-');
        
        // 确定状态：默认为 Active
        $status = 'Active';
        
        $formattedResults[] = [
            'no' => $rowNumber++,
            'id' => (int)$row['id'],
            'date' => $row['date_updated'] ?? $row['date_created'] ?? '-',
            'formula_name' => $formulaName,
            'status' => $status,
            'process_id' => $row['process_id'] ?? null,
            'process_name' => $row['process_name'] ?? '-',
            'template_key' => $row['template_key'] ?? '-',
            'product_type' => $row['product_type'] ?? 'main'
        ];
    }
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => $formattedResults
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}