<?php
/**
 * Account Currency API
 * 管理账户与货币的多对多关系
 * 路径: api/accounts/account_currency_api.php
 */

session_start();
session_write_close(); // 释放 session 锁，允许并发 AJAX 请求并行执行
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/group_company_access.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../transactions/transaction_scope.php';
require_once __DIR__ . '/../deleted_log/deleted_log.php';

/**
 * 标准 JSON 响应
 */
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
 * 检查 account_company 表是否存在
 */
function hasAccountCompanyTable($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * @return array{mode: 'group'|'company', group_pk: int, company_id: int, group_code: string}
 */
function accountCurrencyResolveContext(PDO $pdo): array
{
    $params = [
        'group_id' => $_GET['group_id'] ?? null,
        'view_group' => $_GET['view_group'] ?? null,
        'company_id' => $_GET['company_id'] ?? null,
        'group_only' => $_GET['group_only'] ?? null,
        'session_company_id' => $_SESSION['company_id'] ?? null,
    ];

    $accountId = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
    if ($accountId > 0) {
        $accountCtx = tenant_resolve_currency_context_for_account($pdo, $accountId);
        if ($accountCtx !== null) {
            return $accountCtx;
        }
    }

    return tenant_resolve_currency_context_from_request($pdo, $params);
}

/**
 * 获取账户关联的货币列表（当前公司）
 */
function accountCurrencyTableExists(PDO $pdo): bool {
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'account_currency'");
        return $st && $st->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function dbGetAccountCurrencies(PDO $pdo, int $account_id, array $ctx): array
{
    if (($ctx['mode'] ?? '') === 'group' && tenant_table_has_scope_columns($pdo, 'currency')) {
        $sql = "SELECT ac.id, ac.account_id, ac.currency_id, c.code AS currency_code
                FROM account_currency ac
                INNER JOIN currency c ON ac.currency_id = c.id
                WHERE ac.account_id = ? AND c.scope_type = 'group' AND c.scope_id = ?
                ORDER BY ac.created_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$account_id, (int) ($ctx['group_pk'] ?? 0)]);
    } else {
        $company_id = (int) ($ctx['company_id'] ?? 0);
        $sql = "SELECT ac.id, ac.account_id, ac.currency_id, c.code AS currency_code
                FROM account_currency ac
                INNER JOIN currency c ON ac.currency_id = c.id
                WHERE ac.account_id = ? AND c.company_id = ?"
            . tenant_sql_currency_subsidiary_only($pdo, 'c')
            . ' ORDER BY ac.created_at ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$account_id, $company_id]);
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Member Win/Loss 等：返回账户在当前公司下拥有的全部币别（含无 account_currency 行时的 account.currency_id 兜底）
 */
function dbGetAccountOwnedCurrenciesResolved(PDO $pdo, int $account_id, array $ctx): array
{
    if (accountCurrencyTableExists($pdo)) {
        $rows = dbGetAccountCurrencies($pdo, $account_id, $ctx);
        if (!empty($rows)) {
            return $rows;
        }
    }
    try {
        $check = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency_id'");
        if ($check && $check->rowCount() > 0) {
            if (($ctx['mode'] ?? '') === 'group' && tenant_table_has_scope_columns($pdo, 'currency')) {
                $stmt = $pdo->prepare("
                    SELECT c.id AS currency_id, c.code AS currency_code
                    FROM account a
                    INNER JOIN currency c ON a.currency_id = c.id
                    WHERE a.id = ? AND c.scope_type = 'group' AND c.scope_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$account_id, (int) ($ctx['group_pk'] ?? 0)]);
            } else {
                $company_id = (int) ($ctx['company_id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT c.id AS currency_id, c.code AS currency_code
                    FROM account a
                    INNER JOIN currency c ON a.currency_id = c.id
                    WHERE a.id = ? AND c.company_id = ?"
                    . tenant_sql_currency_subsidiary_only($pdo, 'c')
                    . ' LIMIT 1');
                $stmt->execute([$account_id, $company_id]);
            }
            $one = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($one && !empty($one['currency_code'])) {
                return [[
                    'id' => null,
                    'account_id' => $account_id,
                    'currency_id' => (int) $one['currency_id'],
                    'currency_code' => $one['currency_code'],
                ]];
            }
        }
    } catch (PDOException $e) {
        // ignore
    }
    return [];
}

/**
 * 获取当前 scope 下所有货币（集团账本 vs 子公司）
 */
function dbGetScopeCurrencies(PDO $pdo, array $ctx): array
{
    return tenant_fetch_currencies($pdo, $ctx);
}

/**
 * 获取账户已关联的货币 ID 列表
 */
function dbGetLinkedCurrencyIds($pdo, $account_id) {
    $stmt = $pdo->prepare("SELECT currency_id FROM account_currency WHERE account_id = ?");
    $stmt->execute([$account_id]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'currency_id');
}

function dbCurrencyBelongsToScope(PDO $pdo, int $currency_id, array $ctx): bool
{
    return tenant_currency_belongs_to_context($pdo, $currency_id, $ctx);
}

/**
 * 检查账户-货币是否已关联
 */
function dbAccountCurrencyLinked($pdo, $account_id, $currency_id) {
    $stmt = $pdo->prepare("SELECT id FROM account_currency WHERE account_id = ? AND currency_id = ?");
    $stmt->execute([$account_id, $currency_id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 添加账户-货币关联
 */
function dbAddAccountCurrency($pdo, $account_id, $currency_id) {
    $stmt = $pdo->prepare("INSERT INTO account_currency (account_id, currency_id) VALUES (?, ?)");
    $stmt->execute([$account_id, $currency_id]);
}

/**
 * 获取账户关联货币数量
 */
function dbCountAccountCurrencies($pdo, $account_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM account_currency WHERE account_id = ?");
    $stmt->execute([$account_id]);
    return (int) $stmt->fetchColumn();
}

/**
 * 删除账户-货币关联
 */
function dbRemoveAccountCurrency($pdo, $account_id, $currency_id) {
    $stmt = $pdo->prepare("DELETE FROM account_currency WHERE account_id = ? AND currency_id = ?");
    $stmt->execute([$account_id, $currency_id]);
    return $stmt->rowCount();
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(false, '用户未登录或缺少公司信息', null, 401);
        exit;
    }

    try {
        $currencyCtx = accountCurrencyResolveContext($pdo);
    } catch (Exception $e) {
        jsonResponse(false, $e->getMessage(), null, 400);
        exit;
    }

    $permCompanyId = (int) ($currencyCtx['company_id'] ?? 0);
    if ($permCompanyId <= 0) {
        jsonResponse(false, '用户未登录或缺少公司信息', null, 401);
        exit;
    }

    $groupCode = (string) ($currencyCtx['group_code'] ?? '');
    if ($groupCode !== '' && gc_is_group_login()) {
        gc_assert_company_id_allowed_for_login_scope($pdo, $permCompanyId, $groupCode);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $verifyAccount = function ($account_id) use ($pdo, $currencyCtx) {
        return tenant_account_belongs_to_context($pdo, (int) $account_id, $currencyCtx);
    };

    if ($method === 'GET') {
        if ($action === 'get_account_currencies') {
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            if (!$account_id) {
                jsonResponse(false, '账户ID是必需的', null, 400);
                exit;
            }
            if (!$verifyAccount($account_id)) {
                jsonResponse(false, '账户不存在或无权限访问', null, 403);
                exit;
            }
            $currencies = dbGetAccountOwnedCurrenciesResolved($pdo, $account_id, $currencyCtx);
            jsonResponse(true, '', $currencies);
            exit;
        }

        if ($action === 'get_available_currencies') {
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            $all = dbGetScopeCurrencies($pdo, $currencyCtx);
            $linked_ids = $account_id ? dbGetLinkedCurrencyIds($pdo, $account_id) : [];
            $result = array_map(static function ($c) use ($linked_ids) {
                $row = [
                    'id' => (int) $c['id'],
                    'code' => $c['code'],
                    'is_linked' => in_array($c['id'], $linked_ids, true),
                ];
                if (array_key_exists('sync_source', $c)) {
                    $row['sync_source'] = $c['sync_source'];
                }
                if (array_key_exists('deletable', $c)) {
                    $row['deletable'] = (bool) $c['deletable'];
                }

                return $row;
            }, $all);
            jsonResponse(true, '', $result);
            exit;
        }

        /**
         * Member Win/Loss：批量返回多个账户在当前公司拥有的币别；member 仅能查关联闭包内账户。
         * GET account_ids=1,2,3
         */
        if ($action === 'get_batch_account_currencies') {
            if (!isset($_SESSION['user_id'])) {
                jsonResponse(false, '请先登录', null, 401);
                exit;
            }
            $raw = isset($_GET['account_ids']) ? trim((string) $_GET['account_ids']) : '';
            $parts = $raw !== '' ? preg_split('/\s*,\s*/', $raw) : [];
            $ids = [];
            foreach ($parts as $p) {
                $n = (int) $p;
                if ($n > 0 && !in_array($n, $ids, true)) {
                    $ids[] = $n;
                }
            }
            if ($ids === []) {
                jsonResponse(false, 'account_ids 参数无效', null, 400);
                exit;
            }
            require_once __DIR__ . '/../includes/member_linked_closure.php';
            $userType = strtolower((string) ($_SESSION['user_type'] ?? ''));
            $allowedMap = null;
            if ($userType === 'member') {
                $loginId = member_session_canonical_account_id();
                if ($loginId <= 0) {
                    jsonResponse(false, '无法识别会话', null, 403);
                    exit;
                }
                $allowed = member_linked_member_closure_ids($pdo, $loginId, $permCompanyId);
                $allowedMap = [];
                foreach ($allowed as $x) {
                    $allowedMap[(int) $x] = true;
                }
            }
            $result = [];
            foreach ($ids as $aid) {
                if ($allowedMap !== null && empty($allowedMap[$aid])) {
                    continue;
                }
                if (!tenant_account_belongs_to_context($pdo, $aid, $currencyCtx)) {
                    continue;
                }
                $rows = dbGetAccountOwnedCurrenciesResolved($pdo, $aid, $currencyCtx);
                $clist = [];
                foreach ($rows as $r) {
                    $clist[] = [
                        'currency_id' => isset($r['currency_id']) ? (int) $r['currency_id'] : (isset($r['id']) ? (int) $r['id'] : 0),
                        'currency_code' => isset($r['currency_code'])
                            ? strtoupper(trim((string) $r['currency_code']))
                            : '',
                    ];
                }
                $result[] = [
                    'account_id' => $aid,
                    'currencies' => $clist,
                ];
            }
            jsonResponse(true, '', $result);
            exit;
        }

        jsonResponse(false, '无效的操作', null, 400);
        exit;
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        if ($action === 'add_currency') {
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $currency_id = isset($data['currency_id']) ? (int)$data['currency_id'] : 0;
            if (!$account_id || !$currency_id) {
                jsonResponse(false, '账户ID和货币ID是必需的', null, 400);
                exit;
            }
            if (!$verifyAccount($account_id)) {
                jsonResponse(false, '账户不存在或无权限访问', null, 403);
                exit;
            }
            if (!dbCurrencyBelongsToScope($pdo, $currency_id, $currencyCtx)) {
                jsonResponse(false, '货币不存在或无权限访问', null, 403);
                exit;
            }
            if (dbAccountCurrencyLinked($pdo, $account_id, $currency_id)) {
                jsonResponse(false, '该货币已经关联到此账户', null, 400);
                exit;
            }
            dbAddAccountCurrency($pdo, $account_id, $currency_id);
            jsonResponse(true, '货币添加成功', ['account_id' => $account_id, 'currency_id' => $currency_id]);
            exit;
        }

        if ($action === 'remove_currency') {
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $currency_id = isset($data['currency_id']) ? (int)$data['currency_id'] : 0;
            if (!$account_id || !$currency_id) {
                jsonResponse(false, '账户ID和货币ID是必需的', null, 400);
                exit;
            }
            if (!$verifyAccount($account_id)) {
                jsonResponse(false, '账户不存在或无权限访问', null, 403);
                exit;
            }
            if (dbCountAccountCurrencies($pdo, $account_id) <= 1) {
                jsonResponse(false, '账户必须至少保留一个货币，无法删除', null, 400);
                exit;
            }
            $stmtAc = $pdo->prepare('SELECT id FROM account_currency WHERE account_id = ? AND currency_id = ? LIMIT 1');
            $stmtAc->execute([$account_id, $currency_id]);
            $acRow = $stmtAc->fetch(PDO::FETCH_ASSOC);
            if ($acRow && isset($acRow['id'])) {
                deletedLog(
                    $pdo,
                    '',
                    '/api/accounts/account_currency_api.php',
                    'account_currency',
                    (string) $acRow['id'],
                    'DELETE',
                    null,
                    (string) $permCompanyId
                );
            }
            $deleted = dbRemoveAccountCurrency($pdo, $account_id, $currency_id);
            if ($deleted === 0) {
                jsonResponse(false, '关联不存在', null, 400);
                exit;
            }
            jsonResponse(true, '货币移除成功');
            exit;
        }

        jsonResponse(false, '无效的操作', null, 400);
        exit;
    }

    jsonResponse(false, '不支持的请求方法', null, 405);

} catch (PDOException $e) {
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    jsonResponse(false, $e->getMessage(), null, $code);
}