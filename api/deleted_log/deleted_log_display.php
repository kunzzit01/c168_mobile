<?php
/**
 * Deleted Log 列表展示：从快照解析 Acc ID、可读摘要（不写库）
 */

/**
 * @param mixed $payload deleted_logs.deleted_data（JSON 字符串或已解码数组）
 */
function deleted_log_display_decode_payload($payload): ?array
{
    if (is_array($payload)) {
        return $payload;
    }
    if ($payload === null || $payload === '') {
        return null;
    }
    $d = json_decode((string) $payload, true);
    return is_array($d) ? $d : null;
}

/**
 * 将 API/脚本路径映射为简短页面名（给最终用户看：从哪里来点的删除）
 */
function deleted_log_display_page_label(string $page): string
{
    $page = trim($page);
    $map = [
        'account-list.php' => '账号列表 Account List',
        '/api/accounts/delete_accounts_api.php' => '账号列表 Account List',
        '/api/accounts/delete_currency_api.php' => '币种设置 Currency',
        '/api/accounts/account_currency_api.php' => '账号币种 Account Currency',
        '/api/accounts/bulk_account_currency_api.php' => '批量账号币种 Bulk Account Currency',
        '/api/accounts/account_company_api.php' => '账号多公司 Account Company',
        '/api/accounts/account_link_api.php' => '账号关联 Account Link',
        '/api/transactions/maintenance_delete_api.php' => '交易维护 Transaction Maintenance',
        '/api/payment_maintenance/delete_api.php' => '收付款维护 Payment Maintenance',
        '/api/capture_maintenance/delete_api.php' => '抓数维护 Data Capture Maintenance',
        '/api/formula_maintenance/delete_api.php' => '公式维护 Formula Maintenance',
        '/api/processes/delete_processes_api.php' => '流程列表 Process List',
        '/api/ownership/remove_owner_api.php' => '股权 Ownership',
        '/api/maintenance/delete_api.php' => '系统维护跑马灯 Maintenance',
        'processlist.php' => '流程列表 Process List',
        'remove_owner_api.php' => '股权 Ownership',
    ];
    if (isset($map[$page])) {
        return $map[$page];
    }
    $base = basename($page);
    return $base !== '' && $base !== '.' ? $base : $page;
}

/**
 * 根据表与快照生成一行摘要：入口页面 + 删了什么（无 Acc 也会说明行为）
 */
function deleted_log_display_summary(string $table, string $page, ?array $data, string $accDisplay): string
{
    $where = deleted_log_display_page_label($page);
    $acc = trim($accDisplay);
    $accZh = ($acc !== '' && $acc !== '—') ? ('，账号 ' . $acc) : '';

    switch ($table) {
        case 'account':
            return $where . '：删除账号资料（含 Acc ID）' . ($accZh !== '' ? $accZh : '');

        case 'account_company':
            return $where . '：解除该账号与当前公司的关联' . $accZh;

        case 'account_currency':
            return $where . '：删除账号币种设置' . $accZh;

        case 'account_link':
            return $where . '：删除账号关联（Link）' . $accZh;

        case 'currency':
            return $where . '：删除币种配置';

        case 'transactions':
            return $where . '：删除流水 / 交易记录' . $accZh;

        case 'transaction_entry':
            return $where . '：删除分录明细行' . $accZh;

        case 'company_ownership':
            return $where . '：删除一条股权 / 合伙关系记录';

        case 'group_ownership':
            return $where . '：删除集团股权记录';

        case 'data_captures':
            return $where . '：删除抓数主表记录';

        case 'data_capture_details':
            return $where . '：删除抓数明细';

        case 'submitted_processes':
            return $where . '：删除已提交流程对应记录';

        case 'data_capture_templates':
            return $where . '：删除公式 / 模板';

        case 'bank_process':
            return $where . '：删除银行类流程';

        case 'process':
            return $where . '：删除流程主档';

        case 'maintenance_marquee':
            return $where . '：删除维护区跑马灯内容';

        default:
            return $where . '：删除数据（表 ' . $table . '）' . $accZh;
    }
}

/**
 * 批量解析当前页需要的 account.id → account.account_id（展示用）
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,string> id => display account_id
 */
function deleted_log_display_resolve_account_ids(PDO $pdo, array $rows): array
{
    $needIds = [];
    foreach ($rows as $r) {
        $tbl = (string) ($r['table_name'] ?? '');
        $data = deleted_log_display_decode_payload($r['deleted_data'] ?? null);
        if ($data === null) {
            continue;
        }
        if (!in_array($tbl, ['account_company', 'transactions', 'transaction_entry', 'account_currency', 'account_link'], true)) {
            continue;
        }
        if (isset($data['account_id']) && (string) $data['account_id'] !== '') {
            $aid = (int) $data['account_id'];
            if ($aid > 0) {
                $needIds[$aid] = true;
            }
        }
    }
    $ids = array_keys($needIds);
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id, account_id FROM account WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int) $row['id']] = (string) ($row['account_id'] ?? '');
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 展示用 Acc ID：业务上的 account 编号（如 ACC01）；无法解析时返回 —
 *
 * @param array<string,mixed>|null $data
 * @param array<int,string>        $idToAcc resolved account.id -> account_id
 */
function deleted_log_display_acc_id(string $table, ?array $data, array $idToAcc): string
{
    if ($data === null) {
        return '—';
    }

    if ($table === 'account') {
        $s = isset($data['account_id']) ? trim((string) $data['account_id']) : '';
        return $s !== '' ? $s : '—';
    }

    if (in_array($table, ['account_company', 'transactions', 'transaction_entry', 'account_currency', 'account_link'], true)) {
        if (!isset($data['account_id'])) {
            return '—';
        }
        $pid = (int) $data['account_id'];
        if ($pid > 0 && isset($idToAcc[$pid]) && $idToAcc[$pid] !== '') {
            return $idToAcc[$pid];
        }
        return '#' . $pid;
    }

    return '—';
}
