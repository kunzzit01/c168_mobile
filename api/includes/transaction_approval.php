<?php
/**
 * Shared transaction approval helpers (submit_api, bank process post, contra inbox).
 * User role = user.role (manager, partnership, …). Account role = account.role (PARTNER, …).
 */

if (!function_exists('tx_is_manager_or_above_role')) {
    function tx_is_manager_or_above_role(string $role): bool
    {
        $role = strtolower(trim($role));
        return in_array($role, ['manager', 'admin', 'owner'], true);
    }
}

if (!function_exists('tx_requires_transaction_approval')) {
    /** Manager 以下：交易日期早于今天则需审批。 */
    function tx_requires_transaction_approval(string $role, string $transactionDateDb): bool
    {
        if (tx_is_manager_or_above_role($role)) {
            return false;
        }
        $today = date('Y-m-d');
        return $transactionDateDb < $today;
    }
}

if (!function_exists('tx_requires_approval_for_type')) {
    function tx_requires_approval_for_type(string $transactionType): bool
    {
        $type = strtoupper(trim($transactionType));
        return in_array($type, ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM', 'CLEAR', 'ADJUSTMENT', 'PROFIT', 'WIN', 'LOSE'], true);
    }
}

if (!function_exists('tx_account_has_role')) {
    function tx_account_has_role(PDO $pdo, int $accountId, string $roleCode): bool
    {
        if ($accountId <= 0) {
            return false;
        }
        static $cache = [];
        $key = $accountId . ':' . strtoupper(trim($roleCode));
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $pdo->prepare('SELECT UPPER(TRIM(COALESCE(role, \'\'))) FROM account WHERE id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        $rowRole = (string) ($stmt->fetchColumn() ?: '');
        $cache[$key] = ($rowRole === strtoupper(trim($roleCode)));
        return $cache[$key];
    }
}

if (!function_exists('tx_is_partnership_user_role')) {
    function tx_is_partnership_user_role(string $userRole): bool
    {
        return strtolower(trim($userRole)) === 'partnership';
    }
}

if (!function_exists('tx_submit_skips_transaction_approval')) {
    /**
     * Transaction Payment 手工提交：partnership 用户或 PARTNER 账户的 WIN/LOSE 免审批。
     */
    function tx_submit_skips_transaction_approval(
        PDO $pdo,
        string $userRole,
        string $transactionType,
        int $accountId,
        ?int $fromAccountId = null
    ): bool {
        if (tx_is_partnership_user_role($userRole)) {
            return true;
        }
        $type = strtoupper(trim($transactionType));
        if (!in_array($type, ['WIN', 'LOSE'], true)) {
            return false;
        }
        if (tx_account_has_role($pdo, $accountId, 'PARTNER')) {
            return true;
        }
        if ($fromAccountId !== null && $fromAccountId > 0 && tx_account_has_role($pdo, (int) $fromAccountId, 'PARTNER')) {
            return true;
        }
        return false;
    }
}

if (!function_exists('tx_bank_process_collect_account_ids')) {
    /** @return int[] */
    function tx_bank_process_collect_account_ids(PDO $pdo, array $process, ?callable $resolveAccountIdByText = null): array
    {
        $ids = [];
        foreach (['card_merchant_id', 'customer_id', 'profit_account_id'] as $key) {
            if (!empty($process[$key])) {
                $ids[] = (int) $process[$key];
            }
        }
        $sharing = trim((string) ($process['profit_sharing'] ?? ''));
        if ($sharing !== '' && $resolveAccountIdByText !== null) {
            $companyId = (int) ($process['company_id'] ?? 0);
            foreach (preg_split('/\s*,\s*/', $sharing) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $dash = strrpos($part, ' - ');
                $accountText = $dash !== false ? trim(substr($part, 0, $dash)) : $part;
                $aid = $resolveAccountIdByText($pdo, $companyId, $accountText);
                if ($aid !== null && $aid > 0) {
                    $ids[] = (int) $aid;
                }
            }
        }
        return array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
    }
}

if (!function_exists('tx_bank_process_involves_partner_account')) {
    function tx_bank_process_involves_partner_account(PDO $pdo, array $process, ?callable $resolveAccountIdByText = null): bool
    {
        foreach (tx_bank_process_collect_account_ids($pdo, $process, $resolveAccountIdByText) as $aid) {
            if (tx_account_has_role($pdo, $aid, 'PARTNER')) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('tx_bank_process_post_skips_approval')) {
    /**
     * Bank Process Accounting Due 入账：partnership 用户或流程含 PARTNER 账户时免经理审批。
     */
    function tx_bank_process_post_skips_approval(
        PDO $pdo,
        array $process,
        int $txnAccountId,
        string $userRole,
        ?callable $resolveAccountIdByText = null
    ): bool {
        if (tx_is_partnership_user_role($userRole)) {
            return true;
        }
        if (tx_account_has_role($pdo, $txnAccountId, 'PARTNER')) {
            return true;
        }
        return tx_bank_process_involves_partner_account($pdo, $process, $resolveAccountIdByText);
    }
}

if (!function_exists('tx_apply_transaction_approval_fields')) {
    /**
     * 写入 transactions 审批字段（向后兼容：仅当表含 approval_status 时生效）。
     *
     * @return array<string, mixed> 需 merge 进 insert 行的字段
     */
    function tx_apply_transaction_approval_fields(
        PDO $pdo,
        bool $approved,
        ?int $approvedByUser = null,
        ?int $approvedByOwner = null
    ): array {
        if (!function_exists('tableHasColumn')) {
            return [];
        }
        if (!tableHasColumn($pdo, 'transactions', 'approval_status')) {
            return [];
        }
        if ($approved) {
            $fields = ['approval_status' => 'APPROVED'];
            if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
                $fields['approved_at'] = date('Y-m-d H:i:s');
            }
            if (tableHasColumn($pdo, 'transactions', 'approved_by') && $approvedByUser !== null && $approvedByUser > 0) {
                $fields['approved_by'] = $approvedByUser;
            }
            if (tableHasColumn($pdo, 'transactions', 'approved_by_owner') && $approvedByOwner !== null && $approvedByOwner > 0) {
                $fields['approved_by_owner'] = $approvedByOwner;
            }
            return $fields;
        }
        $fields = ['approval_status' => 'PENDING'];
        if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
            $fields['approved_at'] = null;
        }
        if (tableHasColumn($pdo, 'transactions', 'approved_by')) {
            $fields['approved_by'] = null;
        }
        if (tableHasColumn($pdo, 'transactions', 'approved_by_owner')) {
            $fields['approved_by_owner'] = null;
        }
        return $fields;
    }
}

if (!function_exists('tx_sql_transaction_approval_where')) {
    /**
     * SQL AND fragment: exclude PENDING approval transactions from balances / history.
     * CLEAR has no approval workflow; legacy rows may have NULL approval_status and must still count.
     */
    function tx_sql_transaction_approval_where(PDO $pdo, string $alias = 't'): string
    {
        static $hasColumn = null;
        if ($hasColumn === null) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'approval_status'");
                $hasColumn = $stmt && $stmt->rowCount() > 0;
            } catch (Throwable $e) {
                $hasColumn = false;
            }
        }
        if (!$hasColumn) {
            return '';
        }
        $a = $alias !== '' ? $alias . '.' : '';
        $needsApproval = "'CONTRA','PAYMENT','RECEIVE','CLAIM','ADJUSTMENT','WIN','LOSE','PROFIT'";
        $allTyped = $needsApproval . ",'CLEAR'";
        return " AND ((
            {$a}transaction_type IN ({$needsApproval})
            AND {$a}approval_status = 'APPROVED'
        ) OR (
            {$a}transaction_type = 'CLEAR'
            AND ({$a}approval_status IS NULL OR {$a}approval_status = '' OR {$a}approval_status = 'APPROVED')
        ) OR {$a}transaction_type NOT IN ({$allTyped}))";
    }
}
