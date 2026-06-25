<?php

/**
 * Member：登录身份与 Win/Loss「查看」账号（session 键）的读取。
 * 「可关联账户」闭包枚举与 account_link_api ::getLinkedAccountsForMember() 一致。
 */
/**
 * 旧 session 可能缺少 member_* 键；登录后 bootstrap 前补齐，避免 current_user_api 等 fatal。
 */
if (!function_exists('member_ensure_login_session_fields')) {
    function member_ensure_login_session_fields(): void
    {
        if (!isset($_SESSION['user_type']) || strtolower((string) $_SESSION['user_type']) !== 'member') {
            return;
        }
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return;
        }
        if (empty($_SESSION['member_login_account_id'])) {
            $_SESSION['member_login_account_id'] = $uid;
        }
        if (empty($_SESSION['member_winloss_view_account_id'])) {
            $_SESSION['member_winloss_view_account_id'] = (int) $_SESSION['member_login_account_id'];
        }
    }
}

if (!function_exists('member_session_canonical_account_id')) {
    function member_session_canonical_account_id(): int
    {
        if (!isset($_SESSION['user_type']) || strtolower((string) $_SESSION['user_type']) !== 'member') {
            return (int) ($_SESSION['user_id'] ?? 0);
        }
        $m = (int) ($_SESSION['member_login_account_id'] ?? 0);
        return $m > 0 ? $m : (int) ($_SESSION['user_id'] ?? 0);
    }

    /** Win/Loss 当前 pivot（account.id）；未切换时与登录账号相同 */
    function member_session_winloss_view_account_id(): int
    {
        $login = member_session_canonical_account_id();
        if (!isset($_SESSION['user_type']) || strtolower((string) $_SESSION['user_type']) !== 'member') {
            return (int) ($_SESSION['user_id'] ?? 0);
        }
        $v = (int) ($_SESSION['member_winloss_view_account_id'] ?? 0);
        if ($v > 0 && $v !== $login) {
            return $v;
        }
        return $login;
    }
}

/**
 * 当前会话 member 在公司下的「可关联账户」闭包内的 account.id 列表，
 * 与 api/accounts/account_link_api.php ::getLinkedAccountsForMember() 遍历一致。
 */
if (!function_exists('member_linked_member_closure_ids')) {
    function member_linked_member_closure_ids(PDO $pdo, int $account_id, int $company_id): array
    {
        $account_id = (int) $account_id;
        $company_id = (int) $company_id;
        if ($account_id <= 0 || $company_id <= 0) {
            return [];
        }
        $visited = [];
        $queue = [$account_id];

        while (!empty($queue)) {
            $current_id = (int) array_shift($queue);
            if (isset($visited[$current_id])) {
                continue;
            }
            $visited[$current_id] = true;

            $stmt = $pdo->prepare("
                SELECT account_id_2 AS linked_id, link_type, source_account_id
                FROM account_link WHERE account_id_1 = ? AND company_id = ?
                AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
                UNION
                SELECT account_id_1 AS linked_id, link_type, source_account_id
                FROM account_link WHERE account_id_2 = ? AND company_id = ?
                AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
            ");
            $stmt->execute([$current_id, $company_id, $current_id, $current_id, $company_id, $current_id]);
            $linked_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($linked_data as $row) {
                $linked_id = (int) ($row['linked_id'] ?? 0);
                if (!isset($visited[$linked_id])) {
                    $visited[$linked_id] = true;
                    if (($row['link_type'] ?? '') === 'bidirectional') {
                        $queue[] = $linked_id;
                    }
                }
            }
        }

        return array_map('intval', array_keys($visited));
    }
}
