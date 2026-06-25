<?php
/**
 * Dual-tenant helpers: group ledger (groups.id) vs company subsidiary.
 */

declare(strict_types=1);

require_once __DIR__ . '/group_scope_resolve.php';

function tenant_table_has_scope_columns(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_hash($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $cache[$key] = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'scope_type'")->rowCount() > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/** Dual-tenant schema: currency.scope_type separates group ledger from subsidiary rows. */
function tenant_dual_tenant_enabled(PDO $pdo): bool
{
    return tenant_table_has_scope_columns($pdo, 'currency');
}

/**
 * Group-only request: strip company_id so APIs never hit legacy group-entity company scope.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function tenant_normalize_scope_params(array $params): array
{
    $groupOnly = !empty($params['group_only'])
        && filter_var($params['group_only'], FILTER_VALIDATE_BOOLEAN);
    if ($groupOnly) {
        unset($params['company_id']);
    }

    return $params;
}

function tenant_request_is_group_only(array $params): bool
{
    if (!empty($params['group_only']) && filter_var($params['group_only'], FILTER_VALIDATE_BOOLEAN)) {
        return true;
    }

    $companyRaw = $params['company_id'] ?? null;
    $hasCompany = $companyRaw !== null
        && trim((string) $companyRaw) !== ''
        && (int) $companyRaw > 0;
    if ($hasCompany) {
        return false;
    }

    if (function_exists('gc_is_group_login') && gc_is_group_login()) {
        return true;
    }

    $groupCode = function_exists('gc_normalize_group_code')
        ? gc_normalize_group_code((string) ($params['group_id'] ?? $params['view_group'] ?? ''))
        : strtoupper(trim((string) ($params['group_id'] ?? $params['view_group'] ?? '')));
    if ($groupCode !== '') {
        if (
            function_exists('gc_session_company_login_has_group_ledger_privilege')
            && gc_session_company_login_has_group_ledger_privilege()
        ) {
            return true;
        }
        if (
            function_exists('gc_session_assigned_group_codes')
            && in_array($groupCode, gc_session_assigned_group_codes(), true)
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{mode: 'group'|'company', group_pk: int, company_id: int, group_code: string}
 */
function tenant_resolve_currency_context(
    PDO $pdo,
    ?int $companyId,
    ?string $groupCode,
    bool $forceGroupLedger = false
): array {
    $groupCode = gc_normalize_group_code($groupCode ?? '');
    $companyId = (int) ($companyId ?? 0);

    if ($groupCode !== '' && $companyId <= 0) {
        $groupPk = gc_resolve_group_pk_by_code($pdo, $groupCode);
        if ($groupPk <= 0) {
            throw new Exception('无效的 group_id');
        }

        $useGroupLedger = $forceGroupLedger || tenant_dual_tenant_enabled($pdo);
        if ($useGroupLedger) {
            $anchorId = gc_resolve_group_anchor_company_id($pdo, $groupCode);
            if ($anchorId <= 0) {
                throw new Exception('缺少 company_id');
            }

            return [
                'mode' => 'group',
                'group_pk' => $groupPk,
                'company_id' => $anchorId,
                'group_code' => $groupCode,
            ];
        }

        $legacyId = gc_resolve_legacy_group_entity_company_id($pdo, $groupCode);
        if ($legacyId > 0) {
            return [
                'mode' => 'company',
                'group_pk' => $groupPk,
                'company_id' => $legacyId,
                'group_code' => $groupCode,
            ];
        }

        $anchorId = gc_resolve_group_anchor_company_id($pdo, $groupCode);
        if ($anchorId <= 0) {
            throw new Exception('缺少 company_id');
        }

        return [
            'mode' => 'group',
            'group_pk' => $groupPk,
            'company_id' => $anchorId,
            'group_code' => $groupCode,
        ];
    }

    if ($companyId <= 0) {
        throw new Exception('缺少公司信息');
    }

    return [
        'mode' => 'company',
        'group_pk' => $groupCode !== '' ? gc_resolve_group_pk_by_code($pdo, $groupCode) : 0,
        'company_id' => $companyId,
        'group_code' => $groupCode,
    ];
}

/**
 * @return array<int, string> currency id => uppercase code
 */
function tenant_load_group_tenant_currency_map(PDO $pdo, string $groupCode): array
{
    $g = gc_normalize_group_code($groupCode);
    if ($g === '') {
        return [];
    }
    $pk = gc_resolve_group_pk_by_code($pdo, $g);
    if ($pk <= 0) {
        return [];
    }

    tenant_reconcile_group_currencies_from_subsidiaries($pdo, $g);

    $map = [];
    if (tenant_table_has_scope_columns($pdo, 'currency')) {
        $stmt = $pdo->prepare("
            SELECT id, UPPER(TRIM(code)) AS code
            FROM currency
            WHERE scope_type = 'group' AND scope_id = ?
            ORDER BY code ASC
        ");
        $stmt->execute([$pk]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($code !== '') {
                $map[(int) $row['id']] = $code;
            }
        }
    }

    if (tenant_table_has_scope_columns($pdo, 'currency')) {
        $anchorId = gc_resolve_group_anchor_company_id($pdo, $g);
        $legacyCtx = [
            'mode' => 'group',
            'group_pk' => $pk,
            'company_id' => $anchorId,
            'group_code' => $g,
        ];
        foreach (tenant_fetch_legacy_group_setting_currency_rows($pdo, $legacyCtx) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $legacyCode = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($id > 0 && $legacyCode !== '' && !isset($map[$id])) {
                $map[$id] = $legacyCode;
            }
        }
    }

    return $map;
}

/**
 * Company.id rows that may hold pre-migration group Currency Setting rows.
 *
 * @return int[]
 */
function tenant_group_currency_legacy_company_ids(PDO $pdo, array $ctx): array
{
    $ids = [];
    $groupCode = gc_normalize_group_code((string) ($ctx['group_code'] ?? ''));
    if ($groupCode !== '') {
        $legacyEntity = gc_resolve_legacy_group_entity_company_id($pdo, $groupCode);
        if ($legacyEntity > 0) {
            $ids[$legacyEntity] = true;
        }
    }

    // Never merge subsidiary anchor (e.g. C168) currencies into group-only ledger.
    return array_values(array_keys($ids));
}

/**
 * Legacy currencies (no scope_type) on group anchor / entity — shown until promoted to scope_type=group.
 *
 * @return array<int, array{id: int, code: string}>
 */
function tenant_fetch_legacy_group_setting_currency_rows(PDO $pdo, array $ctx): array
{
    if (($ctx['mode'] ?? '') !== 'group' || !tenant_table_has_scope_columns($pdo, 'currency')) {
        return [];
    }

    $companyIds = tenant_group_currency_legacy_company_ids($pdo, $ctx);
    if ($companyIds === []) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, code FROM currency
        WHERE company_id IN ({$ph})
          AND (scope_type IS NULL OR TRIM(scope_type) = '')
        ORDER BY code ASC
    ");
    $stmt->execute($companyIds);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $rows[] = [
            'id' => (int) $row['id'],
            'code' => $code,
        ];
    }

    return $rows;
}

/**
 * Promote a legacy anchor currency row to group ledger (scope_type=group).
 */
function tenant_promote_currency_to_group_scope(PDO $pdo, int $currencyId, array $ctx): bool
{
    $groupPk = (int) ($ctx['group_pk'] ?? 0);
    if ($groupPk <= 0 || $currencyId <= 0) {
        return false;
    }

    $allowedCompanies = tenant_group_currency_legacy_company_ids($pdo, $ctx);
    if ($allowedCompanies === []) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT company_id FROM currency WHERE id = ? LIMIT 1');
    $stmt->execute([$currencyId]);
    $rowCompanyId = (int) ($stmt->fetchColumn() ?: 0);
    if ($rowCompanyId <= 0 || !in_array($rowCompanyId, $allowedCompanies, true)) {
        return false;
    }

    $anchorId = (int) ($ctx['company_id'] ?? 0);
    $companyId = $anchorId > 0 ? $anchorId : $rowCompanyId;

    $stmt = $pdo->prepare("
        UPDATE currency
        SET scope_type = 'group', scope_id = ?, company_id = ?
        WHERE id = ?
          AND (scope_type IS NULL OR TRIM(scope_type) = '')
    ");
    $stmt->execute([$groupPk, $companyId, $currencyId]);

    return $stmt->rowCount() > 0;
}

function tenant_table_has_sync_source_column(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_hash($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $cache[$key] = $pdo->query("SHOW COLUMNS FROM `currency` LIKE 'sync_source'")->rowCount() > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

/**
 * @return list<string> uppercase group codes for a subsidiary company.id
 */
function tenant_group_codes_for_company(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $codes = [];
    $stmt = $pdo->prepare('SELECT UPPER(TRIM(COALESCE(group_id, ""))) FROM company WHERE id = ? LIMIT 1');
    $stmt->execute([$companyId]);
    $native = gc_normalize_group_code((string) ($stmt->fetchColumn() ?: ''));
    if ($native !== '') {
        $codes[$native] = true;
    }

    if (function_exists('gc_has_groups_table') && gc_has_groups_table($pdo)) {
        try {
            $mapStmt = $pdo->prepare('
                SELECT UPPER(TRIM(g.group_code))
                FROM group_company_map gcm
                INNER JOIN `groups` g ON g.id = gcm.group_id
                WHERE gcm.company_id = ?
            ');
            $mapStmt->execute([$companyId]);
            foreach ($mapStmt->fetchAll(PDO::FETCH_COLUMN) as $groupCode) {
                $norm = gc_normalize_group_code((string) $groupCode);
                if ($norm !== '') {
                    $codes[$norm] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return array_keys($codes);
}

/**
 * @return list<string> uppercase currency codes on subsidiaries under a group
 */
function tenant_subsidiary_currency_codes_for_group(PDO $pdo, string $groupCode): array
{
    $groupCode = gc_normalize_group_code($groupCode);
    if ($groupCode === '') {
        return [];
    }

    $codes = [];
    foreach (gc_company_numeric_ids_for_group_code($pdo, $groupCode) as $companyId) {
        $stmt = $pdo->prepare(
            'SELECT UPPER(TRIM(code)) AS code FROM currency WHERE company_id = ?'
            . tenant_sql_currency_subsidiary_only($pdo)
        );
        $stmt->execute([(int) $companyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $up = strtoupper(trim((string) $code));
            if ($up !== '') {
                $codes[$up] = true;
            }
        }
    }

    return array_keys($codes);
}

function tenant_subsidiary_has_currency_code(PDO $pdo, string $groupCode, string $code): bool
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return false;
    }

    foreach (gc_company_numeric_ids_for_group_code($pdo, $groupCode) as $companyId) {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM currency WHERE company_id = ? AND UPPER(TRIM(code)) = ?'
            . tenant_sql_currency_subsidiary_only($pdo)
            . ' LIMIT 1'
        );
        $stmt->execute([(int) $companyId, $code]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

/**
 * Ensure a group-ledger currency exists because a subsidiary added it (sync_source=subsidiary).
 */
function tenant_ensure_group_currency_from_subsidiary(PDO $pdo, string $groupCode, string $code): void
{
    if (!tenant_dual_tenant_enabled($pdo)) {
        return;
    }

    $code = strtoupper(trim($code));
    $groupCode = gc_normalize_group_code($groupCode);
    if ($code === '' || $groupCode === '') {
        return;
    }

    $groupPk = gc_resolve_group_pk_by_code($pdo, $groupCode);
    if ($groupPk <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id FROM currency
        WHERE scope_type = 'group' AND scope_id = ? AND UPPER(TRIM(code)) = ?
        LIMIT 1
    ");
    $stmt->execute([$groupPk, $code]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $anchorId = gc_resolve_group_anchor_company_id($pdo, $groupCode);
    if ($anchorId <= 0) {
        return;
    }

    $ctx = [
        'mode' => 'group',
        'group_pk' => $groupPk,
        'company_id' => $anchorId,
        'group_code' => $groupCode,
        'sync_source' => 'subsidiary',
    ];

    try {
        tenant_create_currency($pdo, $code, $ctx);
    } catch (Exception $e) {
        // Row may have been created concurrently or manual row already exists.
    }
}

/** Propagate a new subsidiary currency to parent group ledger rows. */
function tenant_sync_company_currency_to_parent_groups(PDO $pdo, int $companyId, string $code): void
{
    $code = strtoupper(trim($code));
    if ($companyId <= 0 || $code === '') {
        return;
    }

    foreach (tenant_group_codes_for_company($pdo, $companyId) as $groupCode) {
        tenant_ensure_group_currency_from_subsidiary($pdo, $groupCode, $code);
    }
}

/**
 * When no subsidiary holds a code anymore, subsidiary-synced group rows become manual (deletable).
 */
function tenant_reconcile_group_currency_sync_source(PDO $pdo, string $groupCode, string $code): void
{
    if (!tenant_table_has_sync_source_column($pdo) || !tenant_dual_tenant_enabled($pdo)) {
        return;
    }

    $code = strtoupper(trim($code));
    $groupCode = gc_normalize_group_code($groupCode);
    if ($code === '' || $groupCode === '') {
        return;
    }

    if (tenant_subsidiary_has_currency_code($pdo, $groupCode, $code)) {
        return;
    }

    $groupPk = gc_resolve_group_pk_by_code($pdo, $groupCode);
    if ($groupPk <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE currency
        SET sync_source = 'manual'
        WHERE scope_type = 'group'
          AND scope_id = ?
          AND UPPER(TRIM(code)) = ?
          AND sync_source = 'subsidiary'
    ");
    $stmt->execute([$groupPk, $code]);
}

function tenant_reconcile_groups_after_company_currency_deleted(PDO $pdo, int $companyId, string $code): void
{
    foreach (tenant_group_codes_for_company($pdo, $companyId) as $groupCode) {
        tenant_reconcile_group_currency_sync_source($pdo, $groupCode, $code);
    }
}

/**
 * Lazy reconcile on group-ledger reads: mirror subsidiary Currency Setting rows into scope_type=group.
 */
function tenant_reconcile_group_currencies_from_subsidiaries(PDO $pdo, string $groupCode): void
{
    if (!tenant_dual_tenant_enabled($pdo)) {
        return;
    }

    $groupCode = gc_normalize_group_code($groupCode);
    if ($groupCode === '') {
        return;
    }

    $groupPk = gc_resolve_group_pk_by_code($pdo, $groupCode);
    if ($groupPk <= 0) {
        return;
    }

    $hasSyncSource = tenant_table_has_sync_source_column($pdo);

    foreach (tenant_subsidiary_currency_codes_for_group($pdo, $groupCode) as $code) {
        $existingStmt = $pdo->prepare("
            SELECT id, sync_source
            FROM currency
            WHERE scope_type = 'group'
              AND scope_id = ?
              AND UPPER(TRIM(code)) = ?
            LIMIT 1
        ");
        $existingStmt->execute([$groupPk, $code]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            tenant_ensure_group_currency_from_subsidiary($pdo, $groupCode, $code);
            continue;
        }

        if (
            $hasSyncSource
            && strtolower(trim((string) ($existing['sync_source'] ?? 'manual'))) !== 'subsidiary'
            && tenant_subsidiary_has_currency_code($pdo, $groupCode, $code)
        ) {
            $upd = $pdo->prepare("
                UPDATE currency
                SET sync_source = 'subsidiary'
                WHERE id = ?
                  AND scope_type = 'group'
                  AND scope_id = ?
            ");
            $upd->execute([(int) ($existing['id'] ?? 0), $groupPk]);
        }
    }
}

function tenant_currency_sync_source_is_deletable(?string $syncSource, array $ctx): bool
{
    if (($ctx['mode'] ?? '') !== 'group') {
        return true;
    }

    return strtolower(trim((string) ($syncSource ?? 'manual'))) !== 'subsidiary';
}

/**
 * @param array{id: int, code: string, sync_source?: string} $row
 * @return array{id: int, code: string, sync_source?: string, deletable: bool}
 */
function tenant_enrich_currency_row_meta(PDO $pdo, array $row, array $ctx): array
{
    $hasSyncSource = tenant_table_has_sync_source_column($pdo);
    $syncSource = 'manual';
    if ($hasSyncSource && isset($row['sync_source'])) {
        $syncSource = strtolower(trim((string) $row['sync_source'])) === 'subsidiary' ? 'subsidiary' : 'manual';
    }

    $out = [
        'id' => (int) ($row['id'] ?? 0),
        'code' => strtoupper(trim((string) ($row['code'] ?? ''))),
        'deletable' => tenant_currency_sync_source_is_deletable($syncSource, $ctx),
    ];
    if ($hasSyncSource) {
        $out['sync_source'] = $syncSource;
    }

    return $out;
}

/**
 * Create currency on group tenant (scope_type=group). company_id column keeps anchor subsidiary for NOT NULL FK.
 *
 * @return array{id: int, code: string}
 */
function tenant_create_currency(PDO $pdo, string $code, array $ctx): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        throw new Exception('Currency code is required');
    }

    $companyId = (int) ($ctx['company_id'] ?? 0);
    if ($companyId <= 0) {
        throw new Exception('缺少公司信息');
    }

    $hasScope = tenant_table_has_scope_columns($pdo, 'currency');
    $requestedSyncSource = strtolower(trim((string) ($ctx['sync_source'] ?? 'manual'))) === 'subsidiary'
        ? 'subsidiary'
        : 'manual';

    if (($ctx['mode'] ?? '') === 'group' && $hasScope) {
        $groupPk = (int) ($ctx['group_pk'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT id FROM currency
            WHERE scope_type = 'group' AND scope_id = ? AND UPPER(TRIM(code)) = ?
            LIMIT 1
        ");
        $stmt->execute([$groupPk, $code]);
        $existing = (int) ($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            return ['id' => $existing, 'code' => $code];
        }

        foreach (tenant_fetch_legacy_group_setting_currency_rows($pdo, $ctx) as $legacyRow) {
            if (strtoupper(trim((string) ($legacyRow['code'] ?? ''))) !== $code) {
                continue;
            }
            $legacyId = (int) ($legacyRow['id'] ?? 0);
            if ($legacyId > 0 && tenant_promote_currency_to_group_scope($pdo, $legacyId, $ctx)) {
                if (tenant_table_has_sync_source_column($pdo)) {
                    $upd = $pdo->prepare('UPDATE currency SET sync_source = ? WHERE id = ?');
                    $upd->execute([$requestedSyncSource, $legacyId]);
                }
                return ['id' => $legacyId, 'code' => $code];
            }
        }

        try {
            if (tenant_table_has_sync_source_column($pdo)) {
                $stmt = $pdo->prepare("
                    INSERT INTO currency (code, company_id, scope_type, scope_id, sync_source)
                    VALUES (?, ?, 'group', ?, ?)
                ");
                $stmt->execute([$code, $companyId, $groupPk, $requestedSyncSource]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO currency (code, company_id, scope_type, scope_id)
                    VALUES (?, ?, 'group', ?)
                ");
                $stmt->execute([$code, $companyId, $groupPk]);
            }
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $findStmt = $pdo->prepare("
                    SELECT id FROM currency
                    WHERE scope_type = 'group' AND scope_id = ? AND UPPER(TRIM(code)) = ?
                    LIMIT 1
                ");
                $findStmt->execute([$groupPk, $code]);
                $existing = (int) ($findStmt->fetchColumn() ?: 0);
                if ($existing > 0) {
                    return ['id' => $existing, 'code' => $code];
                }
                foreach (tenant_fetch_legacy_group_setting_currency_rows($pdo, $ctx) as $legacyRow) {
                    if (strtoupper(trim((string) ($legacyRow['code'] ?? ''))) !== $code) {
                        continue;
                    }
                    $legacyId = (int) ($legacyRow['id'] ?? 0);
                    if ($legacyId > 0 && tenant_promote_currency_to_group_scope($pdo, $legacyId, $ctx)) {
                        return ['id' => $legacyId, 'code' => $code];
                    }
                }
                throw new Exception(
                    'Currency ' . $code . ' already exists for this group. '
                    . 'If creation still fails, run database/migrations/20260604_currency_scope_unique.sql on the server.'
                );
            }
            throw $e;
        }
    } else {
        $stmt = $pdo->prepare('SELECT id FROM currency WHERE code = ? AND company_id = ?');
        $stmt->execute([$code, $companyId]);
        if ($stmt->fetchColumn()) {
            throw new Exception('Currency ' . $code . ' already exists');
        }
        $stmt = $pdo->prepare('INSERT INTO currency (code, company_id) VALUES (?, ?)');
        $stmt->execute([$code, $companyId]);
        tenant_sync_company_currency_to_parent_groups($pdo, $companyId, $code);
    }

    return ['id' => (int) $pdo->lastInsertId(), 'code' => $code];
}

/**
 * @return array{code: string}|null
 */
function tenant_get_currency_row(PDO $pdo, int $currencyId, array $ctx): ?array
{
    if (!tenant_currency_belongs_to_context($pdo, $currencyId, $ctx)) {
        return null;
    }
    $cols = tenant_table_has_sync_source_column($pdo) ? 'code, sync_source' : 'code';
    $stmt = $pdo->prepare("SELECT {$cols} FROM currency WHERE id = ? LIMIT 1");
    $stmt->execute([$currencyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function tenant_delete_currency(PDO $pdo, int $currencyId, array $ctx): int
{
    if (($ctx['mode'] ?? '') === 'group' && tenant_table_has_scope_columns($pdo, 'currency')) {
        $stmt = $pdo->prepare("
            DELETE FROM currency
            WHERE id = ? AND scope_type = 'group' AND scope_id = ?
        ");
        $stmt->execute([$currencyId, (int) ($ctx['group_pk'] ?? 0)]);
    } else {
        $companyId = (int) ($ctx['company_id'] ?? 0);
        $stmt = $pdo->prepare(
            'DELETE FROM currency WHERE id = ? AND company_id = ?'
            . tenant_sql_currency_subsidiary_only($pdo)
        );
        $stmt->execute([$currencyId, $companyId]);
    }

    return $stmt->rowCount();
}

/**
 * @return array<int, array{id: int, name: string, account_id: string}>
 */
function tenant_get_accounts_using_currency(PDO $pdo, int $currencyId, array $ctx): array
{
    $isGroup = (($ctx['mode'] ?? '') === 'group');
    $groupPk = (int) ($ctx['group_pk'] ?? 0);
    $companyId = (int) ($ctx['company_id'] ?? 0);
    $accounts = [];

    if (!tableExistsForTenant($pdo, 'account_currency')) {
        return [];
    }

    if ($isGroup && $groupPk > 0) {
        $groupAccountIds = tenant_collect_group_account_ids($pdo, $groupPk);
        if ($groupAccountIds === []) {
            return [];
        }
        $idPh = implode(',', array_fill(0, count($groupAccountIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT a.id, a.name, a.account_id
            FROM account_currency ac
            INNER JOIN account a ON a.id = ac.account_id
            WHERE ac.currency_id = ? AND a.id IN ($idPh)
            ORDER BY a.name ASC, a.account_id ASC
        ");
        $stmt->execute(array_merge([$currencyId], $groupAccountIds));

        return tenant_normalize_account_usage_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if (!tableExistsForTenant($pdo, 'account_company')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT a.id, a.name, a.account_id
        FROM account_currency ac
        INNER JOIN account a ON a.id = ac.account_id
        INNER JOIN account_company acc ON a.id = acc.account_id
        WHERE ac.currency_id = ? AND acc.company_id = ?"
        . tenant_sql_account_company_subsidiary_only($pdo, 'acc')
        . ' ORDER BY a.name ASC, a.account_id ASC
    ');
    $stmt->execute([$currencyId, $companyId]);

    return tenant_normalize_account_usage_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array{id: int, name: string, account_id: string}>
 */
function tenant_normalize_account_usage_rows(array $rows): array
{
    $normalized = [];
    foreach ($rows as $row) {
        $normalized[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'account_id' => (string) ($row['account_id'] ?? ''),
        ];
    }

    return $normalized;
}

/** @internal */
function tableExistsForTenant(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($tableName));

        return $stmt !== false && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<int, array{id: int, code: string}>
 */
function tenant_fetch_currencies(PDO $pdo, array $ctx): array
{
    $companyId = (int) ($ctx['company_id'] ?? 0);
    if ($companyId <= 0) {
        return [];
    }

    $syncCol = tenant_table_has_sync_source_column($pdo) ? ', sync_source' : '';
    if (($ctx['mode'] ?? '') === 'group' && tenant_table_has_scope_columns($pdo, 'currency')) {
        $groupCode = gc_normalize_group_code((string) ($ctx['group_code'] ?? ''));
        if ($groupCode !== '') {
            tenant_reconcile_group_currencies_from_subsidiaries($pdo, $groupCode);
        }
        $groupPk = (int) ($ctx['group_pk'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT id, code{$syncCol} FROM currency
            WHERE scope_type = 'group' AND scope_id = ?
            ORDER BY code ASC
        ");
        $stmt->execute([$groupPk]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, code' . $syncCol . ' FROM currency WHERE company_id = ?'
            . tenant_sql_currency_subsidiary_only($pdo)
            . ' ORDER BY code ASC'
        );
        $stmt->execute([$companyId]);
    }

    $rows = [];
    $seenCodes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $seenCodes[$code] = true;
        $rows[] = tenant_enrich_currency_row_meta($pdo, [
            'id' => (int) $row['id'],
            'code' => $code,
            'sync_source' => $row['sync_source'] ?? 'manual',
        ], $ctx);
    }

    if (($ctx['mode'] ?? '') === 'group' && tenant_table_has_scope_columns($pdo, 'currency')) {
        foreach (tenant_fetch_legacy_group_setting_currency_rows($pdo, $ctx) as $legacyRow) {
            $code = strtoupper(trim((string) ($legacyRow['code'] ?? '')));
            if ($code === '' || isset($seenCodes[$code])) {
                continue;
            }
            $seenCodes[$code] = true;
            $rows[] = tenant_enrich_currency_row_meta($pdo, [
                'id' => (int) $legacyRow['id'],
                'code' => $code,
                'sync_source' => 'manual',
            ], $ctx);
        }
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        });
    }

    return $rows;
}

/**
 * Resolve currency tenant from HTTP-style params (group_only / group_id / company_id).
 *
 * @param array<string, mixed> $params
 * @return array{mode: 'group'|'company', group_pk: int, company_id: int, group_code: string}
 */
function tenant_resolve_currency_context_from_request(PDO $pdo, array $params): array
{
    $params = tenant_normalize_scope_params($params);

    $groupCode = gc_normalize_group_code((string) ($params['group_id'] ?? $params['view_group'] ?? ''));
    $requestedRaw = $params['company_id'] ?? null;
    $requestedId = ($requestedRaw !== null && trim((string) $requestedRaw) !== '') ? (int) $requestedRaw : 0;
    $groupOnly = !empty($params['group_only'])
        && filter_var($params['group_only'], FILTER_VALIDATE_BOOLEAN);

    if ($groupOnly) {
        $requestedId = 0;
    }
    $forceGroupLedger = $groupOnly;
    if (
        !$groupOnly
        && $requestedId <= 0
        && function_exists('gc_is_group_login')
        && gc_is_group_login()
    ) {
        $forceGroupLedger = true;
        $groupOnly = true;
        if ($groupCode === '') {
            $groupCode = gc_normalize_group_code((string) ($_SESSION['login_identifier'] ?? ''));
        }
    }

    if (
        !$groupOnly
        && $requestedId <= 0
        && $groupCode !== ''
        && function_exists('gc_session_can_access_group_ledger')
        && gc_session_can_access_group_ledger($pdo, $groupCode)
    ) {
        $forceGroupLedger = true;
        $groupOnly = true;
    }

    // Subsidiary company pill wins: never map to legacy group-entity company row.
    if ($requestedId > 0 && !$groupOnly) {
        return tenant_resolve_currency_context(
            $pdo,
            $requestedId,
            $groupCode !== '' ? $groupCode : null,
            false
        );
    }

    if ($groupCode !== '') {
        return tenant_resolve_currency_context($pdo, null, $groupCode, $forceGroupLedger);
    }

    // Group-only UI must not fall back to PHP session subsidiary (anchor sync for sidebar).
    if ($groupOnly || $forceGroupLedger) {
        throw new Exception('缺少 group_id');
    }

    $sessionId = (int) ($params['session_company_id'] ?? 0);
    if ($sessionId <= 0) {
        throw new Exception('缺少公司信息');
    }

    return tenant_resolve_currency_context($pdo, $sessionId, null, false);
}

/** Force group ledger for group login or explicit group_only with ledger permission. */
function tenant_account_api_force_group_ledger(): bool
{
    if (function_exists('gc_is_group_login') && gc_is_group_login()) {
        return true;
    }

    if (
        !empty($_GET['group_only'])
        && filter_var($_GET['group_only'], FILTER_VALIDATE_BOOLEAN)
        && function_exists('gc_session_can_use_group_ledger')
    ) {
        return gc_session_can_use_group_ledger();
    }

    return false;
}

/** SQL AND: subsidiary currency rows only (exclude group ledger rows sharing anchor company_id). */
function tenant_sql_currency_subsidiary_only(PDO $pdo, string $alias = ''): string
{
    if (!tenant_table_has_scope_columns($pdo, 'currency')) {
        return '';
    }
    $col = $alias !== '' ? "{$alias}.scope_type" : 'scope_type';

    return " AND ({$col} IS NULL OR TRIM({$col}) = '' OR {$col} = 'company')";
}

function tenant_currency_belongs_to_context(PDO $pdo, int $currencyId, array $ctx): bool
{
    if ($currencyId <= 0) {
        return false;
    }
    if (($ctx['mode'] ?? '') === 'group' && tenant_table_has_scope_columns($pdo, 'currency')) {
        $stmt = $pdo->prepare("
            SELECT id FROM currency
            WHERE id = ? AND scope_type = 'group' AND scope_id = ?
            LIMIT 1
        ");
        $stmt->execute([$currencyId, (int) ($ctx['group_pk'] ?? 0)]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $allowedCompanies = tenant_group_currency_legacy_company_ids($pdo, $ctx);
        if ($allowedCompanies === []) {
            return false;
        }
        $ph = implode(',', array_fill(0, count($allowedCompanies), '?'));
        $legacyStmt = $pdo->prepare("
            SELECT id FROM currency
            WHERE id = ?
              AND company_id IN ({$ph})
              AND (scope_type IS NULL OR TRIM(scope_type) = '')
            LIMIT 1
        ");
        $legacyStmt->execute(array_merge([$currencyId], $allowedCompanies));

        return (bool) $legacyStmt->fetchColumn();
    }
    $companyId = (int) ($ctx['company_id'] ?? 0);
    if ($companyId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT id FROM currency WHERE id = ? AND company_id = ?'
        . tenant_sql_currency_subsidiary_only($pdo)
        . ' LIMIT 1'
    );
    $stmt->execute([$currencyId, $companyId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return list<int>
 */
function tenant_collect_group_account_ids(PDO $pdo, int $groupPk): array
{
    if ($groupPk <= 0) {
        return [];
    }
    $ids = [];
    if (tenant_table_has_scope_columns($pdo, 'account_company')) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT account_id FROM account_company
            WHERE scope_type = 'group' AND scope_id = ?
        ");
        $stmt->execute([$groupPk]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = true;
        }
    }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'account_group_map'")->rowCount() > 0) {
            $stmt = $pdo->prepare('SELECT DISTINCT account_id FROM account_group_map WHERE group_id = ?');
            $stmt->execute([$groupPk]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[(int) $id] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return array_values(array_filter(array_map('intval', array_keys($ids)), static fn (int $id): bool => $id > 0));
}

function tenant_group_code_from_pk(PDO $pdo, int $groupPk): string
{
    if ($groupPk <= 0 || !gc_has_groups_table($pdo)) {
        return '';
    }
    $codeStmt = $pdo->prepare('SELECT group_code FROM `groups` WHERE id = ? LIMIT 1');
    $codeStmt->execute([$groupPk]);

    return gc_normalize_group_code((string) ($codeStmt->fetchColumn() ?: ''));
}

/**
 * Whether the account row is on the group ledger (account_company.scope_type = group).
 *
 * @return array{mode: 'group'|'company', group_code: string, group_pk: int}
 */
function tenant_resolve_account_ledger_scope(PDO $pdo, int $accountId): array
{
    $default = ['mode' => 'company', 'group_code' => '', 'group_pk' => 0];
    if ($accountId <= 0) {
        return $default;
    }

    $groupPk = 0;
    if (tenant_table_has_scope_columns($pdo, 'account_company')) {
        $stmt = $pdo->prepare("
            SELECT scope_id
            FROM account_company
            WHERE account_id = ? AND scope_type = 'group' AND scope_id > 0
            LIMIT 1
        ");
        $stmt->execute([$accountId]);
        $groupPk = (int) ($stmt->fetchColumn() ?: 0);
    }

    if ($groupPk <= 0) {
        try {
            if ($pdo->query("SHOW TABLES LIKE 'account_group_map'")->rowCount() > 0) {
                $stmt = $pdo->prepare('SELECT group_id FROM account_group_map WHERE account_id = ? LIMIT 1');
                $stmt->execute([$accountId]);
                $groupPk = (int) ($stmt->fetchColumn() ?: 0);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($groupPk <= 0) {
        return $default;
    }

    $groupCode = tenant_group_code_from_pk($pdo, $groupPk);

    return ['mode' => 'group', 'group_code' => $groupCode, 'group_pk' => $groupPk];
}

/**
 * Currency API context for an account (group ledger vs subsidiary), ignoring page filter.
 *
 * @return array{mode: 'group'|'company', group_pk: int, company_id: int, group_code: string}|null
 */
function tenant_resolve_currency_context_for_account(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0) {
        return null;
    }
    $ledger = tenant_resolve_account_ledger_scope($pdo, $accountId);
    if (($ledger['mode'] ?? '') !== 'group') {
        return null;
    }
    $groupCode = (string) ($ledger['group_code'] ?? '');
    if ($groupCode === '' && (int) ($ledger['group_pk'] ?? 0) > 0) {
        $groupCode = tenant_group_code_from_pk($pdo, (int) $ledger['group_pk']);
    }
    if ($groupCode === '') {
        return null;
    }

    return tenant_resolve_currency_context($pdo, null, $groupCode, true);
}

function tenant_account_belongs_to_context(PDO $pdo, int $accountId, array $ctx): bool
{
    if ($accountId <= 0) {
        return false;
    }
    if (($ctx['mode'] ?? '') === 'group') {
        return in_array($accountId, tenant_collect_group_account_ids($pdo, (int) ($ctx['group_pk'] ?? 0)), true);
    }
    $companyId = (int) ($ctx['company_id'] ?? 0);
    if ($companyId <= 0) {
        return false;
    }
    $acWhere = tenant_account_company_subsidiary_where($pdo, $companyId, 'ac');
    $stmt = $pdo->prepare('
        SELECT a.id FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE a.id = ? AND ' . $acWhere['sql'] . '
        LIMIT 1
    ');
    $stmt->execute(array_merge([$accountId], $acWhere['params']));

    return (bool) $stmt->fetchColumn();
}

/**
 * SQL AND fragment: subsidiary company membership only (exclude group ledger rows that reuse anchor company_id).
 */
function tenant_sql_account_company_subsidiary_only(PDO $pdo, string $alias = 'ac'): string
{
    if (!tenant_table_has_scope_columns($pdo, 'account_company')) {
        return '';
    }

    return " AND ({$alias}.scope_type IS NULL OR TRIM({$alias}.scope_type) = '' OR {$alias}.scope_type = 'company')";
}

/**
 * WHERE fragment + bind params: account_company row belongs to one subsidiary (dual-tenant safe).
 *
 * @return array{sql: string, params: array<int>}
 */
function tenant_account_company_subsidiary_where(PDO $pdo, int $companyId, string $alias = 'ac'): array
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'ac';
    if ($companyId <= 0) {
        return ['sql' => '1=0', 'params' => []];
    }
    $subOnly = tenant_sql_account_company_subsidiary_only($pdo, $a);
    if (tenant_table_has_scope_columns($pdo, 'account_company')) {
        // Dual-tenant: company_id FK may be shared anchor; scope_id is the subsidiary key.
        return [
            'sql' => "COALESCE(NULLIF({$a}.scope_id, 0), {$a}.company_id) = ?{$subOnly}",
            'params' => [$companyId],
        ];
    }

    return [
        'sql' => "{$a}.company_id = ?{$subOnly}",
        'params' => [$companyId],
    ];
}

function tenant_link_account_group_scope(PDO $pdo, int $accountId, int $groupPk, int $anchorCompanyId): void
{
    if ($groupPk <= 0 || $anchorCompanyId <= 0) {
        throw new Exception('无效的集团范围');
    }
    if (!tenant_table_has_scope_columns($pdo, 'account_company')) {
        $stmt = $pdo->prepare('INSERT INTO account_company (account_id, company_id) VALUES (?, ?)');
        $stmt->execute([$accountId, $anchorCompanyId]);
        return;
    }

    $stmt = $pdo->prepare('
        SELECT id FROM account_company
        WHERE account_id = ? AND scope_type = ? AND scope_id = ?
        LIMIT 1
    ');
    $stmt->execute([$accountId, 'group', $groupPk]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO account_company (account_id, company_id, scope_type, scope_id)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$accountId, $anchorCompanyId, 'group', $groupPk]);
}
