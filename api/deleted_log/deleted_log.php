<?php
/**
 * 统一删除日志：SELECT 原行 → JSON → deleted_logs（不影响后续 DELETE 流程）
 *
 * @param PDO         $conn       数据库连接（本项目统一使用 PDO；mysqli 请自行适配）
 * @param string      $user       兜底用户名（优先使用 $_SESSION['username'] / login_id）
 * @param string      $page       调用页面或 API 标识
 * @param string      $table      物理表名（必须通过白名单）
 * @param string      $recordId   写入日志主键展示字段；WHERE id=? 时使用
 * @param string      $actionType 默认 DELETE
 * @param array|null  $whereEquals 若提供，则 WHERE 按关联列等值查询（用于无主键 id 的表）
 * @param string|null $companyIdOverride 写入日志行的 company_id（应与本次删除所属公司一致）
 *
 * @return bool 是否成功写入日志（未查到原行返回 false；失败不抛异常以免影响删除）
 */
function deleted_log_allowed_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }
    $tables = [
        'account',
        'account_company',
        'account_currency',
        'account_link',
        'currency',
        'transactions',
        'transaction_entry',
        'company_ownership',
        'group_ownership',
        'data_captures',
        'data_capture_details',
        'submitted_processes',
        'data_capture_templates',
        'bank_process',
        'process',
        'maintenance_marquee',
    ];
    sort($tables);
    return $tables;
}

function deleted_log_validate_table(string $table): bool
{
    return in_array($table, deleted_log_allowed_tables(), true);
}

/**
 * 账号在「当前操作公司」以外是否仍有 account_company 关联。
 * true = 仅移除一家公司挂靠，账号主档仍在 → 只记 account_company 日志。
 * false = 仅此一家公司挂靠，删除后将删掉 account 主档 → 合并为只记 account 日志（避免同一操作两条审计）。
 */
function deleted_log_account_has_other_company_links(PDO $pdo, int $accountId, int $exceptCompanyId): bool
{
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM account_company WHERE account_id = ? AND company_id != ?');
        $st->execute([$accountId, $exceptCompanyId]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('deleted_log_account_has_other_company_links: ' . $e->getMessage());
        return true;
    }
}

function deleted_log_session_username(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (!empty($_SESSION['username'])) {
        return (string) $_SESSION['username'];
    }
    if (!empty($_SESSION['login_id'])) {
        return (string) $_SESSION['login_id'];
    }
    if (!empty($_SESSION['name'])) {
        return (string) $_SESSION['name'];
    }
    return '';
}

function deleted_log_company_id_string(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    return isset($_SESSION['company_id']) ? (string) $_SESSION['company_id'] : '';
}

function deleted_log_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * 校验当前登录用户是否可操作该公司（删除请求里的 company_id）
 */
function deleted_log_user_can_use_company(PDO $pdo, int $companyId): bool
{
    if ($companyId <= 0) {
        return false;
    }
    $ut = strtolower((string) ($_SESSION['user_type'] ?? 'user'));
    if ($ut === 'owner') {
        $oid = (int) ($_SESSION['owner_id'] ?? $_SESSION['real_owner_id'] ?? 0);
        if ($oid <= 0) {
            return false;
        }
        try {
            $st = $pdo->prepare('SELECT 1 FROM company WHERE id = ? AND owner_id = ? LIMIT 1');
            $st->execute([$companyId, $oid]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM user_company_map WHERE user_id = ? AND company_id = ? LIMIT 1');
        $st->execute([$uid, $companyId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function deletedLog(PDO $conn, string $user, string $page, string $table, string $recordId, string $actionType = 'DELETE', ?array $whereEquals = null, ?string $companyIdOverride = null): bool
{
    if (!deleted_log_validate_table($table)) {
        error_log('deletedLog: rejected non-whitelist table: ' . $table);
        return false;
    }

    $effectiveUser = deleted_log_session_username();
    if ($effectiveUser === '') {
        $effectiveUser = $user;
    }

    try {
        if ($whereEquals === null || $whereEquals === []) {
            $stmt = $conn->prepare('SELECT * FROM `' . $table . '` WHERE `id` = ? LIMIT 1');
            $stmt->execute([$recordId]);
        } else {
            $parts = [];
            $params = [];
            foreach ($whereEquals as $col => $val) {
                if (!is_string($col) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
                    continue;
                }
                $parts[] = '`' . $col . '` = ?';
                $params[] = $val;
            }
            if ($parts === []) {
                return false;
            }
            $sql = 'SELECT * FROM `' . $table . '` WHERE ' . implode(' AND ', $parts) . ' LIMIT 1';
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = '{}';
        }

        $ins = $conn->prepare(
            'INSERT INTO `deleted_logs` (`user`, `company_id`, `page`, `table_name`, `record_id`, `action_type`, `ip_address`, `deleted_data`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $cidStored = ($companyIdOverride !== null && trim($companyIdOverride) !== '')
            ? trim($companyIdOverride)
            : deleted_log_company_id_string();
        $ins->execute([
            $effectiveUser,
            $cidStored,
            $page,
            $table,
            $recordId,
            $actionType,
            deleted_log_client_ip(),
            $json,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('deletedLog failed: ' . $e->getMessage());
        return false;
    }
}
