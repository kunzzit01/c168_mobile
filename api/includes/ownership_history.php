<?php
/**
 * Monthly ownership snapshot helpers.
 *
 * Live tables (company_ownership / group_ownership) = current working config (carries into next month).
 * History tables (*_history, effective_month = YYYY-MM-01) = frozen past months for the Ownership UI.
 *
 * On save: update live first, then snapshot ONLY the current calendar month in history.
 * Past months can be updated explicitly via batch save with a month=YYYY-MM parameter.
 */

function ownership_history_ensure_tables(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    // MySQL/MariaDB: CREATE TABLE implicitly commits and ends any active transaction.
    // Skip DDL when tables already exist (same pattern as domain_api ensureDomainListFeeSettingsTable).
    $companyExists = $pdo->query("SHOW TABLES LIKE 'company_ownership_history'")->rowCount() > 0;
    $groupExists = $pdo->query("SHOW TABLES LIKE 'group_ownership_history'")->rowCount() > 0;

    if ($companyExists && $groupExists) {
        // Tables exist — still run index upgrade below (may have old 4-column unique key).
    } else {
        if (!$companyExists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS company_ownership_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    effective_month DATE NOT NULL,
                    account_id INT NOT NULL,
                    owner_type ENUM('account','owner','user','group') NOT NULL DEFAULT 'account',
                    percentage DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                    partner_group_id VARCHAR(50) DEFAULT NULL,
                    read_only TINYINT(1) NOT NULL DEFAULT 1,
                    saved_by INT DEFAULT NULL,
                    saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_co_hist_month_account (company_id, effective_month, account_id, owner_type, partner_group_id),
                    KEY idx_co_hist_company_month (company_id, effective_month)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!$groupExists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS group_ownership_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    group_id VARCHAR(50) NOT NULL,
                    owner_id INT NOT NULL DEFAULT 0,
                    effective_month DATE NOT NULL,
                    account_id INT NOT NULL,
                    owner_type ENUM('owner','user','group') NOT NULL DEFAULT 'owner',
                    percentage DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                    partner_group_id VARCHAR(50) DEFAULT NULL,
                    read_only TINYINT(1) NOT NULL DEFAULT 1,
                    saved_by INT DEFAULT NULL,
                    saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_go_hist_month_account (group_id, effective_month, account_id, owner_type, partner_group_id),
                    KEY idx_go_hist_group_month (group_id, effective_month)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    ownership_history_upgrade_unique_indexes($pdo);

    $ensured = true;
}

/** Rebuild history unique keys to include partner_group_id (idempotent). */
function ownership_history_upgrade_unique_indexes(PDO $pdo): void
{
    if ($pdo->query("SHOW TABLES LIKE 'company_ownership_history'")->rowCount() > 0) {
        try {
            $pdo->exec('ALTER TABLE company_ownership_history DROP INDEX uq_co_hist_month_account');
        } catch (Exception $e) {
        }
        try {
            $pdo->exec('ALTER TABLE company_ownership_history ADD UNIQUE KEY uq_co_hist_month_account (company_id, effective_month, account_id, owner_type, partner_group_id)');
        } catch (Exception $e) {
        }
    }

    if ($pdo->query("SHOW TABLES LIKE 'group_ownership_history'")->rowCount() > 0) {
        try {
            $pdo->exec('ALTER TABLE group_ownership_history DROP INDEX uq_go_hist_month_account');
        } catch (Exception $e) {
        }
        try {
            $pdo->exec('ALTER TABLE group_ownership_history ADD UNIQUE KEY uq_go_hist_month_account (group_id, effective_month, account_id, owner_type, partner_group_id)');
        } catch (Exception $e) {
        }
    }
}

function ownership_history_effective_month_from_now(): string
{
    return date('Y-m-01');
}

function ownership_history_current_month_key(): string
{
    return date('Y-m');
}

/** @return array{month_key: string, effective_month: string}|null */
function ownership_history_parse_month_param(?string $raw): ?array
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $raw = trim($raw);
    if (!preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
        return null;
    }
    $year = (int) $m[1];
    $mon = (int) $m[2];
    if ($mon < 1 || $mon > 12) {
        return null;
    }
    return [
        'month_key' => sprintf('%04d-%02d', $year, $mon),
        'effective_month' => sprintf('%04d-%02d-01', $year, $mon),
    ];
}

function ownership_history_is_past_month(string $monthKey): bool
{
    return $monthKey < ownership_history_current_month_key();
}

function ownership_history_previous_month_key(): string
{
    return date('Y-m', strtotime('first day of last month'));
}

/** @return list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}> */
function ownership_history_collect_company_rows_from_live(PDO $pdo, int $companyId): array
{
    if ($pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() < 1) {
        return [];
    }
    if ($pdo->query("SHOW COLUMNS FROM company_ownership LIKE 'owner_type'")->rowCount() < 1) {
        return [];
    }

    require_once __DIR__ . '/money_decimal.php';

    $stmt = $pdo->prepare("
        SELECT account_id, owner_type, percentage, partner_group_id, COALESCE(read_only, 1) AS read_only
        FROM company_ownership
        WHERE company_id = ? AND owner_type != 'account'
    ");
    $stmt->execute([$companyId]);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'account_id' => (int) $row['account_id'],
            'owner_type' => (string) $row['owner_type'],
            'percentage' => money_out($row['percentage'], 2),
            'partner_group_id' => $row['partner_group_id'],
            'read_only' => (int) $row['read_only'],
        ];
    }

    return $rows;
}

/** Snapshot current calendar month for one company from live rows (does not touch other months). */
function ownership_history_snapshot_company_from_live(PDO $pdo, int $companyId, ?int $savedBy): void
{
    ownership_history_save_company(
        $pdo,
        $companyId,
        ownership_history_collect_company_rows_from_live($pdo, $companyId),
        $savedBy
    );
}

/** Best-effort snapshot; never throws (link/remove must not fail when history write fails). */
function ownership_history_snapshot_company_from_live_safe(PDO $pdo, int $companyId, ?int $savedBy): void
{
    try {
        ownership_history_snapshot_company_from_live($pdo, $companyId, $savedBy);
    } catch (Throwable $e) {
        error_log('ownership history snapshot company ' . $companyId . ': ' . $e->getMessage());
    }
}

/** @return list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}> */
function ownership_history_collect_group_rows_from_live(PDO $pdo, string $groupId): array
{
    if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() < 1) {
        return [];
    }

    require_once __DIR__ . '/money_decimal.php';

    $stmt = $pdo->prepare("
        SELECT account_id, owner_type, percentage, partner_group_id, COALESCE(read_only, 1) AS read_only
        FROM group_ownership
        WHERE group_id = ?
    ");
    $stmt->execute([$groupId]);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'account_id' => (int) $row['account_id'],
            'owner_type' => (string) $row['owner_type'],
            'percentage' => money_out($row['percentage'], 2),
            'partner_group_id' => $row['partner_group_id'],
            'read_only' => (int) $row['read_only'],
        ];
    }

    return $rows;
}

function ownership_history_resolve_group_owner_id(PDO $pdo, string $groupId): int
{
    if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0) {
        $stmt = $pdo->prepare('SELECT owner_id FROM group_ownership WHERE group_id = ? LIMIT 1');
        $stmt->execute([$groupId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0) {
            return $ownerId;
        }
    }

    $stmt = $pdo->prepare('SELECT DISTINCT owner_id FROM company WHERE UPPER(TRIM(group_id)) = UPPER(TRIM(?)) LIMIT 1');
    $stmt->execute([$groupId]);

    return (int) $stmt->fetchColumn();
}

/** Snapshot current calendar month for one group from live rows (does not touch other months). */
function ownership_history_snapshot_group_from_live(PDO $pdo, string $groupId, ?int $savedBy): void
{
    $ownerId = ownership_history_resolve_group_owner_id($pdo, $groupId);
    ownership_history_save_group(
        $pdo,
        $groupId,
        $ownerId,
        ownership_history_collect_group_rows_from_live($pdo, $groupId),
        $savedBy
    );
}

/** Best-effort snapshot; never throws (link/remove must not fail when history write fails). */
function ownership_history_snapshot_group_from_live_safe(PDO $pdo, string $groupId, ?int $savedBy): void
{
    try {
        ownership_history_snapshot_group_from_live($pdo, $groupId, $savedBy);
    } catch (Throwable $e) {
        error_log('ownership history snapshot group ' . $groupId . ': ' . $e->getMessage());
    }
}

/**
 * @param list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}> $rows
 */
function ownership_history_save_company_for_month(
    PDO $pdo,
    int $companyId,
    array $rows,
    ?int $savedBy,
    string $effectiveMonth
): void {
    ownership_history_ensure_tables($pdo);

    $del = $pdo->prepare('DELETE FROM company_ownership_history WHERE company_id = ? AND effective_month = ?');
    $del->execute([$companyId, $effectiveMonth]);

    if (count($rows) === 0) {
        return;
    }

    $ins = $pdo->prepare('
        INSERT INTO company_ownership_history
            (company_id, effective_month, account_id, owner_type, percentage, partner_group_id, read_only, saved_by, saved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    foreach ($rows as $row) {
        $ins->execute([
            $companyId,
            $effectiveMonth,
            (int) $row['account_id'],
            $row['owner_type'],
            $row['percentage'],
            $row['partner_group_id'],
            (int) $row['read_only'],
            $savedBy,
        ]);
    }
}

/**
 * @param list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}> $rows
 */
function ownership_history_save_company(PDO $pdo, int $companyId, array $rows, ?int $savedBy): void
{
    ownership_history_save_company_for_month(
        $pdo,
        $companyId,
        $rows,
        $savedBy,
        ownership_history_effective_month_from_now()
    );
}

/**
 * @param list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}> $rows
 */
function ownership_history_save_group_for_month(
    PDO $pdo,
    string $groupId,
    int $ownerId,
    array $rows,
    ?int $savedBy,
    string $effectiveMonth
): void {
    ownership_history_ensure_tables($pdo);

    $del = $pdo->prepare('DELETE FROM group_ownership_history WHERE group_id = ? AND effective_month = ?');
    $del->execute([$groupId, $effectiveMonth]);

    if (count($rows) === 0) {
        return;
    }

    $ins = $pdo->prepare('
        INSERT INTO group_ownership_history
            (group_id, owner_id, effective_month, account_id, owner_type, percentage, partner_group_id, read_only, saved_by, saved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    foreach ($rows as $row) {
        $ins->execute([
            $groupId,
            $ownerId,
            $effectiveMonth,
            (int) $row['account_id'],
            $row['owner_type'],
            $row['percentage'],
            $row['partner_group_id'],
            (int) $row['read_only'],
            $savedBy,
        ]);
    }
}

/**
 * @param list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}> $rows
 */
function ownership_history_save_group(PDO $pdo, string $groupId, int $ownerId, array $rows, ?int $savedBy): void
{
    ownership_history_save_group_for_month(
        $pdo,
        $groupId,
        $ownerId,
        $rows,
        $savedBy,
        ownership_history_effective_month_from_now()
    );
}

/**
 * Copy live ownership tables into monthly history for a past month (after data adjustment).
 *
 * @return array{effective_month: string, company_rows: int, group_rows: int}
 */
function ownership_history_backfill_month_from_live(PDO $pdo, string $monthKey, ?int $savedBy = null): array
{
    $parsed = ownership_history_parse_month_param($monthKey);
    if ($parsed === null) {
        throw new InvalidArgumentException('Invalid month key (expected YYYY-MM)');
    }
    if (!ownership_history_is_past_month($parsed['month_key'])) {
        throw new InvalidArgumentException('Backfill is only allowed for past months');
    }

    ownership_history_ensure_tables($pdo);
    $effectiveMonth = $parsed['effective_month'];
    $companyRows = 0;
    $groupRows = 0;

    if ($pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() > 0) {
        $hasOwnerType = $pdo->query("SHOW COLUMNS FROM company_ownership LIKE 'owner_type'")->rowCount() > 0;
        if ($hasOwnerType) {
            $pdo->prepare('DELETE FROM company_ownership_history WHERE effective_month = ?')
                ->execute([$effectiveMonth]);
            $stmt = $pdo->prepare("
                INSERT INTO company_ownership_history
                    (company_id, effective_month, account_id, owner_type, percentage, partner_group_id, read_only, saved_by, saved_at)
                SELECT company_id, ?, account_id, owner_type, percentage, partner_group_id, COALESCE(read_only, 1), ?, NOW()
                FROM company_ownership
                WHERE owner_type != 'account'
            ");
            $stmt->execute([$effectiveMonth, $savedBy]);
            $companyRows = $stmt->rowCount();
        }
    }

    if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0) {
        $pdo->prepare('DELETE FROM group_ownership_history WHERE effective_month = ?')
            ->execute([$effectiveMonth]);
        $stmt = $pdo->prepare("
            INSERT INTO group_ownership_history
                (group_id, owner_id, effective_month, account_id, owner_type, percentage, partner_group_id, read_only, saved_by, saved_at)
            SELECT group_id, owner_id, ?, account_id, owner_type, percentage, partner_group_id, COALESCE(read_only, 1), ?, NOW()
            FROM group_ownership
        ");
        $stmt->execute([$effectiveMonth, $savedBy]);
        $groupRows = $stmt->rowCount();
    }

    return [
        'effective_month' => $effectiveMonth,
        'company_rows' => $companyRows,
        'group_rows' => $groupRows,
    ];
}

/**
 * @param list<string> $monthKeys YYYY-MM
 */
function ownership_history_apply_retrofill_months(
    PDO $pdo,
    int $companyId,
    array $rows,
    ?int $savedBy,
    array $monthKeys
): void {
    foreach ($monthKeys as $monthKey) {
        $parsed = ownership_history_parse_month_param($monthKey);
        if ($parsed === null || !ownership_history_is_past_month($parsed['month_key'])) {
            continue;
        }
        ownership_history_save_company_for_month($pdo, $companyId, $rows, $savedBy, $parsed['effective_month']);
    }
}

/**
 * @param list<string> $monthKeys YYYY-MM
 */
function ownership_history_apply_group_retrofill_months(
    PDO $pdo,
    string $groupId,
    int $ownerId,
    array $rows,
    ?int $savedBy,
    array $monthKeys
): void {
    foreach ($monthKeys as $monthKey) {
        $parsed = ownership_history_parse_month_param($monthKey);
        if ($parsed === null || !ownership_history_is_past_month($parsed['month_key'])) {
            continue;
        }
        ownership_history_save_group_for_month($pdo, $groupId, $ownerId, $rows, $savedBy, $parsed['effective_month']);
    }
}

/**
 * For a past month: copy live → history only for companies/groups that have no rows yet for that month.
 * Live table is unchanged. Current/future months are never written.
 *
 * @return array{effective_month: string, company_rows: int, group_rows: int}
 */
function ownership_history_seal_month_gaps_from_live(PDO $pdo, string $monthKey, ?int $savedBy = null): array
{
    $parsed = ownership_history_parse_month_param($monthKey);
    if ($parsed === null) {
        throw new InvalidArgumentException('Invalid month key (expected YYYY-MM)');
    }
    if (!ownership_history_is_past_month($parsed['month_key'])) {
        throw new InvalidArgumentException('Can only seal completed past months');
    }

    ownership_history_ensure_tables($pdo);
    $effectiveMonth = $parsed['effective_month'];
    $companyRows = 0;
    $groupRows = 0;

    if ($pdo->query("SHOW TABLES LIKE 'company_ownership'")->rowCount() > 0
        && $pdo->query("SHOW COLUMNS FROM company_ownership LIKE 'owner_type'")->rowCount() > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO company_ownership_history
                (company_id, effective_month, account_id, owner_type, percentage, partner_group_id, read_only, saved_by, saved_at)
            SELECT co.company_id, ?, co.account_id, co.owner_type, co.percentage, co.partner_group_id, COALESCE(co.read_only, 1), ?, NOW()
            FROM company_ownership co
            WHERE co.owner_type != 'account'
              AND NOT EXISTS (
                  SELECT 1 FROM company_ownership_history h
                  WHERE h.company_id = co.company_id AND h.effective_month = ?
              )
        ");
        $stmt->execute([$effectiveMonth, $savedBy, $effectiveMonth]);
        $companyRows = $stmt->rowCount();
    }

    if ($pdo->query("SHOW TABLES LIKE 'group_ownership'")->rowCount() > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO group_ownership_history
                (group_id, owner_id, effective_month, account_id, owner_type, percentage, partner_group_id, read_only, saved_by, saved_at)
            SELECT go.group_id, go.owner_id, ?, go.account_id, go.owner_type, go.percentage, go.partner_group_id, COALESCE(go.read_only, 1), ?, NOW()
            FROM group_ownership go
            WHERE NOT EXISTS (
                SELECT 1 FROM group_ownership_history h
                WHERE h.group_id = go.group_id AND h.effective_month = ?
            )
        ");
        $stmt->execute([$effectiveMonth, $savedBy, $effectiveMonth]);
        $groupRows = $stmt->rowCount();
    }

    return [
        'effective_month' => $effectiveMonth,
        'company_rows' => $companyRows,
        'group_rows' => $groupRows,
    ];
}

/** @return array{effective_month: string, company_rows: int, group_rows: int} */
function ownership_history_seal_previous_month_gaps_from_live(PDO $pdo, ?int $savedBy = null): array
{
    return ownership_history_seal_month_gaps_from_live($pdo, ownership_history_previous_month_key(), $savedBy);
}

/** @return array{saved_at: ?string, has_snapshot: bool} */
function ownership_history_company_meta(PDO $pdo, int $companyId, string $effectiveMonth): array
{
    ownership_history_ensure_tables($pdo);
    $stmt = $pdo->prepare('
        SELECT MAX(saved_at) AS saved_at, COUNT(*) AS cnt
        FROM company_ownership_history
        WHERE company_id = ? AND effective_month = ?
    ');
    $stmt->execute([$companyId, $effectiveMonth]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cnt = (int) ($row['cnt'] ?? 0);
    return [
        'saved_at' => $cnt > 0 ? (string) $row['saved_at'] : null,
        'has_snapshot' => $cnt > 0,
    ];
}

/** @return array{saved_at: ?string, has_snapshot: bool} */
function ownership_history_group_meta(PDO $pdo, string $groupId, string $effectiveMonth): array
{
    ownership_history_ensure_tables($pdo);
    $stmt = $pdo->prepare('
        SELECT MAX(saved_at) AS saved_at, COUNT(*) AS cnt
        FROM group_ownership_history
        WHERE group_id = ? AND effective_month = ?
    ');
    $stmt->execute([$groupId, $effectiveMonth]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cnt = (int) ($row['cnt'] ?? 0);
    return [
        'saved_at' => $cnt > 0 ? (string) $row['saved_at'] : null,
        'has_snapshot' => $cnt > 0,
    ];
}

/**
 * @param list<array<string,mixed>> $owners
 * @param array<int,string|null> $existingGroups
 * @param array<int,int> $existingReadOnly
 * @return list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}>
 */
function ownership_build_company_history_rows_from_payload(array $owners, array $existingGroups = [], array $existingReadOnly = []): array
{
    require_once __DIR__ . '/money_decimal.php';

    $historyRows = [];
    foreach ($owners as $owner) {
        $raw_id = (string) ($owner['account_id'] ?? '');
        $owner_type = 'account';
        $real_id = $raw_id;
        $is_group_entry = false;
        $group_ref = null;
        $isExternal = !empty($owner['is_external_partner']);

        if (strpos($raw_id, 'G_') === 0) {
            $owner_type = 'group';
            $real_id = 0;
            $group_ref = substr($raw_id, 2);
            $is_group_entry = true;
        } elseif (strpos($raw_id, 'O_') === 0) {
            $owner_type = 'owner';
            $real_id = substr($raw_id, 2);
        } elseif (strpos($raw_id, 'U_') === 0) {
            $owner_type = 'user';
            $real_id = substr($raw_id, 2);
        } elseif (strpos($raw_id, 'A_') === 0) {
            $owner_type = 'account';
            $real_id = substr($raw_id, 2);
        }

        $pgid = null;
        $roVal = isset($owner['read_only']) ? (int) $owner['read_only'] : 1;

        if ($is_group_entry) {
            $pgid = $group_ref;
        } elseif ($owner_type === 'owner' && isset($existingGroups[(int) $real_id])) {
            $pgid = $existingGroups[(int) $real_id];
            if (!isset($owner['read_only'])) {
                $roVal = $existingReadOnly[(int) $real_id] ?? 1;
            }
        }

        $pctRaw = $isExternal ? '0' : ($owner['percentage'] ?? 0);
        $pctOut = money_out(money_normalize($pctRaw, 2), 2);

        $historyRows[] = [
            'account_id' => (int) $real_id,
            'owner_type' => $owner_type,
            'percentage' => $pctOut,
            'partner_group_id' => $pgid,
            'read_only' => $roVal,
        ];
    }

    return $historyRows;
}

/**
 * @param list<array<string,mixed>> $owners
 * @param array<int,string|null> $existingGroups
 * @param array<int,int> $existingReadOnly
 * @param array<string,int> $existingGroupReadOnly partner_group_id (upper) => read_only
 * @return list<array{account_id:int,owner_type:string,percentage:string,partner_group_id:?string,read_only:int}>
 */
function ownership_build_group_history_rows_from_payload(
    array $owners,
    array $existingGroups = [],
    array $existingReadOnly = [],
    array $existingGroupReadOnly = []
): array {
    require_once __DIR__ . '/money_decimal.php';

    $historyRows = [];
    foreach ($owners as $owner) {
        $raw_id = (string) ($owner['account_id'] ?? '');
        $owner_type = 'owner';
        $real_id = 0;
        $pgid = null;
        $roVal = isset($owner['read_only']) ? (int) $owner['read_only'] : 1;
        $isExternal = !empty($owner['is_external_partner']);

        if (strpos($raw_id, 'G_') === 0) {
            $owner_type = 'group';
            $real_id = 0;
            $pgid = substr($raw_id, 2);
            if (!isset($owner['read_only'])) {
                $key = strtoupper(trim((string) $pgid));
                if ($key !== '' && isset($existingGroupReadOnly[$key])) {
                    $roVal = $existingGroupReadOnly[$key];
                }
            }
        } elseif (strpos($raw_id, 'O_') === 0) {
            $owner_type = 'owner';
            $real_id = (int) substr($raw_id, 2);
            if (isset($existingGroups[$real_id])) {
                $pgid = $existingGroups[$real_id];
                if (!isset($owner['read_only'])) {
                    $roVal = $existingReadOnly[$real_id] ?? 1;
                }
            }
        } elseif (strpos($raw_id, 'U_') === 0) {
            $owner_type = 'user';
            $real_id = (int) substr($raw_id, 2);
        } else {
            $owner_type = 'owner';
            $real_id = (int) $raw_id;
        }

        $pctRaw = $isExternal ? '0' : ($owner['percentage'] ?? 0);
        $pctOut = money_out(money_normalize($pctRaw, 2), 2);

        $historyRows[] = [
            'account_id' => $real_id,
            'owner_type' => $owner_type,
            'percentage' => $pctOut,
            'partner_group_id' => $pgid,
            'read_only' => $roVal,
        ];
    }

    return $historyRows;
}
