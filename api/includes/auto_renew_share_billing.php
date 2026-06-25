<?php
/**
 * Auto Renew approve — Share % commission + net profit billing (renewal cycle).
 * Uses AUTO_RENEW|COMMISSION / AUTO_RENEW|NET_PROFIT sms markers keyed by expiration_snapshot.
 */

/**
 * @param mixed $raw
 * @return array{profit: list, sales: list, cs: list, it: list}
 */
function auto_renew_normalize_share_allocations($raw): array
{
    $empty = ['profit' => [], 'sales' => [], 'cs' => [], 'it' => []];
    if ($raw === null || $raw === '') {
        return $empty;
    }
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($raw)) {
            return $empty;
        }
    }
    if (!is_array($raw)) {
        return $empty;
    }
    $out = $empty;
    foreach (['profit', 'sales', 'cs', 'it'] as $role) {
        if (empty($raw[$role]) || !is_array($raw[$role])) {
            continue;
        }
        foreach ($raw[$role] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $aid = isset($row['account_id']) ? (int) $row['account_id'] : 0;
            $pct = isset($row['percentage']) && money_is_valid($row['percentage'])
                ? money_normalize($row['percentage'], 4)
                : '0.0000';
            if ($aid !== 0 && money_cmp($pct, '0', 4) >= 0) {
                $out[$role][] = [
                    'account_id' => $aid,
                    'percentage' => money_strip_zeros($pct),
                ];
            }
        }
    }
    return $out;
}

function auto_renew_load_tenant_share_allocations(PDO $pdo, string $entityType, string $tenantCode): array
{
    $code = strtoupper(trim($tenantCode));
    if ($code === '') {
        return auto_renew_normalize_share_allocations(null);
    }
    $entityType = auto_renew_normalize_entity_type($entityType);
    try {
        if ($entityType === 'group' && auto_renew_has_groups_table($pdo)) {
            $st = $pdo->prepare('SELECT fee_share_allocations FROM `groups` WHERE UPPER(TRIM(group_code)) = ? LIMIT 1');
            $st->execute([$code]);
            return auto_renew_normalize_share_allocations($st->fetchColumn());
        }
        $st = $pdo->prepare('SELECT fee_share_allocations FROM company WHERE UPPER(TRIM(company_id)) = ? LIMIT 1');
        $st->execute([$code]);
        return auto_renew_normalize_share_allocations($st->fetchColumn());
    } catch (PDOException $e) {
        return auto_renew_normalize_share_allocations(null);
    }
}

function auto_renew_build_commission_sms(
    string $entityType,
    string $tenantCode,
    string $expirationSnapshot,
    string $role,
    int $accountId
): string {
    $code = strtoupper(trim($tenantCode));
    $exp = trim($expirationSnapshot);
    $roleU = strtoupper(trim($role));
    if (auto_renew_normalize_entity_type($entityType) === 'group') {
        return "[AUTO_RENEW|COMMISSION|GROUP|{$code}|{$exp}|ROLE:{$roleU}|AID:{$accountId}]";
    }
    return "[AUTO_RENEW|COMMISSION|{$code}|{$exp}|ROLE:{$roleU}|AID:{$accountId}]";
}

function auto_renew_build_net_profit_sms(string $entityType, string $tenantCode, string $expirationSnapshot): string
{
    $code = strtoupper(trim($tenantCode));
    $exp = trim($expirationSnapshot);
    if (auto_renew_normalize_entity_type($entityType) === 'group') {
        return "[AUTO_RENEW|NET_PROFIT|GROUP|{$code}|{$exp}]";
    }
    return "[AUTO_RENEW|NET_PROFIT|{$code}|{$exp}]";
}

function auto_renew_resolve_profit_target_account(PDO $pdo, int $c168Pk, array $normalized): ?int
{
    $profitRows = $normalized['profit'] ?? [];
    if (!is_array($profitRows)) {
        $profitRows = [];
    }
    foreach ($profitRows as $row) {
        $aid = isset($row['account_id']) ? (int) $row['account_id'] : 0;
        if ($aid <= 0) {
            continue;
        }
        $chk = $pdo->prepare("
            SELECT COUNT(*)
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE a.id = ?
              AND ac.company_id = ?
              AND LOWER(TRIM(COALESCE(a.role, ''))) = 'profit'
        ");
        $chk->execute([$aid, $c168Pk]);
        if ((int) $chk->fetchColumn() > 0) {
            return $aid;
        }
    }
    try {
        $st = $pdo->prepare("
            SELECT a.id
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND LOWER(TRIM(COALESCE(a.role, ''))) = 'profit'
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            ORDER BY CASE
                WHEN UPPER(TRIM(COALESCE(a.account_id, ''))) = 'C168' THEN 0
                WHEN UPPER(TRIM(COALESCE(a.account_id, ''))) = 'PROFIT' THEN 1
                ELSE 2
            END, a.id ASC
            LIMIT 1
        ");
        $st->execute([$c168Pk]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int) $v : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * @return array{created_count:int, commission_total:string}
 */
function auto_renew_create_share_commission_payments(
    PDO $pdo,
    int $c168Pk,
    string $tenantCode,
    string $expirationSnapshot,
    string $entityType,
    array $normalized,
    int $poolAccountId,
    string $feeAmount,
    ?int $createdByUser,
    ?int $createdByOwner
): array {
    $result = ['created_count' => 0, 'commission_total' => '0'];
    if ($c168Pk <= 0 || money_cmp($feeAmount, '0') <= 0 || $poolAccountId <= 0) {
        return $result;
    }

    $hasCurrencyId = auto_renew_table_has_column($pdo, 'transactions', 'currency_id');
    $hasApprovalStatus = auto_renew_table_has_column($pdo, 'transactions', 'approval_status');
    $hasApprovedBy = auto_renew_table_has_column($pdo, 'transactions', 'approved_by');
    $hasApprovedByOwner = auto_renew_table_has_column($pdo, 'transactions', 'approved_by_owner');
    $hasApprovedAt = auto_renew_table_has_column($pdo, 'transactions', 'approved_at');
    $hasCreatedAt = auto_renew_table_has_column($pdo, 'transactions', 'created_at');
    $defaultTxnCurrencyId = $hasCurrencyId ? auto_renew_resolve_c168_default_currency_id($pdo, $c168Pk) : null;

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $ownerCode = 'C168';
    try {
        $stOwner = $pdo->prepare('SELECT UPPER(TRIM(company_id)) FROM company WHERE id = ? LIMIT 1');
        $stOwner->execute([$c168Pk]);
        $oc = $stOwner->fetchColumn();
        if ($oc !== false && $oc !== null && trim((string) $oc) !== '') {
            $ownerCode = strtoupper(trim((string) $oc));
        }
    } catch (PDOException $e) {
        // keep default
    }

    $tenantU = strtoupper(trim($tenantCode));
    if ($tenantU === '') {
        $tenantU = $ownerCode;
    }

    $roleLabelMap = ['sales' => 'Sales', 'cs' => 'CS', 'it' => 'IT'];
    foreach (['sales', 'cs', 'it'] as $role) {
        $rows = $normalized[$role] ?? [];
        if (!is_array($rows)) {
            continue;
        }
        $roleLabel = $roleLabelMap[$role] ?? ucfirst($role);
        $description = $roleLabel . ' Commision for ' . $tenantU;
        foreach ($rows as $row) {
            $aid = isset($row['account_id']) ? (int) $row['account_id'] : 0;
            $pct = isset($row['percentage']) && money_is_valid($row['percentage'])
                ? money_normalize($row['percentage'], 4)
                : '0.0000';
            if ($aid <= 0 || money_cmp($pct, '0', 4) <= 0) {
                continue;
            }
            $amount = money_div(money_mul($feeAmount, $pct, MONEY_CALC_SCALE), '100', 2);
            if (money_cmp($amount, '0') <= 0) {
                continue;
            }
            $roleSql = "LOWER(TRIM(COALESCE(a.role, ''))) IN ('staff', 'agent')";
            $chk = $pdo->prepare("
                SELECT COUNT(*)
                FROM account_company ac
                INNER JOIN account a ON a.id = ac.account_id
                WHERE ac.account_id = ? AND ac.company_id = ?
                  AND ($roleSql)
            ");
            $chk->execute([$aid, $c168Pk]);
            if ((int) $chk->fetchColumn() <= 0) {
                continue;
            }
            if ($poolAccountId === $aid) {
                continue;
            }
            $smsMarker = auto_renew_build_commission_sms($entityType, $tenantCode, $expirationSnapshot, $role, $aid);
            $dupStmt = $pdo->prepare("
                SELECT id FROM transactions
                WHERE company_id = ? AND transaction_type = 'PAYMENT' AND account_id = ?
                  AND (sms = ? OR sms LIKE ?)
                LIMIT 1
            ");
            $dupStmt->execute([$c168Pk, $aid, $smsMarker, $smsMarker . '|%']);
            if ($dupStmt->fetchColumn() !== false) {
                continue;
            }

            $insertCols = [
                'company_id' => $c168Pk,
                'transaction_type' => 'PAYMENT',
                'account_id' => $aid,
                'from_account_id' => $poolAccountId,
                'amount' => money_normalize($amount, 2),
                'transaction_date' => $today,
                'description' => $description,
                'sms' => $smsMarker,
                'created_by' => $createdByUser,
                'created_by_owner' => $createdByOwner,
            ];
            if ($hasCurrencyId) {
                $insertCols['currency_id'] = $defaultTxnCurrencyId;
            }
            if ($hasApprovalStatus) {
                $insertCols['approval_status'] = 'APPROVED';
                if ($hasApprovedBy) {
                    $insertCols['approved_by'] = $createdByUser;
                }
                if ($hasApprovedByOwner) {
                    $insertCols['approved_by_owner'] = $createdByOwner;
                }
                if ($hasApprovedAt) {
                    $insertCols['approved_at'] = $now;
                }
            }
            if ($hasCreatedAt) {
                $insertCols['created_at'] = $now;
            }
            $columns = array_keys($insertCols);
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $sql = 'INSERT INTO transactions (`' . implode('`,`', $columns) . "`) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($insertCols));
            $result['created_count']++;
            $result['commission_total'] = money_add($result['commission_total'], $amount, 2);
        }
    }

    return $result;
}

/**
 * @return array{created:bool, amount:string}
 */
function auto_renew_create_net_profit_payment(
    PDO $pdo,
    int $c168Pk,
    string $tenantCode,
    string $expirationSnapshot,
    string $entityType,
    array $normalized,
    string $feeAmount,
    string $commissionTotal,
    ?int $createdByUser,
    ?int $createdByOwner
): array {
    $out = ['created' => false, 'amount' => '0'];
    if ($c168Pk <= 0) {
        return $out;
    }
    $net = money_sub($feeAmount, $commissionTotal, 2);
    $out['amount'] = money_out($net);
    if (money_cmp($net, '0') <= 0) {
        return $out;
    }

    $profitAccId = auto_renew_resolve_profit_target_account($pdo, $c168Pk, $normalized);
    if (!$profitAccId || $profitAccId <= 0) {
        return $out;
    }

    $smsMarker = auto_renew_build_net_profit_sms($entityType, $tenantCode, $expirationSnapshot);
    $dupStmt = $pdo->prepare("
        SELECT id FROM transactions
        WHERE company_id = ? AND transaction_type = 'PAYMENT'
          AND (sms = ? OR sms LIKE ?)
        LIMIT 1
    ");
    $dupStmt->execute([$c168Pk, $smsMarker, $smsMarker . '|%']);
    if ($dupStmt->fetchColumn() !== false) {
        return $out;
    }

    $hasCurrencyId = auto_renew_table_has_column($pdo, 'transactions', 'currency_id');
    $hasApprovalStatus = auto_renew_table_has_column($pdo, 'transactions', 'approval_status');
    $hasApprovedBy = auto_renew_table_has_column($pdo, 'transactions', 'approved_by');
    $hasApprovedByOwner = auto_renew_table_has_column($pdo, 'transactions', 'approved_by_owner');
    $hasApprovedAt = auto_renew_table_has_column($pdo, 'transactions', 'approved_at');
    $hasCreatedAt = auto_renew_table_has_column($pdo, 'transactions', 'created_at');
    $defaultTxnCurrencyId = $hasCurrencyId ? auto_renew_resolve_c168_default_currency_id($pdo, $c168Pk) : null;

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $ownerCode = 'C168';
    try {
        $stOwner = $pdo->prepare('SELECT UPPER(TRIM(company_id)) FROM company WHERE id = ? LIMIT 1');
        $stOwner->execute([$c168Pk]);
        $oc = $stOwner->fetchColumn();
        if ($oc !== false && $oc !== null && trim((string) $oc) !== '') {
            $ownerCode = strtoupper(trim((string) $oc));
        }
    } catch (PDOException $e) {
        // keep default
    }

    $insertCols = [
        'company_id' => $c168Pk,
        'transaction_type' => 'PAYMENT',
        'account_id' => $profitAccId,
        'from_account_id' => null,
        'amount' => money_normalize($net, 2),
        'transaction_date' => $today,
        'description' => 'Profit By ' . $ownerCode,
        'sms' => $smsMarker,
        'created_by' => $createdByUser,
        'created_by_owner' => $createdByOwner,
    ];
    if ($hasCurrencyId) {
        $insertCols['currency_id'] = $defaultTxnCurrencyId;
    }
    if ($hasApprovalStatus) {
        $insertCols['approval_status'] = 'APPROVED';
        if ($hasApprovedBy) {
            $insertCols['approved_by'] = $createdByUser;
        }
        if ($hasApprovedByOwner) {
            $insertCols['approved_by_owner'] = $createdByOwner;
        }
        if ($hasApprovedAt) {
            $insertCols['approved_at'] = $now;
        }
    }
    if ($hasCreatedAt) {
        $insertCols['created_at'] = $now;
    }
    $cols = array_keys($insertCols);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO transactions (`' . implode('`,`', $cols) . "`) VALUES ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute(array_values($insertCols));
    $out['created'] = true;
    return $out;
}

/**
 * C168 资金池：与 Domain 一致，优先 Share% Profit 账号，否则用续费 To 账号。
 */
function auto_renew_resolve_fee_pool_account_id(
    PDO $pdo,
    int $c168Pk,
    string $tenantCode,
    string $entityType,
    int $fallbackToAccountId
): int {
    $normalized = auto_renew_load_tenant_share_allocations($pdo, $entityType, $tenantCode);
    $poolId = auto_renew_resolve_profit_target_account($pdo, $c168Pk, $normalized);
    if ($poolId && $poolId > 0) {
        return (int) $poolId;
    }
    return (int) $fallbackToAccountId;
}

function auto_renew_apply_share_billing_on_approve(
    PDO $pdo,
    int $c168Pk,
    string $tenantCode,
    string $expirationSnapshot,
    string $entityType,
    string $feeAmount,
    int $poolAccountId,
    ?int $createdByUser,
    ?int $createdByOwner
): void {
    $normalized = auto_renew_load_tenant_share_allocations($pdo, $entityType, $tenantCode);
    $hasShare = !empty($normalized['profit']) || !empty($normalized['sales'])
        || !empty($normalized['cs']) || !empty($normalized['it']);
    if (!$hasShare) {
        return;
    }
    $commissionResult = auto_renew_create_share_commission_payments(
        $pdo,
        $c168Pk,
        $tenantCode,
        $expirationSnapshot,
        $entityType,
        $normalized,
        $poolAccountId,
        $feeAmount,
        $createdByUser,
        $createdByOwner
    );
    auto_renew_create_net_profit_payment(
        $pdo,
        $c168Pk,
        $tenantCode,
        $expirationSnapshot,
        $entityType,
        $normalized,
        $feeAmount,
        (string) ($commissionResult['commission_total'] ?? '0'),
        $createdByUser,
        $createdByOwner
    );
}

/**
 * @return list<string>
 */
function auto_renew_normalize_snapshot_date(string $snapshot): string
{
    $s = trim($snapshot);
    if ($s === '') {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1];
    }

    return $s;
}

/**
 * Resolve tenant + expiration from the linked renewal fee PAYMENT sms when possible.
 *
 * @return array{tenant_code:string, expiration_snapshot:string, entity_type:string}
 */
function auto_renew_resolve_renewal_cycle_context(
    PDO $pdo,
    int $c168Pk,
    int $feeTxnId,
    string $fallbackCode,
    string $fallbackSnapshot,
    string $fallbackEntityType
): array {
    if ($feeTxnId > 0 && $c168Pk > 0) {
        try {
            $st = $pdo->prepare('SELECT sms FROM transactions WHERE id = ? AND company_id = ? LIMIT 1');
            $st->execute([$feeTxnId, $c168Pk]);
            $parsed = auto_renew_parse_fee_sms((string) ($st->fetchColumn() ?: ''));
            if (is_array($parsed)) {
                return [
                    'tenant_code' => strtoupper(trim((string) ($parsed['tenant_code'] ?? ''))),
                    'expiration_snapshot' => auto_renew_normalize_snapshot_date((string) ($parsed['expiration_snapshot'] ?? '')),
                    'entity_type' => auto_renew_normalize_entity_type((string) ($parsed['entity_type'] ?? 'company')),
                ];
            }
        } catch (PDOException $e) {
            // fall through to request row values
        }
    }

    return [
        'tenant_code' => strtoupper(trim($fallbackCode)),
        'expiration_snapshot' => auto_renew_normalize_snapshot_date($fallbackSnapshot),
        'entity_type' => auto_renew_normalize_entity_type($fallbackEntityType),
    ];
}

/**
 * @return list<string>
 */
function auto_renew_share_billing_sms_like_patterns(
    string $entityType,
    string $tenantCode,
    string $expirationSnapshot
): array {
    $code = strtoupper(trim($tenantCode));
    $exp = auto_renew_normalize_snapshot_date($expirationSnapshot);
    if ($code === '' || $exp === '') {
        return [];
    }
    if (auto_renew_normalize_entity_type($entityType) === 'group') {
        return [
            "[AUTO_RENEW|COMMISSION|GROUP|{$code}|{$exp}%",
            "[AUTO_RENEW|NET_PROFIT|GROUP|{$code}|{$exp}%",
        ];
    }
    return [
        "[AUTO_RENEW|COMMISSION|{$code}|{$exp}%",
        "[AUTO_RENEW|NET_PROFIT|{$code}|{$exp}%",
    ];
}

/**
 * Commission + net profit PAYMENT ids for one renewal cycle (expiration_snapshot).
 *
 * @return list<int>
 */
function auto_renew_find_share_billing_transaction_ids(
    PDO $pdo,
    int $c168Pk,
    string $tenantCode,
    string $expirationSnapshot,
    string $entityType
): array {
    if ($c168Pk <= 0) {
        return [];
    }
    $code = strtoupper(trim($tenantCode));
    $exp = auto_renew_normalize_snapshot_date($expirationSnapshot);
    if ($code === '' || $exp === '') {
        return [];
    }

    $ids = [];
    $patterns = auto_renew_share_billing_sms_like_patterns($entityType, $code, $exp);
    try {
        foreach ($patterns as $pattern) {
            $st = $pdo->prepare("
                SELECT id FROM transactions
                WHERE company_id = ? AND transaction_type = 'PAYMENT' AND sms LIKE ?
            ");
            $st->execute([$c168Pk, $pattern]);
            while ($id = $st->fetchColumn()) {
                $tid = (int) $id;
                if ($tid > 0) {
                    $ids[$tid] = true;
                }
            }
        }

        // Broader fallback: any AUTO_RENEW commission/profit row for this tenant + cycle.
        $needle = '%|' . $code . '|' . $exp . '%';
        $st = $pdo->prepare("
            SELECT id FROM transactions
            WHERE company_id = ?
              AND transaction_type = 'PAYMENT'
              AND (
                sms LIKE '[AUTO_RENEW|COMMISSION|%'
                OR sms LIKE '[AUTO_RENEW|NET_PROFIT|%'
              )
              AND sms LIKE ?
        ");
        $st->execute([$c168Pk, $needle]);
        while ($id = $st->fetchColumn()) {
            $tid = (int) $id;
            if ($tid > 0) {
                $ids[$tid] = true;
            }
        }
    } catch (PDOException $e) {
        return [];
    }

    return array_keys($ids);
}

/**
 * Delete C168 renewal-related PAYMENT rows by id (no scope_type ledger filter).
 *
 * @return list<int> deleted ids
 */
function auto_renew_delete_c168_transaction_ids(
    PDO $pdo,
    int $c168Pk,
    array $ids,
    array $session,
    string $pageTag = '/api/subscription/auto_renew_api.php'
): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
    if ($c168Pk <= 0 || $ids === []) {
        return [];
    }

    $userRole = strtolower(trim((string) ($session['role'] ?? '')));
    $userId = (int) ($session['user_id'] ?? 0);
    $ownerId = isset($session['owner_id']) ? (int) $session['owner_id'] : null;
    $deletedByUserId = null;
    $deletedByOwnerId = null;
    if ($userRole === 'owner') {
        $deletedByOwnerId = $ownerId ?: $userId;
    } else {
        $deletedByUserId = $userId;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $hasDeletedScope = tx_table_has_scope_column($pdo, 'transactions_deleted')
        && tx_table_has_scope_column($pdo, 'transactions');
    $scopeCols = $hasDeletedScope ? ', scope_type, scope_id' : '';
    $scopeSelect = $hasDeletedScope
        ? ", COALESCE(NULLIF(TRIM(t.scope_type), ''), 'company') AS scope_type, t.scope_id"
        : '';

    $userTag = (string) ($session['login_id'] ?? $session['name'] ?? '');
    foreach ($ids as $tid) {
        $entryListStmt = $pdo->prepare('SELECT id FROM transaction_entry WHERE header_id = ?');
        $entryListStmt->execute([(int) $tid]);
        while ($eid = $entryListStmt->fetchColumn()) {
            deletedLog($pdo, $userTag, $pageTag, 'transaction_entry', (string) $eid);
        }
        deletedLog($pdo, $userTag, $pageTag, 'transactions', (string) $tid);
    }

    bmp_recordResendPendingForTransactionIds($pdo, $c168Pk, $ids);

    $backupSql = "
        INSERT INTO transactions_deleted (
            transaction_id, company_id{$scopeCols}, transaction_type, account_id, from_account_id,
            amount, currency_id, transaction_date, description, sms, created_by, created_by_owner, created_at,
            deleted_by_user_id, deleted_by_owner_id, deleted_at
        )
        SELECT
            t.id AS transaction_id, t.company_id{$scopeSelect}, t.transaction_type, t.account_id, t.from_account_id,
            t.amount, t.currency_id, t.transaction_date, t.description, t.sms, t.created_by, t.created_by_owner, t.created_at,
            ?, ?, NOW()
        FROM transactions t
        WHERE t.id IN ($placeholders) AND t.company_id = ?
    ";
    $backupParams = array_merge([$deletedByUserId, $deletedByOwnerId], $ids, [$c168Pk]);
    $backupStmt = $pdo->prepare($backupSql);
    $backupStmt->execute($backupParams);

    payment_delete_transaction_entries($pdo, $ids);

    $deleteSql = "DELETE FROM transactions WHERE id IN ($placeholders) AND company_id = ?";
    $deleteParams = array_merge($ids, [$c168Pk]);
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute($deleteParams);

    return $ids;
}

/**
 * Main renewal fee + commission + net profit ids for one approved cycle.
 *
 * @return list<int>
 */
function auto_renew_collect_renewal_billing_transaction_ids(
    PDO $pdo,
    int $c168Pk,
    int $feeTxnId,
    string $tenantCode,
    string $expirationSnapshot,
    string $entityType
): array {
    $cycle = auto_renew_resolve_renewal_cycle_context(
        $pdo,
        $c168Pk,
        $feeTxnId,
        $tenantCode,
        $expirationSnapshot,
        $entityType
    );

    $ids = [];
    if ($feeTxnId > 0 && auto_renew_transaction_is_active($pdo, $c168Pk, $feeTxnId)) {
        $ids[$feeTxnId] = true;
    }

    foreach (auto_renew_find_share_billing_transaction_ids(
        $pdo,
        $c168Pk,
        $cycle['tenant_code'],
        $cycle['expiration_snapshot'],
        $cycle['entity_type']
    ) as $shareTxnId) {
        if (auto_renew_transaction_is_active($pdo, $c168Pk, $shareTxnId)) {
            $ids[$shareTxnId] = true;
        }
    }

    return array_keys($ids);
}

/**
 * Remove commission + net profit PAYMENT rows created on auto-renew approve.
 */
function auto_renew_delete_share_billing_payments(
    PDO $pdo,
    int $c168Pk,
    string $tenantCode,
    string $expirationSnapshot,
    string $entityType,
    array $session
): void {
    $ids = auto_renew_find_share_billing_transaction_ids(
        $pdo,
        $c168Pk,
        $tenantCode,
        $expirationSnapshot,
        $entityType
    );
    if ($ids === []) {
        return;
    }
    auto_renew_delete_c168_transaction_ids($pdo, $c168Pk, $ids, $session);
}
