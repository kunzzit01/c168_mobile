<?php
session_start();
// session_write_close() 将在 session 写入（回填 company_code）完成后调用
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/password_hashing.php';
require_once __DIR__ . '/../../includes/email_validation.php';
require_once __DIR__ . '/../../includes/tenant_scope.php';
require_once __DIR__ . '/../c168/c168_domain_access.php';
require_once __DIR__ . '/../includes/money_decimal.php';
require_once __DIR__ . '/domain_groups_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function domain_api_clear_session_user_cache(): void
{
    static $loaded = false;
    if (!$loaded) {
        $path = __DIR__ . '/../../includes/session_user_payload_cache.php';
        if (is_file($path)) {
            require_once $path;
        }
        $loaded = true;
    }
    if (function_exists('session_user_payload_cache_clear')) {
        session_user_payload_cache_clear();
    }
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

$action = $data['action'] ?? '';

// 检查用户是否已登录（对于需要权限的操作）
if (in_array($action, ['list', 'create', 'update', 'delete', 'validate_domain_code', 'get_domain_fee_settings', 'save_domain_fee_settings', 'get_company_share_settings', 'save_company_share_settings', 'save_group_share_settings', 'save_group_tenant_settings'], true)) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not logged in', 'data' => null]);
        exit;
    }

    // 根据 company_id 回填 company_code（Remember me、仅更新了 id 等情况下 session 可能缺 code，导致误判非 C168）
    $sessCoId = $_SESSION['company_id'] ?? null;
    if ($sessCoId) {
        try {
            $ccStmt = $pdo->prepare('SELECT company_id FROM company WHERE id = ? LIMIT 1');
            $ccStmt->execute([(int) $sessCoId]);
            $ccVal = $ccStmt->fetchColumn();
            if ($ccVal !== false && $ccVal !== null && trim((string) $ccVal) !== '') {
                $_SESSION['company_code'] = trim((string) $ccVal);
            }
        } catch (PDOException $e) {
            // ignore
        }
    }
    // session 写入完成，立即释放锁，允许并发请求执行
    session_write_close();
    
    // C168：$canUseC168DomainActions = userlist 角色白名单；修改他人二级密码仍用 $isOwnerOrAdmin
    $user_role = strtolower($_SESSION['role'] ?? '');
    $company_id = $_SESSION['company_id'] ?? null;
    $company_code = strtoupper($_SESSION['company_code'] ?? '');
    
    $isOwnerOrAdmin = in_array($user_role, ['owner', 'admin'], true);
    $isC168ByCode = ($company_code === 'C168');
    $isC168ById = isC168Company($pdo, $company_id);
    $hasC168Context = ($isC168ByCode || $isC168ById);
    $canUseC168DomainActions = $hasC168Context && userHasC168DomainPageAccess($user_role);
} else {
    // 不需要写 session，直接释放锁
    session_write_close();
}

/**
 * 将 ID 数组标准化为唯一的整型列表
 */
function normalizeIds(array $ids): array
{
    $normalized = [];
    foreach ($ids as $id) {
        if ($id === null || $id === '') {
            continue;
        }
        $normalized[] = (int)$id;
    }
    return array_values(array_unique($normalized));
}

/**
 * 根据给定 SQL 查询返回整型 ID 列
 */
function fetchIds(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return normalizeIds($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 为 IN 语句生成占位符
 */
function buildInPlaceholders(int $count): string
{
    return implode(',', array_fill(0, $count, '?'));
}

/**
 * 删除指定表中匹配 ID 的记录
 */
function deleteByIds(PDO $pdo, string $table, string $column, array $ids): void
{
    $ids = normalizeIds($ids);
    if (empty($ids)) {
        return;
    }
    
    $placeholders = buildInPlaceholders(count($ids));

    // transactions 需要先清理子表 transaction_entry，避免外键约束失败
    if ($table === 'transactions') {
        $txnIds = fetchIds(
            $pdo,
            sprintf("SELECT `id` FROM `transactions` WHERE `%s` IN (%s)", $column, $placeholders),
            $ids
        );

        if (!empty($txnIds)) {
            try {
                $hasEntry = $pdo->query("SHOW TABLES LIKE 'transaction_entry'")->rowCount() > 0;
                if ($hasEntry) {
                    $txnPh = buildInPlaceholders(count($txnIds));
                    $delEntry = $pdo->prepare("DELETE FROM `transaction_entry` WHERE `header_id` IN ($txnPh)");
                    $delEntry->execute($txnIds);
                }
            } catch (Exception $e) {
                // 保持旧环境兼容：如果不支持/不存在则忽略
            }
        }
    }

    $sql = sprintf("DELETE FROM `%s` WHERE `%s` IN (%s)", $table, $column, $placeholders);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
}

function domainApiTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        return $stmt && $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function domainApiDeleteRowsByCompanyIds(PDO $pdo, string $table, array $companyDbIds): void
{
    deleteByIds($pdo, $table, 'company_id', $companyDbIds);
}

/**
 * Remove domain-provisioned MEMBER accounts on C168 when a tenant company code is deleted.
 *
 * @param string[] $codes
 */
function domainApiDeleteC168ProvisionedMemberAccountsByCodes(PDO $pdo, array $codes): void
{
    $codes = array_values(array_unique(array_filter(array_map(
        static fn ($raw) => strtoupper(trim((string) $raw)),
        $codes
    ), static fn ($c) => $c !== '' && $c !== 'C168')));
    if ($codes === []) {
        return;
    }

    $c168Pk = resolveC168TargetCompanyId($pdo) ?? getMasterC168CompanyNumericId($pdo);
    if (!$c168Pk || (int) $c168Pk <= 0) {
        return;
    }
    $c168Pk = (int) $c168Pk;

    $findStmt = $pdo->prepare("
        SELECT a.id
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
          AND UPPER(TRIM(a.account_id)) = ?
        LIMIT 1
    ");

    foreach ($codes as $code) {
        $findStmt->execute([$c168Pk, $code]);
        $accId = (int) ($findStmt->fetchColumn() ?: 0);
        if ($accId <= 0 || !domainApiAccountLooksLikeDomainProvisionedMember($pdo, $accId)) {
            continue;
        }
        deleteByIds($pdo, 'account_link', 'account_id_1', [$accId]);
        deleteByIds($pdo, 'account_link', 'account_id_2', [$accId]);
        deleteByIds($pdo, 'account_company', 'account_id', [$accId]);
        deleteByIds($pdo, 'account', 'id', [$accId]);
    }
}

/**
 * Returns a user-facing reason when company still has ledger / operational data and must not be removed from domain.
 */
function domainApiCompanyOperationalBlockReason(PDO $pdo, int $companyDbId, string $companyCode = ''): ?string
{
    if ($companyDbId <= 0) {
        return null;
    }

    $label = strtoupper(trim($companyCode));
    if ($label === '') {
        $label = 'ID ' . $companyDbId;
    }

    $countFor = static function (PDO $pdo, string $table, string $whereSql, array $params): int {
        if (!domainApiTableExists($pdo, $table)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$whereSql}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    };

    $parts = [];
    $txCount = $countFor($pdo, 'transactions', 'company_id = ?', [$companyDbId]);
    if ($txCount > 0) {
        $parts[] = $txCount . ' transaction(s) (Transaction Payment / ledger)';
    }
    $accCount = $countFor($pdo, 'account_company', 'company_id = ?', [$companyDbId]);
    if ($accCount > 0) {
        $parts[] = $accCount . ' linked account(s)';
    }
    $capCount = $countFor($pdo, 'data_captures', 'company_id = ?', [$companyDbId]);
    if ($capCount > 0) {
        $parts[] = $capCount . ' data capture(s)';
    }
    $procCount = $countFor($pdo, 'process', 'company_id = ?', [$companyDbId]);
    if ($procCount > 0) {
        $parts[] = $procCount . ' process(es)';
    }
    if (domainApiTableExists($pdo, 'bank_process')) {
        $bpCount = $countFor($pdo, 'bank_process', 'company_id = ?', [$companyDbId]);
        if ($bpCount > 0) {
            $parts[] = $bpCount . ' bank process(es)';
        }
    }
    if (domainApiTableExists($pdo, 'company_ownership')) {
        $ownCount = $countFor($pdo, 'company_ownership', 'company_id = ?', [$companyDbId]);
        if ($ownCount > 0) {
            $parts[] = $ownCount . ' ownership record(s)';
        }
    }

    if ($parts === []) {
        return null;
    }

    return 'Cannot remove company "' . $label . '" from domain — it still has '
        . implode(', ', $parts)
        . '. It will be detached from this domain; accounts and payment history are kept.';
}

function domainApiCompanyHasOperationalData(PDO $pdo, int $companyDbId): bool
{
    return domainApiCompanyOperationalBlockReason($pdo, $companyDbId, '') !== null;
}

/**
 * @param array<int, array<string, mixed>> $companyRows
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
 */
function domainApiPartitionCompaniesForDomainRemoval(PDO $pdo, array $companyRows): array
{
    $detach = [];
    $hardDelete = [];
    foreach ($companyRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (domainApiCompanyHasOperationalData($pdo, (int) ($row['id'] ?? 0))) {
            $detach[] = $row;
        } else {
            $hardDelete[] = $row;
        }
    }
    return [$detach, $hardDelete];
}

function ensureCompanyOwnerIdNullable(PDO $pdo): void
{
    try {
        $col = $pdo->query("SHOW COLUMNS FROM company LIKE 'owner_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$col || stripos((string) ($col['Null'] ?? ''), 'YES') !== false) {
            return;
        }
        $fkName = $pdo->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'company'
              AND COLUMN_NAME = 'owner_id'
              AND REFERENCED_TABLE_NAME = 'owner'
            LIMIT 1
        ")->fetchColumn();
        if ($fkName) {
            $safeFk = str_replace('`', '', (string) $fkName);
            $pdo->exec("ALTER TABLE company DROP FOREIGN KEY `{$safeFk}`");
        }
        $pdo->exec("ALTER TABLE company MODIFY owner_id int UNSIGNED NULL COMMENT 'FK to owner.id; NULL when detached from domain'");
        $pdo->exec('ALTER TABLE company ADD CONSTRAINT fk_company_owner FOREIGN KEY (owner_id) REFERENCES owner(id) ON DELETE CASCADE ON UPDATE CASCADE');
    } catch (PDOException $e) {
        error_log('ensureCompanyOwnerIdNullable: ' . $e->getMessage());
    }
}

/**
 * Detach companies from domain: clear owner_id / group_id, keep accounts + transactions + captures.
 *
 * @param int[] $companyDbIds
 */
function domainApiDetachCompaniesFromOwner(PDO $pdo, array $companyDbIds, int $ownerId): void
{
    ensureCompanyOwnerIdNullable($pdo);

    $companyDbIds = normalizeIds($companyDbIds);
    if ($companyDbIds === [] || $ownerId <= 0) {
        return;
    }

    $placeholders = buildInPlaceholders(count($companyDbIds));
    $stmt = $pdo->prepare("SELECT id FROM company WHERE owner_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$ownerId], $companyDbIds));
    $allowedIds = normalizeIds($stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($allowedIds === []) {
        return;
    }

    $in = buildInPlaceholders(count($allowedIds));
    $upd = $pdo->prepare("UPDATE company SET owner_id = NULL, group_id = NULL WHERE owner_id = ? AND id IN ($in)");
    $upd->execute(array_merge([$ownerId], $allowedIds));

    if (domainApiTableExists($pdo, 'group_company_map')) {
        deleteByIds($pdo, 'group_company_map', 'company_id', $allowedIds);
    }
}

function domainApiFindDetachedCompanyPk(PDO $pdo, string $companyCode): ?int
{
    $code = strtoupper(trim($companyCode));
    if ($code === '') {
        return null;
    }
    ensureCompanyOwnerIdNullable($pdo);
    try {
        $stmt = $pdo->prepare('SELECT id FROM company WHERE UPPER(TRIM(company_id)) = ? AND owner_id IS NULL LIMIT 1');
        $stmt->execute([$code]);
        $pk = $stmt->fetchColumn();
        return ($pk !== false && $pk !== null) ? (int) $pk : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * @param array<int, array<string, mixed>> $companyRows rows with id + company_id
 */
function domainApiAssertCompaniesRemovableFromDomain(PDO $pdo, array $companyRows): ?string
{
    foreach ($companyRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $reason = domainApiCompanyOperationalBlockReason(
            $pdo,
            (int) ($row['id'] ?? 0),
            (string) ($row['company_id'] ?? '')
        );
        if ($reason !== null) {
            return $reason;
        }
    }
    return null;
}

/**
 * Remove domain registration for unused companies only (no transactions / accounts / captures).
 * Does NOT delete account rows or transaction payment ledger.
 *
 * @param int[] $companyDbIds numeric company.id
 * @param string[] $companyCodeStrings business codes (company.company_id) for C168 member cleanup
 */
function domainApiCascadeDeleteCompanies(PDO $pdo, array $companyDbIds, array $companyCodeStrings = []): void
{
    $companyDbIds = normalizeIds($companyDbIds);
    if ($companyDbIds === []) {
        return;
    }

    domainApiDeleteRowsByCompanyIds($pdo, 'description', $companyDbIds);
    domainApiDeleteRowsByCompanyIds($pdo, 'currency', $companyDbIds);
    domainApiDeleteRowsByCompanyIds($pdo, 'account_link', $companyDbIds);
    domainApiDeleteRowsByCompanyIds($pdo, 'account_company', $companyDbIds);

    if (domainApiTableExists($pdo, 'company_auto_renew_request')) {
        domainApiDeleteRowsByCompanyIds($pdo, 'company_auto_renew_request', $companyDbIds);
    }

    domainApiDeleteRowsByCompanyIds($pdo, 'user_company_map', $companyDbIds);
    if (domainApiTableExists($pdo, 'user_company_permissions')) {
        domainApiDeleteRowsByCompanyIds($pdo, 'user_company_permissions', $companyDbIds);
    }

    domainApiDeleteC168ProvisionedMemberAccountsByCodes($pdo, $companyCodeStrings);

    deleteByIds($pdo, 'company', 'id', $companyDbIds);
}

/**
 * 检查公司是否为 C168（用于二级密码等权限判断）
 */
/**
 * Domain 列表页：全局 Price（单行 id=1）
 * 注意：MySQL/MariaDB 中任意 CREATE TABLE 都会隐式提交并结束当前事务。
 * 表已存在时必须跳过 CREATE，否则在 beginTransaction 之后的入账逻辑里再调用会触发
 * “There is no active transaction”。
 */
function domainListFeeSettingsCreateTableSql(): string
{
    return "
        CREATE TABLE IF NOT EXISTS `domain_list_fee_settings` (
            `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            `price` DECIMAL(25,8) NULL DEFAULT NULL COMMENT 'Legacy single price (synced from company 6-month)',
            `group_price` DECIMAL(25,8) NULL DEFAULT NULL COMMENT 'Default fee for group tenants (6-month fallback)',
            `company_price` DECIMAL(25,8) NULL DEFAULT NULL COMMENT 'Default fee for company tenants (6-month fallback)',
            `company_period_prices` LONGTEXT NULL DEFAULT NULL COMMENT 'Company per-period prices JSON',
            `group_period_prices` LONGTEXT NULL DEFAULT NULL COMMENT 'Group per-period prices JSON',
            `period_prices` LONGTEXT NULL DEFAULT NULL COMMENT 'Unified JSON {company,group} for legacy readers',
            `maintenance_fee` DECIMAL(14,4) NULL DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
}

function ensureDomainListFeeSettingsTable(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'domain_list_fee_settings'");
        if ($stmt && $stmt->fetch(PDO::FETCH_NUM) !== false) {
            $pdo->exec("INSERT IGNORE INTO `domain_list_fee_settings` (`id`, `price`) VALUES (1, NULL)");
            try {
                $pdo->exec("ALTER TABLE `domain_list_fee_settings` MODIFY COLUMN `price` DECIMAL(25,8) NULL DEFAULT NULL");
            } catch (Exception $e) {
                // Best effort for old schemas.
            }
            $ensured = true;
            ensureDomainListFeePriceColumns($pdo);
            return;
        }
    } catch (Exception $e) {
        // 继续尝试建表
    }
    $pdo->exec(domainListFeeSettingsCreateTableSql());
    try {
        $pdo->exec("ALTER TABLE `domain_list_fee_settings` MODIFY COLUMN `price` DECIMAL(25,8) NULL DEFAULT NULL");
    } catch (Exception $e) {
        // Best effort for old schemas; save will still fail visibly if the column is incompatible.
    }
    $pdo->exec("INSERT IGNORE INTO `domain_list_fee_settings` (`id`, `price`) VALUES (1, NULL)");
    $ensured = true;
    ensureDomainListFeePriceColumns($pdo);
}

/**
 * domain_list_fee_settings：分组价 / 公司价（与旧 price 列并存，price 同步为公司价）
 */
function ensureDomainListFeePriceColumns(PDO $pdo): void {
    ensureDomainListFeeSettingsTable($pdo);
    foreach (['group_price', 'company_price', 'company_period_prices', 'period_prices', 'group_period_prices'] as $col) {
        if (tableHasColumn($pdo, 'domain_list_fee_settings', $col)) {
            continue;
        }
        try {
            if (in_array($col, ['period_prices', 'company_period_prices', 'group_period_prices'], true)) {
                $pdo->exec("ALTER TABLE `domain_list_fee_settings` ADD COLUMN `{$col}` LONGTEXT NULL DEFAULT NULL");
            } else {
                $pdo->exec("ALTER TABLE `domain_list_fee_settings` ADD COLUMN `{$col}` DECIMAL(25,8) NULL DEFAULT NULL");
            }
        } catch (Exception $e) {
            // Column may already exist.
        }
    }
}

/** @return list<string> */
function domainListFeePeriodKeys(): array
{
    return ['7days', '1month', '3months', '6months', '1year'];
}

/**
 * @param array<string, mixed>|null $raw
 * @return array<string, ?string>
 */
function normalizeDomainListFeePeriodPrices(?array $raw): array
{
    $out = [];
    foreach (domainListFeePeriodKeys() as $key) {
        $out[$key] = null;
    }
    if (!is_array($raw)) {
        return $out;
    }
    foreach (domainListFeePeriodKeys() as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $val = normalizeOptionalDecimal($raw[$key]);
        if ($val === false) {
            return [];
        }
        $out[$key] = $val !== null ? money_out($val) : null;
    }
    return $out;
}

/** @return array<string, mixed>|null */
function decodeDomainListFeeJsonColumn($value): ?array
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed>|null $decoded
 * @param 'company'|'group' $kind
 * @return array<string, ?string>|null
 */
function extractDomainListFeePeriodPricesFromPayload(?array $decoded, string $kind): ?array
{
    if (!is_array($decoded)) {
        return null;
    }
    if ($kind === 'company' && isset($decoded['company']) && is_array($decoded['company'])) {
        $parsed = normalizeDomainListFeePeriodPrices($decoded['company']);
        return $parsed === [] ? null : $parsed;
    }
    if ($kind === 'group' && isset($decoded['group']) && is_array($decoded['group'])) {
        $parsed = normalizeDomainListFeePeriodPrices($decoded['group']);
        return $parsed === [] ? null : $parsed;
    }
    if ($kind === 'company') {
        foreach (domainListFeePeriodKeys() as $key) {
            if (array_key_exists($key, $decoded)) {
                $parsed = normalizeDomainListFeePeriodPrices($decoded);
                return $parsed === [] ? null : $parsed;
            }
        }
    }
    if ($kind === 'group') {
        if (!isset($decoded['company']) && !isset($decoded['group'])) {
            foreach (domainListFeePeriodKeys() as $key) {
                if (array_key_exists($key, $decoded)) {
                    $parsed = normalizeDomainListFeePeriodPrices($decoded);
                    return $parsed === [] ? null : $parsed;
                }
            }
        }
    }
    return null;
}

/**
 * @param array<string, ?string> $periodPrices
 * @return array<string, ?string>
 */
function applyDomainListFeeLegacyFlatPrice(array $periodPrices, ?string $legacyFlat): array
{
    $hasAny = false;
    foreach ($periodPrices as $v) {
        if ($v !== null && $v !== '' && money_cmp($v, '0') > 0) {
            $hasAny = true;
            break;
        }
    }
    if (!$hasAny && $legacyFlat !== null && $legacyFlat !== '' && money_cmp($legacyFlat, '0') > 0) {
        $periodPrices['6months'] = money_out($legacyFlat);
    }
    return $periodPrices;
}

/**
 * @param mixed $rawCompanyPeriodPrices
 * @param mixed $rawPeriodPrices
 * @param array<string, mixed> $row
 * @return array<string, ?string>
 */
function decodeDomainListFeePeriodPricesFromRow($rawCompanyPeriodPrices, $rawPeriodPrices, array $row): array
{
    $periodPrices = normalizeDomainListFeePeriodPrices(null);
    $parsed = null;

    $companyDecoded = decodeDomainListFeeJsonColumn($rawCompanyPeriodPrices);
    if ($companyDecoded !== null) {
        $parsed = extractDomainListFeePeriodPricesFromPayload($companyDecoded, 'company');
        if ($parsed === null && !isset($companyDecoded['company']) && !isset($companyDecoded['group'])) {
            $flat = normalizeDomainListFeePeriodPrices($companyDecoded);
            $parsed = $flat === [] ? null : $flat;
        }
    }

    if ($parsed === null) {
        $decoded = decodeDomainListFeeJsonColumn($rawPeriodPrices);
        $parsed = extractDomainListFeePeriodPricesFromPayload($decoded, 'company');
    }
    if (is_array($parsed)) {
        $periodPrices = $parsed;
    }
    $legacy = $row['company_price'] ?? $row['price'] ?? null;
    return applyDomainListFeeLegacyFlatPrice(
        $periodPrices,
        $legacy !== null && $legacy !== '' ? (string) $legacy : null
    );
}

/**
 * @param mixed $rawGroupPeriodPrices
 * @param mixed $rawPeriodPrices
 * @param array<string, mixed> $row
 * @return array<string, ?string>
 */
function decodeDomainListFeeGroupPeriodPricesFromRow($rawGroupPeriodPrices, $rawPeriodPrices, array $row): array
{
    $periodPrices = normalizeDomainListFeePeriodPrices(null);
    $parsed = null;

    $groupDecoded = decodeDomainListFeeJsonColumn($rawGroupPeriodPrices);
    if ($groupDecoded !== null) {
        $parsed = extractDomainListFeePeriodPricesFromPayload($groupDecoded, 'group');
        if ($parsed === null && !isset($groupDecoded['company']) && !isset($groupDecoded['group'])) {
            $flat = normalizeDomainListFeePeriodPrices($groupDecoded);
            $parsed = $flat === [] ? null : $flat;
        }
    }

    if ($parsed === null) {
        $periodDecoded = decodeDomainListFeeJsonColumn($rawPeriodPrices);
        if (is_array($periodDecoded) && isset($periodDecoded['group']) && is_array($periodDecoded['group'])) {
            $parsed = extractDomainListFeePeriodPricesFromPayload($periodDecoded, 'group');
        }
    }

    if (is_array($parsed)) {
        $periodPrices = $parsed;
    }

    $legacy = $row['group_price'] ?? null;
    return applyDomainListFeeLegacyFlatPrice(
        $periodPrices,
        $legacy !== null && $legacy !== '' ? (string) $legacy : null
    );
}

/** @param mixed $raw */
function parseDomainListFeePeriodInput($raw): ?array
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }
        return $decoded;
    }
    return is_array($raw) ? $raw : null;
}

/**
 * @return array{price: ?string, group_price: ?string, company_price: ?string, company_period_prices: array<string, ?string>, period_prices: array<string, ?string>, group_period_prices: array<string, ?string>}
 */
function fetchDomainListFeeSettingsRow(PDO $pdo): array
{
    ensureDomainListFeePriceColumns($pdo);
    $cols = ['price', 'group_price', 'company_price'];
    if (tableHasColumn($pdo, 'domain_list_fee_settings', 'company_period_prices')) {
        $cols[] = 'company_period_prices';
    }
    $cols[] = 'period_prices';
    if (tableHasColumn($pdo, 'domain_list_fee_settings', 'group_period_prices')) {
        $cols[] = 'group_period_prices';
    }
    $sql = 'SELECT `' . implode('`, `', $cols) . '` FROM `domain_list_fee_settings` WHERE `id` = 1';
    $stmt = $pdo->query($sql);
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) {
        return [
            'price' => null,
            'group_price' => null,
            'company_price' => null,
            'company_period_prices' => normalizeDomainListFeePeriodPrices(null),
            'period_prices' => normalizeDomainListFeePeriodPrices(null),
            'group_period_prices' => normalizeDomainListFeePeriodPrices(null),
        ];
    }
    $rawCompanyPeriodPrices = $row['company_period_prices'] ?? null;
    $rawPeriodPrices = $row['period_prices'] ?? null;
    $rawGroupPeriodPrices = $row['group_period_prices'] ?? null;
    foreach (['price', 'group_price', 'company_price'] as $key) {
        if ($row[$key] !== null && $row[$key] !== '') {
            $row[$key] = money_out($row[$key]);
        } else {
            $row[$key] = null;
        }
    }
    $row['company_period_prices'] = decodeDomainListFeePeriodPricesFromRow($rawCompanyPeriodPrices, $rawPeriodPrices, $row);
    $row['group_period_prices'] = decodeDomainListFeeGroupPeriodPricesFromRow($rawGroupPeriodPrices, $rawPeriodPrices, $row);
    $row['period_prices'] = $row['company_period_prices'];
    $syncCompany = $row['company_period_prices']['6months'] ?? null;
    if ($syncCompany !== null && $syncCompany !== '') {
        $row['company_price'] = $syncCompany;
        $row['price'] = $syncCompany;
    }
    $syncGroup = $row['group_period_prices']['6months'] ?? null;
    if ($syncGroup !== null && $syncGroup !== '') {
        $row['group_price'] = $syncGroup;
    }
    return $row;
}

/**
 * company 表：费用分成（Sales / CS / IT），JSON
 */
function ensureCompanyFeeShareColumn(PDO $pdo): void {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM `company` LIKE 'fee_share_allocations'");
        if ($check && $check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `company` ADD COLUMN `fee_share_allocations` JSON NULL DEFAULT NULL COMMENT 'Sales/CS/IT fee share % by account' AFTER `permissions`");
        }
    } catch (Exception $e) {
        // 兼容旧环境
    }
}

/**
 * account 表：标记账号来源（domain 自动建账 / 手动建账等）
 * 注意：DDL 必须在事务外调用，避免隐式提交。
 */
function ensureAccountCreatedSourceColumn(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $check = $pdo->query("SHOW COLUMNS FROM `account` LIKE 'created_source'");
        if ($check && $check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `account` ADD COLUMN `created_source` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Account source, e.g. domain_auto/manual' AFTER `status`");
        }
    } catch (Exception $e) {
        // 兼容旧环境
    }
    $ensured = true;
}

function domainApiHasAccountCreatedSourceColumn(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `account` LIKE 'created_source'");
        $has = $stmt && $stmt->rowCount() > 0;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * @param mixed $raw
 * @return array{profit: list<array{account_id:int,percentage:string}>, sales: list, cs: list, it: list}
 */
function normalizeFeeShareAllocationsInput($raw): array {
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
            $pct = isset($row['percentage']) && money_is_valid($row['percentage']) ? money_normalize($row['percentage'], 4) : '0.0000';
            // 正数 = account.id（须为 C168 旗下 Account）；负数 = -user.id（Admin 用户）
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

function feeShareAllocationsToJson(?array $normalized): ?string {
    if ($normalized === null) {
        return null;
    }
    $allEmpty = empty($normalized['profit']) && empty($normalized['sales']) && empty($normalized['cs']) && empty($normalized['it']);
    if ($allEmpty) {
        return null;
    }
    return json_encode($normalized, JSON_UNESCAPED_UNICODE);
}

/**
 * 读取某来源公司 Share% 中的 Profit 目标账号（必须是 C168 下 role=profit）。
 */
function resolveShareProfitTargetAccountId(PDO $pdo, string $sourceCompanyCode): ?int
{
    $src = strtoupper(trim($sourceCompanyCode));
    if ($src === '') {
        return null;
    }
    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        return null;
    }
    try {
        $st = $pdo->prepare("SELECT fee_share_allocations FROM company WHERE UPPER(TRIM(company_id)) = ? LIMIT 1");
        $st->execute([$src]);
        $allocRaw = $st->fetchColumn();
        $normalized = normalizeFeeShareAllocationsInput($allocRaw);
        $profitRows = $normalized['profit'] ?? [];
        if (!is_array($profitRows)) {
            return null;
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
    } catch (PDOException $e) {
        return null;
    }
    return null;
}

function resolveShareProfitTargetAccountIdForGroup(PDO $pdo, string $groupCode): ?int
{
    $src = strtoupper(trim($groupCode));
    if ($src === '' || !domainApiHasGroupsTable($pdo)) {
        return null;
    }
    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT fee_share_allocations FROM `groups` WHERE UPPER(TRIM(group_code)) = ? LIMIT 1');
        $st->execute([$src]);
        $allocRaw = $st->fetchColumn();
        $normalized = normalizeFeeShareAllocationsInput($allocRaw);
        $profitRows = $normalized['profit'] ?? [];
        if (!is_array($profitRows)) {
            return null;
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
    } catch (PDOException $e) {
        return null;
    }
    return null;
}

function resolveShareProfitTargetAccountIdForTenant(PDO $pdo, string $sourceCode, string $tenantKind = 'company'): ?int
{
    if ($tenantKind === 'group') {
        return resolveShareProfitTargetAccountIdForGroup($pdo, $sourceCode);
    }
    return resolveShareProfitTargetAccountId($pdo, $sourceCode);
}

function collectUniqueAccountIdsFromFeeShare(array $normalized): array {
    $ids = [];
    foreach (['profit', 'sales', 'cs', 'it'] as $role) {
        foreach ($normalized[$role] as $row) {
            if (!array_key_exists('account_id', $row)) {
                continue;
            }
            $aid = (int) $row['account_id'];
            if ($aid !== 0) {
                $ids[] = $aid;
            }
        }
    }
    return array_values(array_unique($ids));
}

function getC168CompanyPk(PDO $pdo): ?int {
    $stmt = $pdo->prepare("SELECT id FROM company WHERE UPPER(TRIM(company_id)) = 'C168' LIMIT 1");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null || $v === '') {
        return null;
    }
    return (int) $v;
}

function getCompanyPkByCode(PDO $pdo, string $companyCode): ?int {
    $companyCode = strtoupper(trim($companyCode));
    if ($companyCode === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM company WHERE UPPER(TRIM(company_id)) = ? LIMIT 1");
    $stmt->execute([$companyCode]);
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null || $v === '') {
        return null;
    }
    return (int) $v;
}

/**
 * Share % 账户排除：Group 账本 / domain 自动建账 / MEMBER，仅保留 C168 公司本体账户。
 */
function domainApiFeeShareExcludeNonC168CompanyAccountsSql(PDO $pdo, string $accountAlias = 'a'): string
{
    $sql = " AND LOWER(TRIM(COALESCE({$accountAlias}.role, ''))) <> 'member'";
    if (domainApiHasAccountCreatedSourceColumn($pdo)) {
        $sql .= " AND ({$accountAlias}.created_source IS NULL OR TRIM({$accountAlias}.created_source) = ''"
            . " OR LOWER(TRIM({$accountAlias}.created_source)) <> 'domain_auto')";
    }
    try {
        if ($pdo->query("SHOW TABLES LIKE 'account_group_map'")->rowCount() > 0) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM account_group_map agm WHERE agm.account_id = {$accountAlias}.id)";
        }
    } catch (PDOException $e) {
        // ignore
    }
    return $sql;
}

function domainApiResolveFeeShareC168CompanyPk(PDO $pdo): ?int
{
    $target = resolveC168TargetCompanyId($pdo);
    if ($target !== null && $target > 0) {
        return $target;
    }
    return getC168CompanyPk($pdo);
}

/**
 * Share % 下拉数据：始终仅列出 C168 公司本体 Account（排除 Group 账本账户），role 只能是 staff/agent。
 */
function fetchFeeSharePickerAccounts(PDO $pdo): array {
    $rows = [];
    $c168Pk = domainApiResolveFeeShareC168CompanyPk($pdo);
    if ($c168Pk) {
        $subsidiaryOnly = tenant_sql_account_company_subsidiary_only($pdo, 'ac');
        $excludeNonC168 = domainApiFeeShareExcludeNonC168CompanyAccountsSql($pdo, 'a');
        $accStmt = $pdo->prepare("
            SELECT DISTINCT a.id, a.account_id, a.name
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              {$subsidiaryOnly}
              AND LOWER(TRIM(COALESCE(a.role, ''))) IN ('staff', 'agent')
              {$excludeNonC168}
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            ORDER BY a.account_id ASC
        ");
        $accStmt->execute([$c168Pk]);
        foreach ($accStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id' => (int) $r['id'],
                'account_id' => $r['account_id'],
                'name' => $r['name'],
                'entry_type' => 'account',
            ];
        }
    }
    return $rows;
}

/**
 * Share % Profit 池下拉：C168 公司本体且 role 为 profit（排除 Group 账本账户）。
 */
function fetchFeeShareProfitPickerAccounts(PDO $pdo): array {
    $rows = [];
    $c168Pk = domainApiResolveFeeShareC168CompanyPk($pdo);
    if ($c168Pk) {
        $subsidiaryOnly = tenant_sql_account_company_subsidiary_only($pdo, 'ac');
        $excludeNonC168 = domainApiFeeShareExcludeNonC168CompanyAccountsSql($pdo, 'a');
        $accStmt = $pdo->prepare("
            SELECT DISTINCT a.id, a.account_id, a.name
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              {$subsidiaryOnly}
              AND LOWER(TRIM(COALESCE(a.role, ''))) = 'profit'
              {$excludeNonC168}
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            ORDER BY a.account_id ASC
        ");
        $accStmt->execute([$c168Pk]);
        foreach ($accStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id' => (int) $r['id'],
                'account_id' => $r['account_id'],
                'name' => $r['name'],
                'entry_type' => 'account',
            ];
        }
    }
    return $rows;
}

/**
 * Share % Profit 默认账号：C168 公司下 role=profit，优先 account_id C168，其次 PROFIT。
 */
function resolveDefaultFeeShareProfitAccountId(PDO $pdo): ?int
{
    $c168Pk = domainApiResolveFeeShareC168CompanyPk($pdo);
    if (!$c168Pk) {
        return null;
    }
    return resolveC168ProfitRoleAccountId($pdo, $c168Pk, 0);
}

/**
 * Profit 未配置账号时，自动填入默认 C168 profit 账号。
 *
 * @param array{profit: list, sales: list, cs: list, it: list} $normalized
 * @return array{profit: list, sales: list, cs: list, it: list}
 */
function applyDefaultProfitAllocationIfEmpty(PDO $pdo, array $normalized): array
{
    $profitRows = $normalized['profit'] ?? [];
    if (!is_array($profitRows)) {
        $profitRows = [];
    }
    foreach ($profitRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int) ($row['account_id'] ?? 0) > 0) {
            return $normalized;
        }
    }
    $defaultId = resolveDefaultFeeShareProfitAccountId($pdo);
    if (!$defaultId || $defaultId <= 0) {
        return $normalized;
    }
    $normalized['profit'] = [
        [
            'account_id' => $defaultId,
            'percentage' => '0',
        ],
    ];
    return $normalized;
}

/**
 * 校验：C168 旗下；Profit 池仅 profit role；Sales/CS/IT 仅 staff/agent。
 */
function feeShareAllocationsTargetsValid(PDO $pdo, array $normalized): bool {
    $c168Pk = domainApiResolveFeeShareC168CompanyPk($pdo);
    $subsidiaryOnly = tenant_sql_account_company_subsidiary_only($pdo, 'ac');
    $excludeNonC168 = domainApiFeeShareExcludeNonC168CompanyAccountsSql($pdo, 'a');

    $profitIds = [];
    foreach (($normalized['profit'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $aid = isset($row['account_id']) ? (int) $row['account_id'] : 0;
        if ($aid > 0) {
            $profitIds[] = $aid;
        }
    }
    $profitIds = array_values(array_unique($profitIds));

    $otherIds = [];
    foreach (['sales', 'cs', 'it'] as $role) {
        foreach (($normalized[$role] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $aid = isset($row['account_id']) ? (int) $row['account_id'] : 0;
            if ($aid > 0) {
                $otherIds[] = $aid;
            }
        }
    }
    $otherIds = array_values(array_unique($otherIds));

    if (!empty($profitIds)) {
        if (!$c168Pk) {
            return false;
        }
        $placeholders = buildInPlaceholders(count($profitIds));
        $sql = "
            SELECT COUNT(DISTINCT a.id)
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              {$subsidiaryOnly}
              {$excludeNonC168}
              AND a.id IN ($placeholders)
              AND LOWER(TRIM(COALESCE(a.role, ''))) = 'profit'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$c168Pk], $profitIds));
        if ((int) $stmt->fetchColumn() !== count($profitIds)) {
            return false;
        }
    }

    if (!empty($otherIds)) {
        if (!$c168Pk) {
            return false;
        }
        $placeholders = buildInPlaceholders(count($otherIds));
        $sql = "
            SELECT COUNT(DISTINCT a.id)
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              {$subsidiaryOnly}
              {$excludeNonC168}
              AND a.id IN ($placeholders)
              AND LOWER(TRIM(COALESCE(a.role, ''))) IN ('staff', 'agent')
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$c168Pk], $otherIds));
        if ((int) $stmt->fetchColumn() !== count($otherIds)) {
            return false;
        }
    }

    return true;
}

/**
 * 表单或 JSON 中的可选十进制数：空为 null，非法返回 false
 *
 * @param mixed $val
 * @return string|null|false
 */
function normalizeOptionalDecimal($val) {
    if ($val === null || $val === '') {
        return null;
    }
    if (is_string($val)) {
        $val = trim($val);
        if ($val === '') {
            return null;
        }
    }
    if (!money_is_valid($val)) {
        return false;
    }
    return money_normalize($val);
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function getDomainFeePrice(PDO $pdo): ?string
{
    $row = fetchDomainListFeeSettingsRow($pdo);
    $company = $row['company_price'] ?? null;
    if ($company !== null && $company !== '') {
        return money_normalize($company);
    }
    $legacy = $row['price'] ?? null;
    if ($legacy === null || $legacy === '') {
        return null;
    }
    return money_normalize($legacy);
}

function getGroupDomainFeePrice(PDO $pdo): ?string
{
    $row = fetchDomainListFeeSettingsRow($pdo);
    $group = $row['group_price'] ?? null;
    if ($group !== null && $group !== '') {
        return money_normalize($group);
    }
    return null;
}

function getDomainFeePriceForTenant(PDO $pdo, string $tenantKind = 'company'): ?string
{
    return $tenantKind === 'group'
        ? getGroupDomainFeePrice($pdo)
        : getDomainFeePrice($pdo);
}

/** SMS 标记：Group 用 GROUP| 前缀，与 Company 付款去重/报表隔离 */
function domainFeeSmsMarker(string $markerType, string $sourceCode, string $tenantKind = 'company'): string
{
    $codeU = strtoupper(trim($sourceCode));
    if ($tenantKind === 'group') {
        return '[' . $markerType . '|GROUP|' . $codeU . ']';
    }
    return '[' . $markerType . '|' . $codeU . ']';
}

function domainFeeSmsLikePattern(string $markerType, string $sourceCode, string $tenantKind = 'company'): string
{
    return domainFeeSmsMarker($markerType, $sourceCode, $tenantKind) . '%';
}

function domainApiClearTransactionSearchCache(): void
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
 * 在指定公司下解析可用于「付款方」的账户：owner 主账号 -> PROFIT -> 任一 active
 */
function resolvePayerAccountInCompany(PDO $pdo, int $companyPk, int $excludeAccountId): ?int
{
    if ($companyPk <= 0) {
        return null;
    }
    $stmtMain = $pdo->prepare("
        SELECT a.id
        FROM company c
        INNER JOIN owner o ON o.id = c.owner_id
        INNER JOIN account a ON UPPER(TRIM(a.account_id)) = UPPER(TRIM(o.owner_code))
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE c.id = ?
          AND ac.company_id = c.id
          AND a.id <> ?
          AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
        LIMIT 1
    ");
    $stmtMain->execute([$companyPk, $excludeAccountId]);
    $mainId = $stmtMain->fetchColumn();
    if ($mainId !== false && $mainId !== null) {
        return (int) $mainId;
    }
    $stmtProfit = $pdo->prepare("
        SELECT a.id
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
          AND UPPER(TRIM(a.account_id)) = 'PROFIT'
          AND a.id <> ?
          AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
        ORDER BY a.id ASC
        LIMIT 1
    ");
    $stmtProfit->execute([$companyPk, $excludeAccountId]);
    $profitId = $stmtProfit->fetchColumn();
    if ($profitId !== false && $profitId !== null) {
        return (int) $profitId;
    }
    $stmtAny = $pdo->prepare("
        SELECT a.id
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
          AND a.id <> ?
          AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
        ORDER BY a.account_id ASC, a.id ASC
        LIMIT 1
    ");
    $stmtAny->execute([$companyPk, $excludeAccountId]);
    $anyId = $stmtAny->fetchColumn();
    if ($anyId === false || $anyId === null) {
        return null;
    }
    return (int) $anyId;
}

function getCompanyOwnerDisplayLabel(PDO $pdo, int $companyPk): string
{
    if ($companyPk <= 0) {
        return '';
    }
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(o.name), ''), NULLIF(TRIM(o.owner_code), ''), '') AS lbl
        FROM company c
        INNER JOIN owner o ON o.id = c.owner_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$companyPk]);
    $v = $stmt->fetchColumn();
    return ($v !== false && $v !== null) ? trim((string) $v) : '';
}

function getCompanyOwnerCodeByPk(PDO $pdo, int $companyPk): string
{
    if ($companyPk <= 0) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("
            SELECT TRIM(COALESCE(o.owner_code, '')) AS oc
            FROM company c
            INNER JOIN owner o ON o.id = c.owner_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$companyPk]);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null) ? strtoupper(trim((string)$v)) : '';
    } catch (Exception $e) {
        return '';
    }
}

function resolveCompanyOwnerAccountId(PDO $pdo, int $companyPk): ?int
{
    if ($companyPk <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare("
            SELECT a.id
            FROM company c
            INNER JOIN owner o ON o.id = c.owner_id
            INNER JOIN account a ON UPPER(TRIM(a.account_id)) = UPPER(TRIM(o.owner_code))
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE c.id = ?
              AND ac.company_id = c.id
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            LIMIT 1
        ");
        $st->execute([$companyPk]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : null;
    } catch (PDOException $e) {
        return null;
    }
}

function resolveC168OwnerAccountId(PDO $pdo, int $c168Pk): ?int
{
    return resolveCompanyOwnerAccountId($pdo, $c168Pk);
}

function resolveC168ProfitRoleAccountId(PDO $pdo, int $c168Pk, int $excludeAccountId = 0): ?int
{
    if ($c168Pk <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare("
            SELECT a.id
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND LOWER(TRIM(COALESCE(a.role, ''))) = 'profit'
              AND a.id <> ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            ORDER BY CASE
                WHEN UPPER(TRIM(COALESCE(a.account_id, ''))) = 'C168' THEN 0
                WHEN UPPER(TRIM(COALESCE(a.account_id, ''))) = 'PROFIT' THEN 1
                ELSE 2
            END, a.id ASC
            LIMIT 1
        ");
        $st->execute([$c168Pk, (int)$excludeAccountId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * 第三笔：C168 最终净利润（fee - commissions）。
 * 用独立标记避免重复写入，同一天同来源公司只写一笔。
 */
function createDomainNetProfitPayment(
    PDO $pdo,
    string $sourceCompanyCode,
    string $feeAmount,
    string $commissionTotal,
    ?int $fromPoolAccountId,
    ?int $createdByUser,
    ?int $createdByOwner,
    string $tenantKind = 'company'
): array {
    $out = [
        'created' => false,
        'skipped_duplicate' => false,
        'skipped_zero_or_negative' => false,
        'amount' => '0',
    ];
    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        return $out;
    }
    $net = money_sub($feeAmount, $commissionTotal, 2);
    $out['amount'] = money_out($net);
    if (money_cmp($net, '0') <= 0) {
        $out['skipped_zero_or_negative'] = true;
        return $out;
    }

    $tenantKind = $tenantKind === 'group' ? 'group' : 'company';
    $today = date('Y-m-d');
    $srcU = strtoupper(trim($sourceCompanyCode));
    $smsMarker = domainFeeSmsMarker('DOMAIN_NET_PROFIT', $srcU, $tenantKind);
    $dupStmt = $pdo->prepare("
        SELECT id FROM transactions
        WHERE company_id = ? AND transaction_type = 'PAYMENT'
          AND (
                sms = ?
                OR sms LIKE ?
          )
        LIMIT 1
    ");
    $dupStmt->execute([$c168Pk, $smsMarker, $smsMarker . '|%']);
    if ($dupStmt->fetchColumn() !== false) {
        $out['skipped_duplicate'] = true;
        return $out;
    }

    // 目标优先使用来源公司 Share% 里配置的 Profit 账号（必须为 C168 且 role=profit）
    $profitAccId = resolveShareProfitTargetAccountIdForTenant($pdo, $srcU, $tenantKind);
    if (!$profitAccId || $profitAccId <= 0) {
        $profitAccId = resolveC168ProfitRoleAccountId($pdo, $c168Pk, 0);
    }
    // 没有有效 Profit 目标则不建单；from_account_id 固定为空（展示为 "-"）
    if (!$profitAccId || $profitAccId <= 0) {
        return $out;
    }

    $hasCurrencyId = tableHasColumn($pdo, 'transactions', 'currency_id');
    $hasApprovalStatus = tableHasColumn($pdo, 'transactions', 'approval_status');
    $hasApprovedBy = tableHasColumn($pdo, 'transactions', 'approved_by');
    $hasApprovedByOwner = tableHasColumn($pdo, 'transactions', 'approved_by_owner');
    $hasApprovedAt = tableHasColumn($pdo, 'transactions', 'approved_at');
    $hasCreatedAt = tableHasColumn($pdo, 'transactions', 'created_at');
    $defaultTxnCurrencyId = $hasCurrencyId ? resolveC168DefaultTransactionCurrencyId($pdo, $c168Pk) : null;
    $now = date('Y-m-d H:i:s');

    $ownerCode = getCompanyOwnerCodeByPk($pdo, $c168Pk);
    if ($ownerCode === '') {
        $ownerCode = 'C168';
    }

    $insertCols = [
        'company_id' => $c168Pk,
        'transaction_type' => 'PAYMENT',
        'account_id' => $profitAccId,
        'from_account_id' => null,
        'amount' => $net,
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
        if ($hasApprovedBy) { $insertCols['approved_by'] = $createdByUser; }
        if ($hasApprovedByOwner) { $insertCols['approved_by_owner'] = $createdByOwner; }
        if ($hasApprovedAt) { $insertCols['approved_at'] = $now; }
    }
    if ($hasCreatedAt) {
        $insertCols['created_at'] = $now;
    }
    $cols = array_keys($insertCols);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO transactions (`" . implode('`,`', $cols) . "`) VALUES ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute(array_values($insertCols));
    $out['created'] = true;
    return $out;
}

/**
 * C168 侧接收 domain list 费用、并向 Agent 付款时使用的资金池账户
 */
function resolveC168DomainFeePoolAccountId(PDO $pdo, int $c168Pk, int $excludeAccountId): ?int
{
    return resolvePayerAccountInCompany($pdo, $c168Pk, $excludeAccountId);
}

function resolveC168DomainFeeReceiverAccountId(PDO $pdo, int $c168Pk, int $excludeAccountId = 0): ?int
{
    if ($c168Pk <= 0) {
        return null;
    }
    $ownerId = resolveC168OwnerAccountId($pdo, $c168Pk);
    if ($ownerId && $ownerId > 0 && $ownerId !== $excludeAccountId) {
        return $ownerId;
    }
    try {
        $stC168 = $pdo->prepare("
            SELECT a.id
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND UPPER(TRIM(COALESCE(a.account_id, ''))) = 'C168'
              AND a.id <> ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            LIMIT 1
        ");
        $stC168->execute([$c168Pk, (int)$excludeAccountId]);
        $c168Id = $stC168->fetchColumn();
        if ($c168Id !== false && $c168Id !== null) {
            return (int)$c168Id;
        }
    } catch (PDOException $e) {
    }
    try {
        $stAny = $pdo->prepare("
            SELECT a.id
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND a.id <> ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
              AND LOWER(TRIM(COALESCE(a.role, ''))) <> 'profit'
              AND UPPER(TRIM(COALESCE(a.account_id, ''))) <> 'PROFIT'
            ORDER BY a.id ASC
            LIMIT 1
        ");
        $stAny->execute([$c168Pk, (int)$excludeAccountId]);
        $v = $stAny->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Domain 在 C168 下自动建的 MEMBER 现为公司代码本体（如 AA/95），
 * 但仍需兼容历史 OWNERCODE_COMPANY 旧账号；List Fee 付款方须能解析到该账号。
 */
function resolveC168DomainProvisionedMemberByCompanyCode(PDO $pdo, int $c168Pk, string $customerCompanyCode, int $excludeAccountId = 0): ?int
{
    return resolveC168DomainProvisionedMemberByTenantCode($pdo, $c168Pk, $customerCompanyCode, $excludeAccountId, 'company');
}

function resolveC168DomainProvisionedMemberByTenantCode(PDO $pdo, int $c168Pk, string $tenantCode, int $excludeAccountId, string $tenantKind): ?int
{
    $src = strtoupper(trim($tenantCode));
    if ($c168Pk <= 0 || $src === '') {
        return null;
    }
    $ownerUpper = '';
    if ($tenantKind === 'group') {
        $ownerUpper = domainApiGetGroupOwnerCodeByGroupCode($pdo, $src);
    } else {
        try {
            $st = $pdo->prepare("
                SELECT UPPER(TRIM(COALESCE(o.owner_code, ''))) AS oc
                FROM company c
                INNER JOIN owner o ON o.id = c.owner_id
                WHERE UPPER(TRIM(c.company_id)) = ?
                ORDER BY c.id ASC
                LIMIT 1
            ");
            $st->execute([$src]);
            $ownerUpper = strtoupper(trim((string) ($st->fetchColumn() ?: '')));
        } catch (PDOException $e) {
            return null;
        }
    }
    if ($ownerUpper === '') {
        return null;
    }
    $accountCode = domainApiResolveProvisionedMemberAccountCode($pdo, $c168Pk, $ownerUpper, $src);
    try {
        $st2 = $pdo->prepare("
            SELECT a.id
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND UPPER(TRIM(a.account_id)) = UPPER(TRIM(?))
              AND a.id <> ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            LIMIT 1
        ");
        $st2->execute([$c168Pk, $accountCode, (int) $excludeAccountId]);
        $v = $st2->fetchColumn();
        if ($v !== false && $v !== null) {
            return (int) $v;
        }
        // 兼容旧库：OWNERCODE_COMPANY
        $legacyCode = domainApiBuildLegacyOwnerPrefixedProvisionedMemberAccountId($ownerUpper, $src);
        if (strtoupper(trim($legacyCode)) !== strtoupper(trim($accountCode))) {
            $st2->execute([$c168Pk, $legacyCode, (int) $excludeAccountId]);
            $v2 = $st2->fetchColumn();
            if ($v2 !== false && $v2 !== null) {
                return (int) $v2;
            }
        }
        return null;
    } catch (PDOException $e) {
        return null;
    }
}

function resolveDomainFeeSourceAccountId(PDO $pdo, int $c168Pk, string $customerCompanyCode, int $excludeAccountId = 0, string $tenantKind = 'company'): ?int
{
    $srcCode = strtoupper(trim($customerCompanyCode));
    if ($srcCode === '') {
        return null;
    }

    $fromC168CompanyCode = resolveC168CompanyCodeAccountId($pdo, $c168Pk, $srcCode, $excludeAccountId);
    if ($fromC168CompanyCode && $fromC168CompanyCode > 0) {
        return $fromC168CompanyCode;
    }

    $fromProvisioned = resolveC168DomainProvisionedMemberByTenantCode($pdo, $c168Pk, $srcCode, $excludeAccountId, $tenantKind);
    if ($fromProvisioned && $fromProvisioned > 0) {
        return $fromProvisioned;
    }

    // 不再回退到 owner/K/任意账号；仅允许公司码映射或 Domain 自动建账映射。
    return null;
}

/**
 * 回退：在 C168 下按公司代码匹配同名 member 账户（例如 LAG -> account.account_id='LAG'）。
 */
function resolveC168CompanyCodeAccountId(PDO $pdo, int $c168Pk, string $companyCode, int $excludeAccountId = 0): ?int
{
    $code = strtoupper(trim($companyCode));
    if ($c168Pk <= 0 || $code === '') {
        return null;
    }
    try {
        $st = $pdo->prepare("
            SELECT a.id
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND UPPER(TRIM(a.account_id)) = ?
              AND a.id <> ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            LIMIT 1
        ");
        $st->execute([$c168Pk, $code, (int)$excludeAccountId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int)$v : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Transaction Payment / search_api 按 currency_id 汇总 Cr/Dr，且忽略 currency_id IS NULL 的 PAYMENT。
 * Domain 入账必须写入 C168 公司下的币种，优先 MYR，否则取该公司第一条 currency。
 */
function resolveC168DefaultTransactionCurrencyId(PDO $pdo, int $c168CompanyPk): ?int {
    if ($c168CompanyPk <= 0 || !tableHasColumn($pdo, 'transactions', 'currency_id')) {
        return null;
    }
    try {
        $st = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? AND UPPER(TRIM(code)) = 'MYR' ORDER BY id ASC LIMIT 1");
        $st->execute([$c168CompanyPk]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) {
            return (int) $v;
        }
        $st2 = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? ORDER BY id ASC LIMIT 1");
        $st2->execute([$c168CompanyPk]);
        $v2 = $st2->fetchColumn();
        if ($v2 !== false && $v2 !== null) {
            return (int) $v2;
        }
    } catch (Exception $e) {
        return null;
    }
    return null;
}

/**
 * 新增公司后自动创建/挂载 MEMBER 账号时，默认绑定该公司币别（优先 MYR）。
 * account_currency 没有 company_id，因此通过 currency.company_id 限定。
 */
function domainApiEnsureAccountDefaultCurrency(PDO $pdo, int $accountId, int $companyPk, string $preferredCode = 'MYR'): void
{
    if ($accountId <= 0 || $companyPk <= 0) {
        return;
    }
    static $hasTable = null;
    if ($hasTable === null) {
        try {
            $hasTable = $pdo->query("SHOW TABLES LIKE 'account_currency'")->rowCount() > 0;
        } catch (Exception $e) {
            $hasTable = false;
        }
    }
    if (!$hasTable) {
        return;
    }

    try {
        // 若该账号已绑定过“本公司”的任一币别，则不覆盖（保持用户自定义）。
        // 注意：account_currency 没有 company_id，因此要 join currency 来限定 company。
        $chk = $pdo->prepare("
            SELECT COUNT(*)
            FROM account_currency ac
            INNER JOIN currency c ON ac.currency_id = c.id
            WHERE ac.account_id = ?
              AND c.company_id = ?
        ");
        $chk->execute([$accountId, $companyPk]);
        if ((int)$chk->fetchColumn() > 0) {
            return;
        }

        $curId = null;
        $st = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? AND UPPER(TRIM(code)) = ? ORDER BY id ASC LIMIT 1");
        $st->execute([$companyPk, strtoupper(trim($preferredCode))]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) {
            $curId = (int)$v;
        } else {
            $st2 = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? ORDER BY id ASC LIMIT 1");
            $st2->execute([$companyPk]);
            $v2 = $st2->fetchColumn();
            if ($v2 !== false && $v2 !== null) {
                $curId = (int)$v2;
            }
        }
        if (!$curId || $curId <= 0) {
            return;
        }

        // 避免重复插入
        $chk2 = $pdo->prepare("SELECT 1 FROM account_currency WHERE account_id = ? AND currency_id = ? LIMIT 1");
        $chk2->execute([$accountId, $curId]);
        if ($chk2->fetchColumn() === false) {
            $ins = $pdo->prepare("INSERT INTO account_currency (account_id, currency_id) VALUES (?, ?)");
            $ins->execute([$accountId, $curId]);
        }
    } catch (PDOException $e) {
        // 忽略重复/兼容性错误
    }
}

/**
 * Domain List Fee：客户公司账户 -> Share% 配置的 Profit 入账账号（与佣金 from、净利润 from 同一池）；
 * 顾客款先入该池，再由佣金 PAYMENT 从该池扣出各 %，剩余留在 Profit 账号即净利润。
 * 无 Share% Profit 时仅回退 C168 PROFIT 角色账号；不再回退到 owner/K 等账户。
 * 去重由 DOMAIN_LIST_FEE sms 标记负责；删除该笔后可再次创建。
 */
function createDomainListFeePayment(
    PDO $pdo,
    string $customerCompanyCode,
    ?int $createdByUser,
    ?int $createdByOwner,
    string $tenantKind = 'company'
): array {
    $out = [
        'created' => false,
        'skipped_duplicate' => false,
        'skipped_no_price' => false,
        'skipped_no_customer' => false,
        'skipped_no_c168' => false,
        'skipped_no_accounts' => false,
        'amount' => '0',
        'pool_account_id' => null,
    ];
    $tenantKind = $tenantKind === 'group' ? 'group' : 'company';
    $feePrice = getDomainFeePriceForTenant($pdo, $tenantKind);
    if ($feePrice === null || money_cmp($feePrice, '0') <= 0) {
        $out['skipped_no_price'] = true;
        return $out;
    }
    $out['amount'] = money_normalize($feePrice, 2);
    $custCodeU = strtoupper(trim($customerCompanyCode));
    if ($tenantKind === 'group') {
        if (!domainApiGroupExistsByCode($pdo, $custCodeU)) {
            $out['skipped_no_customer'] = true;
            return $out;
        }
    } else {
        $customerPk = getCompanyPkByCode($pdo, $customerCompanyCode);
        if (!$customerPk) {
            $out['skipped_no_customer'] = true;
            return $out;
        }
    }
    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        $out['skipped_no_c168'] = true;
        return $out;
    }
    $poolEarly = resolveShareProfitTargetAccountIdForTenant($pdo, $custCodeU, $tenantKind);
    if (!$poolEarly || $poolEarly <= 0) {
        $poolEarly = resolveC168ProfitRoleAccountId($pdo, $c168Pk, 0);
    }
    if ($poolEarly && $poolEarly > 0) {
        $out['pool_account_id'] = (int) $poolEarly;
    }
    $today = date('Y-m-d');
    $feeSms = domainFeeSmsMarker('DOMAIN_LIST_FEE', $custCodeU, $tenantKind);
    $dupStmt = $pdo->prepare("
        SELECT id FROM transactions
        WHERE company_id = ? AND transaction_type = 'PAYMENT'
          AND (
                sms = ?
                OR sms LIKE ?
          )
        LIMIT 1
    ");
    $dupStmt->execute([$c168Pk, $feeSms, $feeSms . '|%']);
    if ($dupStmt->fetchColumn() !== false) {
        $out['skipped_duplicate'] = true;
        return $out;
    }
    // 第一笔 Domain Fee：From=顾客侧账号；To=Share% Profit 池（与后续佣金/净利润同一 account_id）
    $toC168Pool = $poolEarly ? (int) $poolEarly : null;
    if (!$toC168Pool || $toC168Pool <= 0) {
        $out['skipped_no_accounts'] = true;
        return $out;
    }
    $fromCustomer = resolveDomainFeeSourceAccountId($pdo, $c168Pk, $customerCompanyCode, (int)$toC168Pool, $tenantKind);
    if (!$fromCustomer || $fromCustomer === $toC168Pool) {
        $out['skipped_no_accounts'] = true;
        return $out;
    }
    $c168OwnerCode = getCompanyOwnerCodeByPk($pdo, $c168Pk);
    if ($c168OwnerCode === '') {
        $c168OwnerCode = 'C168';
    }
    $desc = $tenantKind === 'group' ? 'Pay Domain Fee (Group)' : 'Pay Domain Fee';

    $now = date('Y-m-d H:i:s');
    $hasCurrencyId = tableHasColumn($pdo, 'transactions', 'currency_id');
    $hasApprovalStatus = tableHasColumn($pdo, 'transactions', 'approval_status');
    $hasApprovedBy = tableHasColumn($pdo, 'transactions', 'approved_by');
    $hasApprovedByOwner = tableHasColumn($pdo, 'transactions', 'approved_by_owner');
    $hasApprovedAt = tableHasColumn($pdo, 'transactions', 'approved_at');
    $hasCreatedAt = tableHasColumn($pdo, 'transactions', 'created_at');
    $defaultTxnCurrencyId = $hasCurrencyId ? resolveC168DefaultTransactionCurrencyId($pdo, $c168Pk) : null;

    $insertCols = [
        'company_id' => $c168Pk,
        'transaction_type' => 'PAYMENT',
        'account_id' => $toC168Pool,
        'from_account_id' => $fromCustomer,
        'amount' => $out['amount'],
        'transaction_date' => $today,
        'description' => $desc,
        'sms' => $feeSms,
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
    $sql = "INSERT INTO transactions (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insertCols));
    $out['created'] = true;
    return $out;
}

/**
 * Confirm 建单：C168 资金池 -> 各 C168 Agent/Staff commission；amount = domain fee * % / 100
 * sms 含客户公司代码，避免多客户共用 C168 时去重误判
 */
function createDomainShareCommissionPayments(
    PDO $pdo,
    string $sourceCompanyCode,
    array $normalizedAllocations,
    ?int $c168SourceAccountId,
    ?int $createdByUser,
    ?int $createdByOwner,
    string $tenantKind = 'company'
): array {
    $result = [
        'created_count' => 0,
        'skipped_admin_count' => 0,
        'skipped_invalid_account_count' => 0,
        'skipped_no_from_account_count' => 0,
        'skipped_duplicate_account_count' => 0,
        'commission_total' => '0',
    ];

    $tenantKind = $tenantKind === 'group' ? 'group' : 'company';
    $feePrice = getDomainFeePriceForTenant($pdo, $tenantKind);
    if ($feePrice === null || money_cmp($feePrice, '0') <= 0) {
        return $result;
    }

    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        return $result;
    }

    $fromPoolId = $c168SourceAccountId;
    if (!$fromPoolId || $fromPoolId <= 0) {
        $fromPoolId = resolveC168DomainFeePoolAccountId($pdo, $c168Pk, 0);
    }
    if (!$fromPoolId) {
        $result['skipped_no_from_account_count']++;
        return $result;
    }

    $hasCurrencyId = tableHasColumn($pdo, 'transactions', 'currency_id');
    $hasApprovalStatus = tableHasColumn($pdo, 'transactions', 'approval_status');
    $hasApprovedBy = tableHasColumn($pdo, 'transactions', 'approved_by');
    $hasApprovedByOwner = tableHasColumn($pdo, 'transactions', 'approved_by_owner');
    $hasApprovedAt = tableHasColumn($pdo, 'transactions', 'approved_at');
    $hasCreatedAt = tableHasColumn($pdo, 'transactions', 'created_at');
    $defaultTxnCurrencyId = $hasCurrencyId ? resolveC168DefaultTransactionCurrencyId($pdo, $c168Pk) : null;

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $c168OwnerCode = getCompanyOwnerCodeByPk($pdo, $c168Pk);
    if ($c168OwnerCode === '') {
        $c168OwnerCode = 'C168';
    }
    $srcU = strtoupper(trim($sourceCompanyCode));
    $roleLabelMap = [
        'sales' => 'Sales',
        'cs' => 'CS',
        'it' => 'IT',
    ];

    // Profit 在 Share% 中代表总利润去向，不属于 commission。
    foreach (['sales', 'cs', 'it'] as $role) {
        $rows = $normalizedAllocations[$role] ?? [];
        if (!is_array($rows)) {
            continue;
        }
        $roleLabel = $roleLabelMap[$role] ?? ucfirst($role);
        $description = $roleLabel . ' Commision for ' . $c168OwnerCode;
        foreach ($rows as $row) {
            $aid = isset($row['account_id']) ? (int) $row['account_id'] : 0;
            $pct = isset($row['percentage']) && money_is_valid($row['percentage']) ? money_normalize($row['percentage'], 4) : '0.0000';

            if ($aid < 0) {
                $result['skipped_admin_count']++;
                continue;
            }
            if ($aid <= 0 || money_cmp($pct, '0', 4) <= 0) {
                continue;
            }

            $amount = money_div(money_mul($feePrice, $pct, MONEY_CALC_SCALE), '100', 2);
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
                $result['skipped_invalid_account_count']++;
                continue;
            }

            $smsMarker = rtrim(domainFeeSmsMarker('DOMAIN_SHARE_COMMISSION', $srcU, $tenantKind), ']')
                . '|ROLE:' . strtoupper($role) . '|AID:' . $aid . ']';
            $dupStmt = $pdo->prepare("
                SELECT id
                FROM transactions
                WHERE company_id = ?
                  AND transaction_type = 'PAYMENT'
                  AND account_id = ?
                  AND (
                        sms = ?
                        OR sms LIKE ?
                  )
                LIMIT 1
            ");
            $dupStmt->execute([$c168Pk, $aid, $smsMarker, $smsMarker . '|%']);
            if ($dupStmt->fetchColumn() !== false) {
                $result['skipped_duplicate_account_count']++;
                continue;
            }

            if ($fromPoolId === $aid) {
                $result['skipped_no_from_account_count']++;
                continue;
            }

            $insertCols = [
                'company_id' => $c168Pk,
                'transaction_type' => 'PAYMENT',
                'account_id' => $aid,
                'from_account_id' => $fromPoolId,
                'amount' => $amount,
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
            $sql = "INSERT INTO transactions (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($insertCols));
            $result['created_count']++;
            $result['commission_total'] = money_add($result['commission_total'], $amount, 2);
        }
    }

    return $result;
}

function hasDomainNetProfitTransactionExecuted(PDO $pdo, string $sourceCompanyCode, string $tenantKind = 'company'): bool
{
    $srcU = strtoupper(trim($sourceCompanyCode));
    if ($srcU === '') {
        return false;
    }
    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        return false;
    }
    $tenantKind = $tenantKind === 'group' ? 'group' : 'company';
    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM transactions t
            WHERE t.company_id = ?
              AND t.transaction_type = 'PAYMENT'
              AND t.sms LIKE ?
            LIMIT 1
        ");
        $st->execute([$c168Pk, domainFeeSmsLikePattern('DOMAIN_NET_PROFIT', $srcU, $tenantKind)]);
        return $st->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function getDomainFeeAndCommissionTotalsBySource(PDO $pdo, string $sourceCompanyCode): array
{
    $out = ['fee' => '0', 'commission' => '0'];
    $srcU = strtoupper(trim($sourceCompanyCode));
    if ($srcU === '') {
        return $out;
    }
    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        return $out;
    }
    try {
        $st = $pdo->prepare("
            SELECT
                SUM(CASE WHEN t.sms LIKE ? THEN t.amount ELSE 0 END) AS fee_total,
                SUM(CASE WHEN t.sms LIKE ? THEN t.amount ELSE 0 END) AS comm_total
            FROM transactions t
            WHERE t.company_id = ?
              AND t.transaction_type = 'PAYMENT'
              AND (t.sms LIKE ? OR t.sms LIKE ?)
        ");
        $st->execute([
            '[DOMAIN_LIST_FEE|' . $srcU . '%',
            '[DOMAIN_SHARE_COMMISSION|' . $srcU . '%',
            $c168Pk,
            '[DOMAIN_LIST_FEE|' . $srcU . '%',
            '[DOMAIN_SHARE_COMMISSION|' . $srcU . '%',
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['fee'] = money_normalize($row['fee_total'] ?? '0');
        $out['commission'] = money_normalize($row['comm_total'] ?? '0');
    } catch (PDOException $e) {
        return $out;
    }
    return $out;
}

function normalizeDomainListFeeTransactionParties(PDO $pdo, string $sourceCompanyCode): bool
{
    $srcU = strtoupper(trim($sourceCompanyCode));
    if ($srcU === '') {
        return false;
    }
    $c168Pk = getC168CompanyPk($pdo);
    $customerPk = getCompanyPkByCode($pdo, $srcU);
    if (!$c168Pk || !$customerPk) {
        return false;
    }
    $toOwner = resolveC168DomainFeeReceiverAccountId($pdo, (int)$c168Pk, 0);
    $fromOwner = resolveDomainFeeSourceAccountId($pdo, (int)$c168Pk, $srcU, (int)$toOwner);
    if (!$toOwner || !$fromOwner || (int)$toOwner === (int)$fromOwner) {
        return false;
    }
    try {
        $st = $pdo->prepare("
            UPDATE transactions t
            SET t.account_id = ?, t.from_account_id = ?
            WHERE t.company_id = ?
              AND t.transaction_type = 'PAYMENT'
              AND t.sms LIKE ?
              AND (t.account_id <> ? OR t.from_account_id <> ?)
        ");
        $st->execute([
            (int)$toOwner,
            (int)$fromOwner,
            (int)$c168Pk,
            '[DOMAIN_LIST_FEE|' . $srcU . '%',
            (int)$toOwner,
            (int)$fromOwner,
        ]);
        return $st->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function normalizeDomainNetProfitTransaction(PDO $pdo, string $sourceCompanyCode): bool
{
    $srcU = strtoupper(trim($sourceCompanyCode));
    if ($srcU === '') {
        return false;
    }
    $c168Pk = getC168CompanyPk($pdo);
    if (!$c168Pk) {
        return false;
    }

    $totals = getDomainFeeAndCommissionTotalsBySource($pdo, $srcU);
    $net = money_sub($totals['fee'], $totals['commission'], 2);
    if (money_cmp($net, '0') <= 0) {
        return false;
    }

    $profitAccId = resolveShareProfitTargetAccountId($pdo, $srcU);
    if (!$profitAccId || $profitAccId <= 0) {
        $profitAccId = resolveC168ProfitRoleAccountId($pdo, (int)$c168Pk, 0);
    }
    if (!$profitAccId || $profitAccId <= 0) {
        return false;
    }

    $ownerCode = getCompanyOwnerCodeByPk($pdo, (int)$c168Pk);
    if ($ownerCode === '') {
        $ownerCode = 'C168';
    }
    $desc = 'Profit By ' . $ownerCode;
    $changed = false;

    try {
        $st = $pdo->prepare("
            UPDATE transactions t
            SET t.account_id = ?,
                t.from_account_id = NULL,
                t.amount = ?,
                t.description = ?
            WHERE t.company_id = ?
              AND t.transaction_type = 'PAYMENT'
              AND t.sms LIKE ?
              AND (
                    t.account_id <> ?
                    OR t.from_account_id IS NOT NULL
                    OR t.amount <> ?
                    OR COALESCE(t.description, '') <> ?
              )
        ");
        $st->execute([
            (int)$profitAccId,
            $net,
            $desc,
            (int)$c168Pk,
            '[DOMAIN_NET_PROFIT|' . $srcU . '%',
            (int)$profitAccId,
            $net,
            $desc,
        ]);
        $changed = ($st->rowCount() > 0);
    } catch (PDOException $e) {
        return false;
    }

    if (!hasDomainNetProfitTransactionExecuted($pdo, $srcU)) {
        $createdByUser = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner'
            ? null
            : (int) ($_SESSION['user_id'] ?? 0);
        $createdByOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner'
            ? (int) ($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0)
            : null;
        $created = createDomainNetProfitPayment(
            $pdo,
            $srcU,
            $totals['fee'],
            $totals['commission'],
            null,
            $createdByUser > 0 ? $createdByUser : null,
            $createdByOwner > 0 ? $createdByOwner : null
        );
        if (!empty($created['created'])) {
            $changed = true;
        }
    }

    return $changed;
}

/**
 * EDIT DOMAIN 按下 Confirm 後：對 companies 中標記 apply_commission_payments_on_domain_save 的公司
 * 寫入 domain list fee 與 Share% 佣金（transactions.PAYMENT），與 Transaction Payment / Payment History 同一數據源。
 */
function domainApiApplyDomainListFeePaymentsFromPayload(PDO $pdo, $companies, bool $hasC168Context, bool $domainActorAllowed): void {
    if (!$hasC168Context || !$domainActorAllowed || !isset($_SESSION['user_id'])) {
        return;
    }
    $rows = domainApiNormalizeCompaniesPayload($companies);
    $createdByUser = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner'
        ? null
        : (int) ($_SESSION['user_id'] ?? 0);
    $createdByOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner'
        ? (int) ($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0)
        : null;
    $u = $createdByUser > 0 ? $createdByUser : null;
    $o = $createdByOwner > 0 ? $createdByOwner : null;
    $any = false;
    foreach ($rows as $row) {
        $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));
        if ($cid === '' || $cid === 'C168') {
            continue;
        }
        $apply = filter_var($row['apply_commission_payments_on_domain_save'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$apply) {
            continue;
        }
        // Confirm 路径优先使用数据库里已保存的 share 配置，避免前端 payload 缺 role 导致漏单
        $normalized = normalizeFeeShareAllocationsInput($row['fee_share_allocations'] ?? null);
        try {
            $stAlloc = $pdo->prepare("SELECT fee_share_allocations FROM company WHERE UPPER(TRIM(company_id)) = ? LIMIT 1");
            $stAlloc->execute([$cid]);
            $dbAllocRaw = $stAlloc->fetchColumn();
            $dbNormalized = normalizeFeeShareAllocationsInput($dbAllocRaw);
            $dbHasAny = !empty($dbNormalized['profit']) || !empty($dbNormalized['sales']) || !empty($dbNormalized['cs']) || !empty($dbNormalized['it']);
            if ($dbHasAny) {
                $normalized = $dbNormalized;
            }
        } catch (Exception $e) {
            // ignore and keep payload normalization
        }
        $feeResult = createDomainListFeePayment($pdo, $cid, $u, $o);
        $poolId = isset($feeResult['pool_account_id']) ? (int) $feeResult['pool_account_id'] : null;
        if ($poolId <= 0) {
            $poolId = null;
        }
        $commissionResult = createDomainShareCommissionPayments($pdo, $cid, $normalized, $poolId, $u, $o);
        $profitResult = createDomainNetProfitPayment(
            $pdo,
            $cid,
            (string) ($feeResult['amount'] ?? '0'),
            (string) ($commissionResult['commission_total'] ?? '0'),
            $poolId,
            $u,
            $o
        );
        if (
            !empty($feeResult['created'])
            || (($commissionResult['created_count'] ?? 0) > 0)
            || !empty($profitResult['created'])
        ) {
            $any = true;
        }
    }
    if ($any) {
        domainApiClearTransactionSearchCache();
    }
}

/**
 * EDIT DOMAIN Confirm：對 groups 中標記 apply_commission_payments_on_domain_save 的 Group
 * 寫入 domain list fee / Share% 佣金 / 淨利潤（與 Company 流程一致，SMS 含 GROUP| 前綴隔離）。
 */
function domainApiApplyGroupDomainListFeePaymentsFromPayload(PDO $pdo, $groups, bool $hasC168Context, bool $domainActorAllowed): void {
    if (!$hasC168Context || !$domainActorAllowed || !isset($_SESSION['user_id'])) {
        return;
    }
    $rows = domainApiNormalizeGroupsPayload($groups);
    $createdByUser = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner'
        ? null
        : (int) ($_SESSION['user_id'] ?? 0);
    $createdByOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner'
        ? (int) ($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0)
        : null;
    $u = $createdByUser > 0 ? $createdByUser : null;
    $o = $createdByOwner > 0 ? $createdByOwner : null;
    $any = false;
    foreach ($rows as $row) {
        $gid = strtoupper(trim((string) ($row['group_code'] ?? '')));
        if ($gid === '' || $gid === 'C168') {
            continue;
        }
        $apply = filter_var($row['apply_commission_payments_on_domain_save'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$apply) {
            continue;
        }
        $normalized = normalizeFeeShareAllocationsInput($row['fee_share_allocations'] ?? null);
        if (domainApiHasGroupsTable($pdo)) {
            try {
                $stAlloc = $pdo->prepare('SELECT fee_share_allocations FROM `groups` WHERE UPPER(TRIM(group_code)) = ? LIMIT 1');
                $stAlloc->execute([$gid]);
                $dbAllocRaw = $stAlloc->fetchColumn();
                $dbNormalized = normalizeFeeShareAllocationsInput($dbAllocRaw);
                $dbHasAny = !empty($dbNormalized['profit']) || !empty($dbNormalized['sales']) || !empty($dbNormalized['cs']) || !empty($dbNormalized['it']);
                if ($dbHasAny) {
                    $normalized = $dbNormalized;
                }
            } catch (Exception $e) {
                // keep payload normalization
            }
        }
        $feeResult = createDomainListFeePayment($pdo, $gid, $u, $o, 'group');
        $poolId = isset($feeResult['pool_account_id']) ? (int) $feeResult['pool_account_id'] : null;
        if ($poolId <= 0) {
            $poolId = null;
        }
        $commissionResult = createDomainShareCommissionPayments($pdo, $gid, $normalized, $poolId, $u, $o, 'group');
        $profitResult = createDomainNetProfitPayment(
            $pdo,
            $gid,
            (string) ($feeResult['amount'] ?? '0'),
            (string) ($commissionResult['commission_total'] ?? '0'),
            $poolId,
            $u,
            $o,
            'group'
        );
        if (
            !empty($feeResult['created'])
            || (($commissionResult['created_count'] ?? 0) > 0)
            || !empty($profitResult['created'])
        ) {
            $any = true;
        }
    }
    if ($any) {
        domainApiClearTransactionSearchCache();
    }
}

/**
 * 在 C168 下为 Domain 表单中的 company / group 代码幂等创建 MEMBER 账号。
 */
function domainApiProvisionC168MemberAccountsForTenantCodes(
    PDO $pdo,
    bool $hasC168Context,
    bool $domainActorAllowed,
    string $ownerDisplayName,
    string $ownerCodeUpper,
    array $tenantCodes
): void {
    if (empty($tenantCodes) || !domainApiMayProvisionC168MemberAccounts($pdo, $hasC168Context, $domainActorAllowed)) {
        return;
    }
    $targetC168 = resolveC168TargetCompanyId($pdo);
    if ($targetC168 === null) {
        return;
    }
    domainApiAutoCreateMemberAccountsUnderC168Company($pdo, $targetC168, $ownerDisplayName, $tenantCodes, $ownerCodeUpper);
}

function isC168Company(PDO $pdo, $company_id): bool {
    if (!$company_id) return false;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
        $stmt->execute([$company_id]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * 主系统 C168 在 company 表中的数字主键（与当前 session 选中的公司无关，用于统一把 MEMBER 挂到 C168 下）
 */
function getMasterC168CompanyNumericId(PDO $pdo): ?int {
    try {
        $stmt = $pdo->query("SELECT id FROM company WHERE UPPER(TRIM(company_id)) = 'C168' ORDER BY id ASC LIMIT 1");
        $v = $stmt->fetchColumn();
        if ($v !== false && $v !== null) {
            return (int) $v;
        }
        $stmt2 = $pdo->query("SELECT id FROM company WHERE UPPER(TRIM(IFNULL(group_id, ''))) = 'C168' ORDER BY id ASC LIMIT 1");
        $v2 = $stmt2->fetchColumn();
        return ($v2 !== false && $v2 !== null) ? (int) $v2 : null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Rename domain-provisioned MEMBER account on C168 when company/group code changes.
 */
function domainApiRenameC168MemberAccountCode(PDO $pdo, string $oldCode, string $newCode): void
{
    $oldCode = strtoupper(trim($oldCode));
    $newCode = strtoupper(trim($newCode));
    if ($oldCode === '' || $newCode === '' || $oldCode === $newCode || $oldCode === 'C168' || $newCode === 'C168') {
        return;
    }

    $c168Pk = resolveC168TargetCompanyId($pdo) ?? getMasterC168CompanyNumericId($pdo);
    if (!$c168Pk || (int) $c168Pk <= 0) {
        return;
    }
    $c168Pk = (int) $c168Pk;

    $findStmt = $pdo->prepare("
        SELECT a.id
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
          AND UPPER(TRIM(a.account_id)) = ?
        LIMIT 1
    ");
    $findStmt->execute([$c168Pk, $oldCode]);
    $accId = (int) ($findStmt->fetchColumn() ?: 0);
    if ($accId <= 0 || !domainApiAccountLooksLikeDomainProvisionedMember($pdo, $accId)) {
        return;
    }

    $conflictStmt = $pdo->prepare("
        SELECT a.id
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
          AND UPPER(TRIM(a.account_id)) = ?
          AND a.id <> ?
        LIMIT 1
    ");
    $conflictStmt->execute([$c168Pk, $newCode, $accId]);
    if ($conflictStmt->fetchColumn() !== false) {
        return;
    }

    $pdo->prepare('UPDATE account SET account_id = ? WHERE id = ?')->execute([$newCode, $accId]);
}

/**
 * Apply company_id renames before add/delete sync on domain update.
 *
 * @param array<int, array<string, mixed>> $newCompaniesData
 * @param array<int, array<string, mixed>> $existingCompanies
 * @param string[] $existingCompanyKeys
 */
function domainApiApplyCompanyRenamesFromPayload(
    PDO $pdo,
    array $newCompaniesData,
    array &$existingCompanies,
    array &$existingCompanyKeys
): void {
    foreach ($newCompaniesData as $newCompany) {
        $prev = strtoupper(trim((string) ($newCompany['previous_company_id'] ?? '')));
        $next = strtoupper(trim((string) ($newCompany['company_id'] ?? $newCompany['key'] ?? '')));
        if ($prev === '' || $next === '' || $prev === $next) {
            continue;
        }

        foreach ($existingCompanies as &$existing) {
            $existingKey = strtoupper(trim((string) ($existing['company_id'] ?? '')));
            if ($existingKey !== $prev) {
                continue;
            }
            $companyPk = (int) ($existing['id'] ?? 0);
            if ($companyPk <= 0) {
                break;
            }
            $pdo->prepare('UPDATE company SET company_id = ? WHERE id = ?')->execute([$next, $companyPk]);
            domainApiRenameC168MemberAccountCode($pdo, $prev, $next);
            $existing['company_id'] = $next;
            $idx = array_search($prev, $existingCompanyKeys, true);
            if ($idx !== false) {
                $existingCompanyKeys[$idx] = $next;
            }
            break;
        }
        unset($existing);
    }
}

/**
 * 写入 Account List 时使用的 C168 公司主键：优先当前 session 已选中的 C168 行，否则按库中 company_id / group_id 解析
 */
function resolveC168TargetCompanyId(PDO $pdo): ?int {
    $sid = (int) ($_SESSION['company_id'] ?? 0);
    if ($sid > 0 && isC168Company($pdo, $sid)) {
        return $sid;
    }
    return getMasterC168CompanyNumericId($pdo);
}

/**
 * 请求体里 companies 可能是 JSON 字符串、已解码数组或逗号列表；避免 json_decode(数组) 失败导致不写 company、不建 account
 */
function domainApiNormalizeCompaniesPayload($companies): array {
    if ($companies === null || $companies === '') {
        return [];
    }
    if (is_array($companies)) {
        if (isset($companies['company_id']) || isset($companies['group_id']) || isset($companies['expiration_date'])) {
            return [$companies];
        }
        $out = [];
        foreach ($companies as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }
    if (!is_string($companies)) {
        return [];
    }
    $trim = trim($companies);
    if ($trim === '') {
        return [];
    }
    if ($trim[0] === '[' || $trim[0] === '{') {
        $decoded = json_decode($trim, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return [];
            }
        }
        if (!is_array($decoded)) {
            return [];
        }
        if (isset($decoded['company_id']) || isset($decoded['group_id']) || isset($decoded['expiration_date'])) {
            return [$decoded];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }
    $out = [];
    foreach (array_map('trim', explode(',', $trim)) as $cid) {
        if ($cid === '') {
            continue;
        }
        $out[] = [
            'company_id' => strtoupper($cid),
            'expiration_date' => null,
            'permissions' => [],
            'group_id' => null,
            'fee_share_allocations' => null,
        ];
    }
    return $out;
}

/**
 * Group 实体行（company_id 与 group_id 相同，或仅 group 占位）：与 addaccountapi ensureGroupEntityCompanyId 一致，不参与互斥。
 */
function domainApiRowIsGroupEntity(array $row): bool
{
    $gidRaw = $row['group_id'] ?? null;
    $gid = ($gidRaw !== null && trim((string) $gidRaw) !== '') ? strtoupper(trim((string) $gidRaw)) : '';
    if ($gid === '') {
        return false;
    }
    $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));

    return $cid === $gid || $cid === '';
}

/**
 * Group ID 与 Company ID 不得使用相同代码（同一 owner 提交的 companies payload；排除 group 实体行）
 */
function domainApiValidateGroupCompanyIdMutualExclusivity(array $rows): ?string
{
    $companyKeys = [];
    $groupKeys = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));
        $gidRaw = $row['group_id'] ?? null;
        $gid = ($gidRaw !== null && trim((string) $gidRaw) !== '') ? strtoupper(trim((string) $gidRaw)) : '';
        if ($cid !== '' && !domainApiRowIsGroupEntity($row)) {
            $companyKeys[$cid] = true;
        }
        if ($gid !== '') {
            $groupKeys[$gid] = true;
        }
    }
    foreach (array_keys($companyKeys) as $code) {
        if (isset($groupKeys[$code])) {
            return 'Group ID and Company ID cannot use the same code: ' . $code;
        }
    }

    return null;
}

/**
 * 同一笔保存里：company_id 不得重复；无 company_id 的「仅组」占位行其 group_id 不得重复。
 * 多家公司可共用同一 group_id（正常分组），不计为重复。
 */
function domainApiValidateCompanyGroupCodesUniqueWithinPayload(array $rows): ?string
{
    $seenCompany = [];
    $seenGroupOnly = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));
        $gidRaw = $row['group_id'] ?? null;
        $gid = ($gidRaw !== null && trim((string) $gidRaw) !== '') ? strtoupper(trim((string) $gidRaw)) : '';
        if ($cid !== '') {
            if (isset($seenCompany[$cid])) {
                return 'Duplicate Company ID in this form: "' . $cid . '". Each Company ID must be unique.';
            }
            $seenCompany[$cid] = true;
            continue;
        }
        if ($gid !== '') {
            if (isset($seenGroupOnly[$gid])) {
                return 'Duplicate group-only entry in this form: "' . $gid . '".';
            }
            $seenGroupOnly[$gid] = true;
        }
    }
    return null;
}

/**
 * 从 company 行中提取所有非空 company_id / group_id（同一命名空间，大写去重）。
 *
 * @return string[]
 */
function domainApiCollectCompanyGroupCodesFromRows(array $rows): array
{
    $codesSet = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));
        $gidRaw = $row['group_id'] ?? null;
        $gid = ($gidRaw !== null && trim((string) $gidRaw) !== '') ? strtoupper(trim((string) $gidRaw)) : '';
        if ($cid !== '') {
            $codesSet[$cid] = true;
        }
        if ($gid !== '') {
            $codesSet[$gid] = true;
        }
    }
    return array_keys($codesSet);
}

/**
 * 读取某 owner 当前已占用的 company_id / group_id 代码集合。
 *
 * @return string[]
 */
function domainApiLoadOwnerCompanyGroupCodes(PDO $pdo, int $ownerId): array
{
    if ($ownerId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT company_id, group_id FROM company WHERE owner_id = ?');
    $stmt->execute([$ownerId]);
    return domainApiCollectCompanyGroupCodesFromRows($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * update 保存前：仅保留 payload 中相对该 owner 数据库快照「新出现」的代码行（供跨 owner 唯一性校验）。
 */
function domainApiFilterRowsToNewCompanyGroupCodes(PDO $pdo, int $ownerId, array $rows): array
{
    $existingSet = array_flip(domainApiLoadOwnerCompanyGroupCodes($pdo, $ownerId));
    $filtered = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = strtoupper(trim((string) ($row['company_id'] ?? '')));
        $gidRaw = $row['group_id'] ?? null;
        $gid = ($gidRaw !== null && trim((string) $gidRaw) !== '') ? strtoupper(trim((string) $gidRaw)) : '';
        $hasNewCode = ($cid !== '' && !isset($existingSet[$cid])) || ($gid !== '' && !isset($existingSet[$gid]));
        if ($hasNewCode) {
            $filtered[] = $row;
        }
    }
    return $filtered;
}

/**
 * 全局唯一：payload 中出现的每个非空代码（任一行的 company_id 或 group_id）在数据库中不可再出现在
 * 任意 owner 的 company 行上（company_id 与 group_id 同一命名空间）。
 * create：与全库比对；update：仅校验相对该 owner 新增加的代码（见 domainApiFilterRowsToNewCompanyGroupCodes）。
 * validate_domain_code：单码添加前校验，可传 $excludeOwnerId 排除当前 owner 已有行。
 *
 * （须与 domainApiValidateGroupCompanyIdMutualExclusivity、domainApiValidateCompanyGroupCodesUniqueWithinPayload 配合。）
 */
function domainApiValidateCrossOwnerCompanyGroupExclusivity(PDO $pdo, array $rows, ?int $excludeOwnerId): ?string
{
    $codes = domainApiCollectCompanyGroupCodesFromRows($rows);
    if ($codes === []) {
        return null;
    }
    $in = implode(',', array_fill(0, count($codes), '?'));

    /*
     * 与 owner_id <> ? 等价，但语义为「不排除其它 owner」，只排除本条 domain 正要覆盖的旧行，
     * 避免误解为可按 owner 分立命名空间。
     */
    $excludeBranchClause = '';
    $excludeRepeatParams = [];
    if ($excludeOwnerId !== null && (int) $excludeOwnerId > 0) {
        $excludeBranchClause = ' id NOT IN (SELECT id FROM company WHERE owner_id = ?) AND ';
        $excludeRepeatParams[] = (int) $excludeOwnerId;
    }

    $sql = 'SELECT z.v FROM ('
        . ' SELECT UPPER(TRIM(CAST(company_id AS CHAR))) AS v FROM company WHERE ' . $excludeBranchClause
        . " company_id IS NOT NULL AND TRIM(CAST(company_id AS CHAR)) <> ''"
        . " AND UPPER(TRIM(CAST(company_id AS CHAR))) IN ($in)"
        . ' UNION'
        . ' SELECT UPPER(TRIM(CAST(group_id AS CHAR))) AS v FROM company WHERE ' . $excludeBranchClause
        . " group_id IS NOT NULL AND TRIM(CAST(group_id AS CHAR)) <> ''"
        . " AND UPPER(TRIM(CAST(group_id AS CHAR))) IN ($in)"
        . ' ) AS z WHERE z.v <> \'\' LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        if ($excludeOwnerId !== null && (int) $excludeOwnerId > 0) {
            $execParams = array_merge($excludeRepeatParams, $codes, $excludeRepeatParams, $codes);
            $stmt->execute($execParams);
        } else {
            $stmt->execute(array_merge($codes, $codes));
        }
    } catch (PDOException $e) {
        error_log('[domain_api] domainApiValidateCrossOwnerCompanyGroupExclusivity: ' . $e->getMessage());
        return 'Could not verify company/group code availability. Please try again.';
    }

    $hit = $stmt->fetchColumn();
    if ($hit === false || $hit === null || trim((string) $hit) === '') {
        return null;
    }
    $code = strtoupper(trim((string) $hit));

    return 'This ID "' . $code . '" is already in use by another domain (not allowed). Choose a different Company ID or Group ID.';
}

function domainApiExtractProvisionCompanyIds($companies): array {
    $ids = [];
    foreach (domainApiNormalizeCompaniesPayload($companies) as $row) {
        $c = strtoupper(trim((string) ($row['company_id'] ?? '')));
        // C168 主公司不参与 Domain 自动建账（任何场景都跳过）
        if ($c !== '' && $c !== 'C168') {
            $ids[] = $c;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * 是否允许为 C168 主公司自动建 MEMBER：C168 Domain 白名单角色，且（当前为 C168 上下文，或用户有权访问 C168 主公司）
 */
function domainApiMayProvisionC168MemberAccounts(PDO $pdo, bool $hasC168Context, bool $domainActorAllowed): bool {
    if (!$domainActorAllowed) {
        return false;
    }
    if ($hasC168Context) {
        return true;
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $masterId = resolveC168TargetCompanyId($pdo);
    if ($uid <= 0 || $masterId === null) {
        return false;
    }
    $role = strtolower($_SESSION['role'] ?? '');
    if ($role === 'owner') {
        $owner_id = (int) ($_SESSION['real_owner_id'] ?? $_SESSION['owner_id'] ?? $uid);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$masterId, $owner_id]);
        return $stmt->fetchColumn() > 0;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$uid, $masterId]);
    return $stmt->fetchColumn() > 0;
}

function domainApiHasAccountLinkTable(PDO $pdo): bool {
    static $v = null;
    if ($v !== null) return $v;
    try {
        $v = $pdo->query("SHOW TABLES LIKE 'account_link'")->rowCount() > 0;
    } catch (PDOException $e) {
        $v = false;
    }
    return $v;
}

/**
 * 与 account_link_api 一致：双向关联，account_id_1 < account_id_2
 */
function domainApiLinkAccountsBidirectional(PDO $pdo, int $account_id_1, int $account_id_2, int $company_id): void {
    if ($account_id_1 === $account_id_2 || $account_id_1 <= 0 || $account_id_2 <= 0 || $company_id <= 0) {
        return;
    }
    if (!domainApiHasAccountLinkTable($pdo)) {
        return;
    }
    $a1 = $account_id_1;
    $a2 = $account_id_2;
    if ($a1 > $a2) {
        [$a1, $a2] = [$a2, $a1];
    }
    $stmt = $pdo->prepare("SELECT id FROM account_link WHERE account_id_1 = ? AND account_id_2 = ? AND company_id = ?");
    $stmt->execute([$a1, $a2, $company_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $link_type = 'bidirectional';
    $source = null;
    static $has_link_type = null;
    if ($has_link_type === null) {
        try {
            $check_column_stmt = $pdo->query("SHOW COLUMNS FROM account_link LIKE 'link_type'");
            $has_link_type = $check_column_stmt && $check_column_stmt->rowCount() > 0;
        } catch (PDOException $e) { $has_link_type = false; }
    }
    if ($existing) {
        if ($has_link_type) {
            $updateStmt = $pdo->prepare("UPDATE account_link SET link_type = ?, source_account_id = ? WHERE id = ?");
            $updateStmt->execute([$link_type, $source, $existing['id']]);
        }
        return;
    }
    if ($has_link_type) {
        $ins = $pdo->prepare("INSERT INTO account_link (account_id_1, account_id_2, company_id, link_type, source_account_id) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$a1, $a2, $company_id, $link_type, $source]);
    } else {
        $ins = $pdo->prepare("INSERT INTO account_link (account_id_1, account_id_2, company_id) VALUES (?, ?, ?)");
        $ins->execute([$a1, $a2, $company_id]);
    }
}

function domainApiMemberRoleAllowed(PDO $pdo): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM role WHERE LOWER(code) = LOWER(?)");
        $stmt->execute(['MEMBER']);
        if ($stmt->fetchColumn() > 0) {
            return true;
        }
    } catch (PDOException $e) {
        // 继续：role 表可能缺 MEMBER 行，但 login_process 仍按 MEMBER 登录
    }
    return true;
}

/**
 * Domain 自动建账使用的 MEMBER 模板：role=MEMBER、password=111（与 login_process member 一致）。
 * 仅当账户已符合该模板时才允许覆盖 name/role/password，避免误伤手动创建的同名 account_id（如与公司代码相同的 G）。
 */
function domainApiAccountLooksLikeDomainProvisionedMember(PDO $pdo, int $accountDbId): bool {
    if ($accountDbId <= 0) {
        return false;
    }
    try {
        $hasCreatedSource = domainApiHasAccountCreatedSourceColumn($pdo);
        $sql = $hasCreatedSource
            ? "SELECT role, password, created_source FROM account WHERE id = ? LIMIT 1"
            : "SELECT role, password FROM account WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$accountDbId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($hasCreatedSource) {
            $src = strtolower(trim((string) ($row['created_source'] ?? '')));
            if ($src !== '') {
                return $src === 'domain_auto';
            }
        }
        $role = strtolower(trim((string) ($row['role'] ?? '')));
        $pw = (string) ($row['password'] ?? '');
        return $role === 'member' && $pw === '111';
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Domain 自动创建的 MEMBER 登录账号：固定使用公司代码本体（如 AA / 95）。
 * 不再生成 OWNERCODE_ 前缀（如 K_95），确保 account_id 直接展示 company id。
 */
function domainApiBuildDomainProvisionedMemberAccountId(string $ownerCodeUpper, string $companyCode): string {
    $cc = strtoupper(trim($companyCode));
    return $cc;
}

/** 旧版：OWNERCODE_公司代码，仅用于查找已存在的自动建账账号 */
function domainApiBuildLegacyOwnerPrefixedProvisionedMemberAccountId(string $ownerCodeUpper, string $companyCode): string
{
    $cc = strtoupper(trim($companyCode));
    $owner = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($ownerCodeUpper)));
    if ($owner === '') {
        $owner = 'DOM';
    }
    return $owner . '_' . $cc;
}

/**
 * 解析最终 account_id：固定使用公司代码本体（如 AA）。
 * 不再自动追加任何后缀（如 _1/_2/_X），从源头杜绝带后缀账号的自动创建。
 */
function domainApiResolveProvisionedMemberAccountCode(PDO $pdo, int $c168CompanyId, string $ownerCodeUpper, string $companyCode): string {
    return domainApiBuildDomainProvisionedMemberAccountId($ownerCodeUpper, $companyCode);
}

/**
 * Domain 同步策略：强制 name = Owner 姓名、role = MEMBER、password = 111（明文，与 member 登录一致）
 */
function domainApiForceMemberDefaultsFromDomain(PDO $pdo, int $accountDbId, string $ownerDisplayName): void {
    if ($accountDbId <= 0) {
        return;
    }
    try {
        $hasCreatedSource = domainApiHasAccountCreatedSourceColumn($pdo);
        $sql = $hasCreatedSource
            ? "UPDATE account SET name = ?, role = 'MEMBER', password = '111', created_source = 'domain_auto' WHERE id = ?"
            : "UPDATE account SET name = ?, role = 'MEMBER', password = '111' WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ownerDisplayName, $accountDbId]);
    } catch (PDOException $e) {
        error_log('domainApiForceMemberDefaultsFromDomain: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Admin 等在 user_company_permissions 里用 account_permissions 白名单时，accountlistapi 只返回白名单内账户；
 * 必须把新账户并入该 JSON，否则账户已写入库但列表被 IN 过滤掉（与 addaccountapi 行为一致，不能 require 该文件）。
 */
function domainApiGetUsersWithCompanyAccess(PDO $pdo, array $companyIds): array {
    $companyIds = array_values(array_filter(array_map('intval', $companyIds), function ($id) {
        return $id > 0;
    }));
    if (empty($companyIds)) {
        return [];
    }
    $placeholders = str_repeat('?,', count($companyIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id, ucp.account_permissions
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        LEFT JOIN user_company_permissions ucp ON u.id = ucp.user_id AND ucm.company_id = ucp.company_id
        WHERE ucm.company_id IN ($placeholders)
    ");
    $stmt->execute($companyIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function domainApiMergeAccountIntoUserCompanyPermissions(PDO $pdo, array $users, array $companyIdsToLink, int $newAccountId, string $account_id): void {
    if ($newAccountId <= 0 || $account_id === '') {
        return;
    }
    $loadCurrentStmt = $pdo->prepare("
        SELECT account_permissions
        FROM user_company_permissions
        WHERE user_id = ? AND company_id = ?
        LIMIT 1
    ");
    $updateStmt = $pdo->prepare("
        INSERT INTO user_company_permissions (user_id, company_id, account_permissions, process_permissions)
        VALUES (?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE account_permissions = VALUES(account_permissions)
    ");
    foreach ($users as $user) {
        foreach ($companyIdsToLink as $comp_id) {
            $comp_id = (int) $comp_id;
            if ($comp_id <= 0) {
                continue;
            }
            $currentPermissions = [];
            $hasPermissionsSet = false;
            $loadCurrentStmt->execute([(int) $user['id'], $comp_id]);
            $rawPerm = $loadCurrentStmt->fetchColumn();
            if ($rawPerm !== false && $rawPerm !== null && trim((string) $rawPerm) !== '' && strtolower(trim((string) $rawPerm)) !== 'null') {
                $decoded = json_decode((string) $rawPerm, true);
                if (is_array($decoded)) {
                    $hasPermissionsSet = true;
                    $currentPermissions = $decoded;
                }
            } elseif (isset($user['account_permissions']) && $user['account_permissions'] !== null && trim((string) $user['account_permissions']) !== '' && strtolower(trim((string) $user['account_permissions'])) !== 'null') {
                $decodedSeed = json_decode((string) $user['account_permissions'], true);
                if (is_array($decodedSeed)) {
                    $hasPermissionsSet = true;
                    $currentPermissions = $decodedSeed;
                }
            }
            if (!$hasPermissionsSet) {
                continue;
            }
            $accountExists = false;
            foreach ($currentPermissions as $permission) {
                if (isset($permission['id']) && (int) $permission['id'] === (int) $newAccountId) {
                    $accountExists = true;
                    break;
                }
            }
            if ($accountExists) {
                continue;
            }
            $currentPermissions[] = ['id' => (int) $newAccountId, 'account_id' => $account_id];
            $updateStmt->execute([$user['id'], $comp_id, json_encode($currentPermissions)]);
        }
    }
}

/**
 * C168 在 Add Domain 时为公司代码创建 MEMBER 账户：挂在当前 C168 公司 account list，并关联主账号 C168（account_link）。
 * 密码明文 111，与 login_process.php member 校验一致。
 *
 * @param string $ownerCodeUpper Owner.owner_code（保留参数用于兼容；当前 account_id 固定为公司代码本体）。
 */
function domainApiAutoCreateMemberAccountsUnderC168Company(PDO $pdo, int $c168NumericCompanyId, string $ownerDisplayName, array $companyIdStrings, string $ownerCodeUpper = ''): void {
    if ($c168NumericCompanyId <= 0 || empty($companyIdStrings)) {
        return;
    }
    if (!domainApiMemberRoleAllowed($pdo)) {
        return;
    }

    $ownerCodeUpper = strtoupper(trim($ownerCodeUpper));

    $usersForAccountListPerm = domainApiGetUsersWithCompanyAccess($pdo, [$c168NumericCompanyId]);
    $companyIdsForPerm = [$c168NumericCompanyId];
    $syncListPerm = function (int $accDbId, string $accountCode) use ($pdo, $usersForAccountListPerm, $companyIdsForPerm): void {
        domainApiMergeAccountIntoUserCompanyPermissions($pdo, $usersForAccountListPerm, $companyIdsForPerm, $accDbId, $accountCode);
    };

    $parentStmt = $pdo->prepare("
        SELECT a.id FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE ac.company_id = ? AND UPPER(a.account_id) = 'C168'
        LIMIT 1
    ");
    $parentStmt->execute([$c168NumericCompanyId]);
    $parentAccountId = (int) ($parentStmt->fetchColumn() ?: 0);

    $finalizeMember = function (int $accDbId, string $permAccountCode) use ($pdo, $c168NumericCompanyId, $ownerDisplayName, $parentAccountId, $syncListPerm): void {
        if ($accDbId <= 0) {
            return;
        }
        domainApiForceMemberDefaultsFromDomain($pdo, $accDbId, $ownerDisplayName);
        domainApiEnsureAccountDefaultCurrency($pdo, $accDbId, $c168NumericCompanyId, 'MYR');
        if ($parentAccountId > 0) {
            try {
                domainApiLinkAccountsBidirectional($pdo, $parentAccountId, $accDbId, $c168NumericCompanyId);
            } catch (PDOException $e) {
                error_log('domainApiAutoCreateMemberAccountsUnderC168Company: account_link failed: ' . $e->getMessage());
            }
        }
        $syncListPerm($accDbId, $permAccountCode);
    };

    $findC168ScopedAccStmt = $pdo->prepare("
        SELECT a.id
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
          AND UPPER(TRIM(a.account_id)) = UPPER(TRIM(?))
        LIMIT 1
    ");
    $hasCreatedSource = domainApiHasAccountCreatedSourceColumn($pdo);
    $insertSql = $hasCreatedSource
        ? "INSERT INTO account (account_id, name, role, password, payment_alert, alert_day, alert_specific_date, alert_amount, remark, status, created_source, last_login)
           VALUES (?, ?, 'MEMBER', '111', 0, NULL, NULL, NULL, NULL, 'active', 'domain_auto', NULL)"
        : "INSERT INTO account (account_id, name, role, password, payment_alert, alert_day, alert_specific_date, alert_amount, remark, status, last_login)
           VALUES (?, ?, 'MEMBER', '111', 0, NULL, NULL, NULL, NULL, 'active', NULL)";
    $insertStmt = $pdo->prepare($insertSql);
    $linkCoStmt = $pdo->prepare('INSERT INTO account_company (account_id, company_id) VALUES (?, ?)');

    foreach ($companyIdStrings as $raw) {
        $cid = strtoupper(trim((string) $raw));
        // 双保险：即便上游漏过，C168 也绝不自动建账
        if ($cid === '' || $cid === 'C168') {
            continue;
        }
        $useAccountId = domainApiResolveProvisionedMemberAccountCode($pdo, $c168NumericCompanyId, $ownerCodeUpper, $cid);

        $findC168ScopedAccStmt->execute([$c168NumericCompanyId, $useAccountId]);
        $existingAccId = (int) ($findC168ScopedAccStmt->fetchColumn() ?: 0);

        if ($existingAccId > 0) {
            if (!domainApiAccountLooksLikeDomainProvisionedMember($pdo, $existingAccId)) {
                error_log('domainApiAutoCreateMemberAccountsUnderC168Company: resolved code occupied by non-domain member: ' . $useAccountId);
                continue;
            }
            try {
                $linkCoStmt->execute([$existingAccId, $c168NumericCompanyId]);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
            $finalizeMember($existingAccId, $useAccountId);
            continue;
        }

        try {
            $insertStmt->execute([$useAccountId, $ownerDisplayName]);
            $newAccId = (int) $pdo->lastInsertId();
            if ($newAccId <= 0) {
                continue;
            }
            try {
                $linkCoStmt->execute([$newAccId, $c168NumericCompanyId]);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
            $finalizeMember($newAccId, $useAccountId);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $findC168ScopedAccStmt->execute([$c168NumericCompanyId, $useAccountId]);
                $retryId = (int) ($findC168ScopedAccStmt->fetchColumn() ?: 0);
                if ($retryId > 0 && domainApiAccountLooksLikeDomainProvisionedMember($pdo, $retryId)) {
                    try {
                        $linkCoStmt->execute([$retryId, $c168NumericCompanyId]);
                    } catch (PDOException $e2) {
                        if ((int) ($e2->errorInfo[1] ?? 0) !== 1062) {
                            throw $e2;
                        }
                    }
                    $finalizeMember($retryId, $useAccountId);
                }
                continue;
            }
            throw $e;
        }
    }
}

/**
 * 根据 owner_id 获取 owner 及其公司列表（含到期日）
 */
function getOwnerWithCompanies(PDO $pdo, $owner_id) {
    $ownerId = (int) $owner_id;
    $groupCodes = domainApiOwnerGroupIdsForList($pdo, $ownerId);
    $groupIdsStr = $groupCodes !== [] ? implode(', ', $groupCodes) : null;

    $stmt = $pdo->prepare("
        SELECT o.id, o.owner_code, o.name, o.email, o.created_by,
               GROUP_CONCAT(NULLIF(TRIM(c.company_id), '') ORDER BY c.company_id SEPARATOR ', ') as companies
        FROM owner o
        LEFT JOIN company c ON o.id = c.owner_id
        WHERE o.id = ?
        GROUP BY o.id
    ");
    $stmt->execute([$ownerId]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$owner) {
        return null;
    }
    $owner['group_ids'] = $groupIdsStr;
    $stmt2 = $pdo->prepare("
        SELECT company_id, expiration_date, group_id
        FROM company
        WHERE owner_id = ?
          AND company_id IS NOT NULL AND TRIM(company_id) <> ''
        ORDER BY company_id
    ");
    $stmt2->execute([$ownerId]);
    $owner['companies_full'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    $owner['groups_full'] = domainApiOwnerGroupsFullForList($pdo, $ownerId);
    return $owner;
}

/**
 * 标准 JSON 响应：success, message, data
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

try {
    switch($action) {
        case 'list':
            if (!$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            try {
                $stmt = $pdo->query("
                    SELECT 
                        o.id,
                        o.owner_code,
                        o.name,
                        o.email,
                        o.created_by,
                        o.created_at,
                        GROUP_CONCAT(NULLIF(TRIM(c.company_id), '') ORDER BY c.company_id SEPARATOR ', ') as companies
                    FROM owner o
                    LEFT JOIN company c ON o.id = c.owner_id
                    GROUP BY o.id
                    ORDER BY o.owner_code ASC
                ");
                $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($domains as &$domain) {
                    $oid = (int) $domain['id'];
                    $gCodes = domainApiOwnerGroupIdsForList($pdo, $oid);
                    $domain['group_ids'] = $gCodes !== [] ? implode(', ', $gCodes) : null;
                    $stmt2 = $pdo->prepare("
                        SELECT company_id, expiration_date
                        FROM company
                        WHERE owner_id = ?
                          AND company_id IS NOT NULL AND TRIM(company_id) <> ''
                        ORDER BY company_id
                    ");
                    $stmt2->execute([$oid]);
                    $domain['companies_full'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                    $domain['groups_full'] = domainApiOwnerGroupsFullForList($pdo, $oid);
                }
                unset($domain);

                jsonResponse(true, 'OK', ['domains' => $domains]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error: ' . $e->getMessage(), null);
            }
            break;

        case 'create':
            if (!$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            // Create new owner
            $owner_code = strtoupper(trim($data['owner_code'] ?? ''));
            $name = trim($data['name'] ?? '');
            $emailValidation = validate_email($data['email'] ?? '');
            if (!$emailValidation['ok']) {
                echo json_encode(['success' => false, 'message' => 'Invalid email format', 'data' => null]);
                exit;
            }
            $email = $emailValidation['normalized'];
            $password = $data['password'] ?? '';
            $secondary_password = $data['secondary_password'] ?? '';
            $companies = $data['companies'] ?? '';
            $groups = $data['groups'] ?? '';
            
            // Validate required fields
            if (empty($owner_code) || empty($name) || empty($email) || empty($password) || empty($secondary_password)) {
                echo json_encode(['success' => false, 'message' => 'All fields are required', 'data' => null]);
                exit;
            }
            
            // 验证二级密码：必须是6位数字
            if (!preg_match('/^\d{6}$/', $secondary_password)) {
                echo json_encode(['success' => false, 'message' => 'Secondary password must be exactly 6 digits', 'data' => null]);
                exit;
            }

            $groups_data = domainApiNormalizeGroupsPayload($groups);
            $companies_data = domainApiFilterRealCompaniesPayload(domainApiNormalizeCompaniesPayload($companies));
            $overlapErr = domainApiValidateGroupsAndCompaniesExclusivity($groups_data, $companies_data);
            if ($overlapErr !== null) {
                echo json_encode(['success' => false, 'message' => $overlapErr, 'data' => null]);
                exit;
            }

            $dupGroupErr = domainApiValidateGroupCodesUniqueWithinPayload($groups_data);
            if ($dupGroupErr !== null) {
                echo json_encode(['success' => false, 'message' => $dupGroupErr, 'data' => null]);
                exit;
            }

            $dupInPayloadErr = domainApiValidateCompanyGroupCodesUniqueWithinPayload($companies_data);
            if ($dupInPayloadErr !== null) {
                echo json_encode(['success' => false, 'message' => $dupInPayloadErr, 'data' => null]);
                exit;
            }

            $crossOwnerErr = domainApiValidateCrossOwnerCodesIncludingGroups($pdo, $groups_data, $companies_data, null);
            if ($crossOwnerErr !== null) {
                echo json_encode(['success' => false, 'message' => $crossOwnerErr, 'data' => null]);
                exit;
            }
            
            // Hash passwords
            $hashed_password = secure_hash_password($password);
            $hashed_secondary_password = secure_hash_password($secondary_password);
            
            // DDL 在 MySQL 中会隐式提交并结束当前事务，须在 beginTransaction 之前执行
            ensureCompanyFeeShareColumn($pdo);
            ensureDomainListFeeSettingsTable($pdo);
            ensureAccountCreatedSourceColumn($pdo);
            ensureCompanyOwnerIdNullable($pdo);

            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Insert owner
                $stmt = $pdo->prepare("INSERT INTO owner (owner_code, name, email, password, secondary_password, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$owner_code, $name, $email, $hashed_password, $hashed_secondary_password, $_SESSION['login_id'] ?? 'system']);
                
                $owner_id = $pdo->lastInsertId();
                $loginId = $_SESSION['login_id'] ?? 'system';

                domainApiSaveOwnerGroups($pdo, (int) $owner_id, $groups_data, $loginId);
                
                // Insert companies if any（仅真实 company_id，不含 group 占位行）
                if (!empty($companies_data)) {
                    $insert = $pdo->prepare("INSERT INTO company (company_id, owner_id, created_by, expiration_date, permissions, group_id, fee_share_allocations) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $reattach = $pdo->prepare("UPDATE company SET owner_id = ?, expiration_date = ?, permissions = ?, group_id = ?, fee_share_allocations = ? WHERE id = ? AND owner_id IS NULL");
                    foreach ($companies_data as $company) {
                        $company_id = strtoupper(trim((string) ($company['company_id'] ?? '')));
                        if ($company_id === '') {
                            continue;
                        }
                        $expiration_date = !empty($company['expiration_date']) ? $company['expiration_date'] : null;
                        $permissions = (isset($company['permissions']) && is_array($company['permissions'])) ? json_encode($company['permissions']) : null;
                        $group_id = !empty($company['group_id']) ? strtoupper(trim((string) $company['group_id'])) : null;
                        $fee_share_json = feeShareAllocationsToJson(normalizeFeeShareAllocationsInput($company['fee_share_allocations'] ?? null));
                        $detachedPk = domainApiFindDetachedCompanyPk($pdo, $company_id);
                        if ($detachedPk !== null) {
                            $reattach->execute([$owner_id, $expiration_date, $permissions, $group_id, $fee_share_json, $detachedPk]);
                            continue;
                        }
                        $insert->execute([$company_id, $owner_id, $loginId, $expiration_date, $permissions, $group_id, $fee_share_json]);
                    }
                }

                domainApiSyncGroupCompanyMap($pdo, (int) $owner_id);
                domainApiDeleteGroupOnlyCompanyRows($pdo, (int) $owner_id);

                // 复用已标准化的 companies 数组，避免原始 JSON 字符串格式差异导致只提取到部分 company
                $provisionCompanyIds = domainApiExtractProvisionCompanyIds($companies_data);
                domainApiProvisionC168MemberAccountsForTenantCodes(
                    $pdo,
                    (bool) $hasC168Context,
                    (bool) $canUseC168DomainActions,
                    $name,
                    $owner_code,
                    $provisionCompanyIds
                );
                $provisionGroupIds = domainApiExtractProvisionGroupIds($groups_data);
                domainApiProvisionC168MemberAccountsForTenantCodes(
                    $pdo,
                    (bool) $hasC168Context,
                    (bool) $canUseC168DomainActions,
                    $name,
                    $owner_code,
                    $provisionGroupIds
                );

                domainApiApplyDomainListFeePaymentsFromPayload($pdo, $companies, $hasC168Context, $canUseC168DomainActions);
                domainApiApplyGroupDomainListFeePaymentsFromPayload($pdo, $groups, $hasC168Context, $canUseC168DomainActions);

                $pdo->commit();

                $owner = getOwnerWithCompanies($pdo, $owner_id);
                echo json_encode([
                    'success' => true,
                    'message' => 'Owner created successfully',
                    'data' => $owner
                ]);
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;
            
        case 'update':
            if (!$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            // Update existing owner
            $id = $data['id'] ?? 0;
            $name = trim($data['name'] ?? '');
            $emailValidation = validate_email($data['email'] ?? '');
            if (!$emailValidation['ok']) {
                echo json_encode(['success' => false, 'message' => 'Invalid email format', 'data' => null]);
                exit;
            }
            $email = $emailValidation['normalized'];
            $password = $data['password'] ?? '';
            $secondary_password = $data['secondary_password'] ?? '';
            $companies = $data['companies'] ?? '';
            $groups = $data['groups'] ?? '';
            
            if (empty($id) || empty($name) || empty($email)) {
                echo json_encode(['success' => false, 'message' => 'Required fields are missing', 'data' => null]);
                exit;
            }
            
            // 如果提供了二级密码，验证格式（只有C168的owner/admin可以修改）
            if (!empty($secondary_password)) {
                if (!$hasC168Context || !$isOwnerOrAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Only C168 owner/admin can modify secondary password', 'data' => null]);
                    exit;
                }
                
                // 验证二级密码：必须是6位数字
                if (!preg_match('/^\d{6}$/', $secondary_password)) {
                    echo json_encode(['success' => false, 'message' => 'Secondary password must be exactly 6 digits', 'data' => null]);
                    exit;
                }
            }

            $groups_data = domainApiNormalizeGroupsPayload($groups);
            $companies_data = domainApiFilterRealCompaniesPayload(domainApiNormalizeCompaniesPayload($companies));
            $overlapErr = domainApiValidateGroupsAndCompaniesExclusivity($groups_data, $companies_data);
            if ($overlapErr !== null) {
                echo json_encode(['success' => false, 'message' => $overlapErr, 'data' => null]);
                exit;
            }

            $dupGroupErr = domainApiValidateGroupCodesUniqueWithinPayload($groups_data);
            if ($dupGroupErr !== null) {
                echo json_encode(['success' => false, 'message' => $dupGroupErr, 'data' => null]);
                exit;
            }

            $dupInPayloadErr = domainApiValidateCompanyGroupCodesUniqueWithinPayload($companies_data);
            if ($dupInPayloadErr !== null) {
                echo json_encode(['success' => false, 'message' => $dupInPayloadErr, 'data' => null]);
                exit;
            }

            // 编辑保存：仅对新添加的 Company/Group ID 做跨 domain 唯一性校验（与前端 Add 按钮行为一致）
            $newGroupRows = domainApiFilterGroupsToNewCodes($pdo, (int) $id, $groups_data);
            $newCodeRows = domainApiFilterRowsToNewCompanyGroupCodes($pdo, (int) $id, $companies_data);
            if ($newGroupRows !== [] || $newCodeRows !== []) {
                // 排除当前 owner 已有 company/groups 行，避免重命名 Group 时关联公司的既有 company_id 误判为跨 domain 冲突
                $crossOwnerErr = domainApiValidateCrossOwnerCodesIncludingGroups($pdo, $newGroupRows, $newCodeRows, (int) $id);
                if ($crossOwnerErr !== null) {
                    echo json_encode(['success' => false, 'message' => $crossOwnerErr, 'data' => null]);
                    exit;
                }
            }
            
            // DDL 在 MySQL 中会隐式提交并结束当前事务，须在 beginTransaction 之前执行
            ensureCompanyFeeShareColumn($pdo);
            ensureDomainListFeeSettingsTable($pdo);
            ensureAccountCreatedSourceColumn($pdo);
            ensureCompanyOwnerIdNullable($pdo);

            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Update owner - 根据提供的字段构建UPDATE语句
                $updateFields = [];
                $updateValues = [];
                
                $updateFields[] = "name = ?";
                $updateValues[] = $name;
                
                $updateFields[] = "email = ?";
                $updateValues[] = $email;
                
                if (!empty($password)) {
                    $hashed_password = secure_hash_password($password);
                    $updateFields[] = "password = ?";
                    $updateValues[] = $hashed_password;
                }
                
                // 只有C168的owner/admin可以修改二级密码
                if (!empty($secondary_password) && $hasC168Context && $isOwnerOrAdmin) {
                    $hashed_secondary_password = secure_hash_password($secondary_password);
                    $updateFields[] = "secondary_password = ?";
                    $updateValues[] = $hashed_secondary_password;
                }
                
                $updateValues[] = $id;
                $sql = "UPDATE owner SET " . implode(', ', $updateFields) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateValues);

                $loginId = $_SESSION['login_id'] ?? 'system';
                domainApiSaveOwnerGroups($pdo, (int) $id, $groups_data, $loginId);
                
                // Get existing companies for this owner (real companies only)
                $stmt = $pdo->prepare("
                    SELECT id, company_id, group_id FROM company
                    WHERE owner_id = ?
                      AND company_id IS NOT NULL AND TRIM(company_id) <> ''
                ");
                $stmt->execute([$id]);
                $existing_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $existing_company_keys = array_map(function ($c) {
                    return strtoupper(trim((string) ($c['company_id'] ?? '')));
                }, $existing_companies);
                
                $new_companies_data = [];
                foreach ($companies_data as $company) {
                    $company_id = strtoupper(trim((string) ($company['company_id'] ?? '')));
                    if ($company_id === '') {
                        continue;
                    }
                    $group_id = !empty($company['group_id']) ? strtoupper(trim((string) $company['group_id'])) : null;
                    $new_companies_data[] = [
                        'key' => $company_id,
                        'company_id' => $company_id,
                        'previous_company_id' => strtoupper(trim((string) ($company['previous_company_id'] ?? ''))),
                        'expiration_date' => !empty($company['expiration_date']) ? $company['expiration_date'] : null,
                        'permissions' => (isset($company['permissions']) && is_array($company['permissions'])) ? $company['permissions'] : [],
                        'group_id' => $group_id,
                        'fee_share_allocations' => $company['fee_share_allocations'] ?? null,
                    ];
                }
                $new_company_keys = array_column($new_companies_data, 'key');

                domainApiApplyCompanyRenamesFromPayload($pdo, $new_companies_data, $existing_companies, $existing_company_keys);
                $new_company_keys = array_column($new_companies_data, 'key');
                
                $companies_to_delete = [];
                foreach ($existing_companies as $existing) {
                    $key = strtoupper(trim((string) ($existing['company_id'] ?? '')));
                    if ($key !== '' && !in_array($key, $new_company_keys, true)) {
                        $companies_to_delete[] = $existing;
                    }
                }
                
                // 从 Domain 移除：有账务 → 软解除（保留公司与 Payment）；无账务 → 物理清理
                if (!empty($companies_to_delete)) {
                    [$toDetach, $toHardDelete] = domainApiPartitionCompaniesForDomainRemoval($pdo, $companies_to_delete);
                    if ($toDetach !== []) {
                        domainApiDetachCompaniesFromOwner(
                            $pdo,
                            normalizeIds(array_column($toDetach, 'id')),
                            (int) $id
                        );
                    }
                    if ($toHardDelete !== []) {
                        $delete_db_ids = normalizeIds(array_column($toHardDelete, 'id'));
                        $delete_code_strings = array_map(
                            static fn ($row) => (string) ($row['company_id'] ?? ''),
                            $toHardDelete
                        );
                        domainApiCascadeDeleteCompanies($pdo, $delete_db_ids, $delete_code_strings);
                    }
                }
                
                // Find companies to add (in new list but not existing)
                $companies_to_add = [];
                foreach ($new_companies_data as $new_company) {
                    if (!in_array($new_company['key'], $existing_company_keys)) {
                        $companies_to_add[] = $new_company;
                    }
                }
                
                // Insert new companies
                if (!empty($companies_to_add)) {
                    $insert = $pdo->prepare("INSERT INTO company (company_id, owner_id, created_by, expiration_date, permissions, group_id, fee_share_allocations) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $reattach = $pdo->prepare("UPDATE company SET owner_id = ?, expiration_date = ?, permissions = ?, group_id = ?, fee_share_allocations = ? WHERE id = ? AND owner_id IS NULL");

                    foreach ($companies_to_add as $company_data) {
                        $permissions_json = !empty($company_data['permissions']) && is_array($company_data['permissions']) ? json_encode($company_data['permissions']) : null;
                        $fee_share_json = feeShareAllocationsToJson(normalizeFeeShareAllocationsInput($company_data['fee_share_allocations'] ?? null));
                        $detachedPk = domainApiFindDetachedCompanyPk($pdo, $company_data['company_id']);
                        if ($detachedPk !== null) {
                            $reattach->execute([
                                $id,
                                $company_data['expiration_date'],
                                $permissions_json,
                                $company_data['group_id'],
                                $fee_share_json,
                                $detachedPk,
                            ]);
                            continue;
                        }
                        $insert->execute([
                            $company_data['company_id'],
                            $id,
                            $_SESSION['login_id'] ?? 'system',
                            $company_data['expiration_date'],
                            $permissions_json,
                            $company_data['group_id'],
                            $fee_share_json,
                        ]);
                    }
                }

                // 对该 domain 表单中所有带 company_id 的公司同步 C168 下 MEMBER（幂等；便于历史数据补建）
                // 统一使用已标准化后的数组，确保批量公司都能触发自动建账
                $provisionFromUpdate = domainApiExtractProvisionCompanyIds($new_companies_data);
                $ocStmt = $pdo->prepare('SELECT UPPER(TRIM(owner_code)) FROM owner WHERE id = ? LIMIT 1');
                $ocStmt->execute([$id]);
                $updateOwnerCode = (string) ($ocStmt->fetchColumn() ?: '');
                domainApiProvisionC168MemberAccountsForTenantCodes(
                    $pdo,
                    (bool) $hasC168Context,
                    (bool) $canUseC168DomainActions,
                    $name,
                    $updateOwnerCode,
                    $provisionFromUpdate
                );
                $provisionGroupFromUpdate = domainApiExtractProvisionGroupIds($groups_data);
                domainApiProvisionC168MemberAccountsForTenantCodes(
                    $pdo,
                    (bool) $hasC168Context,
                    (bool) $canUseC168DomainActions,
                    $name,
                    $updateOwnerCode,
                    $provisionGroupFromUpdate
                );
                
                foreach ($new_companies_data as $new_company) {
                    if (in_array($new_company['key'], $existing_company_keys, true)) {
                        foreach ($existing_companies as $existing) {
                            $existing_key = strtoupper(trim((string) ($existing['company_id'] ?? '')));
                            if ($existing_key === $new_company['key']) {
                                $permissions_json = !empty($new_company['permissions']) && is_array($new_company['permissions']) ? json_encode($new_company['permissions']) : null;
                                $fee_share_json = feeShareAllocationsToJson(normalizeFeeShareAllocationsInput($new_company['fee_share_allocations'] ?? null));
                                $updateStmt = $pdo->prepare("UPDATE company SET expiration_date = ?, permissions = ?, group_id = ?, fee_share_allocations = ? WHERE id = ?");
                                $updateStmt->execute([$new_company['expiration_date'], $permissions_json, $new_company['group_id'], $fee_share_json, $existing['id']]);
                                break;
                            }
                        }
                    }
                }

                domainApiSyncGroupCompanyMap($pdo, (int) $id);
                domainApiDeleteGroupOnlyCompanyRows($pdo, (int) $id);

                domainApiApplyDomainListFeePaymentsFromPayload($pdo, $companies, $hasC168Context, $canUseC168DomainActions);
                domainApiApplyGroupDomainListFeePaymentsFromPayload($pdo, $groups, $hasC168Context, $canUseC168DomainActions);
                
                $pdo->commit();
                domain_api_clear_session_user_cache();

                $owner = getOwnerWithCompanies($pdo, $id);
                echo json_encode([
                    'success' => true,
                    'message' => 'Owner updated successfully',
                    'data' => $owner
                ]);
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;
            
        case 'delete':
            if (!$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            // Delete owner and cascade delete all related data手動
            $id = $data['id'] ?? 0;
            
            if (empty($id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid ID', 'data' => null]);
                exit;
            }

            ensureCompanyOwnerIdNullable($pdo);
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // 获取 owner 旗下的所有公司并安全级联删除
                $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ?");
                $stmt->execute([$id]);
                $ownerCompanyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                [$toDetach, $toHardDelete] = domainApiPartitionCompaniesForDomainRemoval($pdo, $ownerCompanyRows);
                if ($toDetach !== []) {
                    domainApiDetachCompaniesFromOwner(
                        $pdo,
                        normalizeIds(array_column($toDetach, 'id')),
                        (int) $id
                    );
                }
                if ($toHardDelete !== []) {
                    domainApiCascadeDeleteCompanies(
                        $pdo,
                        normalizeIds(array_column($toHardDelete, 'id')),
                        array_map(static fn ($row) => (string) ($row['company_id'] ?? ''), $toHardDelete)
                    );
                }
                
                // 删除 owner 直接创建的数据 (data_captures / transactions)
                $ownerCaptureStmt = $pdo->prepare("SELECT id FROM data_captures WHERE user_type = 'owner' AND created_by = ?");
                $ownerCaptureStmt->execute([$id]);
                $ownerCaptureIds = normalizeIds($ownerCaptureStmt->fetchAll(PDO::FETCH_COLUMN));
                
                if (!empty($ownerCaptureIds)) {
                    deleteByIds($pdo, 'data_capture_details', 'capture_id', $ownerCaptureIds);
                    deleteByIds($pdo, 'data_captures', 'id', $ownerCaptureIds);
                }
                
                deleteByIds($pdo, 'transactions', 'created_by', [$id]);
                
                // 删除 company -> owner
                deleteByIds($pdo, 'company', 'owner_id', [$id]);
                deleteByIds($pdo, 'owner', 'id', [$id]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Owner and all related data deleted successfully',
                    'data' => null
                ]);
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        /*
         * 添加 Group / Company 前校验：编码在整张 company 表上唯一（任一行的 company_id 或 group_id 视为同一命名空间）。
         * exclude_owner_id：编辑某 domain 时传入当前 owner.id，跳过其已有 company 行，避免与「尚未保存的旧行」误判冲突。
         * 新建 domain：不传或为 0，与全库比对。
         */
        case 'validate_domain_code':
            if (!$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            $rawCode = (string) ($data['code'] ?? '');
            $code = strtoupper(trim($rawCode));
            $excludeRaw = $data['exclude_owner_id'] ?? null;
            $excludeOwnerId = ($excludeRaw !== null && $excludeRaw !== '' && (int) $excludeRaw > 0)
                ? (int) $excludeRaw
                : null;
            if ($code === '') {
                jsonResponse(false, 'Code is required', ['available' => false], 400);
                exit;
            }
            $pseudoGroups = [['group_code' => $code]];
            $pseudoCompanies = [['company_id' => $code, 'group_id' => null]];
            $err = domainApiValidateCrossOwnerCodesIncludingGroups($pdo, $pseudoGroups, $pseudoCompanies, $excludeOwnerId);
            if ($err !== null) {
                jsonResponse(false, $err, ['available' => false, 'code' => $code], 200);
                exit;
            }
            jsonResponse(true, 'OK', ['available' => true, 'code' => $code]);
            break;
            
        case 'get_companies':
            // Get companies for a specific owner with expiration dates
            $owner_id = $data['owner_id'] ?? ($_GET['owner_id'] ?? 0);
            
            if (empty($owner_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid owner ID', 'data' => null]);
                exit;
            }
            
            try {
                ensureCompanyFeeShareColumn($pdo);
                $stmt = $pdo->prepare("
                    SELECT company_id, expiration_date, permissions, group_id, fee_share_allocations
                    FROM company
                    WHERE owner_id = ?
                      AND company_id IS NOT NULL AND TRIM(company_id) <> ''
                    ORDER BY company_id
                ");
                $stmt->execute([$owner_id]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $companies = [];
                foreach ($rows as $row) {
                    if (domainApiRowIsGroupEntity($row)) {
                        continue;
                    }
                    $perms = $row['permissions'];
                    if ($perms !== null && $perms !== '') {
                        $decoded = json_decode($perms, true);
                        $row['permissions'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                    } else {
                        $row['permissions'] = [];
                    }
                    $row['fee_share_allocations'] = normalizeFeeShareAllocationsInput($row['fee_share_allocations'] ?? null);
                    $companies[] = $row;
                }
                echo json_encode([
                    'success' => true,
                    'message' => 'OK',
                    'data' => ['companies' => $companies]
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'data' => null
                ]);
            }
            break;

        case 'get_groups':
            $owner_id = $data['owner_id'] ?? ($_GET['owner_id'] ?? 0);
            if (empty($owner_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid owner ID', 'data' => null]);
                exit;
            }
            try {
                ensureCompanyFeeShareColumn($pdo);
                $groups = domainApiFetchOwnerGroupsFormatted($pdo, (int) $owner_id);
                echo json_encode([
                    'success' => true,
                    'message' => 'OK',
                    'data' => ['groups' => $groups],
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'data' => null,
                ]);
            }
            break;
            
        case 'get_company_permissions':
            // Get permissions for a specific company
            $company_id = $data['company_id'] ?? '';
            
            if (empty($company_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid company ID', 'data' => null]);
                exit;
            }
            
            try {
                // 通过 company_id (字符串) 查找公司
                $stmt = $pdo->prepare("SELECT permissions FROM company WHERE company_id = ?");
                $stmt->execute([strtoupper($company_id)]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && $result['permissions'] !== null && $result['permissions'] !== '') {
                    $permissions = json_decode($result['permissions'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($permissions)) {
                        echo json_encode([
                            'success' => true,
                            'message' => 'OK',
                            'data' => ['permissions' => $permissions]
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true,
                            'message' => 'OK',
                            'data' => ['permissions' => []]
                        ]);
                    }
                } else {
                    // 无权限设置或公司不存在：返回空数组，不再默认全选
                    echo json_encode([
                        'success' => true,
                        'message' => 'OK',
                        'data' => ['permissions' => []]
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'data' => null
                ]);
            }
            break;
            
        case 'update_company_permissions':
            // Update permissions for a specific company
            $company_id = $data['company_id'] ?? '';
            $permissions = $data['permissions'] ?? [];

            if (empty($company_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid company ID', 'data' => null]);
                exit;
            }

            if (!is_array($permissions)) {
                echo json_encode(['success' => false, 'message' => 'Invalid permissions format', 'data' => null]);
                exit;
            }

            try {
                // 验证权限值
                $valid_permissions = ['Games', 'Bank', 'Loan', 'Rate', 'Money'];
                $filtered_permissions = array_intersect($permissions, $valid_permissions);

                // 转换为 JSON
                $permissions_json = json_encode(array_values($filtered_permissions));

                // 同步写入 expiration_date（前端传 null 时清除，不传时保持原有）
                if (array_key_exists('expiration_date', $data)) {
                    $expiration_date_val = (!empty($data['expiration_date']) && $data['expiration_date'] !== 'null')
                        ? $data['expiration_date']
                        : null;
                    $stmt = $pdo->prepare("UPDATE company SET permissions = ?, expiration_date = ? WHERE company_id = ?");
                    $stmt->execute([$permissions_json, $expiration_date_val, strtoupper($company_id)]);
                    domain_api_clear_session_user_cache();
                } else {
                    // 旧调用方式兼容：未传 expiration_date 时只更新权限
                    $stmt = $pdo->prepare("UPDATE company SET permissions = ? WHERE company_id = ?");
                    $stmt->execute([$permissions_json, strtoupper($company_id)]);
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Permissions updated successfully',
                    'data' => null
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage(),
                    'data' => null
                ]);
            }
            break;

        case 'get_company_share_settings':
            if (!isset($_SESSION['user_id']) || !$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            $shareCompanyCode = strtoupper(trim($data['company_id'] ?? ''));
            if ($shareCompanyCode === '') {
                jsonResponse(false, 'Invalid company ID', null);
                exit;
            }
            try {
                ensureCompanyFeeShareColumn($pdo);
                $stmt = $pdo->prepare("SELECT id, fee_share_allocations FROM company WHERE company_id = ? LIMIT 1");
                $stmt->execute([$shareCompanyCode]);
                $shareRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$shareRow) {
                    jsonResponse(true, 'OK', [
                        'allocations' => applyDefaultProfitAllocationIfEmpty($pdo, normalizeFeeShareAllocationsInput(null)),
                        'accounts' => fetchFeeSharePickerAccounts($pdo),
                        'accounts_profit' => fetchFeeShareProfitPickerAccounts($pdo),
                        'company_exists' => false,
                    ]);
                    break;
                }
                $shareAccounts = fetchFeeSharePickerAccounts($pdo);
                jsonResponse(true, 'OK', [
                    'allocations' => applyDefaultProfitAllocationIfEmpty(
                        $pdo,
                        normalizeFeeShareAllocationsInput($shareRow['fee_share_allocations'] ?? null)
                    ),
                    'accounts' => $shareAccounts,
                    'accounts_profit' => fetchFeeShareProfitPickerAccounts($pdo),
                    'company_exists' => true,
                ]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error: ' . $e->getMessage(), null);
            }
            break;

        case 'save_company_share_settings':
            if (!isset($_SESSION['user_id']) || !$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            $saveShareCode = strtoupper(trim($data['company_id'] ?? ''));
            if ($saveShareCode === '') {
                jsonResponse(false, 'Invalid company ID', null);
                exit;
            }
            $saveNormalized = applyDefaultProfitAllocationIfEmpty(
                $pdo,
                normalizeFeeShareAllocationsInput($data['fee_share_allocations'] ?? null)
            );
            try {
                ensureCompanyFeeShareColumn($pdo);
                $stmt = $pdo->prepare("SELECT id FROM company WHERE company_id = ? LIMIT 1");
                $stmt->execute([$saveShareCode]);
                $saveRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$saveRow) {
                    jsonResponse(false, 'Company not found in database yet; save the domain first.', null);
                    exit;
                }
                $saveCompanyPk = (int) $saveRow['id'];
                if (!feeShareAllocationsTargetsValid($pdo, $saveNormalized)) {
                    jsonResponse(false, 'Share %: Profit rows must use profit-role accounts under C168; Sales/CS/IT must use staff or agent under C168.', null);
                    exit;
                }
                $saveJson = feeShareAllocationsToJson($saveNormalized);
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare("UPDATE company SET fee_share_allocations = ? WHERE id = ?");
                    $up->execute([$saveJson, $saveCompanyPk]);
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }

                jsonResponse(true, 'Share settings saved', [
                    'fee_share_allocations' => $saveNormalized,
                    'domain_fee_payment_created' => false,
                    'domain_fee_skipped_duplicate' => false,
                    'domain_fee_amount' => null,
                    'c168_net_after_share' => null,
                    'commission_payment_created' => 0,
                    'commission_total' => null,
                    'commission_skipped_admin' => 0,
                    'commission_skipped_invalid_account' => 0,
                    'commission_skipped_no_from_account' => 0,
                    'commission_skipped_duplicate_account' => 0,
                    'profit_payment_created' => false,
                    'profit_amount' => null,
                    'domain_one_time_skipped' => false,
                ]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error: ' . $e->getMessage(), null);
            }
            break;

        case 'save_group_share_settings':
            if (!isset($_SESSION['user_id']) || !$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            $groupShareCode = strtoupper(trim($data['group_code'] ?? ''));
            if ($groupShareCode === '') {
                jsonResponse(false, 'Invalid group ID', null);
                exit;
            }
            if (!domainApiHasGroupsTable($pdo)) {
                jsonResponse(false, 'Groups table not available', null);
                exit;
            }
            $saveNormalized = applyDefaultProfitAllocationIfEmpty(
                $pdo,
                normalizeFeeShareAllocationsInput($data['fee_share_allocations'] ?? null)
            );
            try {
                ensureCompanyFeeShareColumn($pdo);
                $stmt = $pdo->prepare('SELECT id FROM `groups` WHERE UPPER(TRIM(group_code)) = ? LIMIT 1');
                $stmt->execute([$groupShareCode]);
                $groupRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$groupRow) {
                    jsonResponse(false, 'Group not found in database yet; save the domain first.', null);
                    exit;
                }
                if (!feeShareAllocationsTargetsValid($pdo, $saveNormalized)) {
                    jsonResponse(false, 'Share %: Profit rows must use profit-role accounts under C168; Sales/CS/IT must use staff or agent under C168.', null);
                    exit;
                }
                $feeJson = feeShareAllocationsToJson($saveNormalized);
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare('UPDATE `groups` SET fee_share_allocations = ? WHERE id = ?');
                    $up->execute([$feeJson, (int) $groupRow['id']]);
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                jsonResponse(true, 'Share settings saved', [
                    'fee_share_allocations' => $saveNormalized,
                ]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error: ' . $e->getMessage(), null);
            }
            break;

        case 'save_group_tenant_settings':
            if (!isset($_SESSION['user_id']) || !$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            $groupCode = strtoupper(trim($data['group_code'] ?? ''));
            if ($groupCode === '') {
                jsonResponse(false, 'Invalid group ID', null);
                exit;
            }
            if (!domainApiHasGroupsTable($pdo)) {
                jsonResponse(false, 'Groups table not available', null);
                exit;
            }
            $saveNormalized = applyDefaultProfitAllocationIfEmpty(
                $pdo,
                normalizeFeeShareAllocationsInput($data['fee_share_allocations'] ?? null)
            );
            $expDate = !empty($data['expiration_date']) ? (string) $data['expiration_date'] : null;
            $applyCommission = filter_var($data['apply_commission_payments'] ?? false, FILTER_VALIDATE_BOOLEAN);
            try {
                ensureCompanyFeeShareColumn($pdo);
                $stmt = $pdo->prepare('SELECT id FROM `groups` WHERE UPPER(TRIM(group_code)) = ? LIMIT 1');
                $stmt->execute([$groupCode]);
                $groupRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$groupRow) {
                    jsonResponse(false, 'Group not found in database yet; save the domain first.', null);
                    exit;
                }
                if (!feeShareAllocationsTargetsValid($pdo, $saveNormalized)) {
                    jsonResponse(false, 'Share %: Profit rows must use profit-role accounts under C168; Sales/CS/IT must use staff or agent under C168.', null);
                    exit;
                }
                $feeJson = feeShareAllocationsToJson($saveNormalized);
                $pdo->beginTransaction();
                try {
                    $up = $pdo->prepare('UPDATE `groups` SET expiration_date = ?, fee_share_allocations = ? WHERE id = ?');
                    $up->execute([$expDate, $feeJson, (int) $groupRow['id']]);
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                if ($applyCommission) {
                    domainApiApplyGroupDomainListFeePaymentsFromPayload($pdo, [[
                        'group_code' => $groupCode,
                        'expiration_date' => $expDate,
                        'permissions' => [],
                        'fee_share_allocations' => $saveNormalized,
                        'apply_commission_payments_on_domain_save' => true,
                    ]], $hasC168Context, $canUseC168DomainActions);
                }
                jsonResponse(true, 'Group settings saved', [
                    'group_code' => $groupCode,
                    'fee_share_allocations' => $saveNormalized,
                    'expiration_date' => $expDate,
                ]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error: ' . $e->getMessage(), null);
            }
            break;

        case 'get_domain_fee_settings':
            if (!$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            try {
                $row = fetchDomainListFeeSettingsRow($pdo);
                jsonResponse(true, 'OK', $row);
            } catch (Exception $e) {
                jsonResponse(false, 'Error: ' . $e->getMessage(), null);
            }
            break;

        case 'save_domain_fee_settings':
            if (!$hasC168Context || !$canUseC168DomainActions) {
                jsonResponse(false, 'Forbidden', null, 403);
                exit;
            }
            $companyPeriodInput = parseDomainListFeePeriodInput($data['company_period_prices'] ?? null);
            if ($companyPeriodInput === null) {
                $companyPeriodInput = parseDomainListFeePeriodInput($data['period_prices'] ?? null);
            }
            $groupPeriodInput = parseDomainListFeePeriodInput($data['group_period_prices'] ?? null);
            $companyPeriodPrices = normalizeDomainListFeePeriodPrices($companyPeriodInput);
            $groupPeriodPrices = normalizeDomainListFeePeriodPrices($groupPeriodInput);
            if ($companyPeriodPrices === [] || $groupPeriodPrices === []) {
                jsonResponse(false, 'Price must be a number or empty', null);
                exit;
            }
            $groupPrice = normalizeOptionalDecimal(
                $data['group_price'] ?? ($groupPeriodPrices['6months'] ?? null)
            );
            $companyPrice = normalizeOptionalDecimal(
                $data['company_price']
                ?? ($companyPeriodPrices['6months'] ?? ($data['price'] ?? null))
            );
            if ($groupPrice === false || $companyPrice === false) {
                jsonResponse(false, 'Price must be a number or empty', null);
                exit;
            }
            if ($companyPrice === null && ($companyPeriodPrices['6months'] ?? null) !== null) {
                $companyPrice = $companyPeriodPrices['6months'];
            }
            if ($groupPrice === null && ($groupPeriodPrices['6months'] ?? null) !== null) {
                $groupPrice = $groupPeriodPrices['6months'];
            }
            try {
                ensureDomainListFeePriceColumns($pdo);
                $unifiedPeriodJson = json_encode([
                    'company' => $companyPeriodPrices,
                    'group' => $groupPeriodPrices,
                ], JSON_UNESCAPED_UNICODE);
                $companyPeriodJson = json_encode($companyPeriodPrices, JSON_UNESCAPED_UNICODE);
                $groupPeriodJson = json_encode($groupPeriodPrices, JSON_UNESCAPED_UNICODE);
                $stmt = $pdo->prepare("
                    UPDATE `domain_list_fee_settings`
                    SET `group_price` = ?, `company_price` = ?, `price` = ?,
                        `company_period_prices` = ?, `group_period_prices` = ?, `period_prices` = ?
                    WHERE `id` = 1
                ");
                $stmt->execute([
                    $groupPrice,
                    $companyPrice,
                    $companyPrice,
                    $companyPeriodJson,
                    $groupPeriodJson,
                    $unifiedPeriodJson,
                ]);
                jsonResponse(true, 'Saved successfully', [
                    'price' => $companyPrice !== null ? money_out($companyPrice) : null,
                    'group_price' => $groupPrice !== null ? money_out($groupPrice) : null,
                    'company_price' => $companyPrice !== null ? money_out($companyPrice) : null,
                    'company_period_prices' => $companyPeriodPrices,
                    'period_prices' => $companyPeriodPrices,
                    'group_period_prices' => $groupPeriodPrices,
                ]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error: ' . $e->getMessage(), null);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action', 'data' => null]);
            break;
    }
    
} catch(PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'data' => null
    ]);
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'data' => null
    ]);
}
?>