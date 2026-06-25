<?php
/**
 * Transaction Submit API
 * 用于提交交易数据
 * 
 * 支持的交易类型：
 * - WIN: 赢钱
 * - LOSE: 输钱
 * - PAYMENT: 付款
 * - CONTRA: 对冲/转账
 * - CLAIM: 索赔
 */

session_start();
// 注意：session_write_close() 将在读取完幂等缓存后调用，允许数据库操作期间并发执行
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/transaction_scope.php';
    require_once __DIR__ . '/../includes/money_decimal.php';
    require_once __DIR__ . '/../includes/transaction_approval.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器初始化失败: ' . $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @deprecated use tx_is_manager_or_above_role */
function isManagerOrAboveRole(string $role): bool
{
    return tx_is_manager_or_above_role($role);
}

/** @deprecated use tx_requires_transaction_approval */
function requiresTransactionApproval(string $role, string $transactionDateDb): bool
{
    return tx_requires_transaction_approval($role, $transactionDateDb);
}

/** @deprecated use tx_requires_approval_for_type */
function requiresApprovalForType(string $transactionType): bool
{
    return tx_requires_approval_for_type($transactionType);
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

/**
 * 插入 transactions（根据现有表结构自动带上可用字段）
 * @return int 新增的 transaction id
 */
function insertTransactionRow(PDO $pdo, array $data): int
{
    $columns = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO transactions (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}

/**
 * 删除 Transaction List 搜索缓存
 *
 * Transaction List 使用 api/transactions/search_api.php，并在系统临时目录下
 * 的 count168_tx_search 目录里做 60 秒文件缓存。
 * 当这里提交新交易（PAYMENT / RECEIVE / CONTRA / RATE 等）后，需要清掉这些缓存文件，
 * 不然在缓存过期前再次搜索会拿到旧数据，看不到刚提交的余额变化。
 */
function clearTransactionSearchCache(): void
{
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'count168_tx_search';
    if (!is_dir($cacheDir)) {
        return;
    }
    foreach (scandir($cacheDir) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $fullPath = $cacheDir . DIRECTORY_SEPARATOR . $file;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

/**
 * 截断到2位小数（不四舍五入）
 */
function submitTrunc2($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return money_normalize('0', 2);
    }
    return money_normalize($value ?? '0', 2);
}

/**
 * 交易入库金额统一按高精度保存（默认 8 位），避免用户输入 6 位小数时被提前截断。
 * 展示口径（2 位）应在前端或响应格式化阶段处理，不影响数据库原值。
 */
function submitStoreAmount($value, int $scale = 8): string
{
    if ($value === null || trim((string)$value) === '') {
        return money_normalize('0', $scale);
    }
    return money_normalize($value ?? '0', $scale);
}

/**
 * RATE 专用：四舍五入到2位小数（half-up），其他交易类型继续使用 submitTrunc2。
 */
function submitRateRound2($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return money_normalize('0', 2);
    }

    $normalized = money_normalize($value, MONEY_CALC_SCALE);
    $adjustment = '0.' . str_repeat('0', 2) . '5';
    if (strpos($normalized, '-') === 0) {
        $adjustment = '-' . $adjustment;
    }

    return money_normalize(bcadd($normalized, $adjustment, MONEY_CALC_SCALE), 2);
}

function submitDecimalPlaces($value): int
{
    $clean = money_clean($value);
    if ($clean === '' || strpos($clean, '.') === false) {
        return 0;
    }
    return strlen(rtrim(substr(strrchr($clean, '.'), 1), " \t\n\r\0\x0B"));
}

/**
 * 基于 session 的轻量幂等缓存（防止同一次点击重复提交）
 */
function getSubmitIdempotencyCache(string $key): ?array
{
    if (!isset($_SESSION['tx_submit_idempotency']) || !is_array($_SESSION['tx_submit_idempotency'])) {
        return null;
    }
    $store = $_SESSION['tx_submit_idempotency'];
    if (!isset($store[$key]) || !is_array($store[$key])) {
        return null;
    }
    $item = $store[$key];
    if (!isset($item['response']) || !is_array($item['response'])) {
        return null;
    }
    return $item['response'];
}

function putSubmitIdempotencyCache(string $key, array $response): void
{
    // 重新开启 session 以写入幂等缓存（之前已调用 session_write_close 释放锁）
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['tx_submit_idempotency']) || !is_array($_SESSION['tx_submit_idempotency'])) {
        $_SESSION['tx_submit_idempotency'] = [];
    }
    $_SESSION['tx_submit_idempotency'][$key] = [
        'created_at' => time(),
        'response' => $response
    ];

    // 仅保留最近 100 条，避免 session 膨胀
    if (count($_SESSION['tx_submit_idempotency']) > 100) {
        uasort($_SESSION['tx_submit_idempotency'], function ($a, $b) {
            $ta = (int)($a['created_at'] ?? 0);
            $tb = (int)($b['created_at'] ?? 0);
            return $ta <=> $tb;
        });
        while (count($_SESSION['tx_submit_idempotency']) > 100) {
            array_shift($_SESSION['tx_submit_idempotency']);
        }
    }
    // 写完立即释放 session 锁
    session_write_close();
}

try {
    // 检查用户登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }
    
    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    // Audit / Partnership 在 read_only=1（或未设置时默认只读）时禁止写入
    if (in_array($userRole, ['audit', 'partnership'], true)) {
        $ro = isset($_SESSION['read_only']) ? (int) $_SESSION['read_only'] : 1;
        if ($ro === 1) {
            throw new Exception('只读账号无法提交交易');
        }
    }

    $listScope = tx_resolve_transaction_list_scope($pdo, $_POST);
    $company_id = (int) ($listScope['company_id'] ?? 0);
    if ($company_id <= 0) {
        $company_id = tx_permission_company_id_for_scope($pdo, $listScope);
    }
    if ($company_id <= 0 && ($listScope['mode'] ?? '') !== 'group') {
        throw new Exception('缺少 company_id');
    }

    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }
    
    $client_request_id = trim($_POST['client_request_id'] ?? '');
    if ($client_request_id !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $client_request_id)) {
        throw new Exception('Invalid client_request_id');
    }
    $idempotencyKey = '';
    if ($client_request_id !== '') {
        $idempotencyKey = tx_idempotency_scope_key($listScope) . ':' . $client_request_id;
        $cachedResponse = getSubmitIdempotencyCache($idempotencyKey);
        if ($cachedResponse !== null) {
            session_write_close(); // 命中缓存，无需继续持有 session 锁
            echo json_encode($cachedResponse, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    // 读取完 session 基本信息和幂等缓存，立即释放 session 锁
    // 后续数据库操作（可能耗时数百毫秒）将不再阻塞其他并发请求
    session_write_close();

    // 获取表单数据
    $transaction_type = trim($_POST['transaction_type'] ?? '');
    $account_id = (int)($_POST['account_id'] ?? 0);
    $from_account_id = !empty($_POST['from_account_id']) ? (int)$_POST['from_account_id'] : null;
    $amount = submitStoreAmount($_POST['amount'] ?? '0', 8);
    $transaction_date = trim($_POST['transaction_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sms = trim($_POST['sms'] ?? '');
    $currency = trim($_POST['currency'] ?? ''); // 获取当前选择的 currency
    $user_type = $_SESSION['user_type'] ?? 'user';
    $created_by_user = null;
    $created_by_owner = null;
    $from_account = null;
    
    if ($user_type === 'owner') {
        $created_by_owner = (int)($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($created_by_owner <= 0) {
            throw new Exception('无法识别当前 owner，提交被拒绝');
        }
    } else {
        $created_by_user = (int)($_SESSION['user_id'] ?? 0);
        if ($created_by_user <= 0) {
            throw new Exception('无法识别当前用户，提交被拒绝');
        }
    }
    
    // 验证必填字段
    if (empty($transaction_type)) {
        throw new Exception('请选择交易类型');
    }
    
    if ($transaction_type === 'RECEIVE') {
        throw new Exception('RECEIVE 交易类型已停用');
    }

    if (!in_array($transaction_type, ['WIN', 'LOSE', 'PAYMENT', 'CONTRA', 'CLAIM', 'RATE', 'CLEAR', 'ADJUSTMENT'])) {
        throw new Exception('无效的交易类型');
    }
    
    // RATE 类型有特殊的验证逻辑
    $is_rate = ($transaction_type === 'RATE');
    $is_adjustment = ($transaction_type === 'ADJUSTMENT');
    
    if (!$is_rate) {
        if ($account_id <= 0) {
            throw new Exception('请选择 To Account');
        }

        if (!$is_adjustment && money_cmp($amount, '0') < 0) {
            throw new Exception('金额不能小于 0');
        }
        if ($is_adjustment && money_cmp($amount, '0') === 0) {
            throw new Exception('ADJUSTMENT 金额不能为 0');
        }
    }
    
    if (empty($transaction_date)) {
        throw new Exception('请选择交易日期');
    }
    
    // 转换日期格式 (严格按 dd/mm/yyyy 转为 yyyy-mm-dd，避免 strtotime 把 7/04 解析成 07/04)
    $transaction_date_obj = DateTime::createFromFormat('d/m/Y', trim($transaction_date));
    $transaction_date_errors = DateTime::getLastErrors();
    $has_parse_error = is_array($transaction_date_errors)
        && (
            ($transaction_date_errors['warning_count'] ?? 0) > 0
            || ($transaction_date_errors['error_count'] ?? 0) > 0
        );
    if (!$transaction_date_obj || $has_parse_error) {
        throw new Exception('交易日期格式无效，请使用 dd/mm/yyyy');
    }
    $transaction_date_db = $transaction_date_obj->format('Y-m-d');
    
    // 检查 transactions 表字段（向后兼容）
    $has_currency_id = tableHasColumn($pdo, 'transactions', 'currency_id');
    $has_approval_status = tableHasColumn($pdo, 'transactions', 'approval_status');

    // 交易审批规则（所有 type 与 CONTRA 保持一致）
    $approval_status = 'APPROVED';
    $approved_by = $created_by_user;
    $approved_by_owner = $created_by_owner;
    $approved_at = date('Y-m-d H:i:s');
    $is_pending_approval = false;

    if ($has_approval_status && requiresApprovalForType($transaction_type)) {
        $skipApproval = tx_submit_skips_transaction_approval(
            $pdo,
            $userRole,
            $transaction_type,
            $account_id,
            $from_account_id
        );
        if (!$skipApproval && requiresTransactionApproval($userRole, $transaction_date_db)) {
            $approval_status = 'PENDING';
            $approved_by = null;
            $approved_by_owner = null;
            $approved_at = null;
            $is_pending_approval = true;
        }
    }

    // WIN/LOSE（PROFIT）：数据库触发器要求 from_account_id 必须为 NULL，插入前会强制置空；前端可选填 From Account 仅用于展示
    // 验证 From Account（PAYMENT/CONTRA/CLAIM/CLEAR 需要，RATE 有特殊处理）
    if (in_array($transaction_type, ['PAYMENT', 'CONTRA', 'CLAIM', 'CLEAR'])) {
        if (!$from_account_id || $from_account_id <= 0) {
            throw new Exception('PAYMENT/CONTRA/CLAIM/CLEAR 交易必须选择 From Account');
        }
        
        if ($from_account_id == $account_id) {
            throw new Exception('From Account 和 To Account 不能相同');
        }
    }
    
    // 验证账户是否属于当前 scope（集团账套 vs 子公司）
    if (!$is_rate) {
        $to_account = tx_fetch_account_row($pdo, $account_id, $listScope);
        if (!$to_account) {
            throw new Exception('To Account 不存在或不属于当前范围');
        }

        if ($from_account_id) {
            $from_account = tx_fetch_account_row($pdo, (int) $from_account_id, $listScope);
            if (!$from_account) {
                throw new Exception('From Account 不存在或不属于当前范围');
            }
        }
    }

    // 验证 currency 并获取 currency_id，如果不存在则自动创建
    $currency_id = null;
    if (!empty($currency)) {
        $currencyCode = strtoupper(trim($currency));
        if (strlen($currencyCode) > 10) {
            throw new Exception('Currency code 长度不能超过 10 个字符');
        }
        $currency_id = tx_resolve_currency_id_for_scope($pdo, $currencyCode, $listScope);
    }
    
    // 自动生成 description（如果为空）
    if (empty($description) && $transaction_type === 'ADJUSTMENT') {
        $description = 'ADJUSTMENT - WIN/LOSS';
    } elseif (empty($description) && in_array($transaction_type, ['PAYMENT', 'CONTRA', 'CLAIM', 'CLEAR'])) {
        // 从 To Account 的视角生成描述
        $description = $transaction_type . ' FROM ' . $from_account['account_id'];
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 处理 RATE 类型
        if ($is_rate) {
            // 获取 RATE 相关参数
            $rate_from_account_id = !empty($_POST['rate_from_account_id']) ? (int)$_POST['rate_from_account_id'] : null;
            $rate_from_currency = trim($_POST['rate_from_currency'] ?? '');
            $rate_from_amount = submitRateRound2($_POST['rate_from_amount'] ?? '0');
            $rate_from_description = trim($_POST['rate_from_description'] ?? '');
            
            $rate_to_account_id = !empty($_POST['rate_to_account_id']) ? (int)$_POST['rate_to_account_id'] : null;
            $rate_to_currency = trim($_POST['rate_to_currency'] ?? '');
            $rate_to_amount = submitRateRound2($_POST['rate_to_amount'] ?? '0');
            $rate_to_description = trim($_POST['rate_to_description'] ?? '');
            
            // 验证第一个 Account 和 Currency 的记录
            if (!$rate_from_account_id || !$rate_to_account_id) {
                throw new Exception('RATE 交易必须填写第一个 Account 和 Currency');
            }
            
            if (money_cmp($rate_from_amount, '0') <= 0 || money_cmp($rate_to_amount, '0') <= 0) {
                throw new Exception('RATE 交易的金额必须大于 0');
            }
            
            // 验证账户（支持 account_company 表）
            // 检查 account_company 表是否存在
            $has_account_company_table = false;
            try {
                $check_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
                $has_account_company_table = $check_stmt->rowCount() > 0;
            } catch (PDOException $e) {
                $has_account_company_table = false;
            }
            
            // 验证 Rate From Account（只使用 account_company 表）
            $stmt = $pdo->prepare("
                SELECT a.id, a.account_id, a.name 
                FROM account a
                INNER JOIN account_company ac ON a.id = ac.account_id
                WHERE a.id = ? AND ac.company_id = ?
            ");
            $stmt->execute([$rate_from_account_id, $company_id]);
            $rate_from_account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rate_from_account) {
                throw new Exception('Rate From Account 不存在或不属于当前公司');
            }
            
            // 验证 Rate To Account（只使用 account_company 表）
            $stmt = $pdo->prepare("
                SELECT a.id, a.account_id, a.name 
                FROM account a
                INNER JOIN account_company ac ON a.id = ac.account_id
                WHERE a.id = ? AND ac.company_id = ?
            ");
            $stmt->execute([$rate_to_account_id, $company_id]);
            $rate_to_account = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rate_to_account) {
                throw new Exception('Rate To Account 不存在或不属于当前公司');
            }
            
            // 验证 currency 并获取 currency_id，如果不存在则自动创建
            $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
            $stmt->execute([$rate_from_currency, $company_id]);
            $rate_from_currency_id = $stmt->fetchColumn();
            if (!$rate_from_currency_id) {
                // 自动创建 currency 到当前公司
                $currencyCode = strtoupper(trim($rate_from_currency));
                if (strlen($currencyCode) > 10) {
                    throw new Exception('Rate From Currency code 长度不能超过 10 个字符');
                }
                $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                $stmt->execute([$currencyCode, $company_id]);
                $rate_from_currency_id = $pdo->lastInsertId();
            }
            
            $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
            $stmt->execute([$rate_to_currency, $company_id]);
            $rate_to_currency_id = $stmt->fetchColumn();
            if (!$rate_to_currency_id) {
                // 自动创建 currency 到当前公司
                $currencyCode = strtoupper(trim($rate_to_currency));
                if (strlen($currencyCode) > 10) {
                    throw new Exception('Rate To Currency code 长度不能超过 10 个字符');
                }
                $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                $stmt->execute([$currencyCode, $company_id]);
                $rate_to_currency_id = $pdo->lastInsertId();
            }
            
            $transaction_ids = [];
            
            $rawRateExchangeRate = $_POST['rate_exchange_rate'] ?? '0';
            if (submitDecimalPlaces($rawRateExchangeRate) > 8) {
                throw new Exception('Exchange Rate 小数位最多 8 位');
            }
            $rate_exchange_rate = money_normalize($rawRateExchangeRate);
            if (money_cmp($rate_exchange_rate, '0') <= 0) {
                throw new Exception('Exchange Rate 必须大于 0');
            }
            
            $rate_transfer_from_account_id = !empty($_POST['rate_transfer_from_account_id']) ? (int)$_POST['rate_transfer_from_account_id'] : null;
            $rate_transfer_to_account_id = !empty($_POST['rate_transfer_to_account_id']) ? (int)$_POST['rate_transfer_to_account_id'] : null;
            $rate_transfer_from_amount = !empty($_POST['rate_transfer_from_amount']) ? submitRateRound2($_POST['rate_transfer_from_amount']) : null;
            $rate_transfer_to_amount = !empty($_POST['rate_transfer_to_amount']) ? submitRateRound2($_POST['rate_transfer_to_amount']) : null;
            $rate_transfer_from_description = trim($_POST['rate_transfer_from_description'] ?? '');
            $rate_transfer_to_description = trim($_POST['rate_transfer_to_description'] ?? '');
            $rate_transfer_from_currency = trim($_POST['rate_transfer_from_currency'] ?? '');
            $rate_transfer_to_currency = trim($_POST['rate_transfer_to_currency'] ?? '');
            
            $rate_middleman_account_id = !empty($_POST['rate_middleman_account_id']) ? (int)$_POST['rate_middleman_account_id'] : null;
            $rate_middleman_amount = !empty($_POST['rate_middleman_amount']) ? submitRateRound2($_POST['rate_middleman_amount']) : null;
            $rate_middleman_description = trim($_POST['rate_middleman_description'] ?? '');
            $rawRateMiddlemanRate = $_POST['rate_middleman_rate'] ?? null;
            if ($rawRateMiddlemanRate !== null && trim((string)$rawRateMiddlemanRate) !== '' && submitDecimalPlaces($rawRateMiddlemanRate) > 8) {
                throw new Exception('Middle-Man rate 小数位最多 8 位');
            }
            $rate_middleman_rate = !empty($_POST['rate_middleman_rate']) ? money_normalize($_POST['rate_middleman_rate']) : null;
            $rate_middleman_currency = trim($_POST['rate_middleman_currency'] ?? $rate_transfer_to_currency ?: $rate_to_currency ?: $rate_from_currency);
            
            if (!$rate_from_account_id || $rate_from_account_id <= 0) {
                throw new Exception('Rate From Account ID 无效');
            }
            if (!$rate_to_account_id || $rate_to_account_id <= 0) {
                throw new Exception('Rate To Account ID 无效');
            }
            
            $rate_group_id = 'RATE_' . time() . '_' . mt_rand(1000, 9999);

            // RATE 主记录（默认视为已批准）
            $rateHeader = [
                'company_id' => $company_id,
                'transaction_type' => 'RATE',
                'account_id' => $rate_to_account_id,
                'from_account_id' => $rate_from_account_id,
                'amount' => $rate_from_amount,
                'transaction_date' => $transaction_date_db,
                'description' => $rate_from_description,
                'sms' => $sms,
                'created_by' => $created_by_user,
                'created_by_owner' => $created_by_owner,
            ];
            if ($has_currency_id) {
                $rateHeader['currency_id'] = $rate_from_currency_id;
            }
            if ($has_approval_status) {
                $rateHeader['approval_status'] = 'APPROVED';
                if (tableHasColumn($pdo, 'transactions', 'approved_by')) {
                    $rateHeader['approved_by'] = $created_by_user;
                }
                if (tableHasColumn($pdo, 'transactions', 'approved_by_owner')) {
                    $rateHeader['approved_by_owner'] = $created_by_owner;
                }
                if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
                    $rateHeader['approved_at'] = date('Y-m-d H:i:s');
                }
            }

            $main_transaction_id = insertTransactionRow($pdo, $rateHeader);
            $transaction_ids[] = $main_transaction_id;
            
            $rate_transfer_currency = $rate_transfer_to_currency ?: $rate_to_currency;
            if ($rate_transfer_currency) {
                $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                $stmt->execute([$rate_transfer_currency, $company_id]);
                $rate_transfer_currency_id = $stmt->fetchColumn();
                if (!$rate_transfer_currency_id) {
                    // 自动创建 currency 到当前公司
                    $currencyCode = strtoupper(trim($rate_transfer_currency));
                    if (strlen($currencyCode) > 10) {
                        throw new Exception('Rate Transfer Currency code 长度不能超过 10 个字符');
                    }
                    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                    $stmt->execute([$currencyCode, $company_id]);
                    $rate_transfer_currency_id = $pdo->lastInsertId();
                }
            } else {
                $rate_transfer_currency_id = $rate_to_currency_id;
            }
            
            if ($rate_middleman_account_id) {
                if ($rate_middleman_currency) {
                    $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                    $stmt->execute([$rate_middleman_currency, $company_id]);
                    $rate_middleman_currency_id = $stmt->fetchColumn();
                    if (!$rate_middleman_currency_id) {
                        // 自动创建 currency 到当前公司
                        $currencyCode = strtoupper(trim($rate_middleman_currency));
                        if (strlen($currencyCode) > 10) {
                            throw new Exception('Rate Middleman Currency code 长度不能超过 10 个字符');
                        }
                        $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                        $stmt->execute([$currencyCode, $company_id]);
                        $rate_middleman_currency_id = $pdo->lastInsertId();
                    }
                } else {
                    $rate_middleman_currency_id = $rate_transfer_currency_id;
                }
            } else {
                $rate_middleman_currency_id = null;
            }
            
            $stmt = $pdo->prepare("INSERT INTO transactions_rate (
                transaction_id, company_id, rate_group_id,
                rate_from_account_id, rate_to_account_id,
                rate_from_currency_id, rate_from_amount,
                rate_to_currency_id, rate_to_amount, exchange_rate,
                rate_transfer_from_account_id, rate_transfer_to_account_id,
                rate_transfer_from_amount, rate_transfer_to_amount,
                rate_middleman_account_id, rate_middleman_rate, rate_middleman_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $main_transaction_id,
                $company_id,
                $rate_group_id,
                $rate_from_account_id,
                $rate_to_account_id,
                $rate_from_currency_id,
                $rate_from_amount,
                $rate_to_currency_id,
                $rate_to_amount,
                $rate_exchange_rate,
                $rate_transfer_from_account_id,
                $rate_transfer_to_account_id,
                $rate_transfer_from_amount,
                $rate_transfer_to_amount,
                $rate_middleman_account_id,
                $rate_middleman_rate,
                $rate_middleman_amount
            ]);
            
            $stmt = $pdo->prepare("INSERT INTO transactions_rate_details (
                rate_group_id, transaction_id, company_id, record_type,
                account_id, from_account_id, amount, currency_id, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $details_stmt = $pdo->prepare("INSERT INTO transactions_rate_details (
                rate_group_id, transaction_id, company_id, record_type,
                account_id, from_account_id, amount, currency_id, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $details_stmt->execute([
                $rate_group_id, $main_transaction_id, $company_id, 'first_from',
                $rate_from_account_id, null, $rate_from_amount, $rate_from_currency_id,
                $rate_from_description
            ]);
            
            $details_stmt->execute([
                $rate_group_id, $main_transaction_id, $company_id, 'first_to',
                // 第一行两个 Account 都跟随第一个币种（例如 SGD），金额都是 rate_from_amount（例如 100）
                $rate_to_account_id, null, $rate_from_amount, $rate_from_currency_id,
                $rate_to_description
            ]);
            
            if ($rate_transfer_from_account_id && $rate_transfer_to_account_id) {
                if (!$rate_transfer_from_amount || !$rate_transfer_to_amount) {
                    throw new Exception('Transfer Account 必须填写金额');
                }
                
                // 验证 Transfer 账户（只使用 account_company 表）
                $stmt = $pdo->prepare("
                    SELECT a.id, a.account_id, a.name 
                    FROM account a
                    INNER JOIN account_company ac ON a.id = ac.account_id
                    WHERE a.id = ? AND ac.company_id = ?
                ");
                $stmt->execute([$rate_transfer_from_account_id, $company_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Rate Transfer From Account 不存在或不属于当前公司');
                }
                
                $stmt = $pdo->prepare("
                    SELECT a.id, a.account_id, a.name 
                    FROM account a
                    INNER JOIN account_company ac ON a.id = ac.account_id
                    WHERE a.id = ? AND ac.company_id = ?
                ");
                $stmt->execute([$rate_transfer_to_account_id, $company_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Rate Transfer To Account 不存在或不属于当前公司');
                }
                
                $rateTransfer = [
                    'company_id' => $company_id,
                    'transaction_type' => 'RATE',
                    'account_id' => $rate_transfer_to_account_id,
                    'from_account_id' => $rate_transfer_from_account_id,
                    'amount' => $rate_transfer_to_amount,
                    'transaction_date' => $transaction_date_db,
                    'description' => $rate_transfer_from_description,
                    'sms' => $sms,
                    'created_by' => $created_by_user,
                    'created_by_owner' => $created_by_owner,
                ];
                if ($has_currency_id) {
                    $rateTransfer['currency_id'] = $rate_transfer_currency_id;
                }
                if ($has_approval_status) {
                    $rateTransfer['approval_status'] = 'APPROVED';
                    if (tableHasColumn($pdo, 'transactions', 'approved_by')) {
                        $rateTransfer['approved_by'] = $created_by_user;
                    }
                    if (tableHasColumn($pdo, 'transactions', 'approved_by_owner')) {
                        $rateTransfer['approved_by_owner'] = $created_by_owner;
                    }
                    if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
                        $rateTransfer['approved_at'] = date('Y-m-d H:i:s');
                    }
                }
                $transfer_transaction_id = insertTransactionRow($pdo, $rateTransfer);
                $transaction_ids[] = $transfer_transaction_id;
                
                $details_stmt->execute([
                $rate_group_id, $transfer_transaction_id, $company_id, 'transfer_from',
                    $rate_transfer_from_account_id, $rate_transfer_from_account_id,
                    $rate_transfer_from_amount, $rate_transfer_currency_id,
                    $rate_transfer_from_description
                ]);
                
                $details_stmt->execute([
                $rate_group_id, $transfer_transaction_id, $company_id, 'transfer_to',
                    $rate_transfer_to_account_id, null,
                    $rate_transfer_to_amount, $rate_transfer_currency_id,
                    $rate_transfer_to_description
                ]);
                
                if ($rate_middleman_account_id && $rate_middleman_amount !== null && money_cmp($rate_middleman_amount, '0') > 0) {
                    // 验证 Middleman 账户（只使用 account_company 表）
                    $stmt = $pdo->prepare("
                        SELECT a.id, a.account_id, a.name 
                        FROM account a
                        INNER JOIN account_company ac ON a.id = ac.account_id
                        WHERE a.id = ? AND ac.company_id = ?
                    ");
                    $stmt->execute([$rate_middleman_account_id, $company_id]);
                    if (!$stmt->fetchColumn()) {
                        throw new Exception('Rate Middleman Account 不存在或不属于当前公司');
                    }
                    
                    $rateMiddle = [
                        'company_id' => $company_id,
                        'transaction_type' => 'RATE',
                        'account_id' => $rate_middleman_account_id,
                        'from_account_id' => null,
                        'amount' => $rate_middleman_amount,
                        'transaction_date' => $transaction_date_db,
                        'description' => $rate_middleman_description,
                        'sms' => $sms,
                        'created_by' => $created_by_user,
                        'created_by_owner' => $created_by_owner,
                    ];
                    if ($has_currency_id) {
                        $rateMiddle['currency_id'] = $rate_middleman_currency_id;
                    }
                    if ($has_approval_status) {
                        $rateMiddle['approval_status'] = 'APPROVED';
                        if (tableHasColumn($pdo, 'transactions', 'approved_by')) {
                            $rateMiddle['approved_by'] = $created_by_user;
                        }
                        if (tableHasColumn($pdo, 'transactions', 'approved_by_owner')) {
                            $rateMiddle['approved_by_owner'] = $created_by_owner;
                        }
                        if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
                            $rateMiddle['approved_at'] = date('Y-m-d H:i:s');
                        }
                    }
                    $middleman_transaction_id = insertTransactionRow($pdo, $rateMiddle);
                    $transaction_ids[] = $middleman_transaction_id;
                    
                    $details_stmt->execute([
                    $rate_group_id, $middleman_transaction_id, $company_id, 'middleman',
                        $rate_middleman_account_id, null,
                        $rate_middleman_amount, $rate_middleman_currency_id,
                        $rate_middleman_description
                    ]);
                    
                    $middleman_deduction = submitTrunc2(money_sub($rate_transfer_from_amount, $rate_transfer_to_amount, 8));
                    if (money_cmp(money_abs($middleman_deduction), '0.01') > 0) {
                        $rateDeduct = [
                            'company_id' => $company_id,
                            'transaction_type' => 'RATE',
                            'account_id' => $rate_transfer_from_account_id,
                            'from_account_id' => $rate_transfer_from_account_id,
                            'amount' => $middleman_deduction,
                            'transaction_date' => $transaction_date_db,
                            'description' => $rate_middleman_description,
                            'sms' => $sms,
                            'created_by' => $created_by_user,
                            'created_by_owner' => $created_by_owner,
                        ];
                        if ($has_currency_id) {
                            $rateDeduct['currency_id'] = $rate_transfer_currency_id;
                        }
                        if ($has_approval_status) {
                            $rateDeduct['approval_status'] = 'APPROVED';
                            if (tableHasColumn($pdo, 'transactions', 'approved_by')) {
                                $rateDeduct['approved_by'] = $created_by_user;
                            }
                            if (tableHasColumn($pdo, 'transactions', 'approved_by_owner')) {
                                $rateDeduct['approved_by_owner'] = $created_by_owner;
                            }
                            if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
                                $rateDeduct['approved_at'] = date('Y-m-d H:i:s');
                            }
                        }
                        $middleman_deduction_transaction_id = insertTransactionRow($pdo, $rateDeduct);
                        $transaction_ids[] = $middleman_deduction_transaction_id;
                        
                        $details_stmt->execute([
                        $rate_group_id, $middleman_deduction_transaction_id, $company_id, 'transfer_from',
                            $rate_transfer_from_account_id, $rate_transfer_from_account_id,
                            $middleman_deduction, $rate_transfer_currency_id,
                            $rate_middleman_description
                        ]);
                    }
                }
            }

            // ==================== 写入统一分录表 transaction_entry（仅针对 RATE） ====================
            try {
                $entrySql = "INSERT INTO transaction_entry
                    (header_id, company_id, account_id, currency_id, amount, entry_type, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
                $entryStmt = $pdo->prepare($entrySql);

                // 1) 第一行：全部跟随第一个币种（例如 SGD），金额 = rate_from_amount（例如 100）
                $sgdAmount      = submitTrunc2($rate_from_amount);
                $sgdCurrencyId  = (int)$rate_from_currency_id;

                // From account：减
                $entryStmt->execute([
                    $main_transaction_id,
                    $company_id,
                    $rate_from_account_id,
                    $sgdCurrencyId,
                    -$sgdAmount,
                    'RATE_FIRST_FROM',
                    $rate_from_description
                ]);

                // To account：加
                $entryStmt->execute([
                    $main_transaction_id,
                    $company_id,
                    $rate_to_account_id,
                    $sgdCurrencyId,
                    $sgdAmount,
                    'RATE_FIRST_TO',
                    $rate_to_description
                ]);

                // 2) 第二行：全部跟随第二个币种（例如 MYR）
                if ($rate_transfer_from_account_id && $rate_transfer_to_account_id && $rate_transfer_currency_id) {
                    $myrFromAmount = submitTrunc2($rate_transfer_from_amount); // 例如 330
                    $myrToAmount   = submitTrunc2($rate_transfer_to_amount);   // 例如 320
                    $myrCurrencyId = (int)$rate_transfer_currency_id;

                    // - Select To (收款方)：最终显示负数
                    // - Select From (付款方)：最终显示正数
                    // search/history 会对 RATE_TRANSFER_* 统一乘以 -1，因此写入符号必定为：
                    // - RATE_TRANSFER_FROM (Select To): 写入正数（乘以-1变负数）
                    // - RATE_TRANSFER_TO (Select From): 写入负数（乘以-1变正数）

                    // account3（Select To/收款方）：写入正数
                    $entryStmt->execute([
                        $main_transaction_id,
                        $company_id,
                        $rate_transfer_from_account_id,
                        $myrCurrencyId,
                        $myrFromAmount,
                        'RATE_TRANSFER_FROM',
                        $rate_transfer_from_description
                    ]);

                    // account4（Select From/付款方）：写入负数
                    $entryStmt->execute([
                        $main_transaction_id,
                        $company_id,
                        $rate_transfer_to_account_id,
                        $myrCurrencyId,
                        money_mul($myrToAmount, '-1', 2),
                        'RATE_TRANSFER_TO',
                        $rate_transfer_to_description
                    ]);

                    // Middle-man：MYR 加手续费（如果存在）
                    if ($rate_middleman_account_id && $rate_middleman_amount !== null && money_cmp($rate_middleman_amount, '0') > 0) {
                        $middleAmount = submitTrunc2($rate_middleman_amount);
                        $middleCurrencyId = (int)$rate_middleman_currency_id ?: $myrCurrencyId;

                        $entryStmt->execute([
                            $main_transaction_id,
                            $company_id,
                            $rate_middleman_account_id,
                            $middleCurrencyId,
                            $middleAmount,
                            'RATE_MIDDLEMAN',
                            $rate_middleman_description
                        ]);
                    }
                }
            } catch (Exception $e) {
                // 为了兼容旧数据，如果分录表写入失败，不阻止主交易提交，只记录日志
                error_log('Failed to insert RATE entries into transaction_entry: ' . $e->getMessage());
            }

            // 提交事务
            $pdo->commit();

            // 提交成功后，清理 Transaction List 搜索缓存，保证前端立刻能搜到最新余额
            clearTransactionSearchCache();

            // 返回成功响应
            $responsePayload = [
                'success' => true,
                'message' => 'RATE transaction submitted successfully, ' . count($transaction_ids) . ' record(s) created',
                'data' => [
                    'transaction_ids' => $transaction_ids,
                    'transaction_type' => $transaction_type,
                    'transaction_date' => $transaction_date
                ]
            ];
            if ($idempotencyKey !== '') {
                putSubmitIdempotencyCache($idempotencyKey, $responsePayload);
            }
            echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
            
        } else {
            // 非 RATE 类型的原有逻辑
            // ADJUSTMENT 需要保留正负号；其他交易类型仍统一保存正数。
            if (!$is_adjustment) {
                $amount = submitStoreAmount(money_abs($amount, 8), 8);
            }
            
            // WIN/LOSE（含前端 PROFIT）：按单条记录保存（To + From + Amount），不再自动生成相反类型第二条
            $txnRow = [
                'company_id' => $company_id,
                'transaction_type' => $transaction_type,
                'account_id' => $account_id,
                'from_account_id' => $from_account_id,
                'amount' => $amount,
                'transaction_date' => $transaction_date_db,
                'description' => $description,
                'sms' => $sms,
                'created_by' => $created_by_user,
                'created_by_owner' => $created_by_owner,
            ];
            if ($has_currency_id) {
                $txnRow['currency_id'] = $currency_id;
            }
            if ($has_approval_status) {
                $txnRow['approval_status'] = $approval_status;
                if (tableHasColumn($pdo, 'transactions', 'approved_by')) {
                    $txnRow['approved_by'] = $approved_by;
                }
                if (tableHasColumn($pdo, 'transactions', 'approved_by_owner')) {
                    $txnRow['approved_by_owner'] = $approved_by_owner;
                }
                if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
                    $txnRow['approved_at'] = $approved_at;
                }
            }
            tx_apply_scope_columns_to_row($pdo, $txnRow, $listScope);

            $transaction_id = insertTransactionRow($pdo, $txnRow);
        
        // 提交事务
        $pdo->commit();

        // 提交成功后，清理 Transaction List 搜索缓存，保证前端立刻能搜到最新余额
        clearTransactionSearchCache();

        // 返回成功响应
        $responsePayload = [
            'success' => true,
            'message' => $is_pending_approval
                ? 'Transaction submitted, pending Manager+ approval to take effect'
                : 'Transaction submitted successfully',
            'data' => [
                'transaction_id' => $transaction_id,
                'transaction_type' => $transaction_type,
                'to_account' => $to_account['account_id'] . ' - ' . $to_account['name'],
                'from_account' => $from_account ? $from_account['account_id'] . ' - ' . $from_account['name'] : null,
                'amount' => number_format(submitTrunc2($amount), 2),
                'transaction_date' => $transaction_date,
                'approval_status' => $has_approval_status ? $approval_status : null
            ]
        ];
        if ($idempotencyKey !== '') {
            putSubmitIdempotencyCache($idempotencyKey, $responsePayload);
        }
        echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
        }
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误: ' . $e->getMessage(),
        'data' => null,
        'error' => '数据库错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器错误: ' . $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>