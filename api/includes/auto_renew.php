<?php
/**
 * Auto renew subscription helpers.
 * Ensures company columns exist; shared by auto_renew_api.php.
 */

require_once __DIR__ . '/../c168/c168_domain_access.php';
require_once __DIR__ . '/../../includes/expiration_status.php';

const AUTO_RENEW_VALID_PERIODS = ['7days', '1month', '3months', '6months', '1year'];

function auto_renew_ensure_columns(PDO $pdo): void
{
    $columns = [
        'auto_renew_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'auto_renew_period' => 'VARCHAR(20) NULL DEFAULT NULL',
        'payment_customer_id' => 'VARCHAR(255) NULL DEFAULT NULL',
        'payment_subscription_id' => 'VARCHAR(255) NULL DEFAULT NULL',
        'auto_renew_updated_at' => 'DATETIME NULL DEFAULT NULL',
        'auto_renew_updated_by' => 'VARCHAR(50) NULL DEFAULT NULL',
    ];

    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM company LIKE ?');
        $stmt->execute([$name]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE company ADD COLUMN `$name` $definition");
        }
    }
}

function auto_renew_is_valid_period(?string $period): bool
{
    if ($period === null || $period === '') {
        return false;
    }
    return in_array($period, AUTO_RENEW_VALID_PERIODS, true);
}

function auto_renew_normalize_period(?string $period): ?string
{
    $period = trim((string) ($period ?? ''));
    return auto_renew_is_valid_period($period) ? $period : null;
}

function auto_renew_calculate_next_expiration(string $period, ?string $baseDate): ?string
{
    if (!auto_renew_is_valid_period($period)) {
        return null;
    }

    $base = $baseDate ? strtotime((string) $baseDate) : false;
    if ($base === false) {
        $base = strtotime(date('Y-m-d'));
    }
    if ($base === false) {
        return null;
    }

    $dt = new DateTime('@' . $base);
    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $dt->setTime(0, 0, 0);

    switch ($period) {
        case '7days':
            $dt->modify('+7 days');
            break;
        case '1month':
            $dt->modify('+1 month');
            break;
        case '3months':
            $dt->modify('+3 months');
            break;
        case '6months':
            $dt->modify('+6 months');
            break;
        case '1year':
            $dt->modify('+1 year');
            break;
        default:
            return null;
    }

    return $dt->format('Y-m-d');
}

function auto_renew_days_until(?string $expirationDate): ?int
{
    if ($expirationDate === null || trim((string) $expirationDate) === '') {
        return null;
    }
    $expTs = strtotime((string) $expirationDate);
    if ($expTs === false) {
        return null;
    }
    $today = strtotime(date('Y-m-d'));
    return (int) floor(($expTs - $today) / 86400);
}

function auto_renew_expiration_status(?int $daysLeft): string
{
    return company_expiration_status($daysLeft);
}

function auto_renew_can_edit(array $session, ?PDO $pdo = null): bool
{
    $userType = strtolower(trim((string) ($session['user_type'] ?? '')));
    $role = strtolower(trim((string) ($session['role'] ?? '')));
    if ($userType === 'member') {
        return false;
    }
    if ((int) ($session['read_only'] ?? 0) === 1) {
        return false;
    }
    if ($pdo instanceof PDO) {
        return userHasC168AutoRenewAccess($pdo, $role, $userType);
    }
    return in_array($role, c168AutoRenewAllowedRoles(), true);
}

function auto_renew_page_access(PDO $pdo, array $session): bool
{
    $role = strtolower(trim((string) ($session['role'] ?? '')));
    $userType = strtolower(trim((string) ($session['user_type'] ?? '')));
    return userHasC168AutoRenewAccess($pdo, $role, $userType);
}

function auto_renew_status_map_access(PDO $pdo, array $session): bool
{
    if (auto_renew_page_access($pdo, $session)) {
        return true;
    }
    $role = strtolower(trim((string) ($session['role'] ?? '')));
    return userSessionHasC168CompanyContext($pdo) && userHasC168DomainPageAccess($role);
}

function auto_renew_list_client_companies(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, company_id, group_id, expiration_date, auto_renew_enabled, auto_renew_period,
               auto_renew_updated_at, auto_renew_updated_by
        FROM company
        WHERE UPPER(company_id) <> 'C168'
        ORDER BY company_id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $list = [];
    foreach ($rows as $row) {
        $list[] = array_merge(
            auto_renew_format_row($row),
            ['company_numeric_id' => (int) ($row['id'] ?? 0)]
        );
    }
    return $list;
}

function auto_renew_resolve_target_company_id(PDO $pdo, array $input, array $session): ?int
{
    $targetId = isset($input['target_company_id']) ? (int) $input['target_company_id'] : 0;
    if ($targetId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND UPPER(company_id) <> 'C168' LIMIT 1");
    $stmt->execute([$targetId]);
    $found = $stmt->fetchColumn();
    return $found ? (int) $found : null;
}

function auto_renew_is_c168(?string $companyCode): bool
{
    return strtoupper(trim((string) $companyCode)) === 'C168';
}

function auto_renew_format_row(array $row): array
{
    $expirationDate = !empty($row['expiration_date']) ? (string) $row['expiration_date'] : null;
    $daysLeft = auto_renew_days_until($expirationDate);
    $enabled = (int) ($row['auto_renew_enabled'] ?? 0) === 1;
    $period = auto_renew_normalize_period($row['auto_renew_period'] ?? null);

    return [
        'company_code' => (string) ($row['company_id'] ?? ''),
        'group_id' => !empty($row['group_id']) ? (string) $row['group_id'] : null,
        'expiration_date' => $expirationDate,
        'days_until_expiration' => $daysLeft,
        'expiration_status' => auto_renew_expiration_status($daysLeft),
        'auto_renew_enabled' => $enabled,
        'auto_renew_period' => $period,
        'preview_next_expiration' => ($enabled && $period && $expirationDate)
            ? auto_renew_calculate_next_expiration($period, $expirationDate)
            : null,
        'auto_renew_updated_at' => $row['auto_renew_updated_at'] ?? null,
        'auto_renew_updated_by' => $row['auto_renew_updated_by'] ?? null,
        'has_payment_gateway' => !empty($row['payment_subscription_id']),
    ];
}

// ── Manual approval queue (company_auto_renew_request) ─────────────────────

require_once __DIR__ . '/money_decimal.php';
require_once __DIR__ . '/payment_delete_shared.php';
require_once __DIR__ . '/auto_renew_share_billing.php';

const AUTO_RENEW_WINDOW_DAYS = 30;
const AUTO_RENEW_HISTORY_DAYS = 90;

if (!function_exists('auto_renew_table_has_column')) {
    function auto_renew_table_has_column(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
            $stmt->execute([$column]);
            $cache[$key] = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

function auto_renew_has_groups_table(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'groups'");
        return $stmt && $stmt->fetch(PDO::FETCH_NUM) !== false;
    } catch (Exception $e) {
        return false;
    }
}

function auto_renew_normalize_entity_type(?string $entityType): string
{
    $type = strtolower(trim((string) ($entityType ?? 'company')));
    return $type === 'group' ? 'group' : 'company';
}

function auto_renew_request_table_has_index(PDO $pdo, string $indexName): bool
{
    try {
        $stmt = $pdo->query('SHOW INDEX FROM `company_auto_renew_request`');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            if (strcasecmp((string) ($row['Key_name'] ?? ''), $indexName) === 0) {
                return true;
            }
        }
    } catch (Exception $e) {
        return false;
    }
    return false;
}

function auto_renew_request_table_has_foreign_key(PDO $pdo, string $fkName): bool
{
    try {
        $stmt = $pdo->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'company_auto_renew_request'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = " . $pdo->quote($fkName) . '
            LIMIT 1
        ');
        return $stmt && $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        return false;
    }
}

function auto_renew_drop_request_foreign_key(PDO $pdo, string $fkName): void
{
    if (!auto_renew_request_table_has_foreign_key($pdo, $fkName)) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE `company_auto_renew_request` DROP FOREIGN KEY `' . str_replace('`', '', $fkName) . '`');
    } catch (Exception $e) {
        // ignore
    }
}

/**
 * MySQL UNIQUE treats NULLs as distinct — dedupe before split unique keys.
 */
function auto_renew_dedupe_request_rows(PDO $pdo): void
{
    try {
        $pdo->exec("
            DELETE r1 FROM company_auto_renew_request r1
            INNER JOIN company_auto_renew_request r2
              ON r1.entity_type = 'company'
             AND r2.entity_type = 'company'
             AND r1.company_id = r2.company_id
             AND r1.expiration_snapshot = r2.expiration_snapshot
             AND r1.id > r2.id
        ");
        $pdo->exec("
            DELETE r1 FROM company_auto_renew_request r1
            INNER JOIN company_auto_renew_request r2
              ON r1.entity_type = 'group'
             AND r2.entity_type = 'group'
             AND r1.group_id = r2.group_id
             AND r1.expiration_snapshot = r2.expiration_snapshot
             AND r1.id > r2.id
        ");
    } catch (Exception $e) {
        // best effort
    }
}

function auto_renew_ensure_request_unique_keys(PDO $pdo): void
{
    auto_renew_dedupe_request_rows($pdo);

    if (auto_renew_request_table_has_index($pdo, 'uq_auto_renew_tenant_exp')) {
        try {
            $pdo->exec('ALTER TABLE `company_auto_renew_request` DROP INDEX `uq_auto_renew_tenant_exp`');
        } catch (Exception $e) {
            // ignore
        }
    }

    if (!auto_renew_request_table_has_index($pdo, 'uq_auto_renew_company_exp')) {
        try {
            $pdo->exec("
                ALTER TABLE `company_auto_renew_request`
                ADD UNIQUE KEY `uq_auto_renew_company_exp` (`company_id`, `expiration_snapshot`)
            ");
        } catch (Exception $e) {
            // ignore
        }
    }

    if (!auto_renew_request_table_has_index($pdo, 'uq_auto_renew_group_exp')) {
        try {
            $pdo->exec("
                ALTER TABLE `company_auto_renew_request`
                ADD UNIQUE KEY `uq_auto_renew_group_exp` (`group_id`, `expiration_snapshot`)
            ");
        } catch (Exception $e) {
            // ignore
        }
    }
}

function auto_renew_ensure_request_table_columns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (!auto_renew_table_has_column($pdo, 'company_auto_renew_request', 'entity_type')) {
        $pdo->exec("
            ALTER TABLE `company_auto_renew_request`
            ADD COLUMN `entity_type` ENUM('company','group') NOT NULL DEFAULT 'company'
                COMMENT 'Tenant type: company or group'
                AFTER `id`
        ");
    }
    if (!auto_renew_table_has_column($pdo, 'company_auto_renew_request', 'group_id')) {
        $pdo->exec("
            ALTER TABLE `company_auto_renew_request`
            ADD COLUMN `group_id` BIGINT UNSIGNED NULL
                COMMENT 'FK groups.id when entity_type=group'
                AFTER `company_id`
        ");
    }
    try {
        $pdo->exec("
            ALTER TABLE `company_auto_renew_request`
            MODIFY COLUMN `company_id` INT UNSIGNED NULL
                COMMENT 'FK company.id when entity_type=company'
        ");
    } catch (Exception $e) {
        // may already be nullable
    }
    if (auto_renew_request_table_has_index($pdo, 'uq_auto_renew_tenant_exp')) {
        auto_renew_drop_request_foreign_key($pdo, 'fk_car_company');
        if (!auto_renew_request_table_has_index($pdo, 'idx_auto_renew_company')) {
            try {
                $pdo->exec('ALTER TABLE `company_auto_renew_request` ADD KEY `idx_auto_renew_company` (`company_id`)');
            } catch (Exception $e) {
                // ignore
            }
        }
    }
    auto_renew_ensure_request_unique_keys($pdo);
    if (!auto_renew_request_table_has_index($pdo, 'idx_auto_renew_group')) {
        try {
            $pdo->exec('ALTER TABLE `company_auto_renew_request` ADD KEY `idx_auto_renew_group` (`group_id`)');
        } catch (Exception $e) {
            // ignore
        }
    }
    if (!auto_renew_request_table_has_foreign_key($pdo, 'fk_car_company')) {
        try {
            $pdo->exec("
                ALTER TABLE `company_auto_renew_request`
                ADD CONSTRAINT `fk_car_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ");
        } catch (Exception $e) {
            // ignore
        }
    }
    if (auto_renew_has_groups_table($pdo) && !auto_renew_request_table_has_foreign_key($pdo, 'fk_car_group')) {
        try {
            $pdo->exec("
                ALTER TABLE `company_auto_renew_request`
                ADD CONSTRAINT `fk_car_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ");
        } catch (Exception $e) {
            // ignore
        }
    }
    $ensured = true;
}

function auto_renew_ensure_request_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $tableExists = false;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'company_auto_renew_request'");
        $tableExists = $stmt && $stmt->fetch(PDO::FETCH_NUM) !== false;
    } catch (Exception $e) {
        $tableExists = false;
    }
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `company_auto_renew_request` (
              `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `entity_type` enum('company','group') NOT NULL DEFAULT 'company',
              `company_id` int(10) UNSIGNED NULL,
              `group_id` bigint UNSIGNED NULL,
              `expiration_snapshot` date NOT NULL,
              `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
              `period` varchar(20) DEFAULT NULL,
              `price` decimal(25,8) DEFAULT NULL,
              `from_account_id` int(11) DEFAULT NULL,
              `to_account_id` int(11) DEFAULT NULL,
              `transaction_id` int(11) DEFAULT NULL,
              `new_expiration_date` date DEFAULT NULL,
              `processed_by` varchar(50) DEFAULT NULL,
              `processed_at` datetime DEFAULT NULL,
              `reject_reason` varchar(255) DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_auto_renew_company_exp` (`company_id`,`expiration_snapshot`),
              UNIQUE KEY `uq_auto_renew_group_exp` (`group_id`,`expiration_snapshot`),
              KEY `idx_auto_renew_status` (`status`),
              KEY `idx_auto_renew_company` (`company_id`),
              KEY `idx_auto_renew_group` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        auto_renew_ensure_request_table_columns($pdo);
    }
    $ensured = true;
}

function auto_renew_build_fee_sms(string $entityType, string $tenantCode, string $expirationSnapshot): string
{
    $code = strtoupper(trim($tenantCode));
    if (auto_renew_normalize_entity_type($entityType) === 'group') {
        return '[AUTO_RENEW|GROUP|' . $code . '|' . $expirationSnapshot . ']';
    }
    return '[AUTO_RENEW|' . $code . '|' . $expirationSnapshot . ']';
}

/**
 * @return array{entity_type:string, tenant_code:string, expiration_snapshot:string}|null
 */
function auto_renew_parse_fee_sms(string $sms): ?array
{
    $sms = trim($sms);
    if (preg_match('/^\[AUTO_RENEW\|GROUP\|([^|\]]+)\|([^|\]]+)/i', $sms, $m)) {
        return [
            'entity_type' => 'group',
            'tenant_code' => strtoupper(trim((string) $m[1])),
            'expiration_snapshot' => trim((string) $m[2]),
        ];
    }
    if (preg_match('/^\[AUTO_RENEW\|([^|\]]+)\|([^|\]]+)/i', $sms, $m)) {
        return [
            'entity_type' => 'company',
            'tenant_code' => strtoupper(trim((string) $m[1])),
            'expiration_snapshot' => trim((string) $m[2]),
        ];
    }
    return null;
}

function auto_renew_get_c168_pk(PDO $pdo): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM company WHERE UPPER(TRIM(company_id)) = 'C168' LIMIT 1");
    $stmt->execute();
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null || $v === '') {
        return null;
    }
    return (int) $v;
}

function auto_renew_ensure_domain_fee_settings(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'domain_list_fee_settings'");
        if (!$stmt || $stmt->fetch(PDO::FETCH_NUM) === false) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `domain_list_fee_settings` (
                    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                    `price` DECIMAL(25,8) NULL DEFAULT NULL,
                    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("INSERT IGNORE INTO `domain_list_fee_settings` (`id`, `price`) VALUES (1, NULL)");
        } else {
            $pdo->exec("INSERT IGNORE INTO `domain_list_fee_settings` (`id`, `price`) VALUES (1, NULL)");
        }
    } catch (Exception $e) {
        // best effort
    }
    foreach (['group_price', 'company_price'] as $col) {
        try {
            $pdo->exec("ALTER TABLE `domain_list_fee_settings` ADD COLUMN `{$col}` DECIMAL(25,8) NULL DEFAULT NULL");
        } catch (Exception $e) {
            // may exist
        }
    }
    foreach (['company_period_prices', 'period_prices', 'group_period_prices'] as $col) {
        if (auto_renew_table_has_column($pdo, 'domain_list_fee_settings', $col)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE `domain_list_fee_settings` ADD COLUMN `{$col}` LONGTEXT NULL DEFAULT NULL");
        } catch (Exception $e) {
            // may exist
        }
    }
    $ensured = true;
}

/**
 * @param array<string, mixed>|null $raw
 * @return array<string, ?string>
 */
function auto_renew_normalize_period_prices(?array $raw): array
{
    $out = [];
    foreach (AUTO_RENEW_VALID_PERIODS as $key) {
        $out[$key] = null;
    }
    if (!is_array($raw)) {
        return $out;
    }
    foreach (AUTO_RENEW_VALID_PERIODS as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $val = $raw[$key];
        if ($val === null || $val === '') {
            $out[$key] = null;
            continue;
        }
        try {
            $out[$key] = money_out(money_normalize((string) $val));
        } catch (Throwable $e) {
            return [];
        }
    }
    return $out;
}

/** @return array<string, mixed>|null */
function auto_renew_decode_fee_json_column($value): ?array
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
function auto_renew_extract_period_prices_from_payload(?array $decoded, string $kind): ?array
{
    if (!is_array($decoded)) {
        return null;
    }
    if ($kind === 'company' && isset($decoded['company']) && is_array($decoded['company'])) {
        $parsed = auto_renew_normalize_period_prices($decoded['company']);
        return $parsed === [] ? null : $parsed;
    }
    if ($kind === 'group' && isset($decoded['group']) && is_array($decoded['group'])) {
        $parsed = auto_renew_normalize_period_prices($decoded['group']);
        return $parsed === [] ? null : $parsed;
    }
    if ($kind === 'company') {
        foreach (AUTO_RENEW_VALID_PERIODS as $key) {
            if (array_key_exists($key, $decoded)) {
                $parsed = auto_renew_normalize_period_prices($decoded);
                return $parsed === [] ? null : $parsed;
            }
        }
    }
    if ($kind === 'group' && !isset($decoded['company']) && !isset($decoded['group'])) {
        foreach (AUTO_RENEW_VALID_PERIODS as $key) {
            if (array_key_exists($key, $decoded)) {
                $parsed = auto_renew_normalize_period_prices($decoded);
                return $parsed === [] ? null : $parsed;
            }
        }
    }
    return null;
}

/**
 * @param array<string, ?string> $periodPrices
 */
function auto_renew_apply_legacy_flat_period_price(array $periodPrices, ?string $legacyFlat): array
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
function auto_renew_decode_company_period_prices_from_row($rawCompanyPeriodPrices, $rawPeriodPrices, array $row): array
{
    $periodPrices = auto_renew_normalize_period_prices(null);
    $parsed = null;

    $companyDecoded = auto_renew_decode_fee_json_column($rawCompanyPeriodPrices);
    if ($companyDecoded !== null) {
        $parsed = auto_renew_extract_period_prices_from_payload($companyDecoded, 'company');
        if ($parsed === null && !isset($companyDecoded['company']) && !isset($companyDecoded['group'])) {
            $flat = auto_renew_normalize_period_prices($companyDecoded);
            $parsed = $flat === [] ? null : $flat;
        }
    }

    if ($parsed === null) {
        $decoded = auto_renew_decode_fee_json_column($rawPeriodPrices);
        $parsed = auto_renew_extract_period_prices_from_payload($decoded, 'company');
    }
    if (is_array($parsed)) {
        $periodPrices = $parsed;
    }
    $legacy = $row['company_price'] ?? $row['price'] ?? null;
    return auto_renew_apply_legacy_flat_period_price(
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
function auto_renew_decode_group_period_prices_from_row($rawGroupPeriodPrices, $rawPeriodPrices, array $row): array
{
    $periodPrices = auto_renew_normalize_period_prices(null);
    $parsed = null;

    $groupDecoded = auto_renew_decode_fee_json_column($rawGroupPeriodPrices);
    if ($groupDecoded !== null) {
        $parsed = auto_renew_extract_period_prices_from_payload($groupDecoded, 'group');
        if ($parsed === null && !isset($groupDecoded['company']) && !isset($groupDecoded['group'])) {
            $flat = auto_renew_normalize_period_prices($groupDecoded);
            $parsed = $flat === [] ? null : $flat;
        }
    }

    if ($parsed === null) {
        $periodDecoded = auto_renew_decode_fee_json_column($rawPeriodPrices);
        if (is_array($periodDecoded) && isset($periodDecoded['group']) && is_array($periodDecoded['group'])) {
            $parsed = auto_renew_extract_period_prices_from_payload($periodDecoded, 'group');
        }
    }

    if (is_array($parsed)) {
        $periodPrices = $parsed;
    }

    $legacy = $row['group_price'] ?? null;
    return auto_renew_apply_legacy_flat_period_price(
        $periodPrices,
        $legacy !== null && $legacy !== '' ? (string) $legacy : null
    );
}

/**
 * @return array{
 *   price: ?string,
 *   group_price: ?string,
 *   company_price: ?string,
 *   company_period_prices: array<string, ?string>,
 *   group_period_prices: array<string, ?string>
 * }
 */
function auto_renew_fetch_domain_fee_settings(PDO $pdo): array
{
    auto_renew_ensure_domain_fee_settings($pdo);
    $cols = ['price', 'group_price', 'company_price'];
    if (auto_renew_table_has_column($pdo, 'domain_list_fee_settings', 'company_period_prices')) {
        $cols[] = 'company_period_prices';
    }
    $cols[] = 'period_prices';
    if (auto_renew_table_has_column($pdo, 'domain_list_fee_settings', 'group_period_prices')) {
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
            'company_period_prices' => auto_renew_normalize_period_prices(null),
            'group_period_prices' => auto_renew_normalize_period_prices(null),
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
    $row['company_period_prices'] = auto_renew_decode_company_period_prices_from_row(
        $rawCompanyPeriodPrices,
        $rawPeriodPrices,
        $row
    );
    $row['group_period_prices'] = auto_renew_decode_group_period_prices_from_row(
        $rawGroupPeriodPrices,
        $rawPeriodPrices,
        $row
    );
    return $row;
}

/**
 * Same shape as domain get_domain_fee_settings (for normalizeDomainFeeSettingsFromApi).
 *
 * @return array{company_period_prices: array<string, string>, group_period_prices: array<string, string>}
 */
function auto_renew_fee_settings_for_api(PDO $pdo): array
{
    $settings = auto_renew_fetch_domain_fee_settings($pdo);
    $company = [];
    $group = [];
    foreach (AUTO_RENEW_VALID_PERIODS as $key) {
        $company[$key] = (string) ($settings['company_period_prices'][$key] ?? '');
        $group[$key] = (string) ($settings['group_period_prices'][$key] ?? '');
    }
    return [
        'company_period_prices' => $company,
        'group_period_prices' => $group,
    ];
}

/** Resolve renewal price by tenant type and period (company → Company Price, group → Group Price). */
function auto_renew_resolve_price_for_period(PDO $pdo, ?string $period, string $feeKind = 'company'): ?string
{
    $period = auto_renew_normalize_period($period);
    if (!$period) {
        return null;
    }
    $feeKind = auto_renew_normalize_entity_type($feeKind);
    $settings = auto_renew_fetch_domain_fee_settings($pdo);
    $prices = $feeKind === 'group'
        ? ($settings['group_period_prices'] ?? [])
        : ($settings['company_period_prices'] ?? []);
    $price = $prices[$period] ?? null;
    if ($price !== null && $price !== '' && money_cmp($price, '0') > 0) {
        return money_normalize($price);
    }
    return null;
}

function auto_renew_resolve_price_for_company(PDO $pdo): ?string
{
    return auto_renew_resolve_price_for_period($pdo, '6months', 'company')
        ?? auto_renew_resolve_price_for_period($pdo, '1year', 'company');
}

function auto_renew_resolve_c168_company_code_account(PDO $pdo, int $c168Pk, string $companyCode, int $excludeAccountId = 0): ?int
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
        $st->execute([$c168Pk, $code, (int) $excludeAccountId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int) $v : null;
    } catch (PDOException $e) {
        return null;
    }
}

function auto_renew_resolve_default_from_account(PDO $pdo, int $c168Pk, string $companyCode, int $excludeAccountId = 0): ?int
{
    $from = auto_renew_resolve_c168_company_code_account($pdo, $c168Pk, $companyCode, $excludeAccountId);
    if ($from && $from > 0) {
        return $from;
    }
    $src = strtoupper(trim($companyCode));
    if ($c168Pk <= 0 || $src === '') {
        return null;
    }
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
        if ($ownerUpper === '') {
            return null;
        }
        $legacyCode = preg_replace('/[^A-Z0-9]/', '', $ownerUpper) . '_' . $src;
        if ($legacyCode === '_' . $src) {
            $legacyCode = 'DOM_' . $src;
        }
        foreach ([$src, $legacyCode] as $accountCode) {
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
        }
    } catch (PDOException $e) {
        return null;
    }
    return null;
}

function auto_renew_resolve_c168_owner_account(PDO $pdo, int $c168Pk, int $excludeAccountId = 0): ?int
{
    if ($c168Pk <= 0) {
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
              AND a.id <> ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            LIMIT 1
        ");
        $st->execute([$c168Pk, (int) $excludeAccountId]);
        $v = $st->fetchColumn();
        return ($v !== false && $v !== null) ? (int) $v : null;
    } catch (PDOException $e) {
        return null;
    }
}

function auto_renew_resolve_default_to_account(PDO $pdo, int $c168Pk, int $excludeAccountId = 0): ?int
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
              AND UPPER(TRIM(COALESCE(a.account_id, ''))) = 'C168'
              AND a.id <> ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            LIMIT 1
        ");
        $st->execute([$c168Pk, (int) $excludeAccountId]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) {
            return (int) $v;
        }
    } catch (PDOException $e) {
        return null;
    }

    return auto_renew_resolve_c168_owner_account($pdo, $c168Pk, $excludeAccountId);
}

function auto_renew_account_code_from_map(?int $accountId, array $accountsById): ?string
{
    if (!$accountId || $accountId <= 0) {
        return null;
    }
    $acc = $accountsById[$accountId] ?? null;
    if (!is_array($acc)) {
        return null;
    }
    $code = trim((string) ($acc['account_code'] ?? ''));
    return $code !== '' ? $code : null;
}

function auto_renew_resolve_c168_default_currency_id(PDO $pdo, int $c168Pk): ?int
{
    if ($c168Pk <= 0 || !auto_renew_table_has_column($pdo, 'transactions', 'currency_id')) {
        return null;
    }
    try {
        $st = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? AND UPPER(TRIM(code)) = 'MYR' ORDER BY id ASC LIMIT 1");
        $st->execute([$c168Pk]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null) {
            return (int) $v;
        }
        $st2 = $pdo->prepare('SELECT id FROM currency WHERE company_id = ? ORDER BY id ASC LIMIT 1');
        $st2->execute([$c168Pk]);
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
 * @return list<array{id:int, account_code:string, name:string}>
 */
function auto_renew_list_c168_accounts(PDO $pdo, int $c168Pk): array
{
    if ($c168Pk <= 0) {
        return [];
    }
    try {
        $st = $pdo->prepare("
            SELECT a.id, a.account_id, a.name
            FROM account a
            INNER JOIN account_company ac ON ac.account_id = a.id
            WHERE ac.company_id = ?
              AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
            ORDER BY UPPER(TRIM(a.account_id)) ASC
        ");
        $st->execute([$c168Pk]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'account_code' => (string) ($row['account_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
            ];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

function auto_renew_company_in_window(?string $expirationDate): bool
{
    $days = auto_renew_days_until($expirationDate);
    if ($days === null) {
        return false;
    }
    return $days <= AUTO_RENEW_WINDOW_DAYS;
}

function auto_renew_sync_window_requests(PDO $pdo): void
{
    auto_renew_ensure_request_table($pdo);
    auto_renew_dedupe_request_rows($pdo);
    $stmt = $pdo->query("
        SELECT id, expiration_date
        FROM company
        WHERE UPPER(TRIM(company_id)) <> 'C168'
          AND expiration_date IS NOT NULL
          AND expiration_date <> ''
    ");
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $insertCompany = $pdo->prepare("
        INSERT IGNORE INTO company_auto_renew_request (entity_type, company_id, expiration_snapshot, status)
        VALUES ('company', ?, ?, 'pending')
    ");
    foreach ($companies as $row) {
        $exp = (string) ($row['expiration_date'] ?? '');
        if (!auto_renew_company_in_window($exp)) {
            continue;
        }
        $insertCompany->execute([(int) $row['id'], $exp]);
    }

    if (!auto_renew_has_groups_table($pdo)) {
        return;
    }
    $groupStmt = $pdo->query("
        SELECT id, expiration_date
        FROM `groups`
        WHERE expiration_date IS NOT NULL
          AND expiration_date <> ''
          AND (status IS NULL OR LOWER(TRIM(status)) = 'active')
    ");
    $groups = $groupStmt ? ($groupStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $insertGroup = $pdo->prepare("
        INSERT IGNORE INTO company_auto_renew_request (entity_type, group_id, expiration_snapshot, status)
        VALUES ('group', ?, ?, 'pending')
    ");
    foreach ($groups as $row) {
        $exp = (string) ($row['expiration_date'] ?? '');
        if (!auto_renew_company_in_window($exp)) {
            continue;
        }
        $insertGroup->execute([(int) $row['id'], $exp]);
    }
}

/**
 * @return array{company:int, group:int}
 */
function auto_renew_count_pending_by_entity(PDO $pdo): array
{
    auto_renew_sync_window_requests($pdo);
    $windowDays = (int) AUTO_RENEW_WINDOW_DAYS;
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM company_auto_renew_request r
        INNER JOIN company c ON c.id = r.company_id
        WHERE r.entity_type = 'company'
          AND r.status = 'pending'
          AND r.expiration_snapshot = c.expiration_date
          AND UPPER(TRIM(c.company_id)) <> 'C168'
          AND c.expiration_date IS NOT NULL
          AND DATEDIFF(c.expiration_date, CURDATE()) <= {$windowDays}
    ");
    $companyCnt = (int) ($stmt->fetchColumn() ?: 0);
    $groupCnt = 0;

    if (auto_renew_has_groups_table($pdo)) {
        $groupStmt = $pdo->query("
            SELECT COUNT(*)
            FROM company_auto_renew_request r
            INNER JOIN `groups` g ON g.id = r.group_id
            WHERE r.entity_type = 'group'
              AND r.status = 'pending'
              AND r.expiration_snapshot = g.expiration_date
              AND g.expiration_date IS NOT NULL
              AND DATEDIFF(g.expiration_date, CURDATE()) <= {$windowDays}
        ");
        $groupCnt = (int) ($groupStmt->fetchColumn() ?: 0);
    }

    return [
        'company' => $companyCnt,
        'group' => $groupCnt,
    ];
}

function auto_renew_count_pending(PDO $pdo): int
{
    $byEntity = auto_renew_count_pending_by_entity($pdo);
    return $byEntity['company'] + $byEntity['group'];
}

/**
 * Domain page company-chip renew badges removed — use Auto Renew page + sidebar pending count.
 * Kept for API compatibility; returns pending companies not yet renewed for current expiration.
 *
 * @return array<string, string> company_code => pending
 */
function auto_renew_status_map(PDO $pdo): array
{
    auto_renew_sync_window_requests($pdo);
    $windowDays = (int) AUTO_RENEW_WINDOW_DAYS;
    $map = [];
    $stmt = $pdo->query("
        SELECT UPPER(TRIM(c.company_id)) AS tenant_code
        FROM company_auto_renew_request r
        INNER JOIN company c ON c.id = r.company_id
        WHERE r.entity_type = 'company'
          AND UPPER(TRIM(c.company_id)) <> 'C168'
          AND r.status = 'pending'
          AND r.expiration_snapshot = c.expiration_date
          AND DATEDIFF(c.expiration_date, CURDATE()) <= {$windowDays}
          AND NOT EXISTS (
            SELECT 1
            FROM company_auto_renew_request ap
            WHERE ap.entity_type = 'company'
              AND ap.company_id = c.id
              AND ap.status = 'approved'
              AND ap.new_expiration_date = c.expiration_date
          )
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $code = (string) ($row['tenant_code'] ?? '');
        if ($code !== '') {
            $map[$code] = 'pending';
        }
    }
    if (!auto_renew_has_groups_table($pdo)) {
        return $map;
    }
    $groupStmt = $pdo->query("
        SELECT UPPER(TRIM(g.group_code)) AS tenant_code
        FROM company_auto_renew_request r
        INNER JOIN `groups` g ON g.id = r.group_id
        WHERE r.entity_type = 'group'
          AND r.status = 'pending'
          AND r.expiration_snapshot = g.expiration_date
          AND DATEDIFF(g.expiration_date, CURDATE()) <= {$windowDays}
          AND NOT EXISTS (
            SELECT 1
            FROM company_auto_renew_request ap
            WHERE ap.entity_type = 'group'
              AND ap.group_id = g.id
              AND ap.status = 'approved'
              AND ap.new_expiration_date = g.expiration_date
          )
    ");
    foreach ($groupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $code = (string) ($row['tenant_code'] ?? '');
        if ($code !== '') {
            $map[$code] = 'pending';
        }
    }
    return $map;
}

function auto_renew_period_display_label(?string $period): string
{
    $period = auto_renew_normalize_period($period);
    if (!$period) {
        return '';
    }
    $map = [
        '7days' => '7 days',
        '1month' => '1 month',
        '3months' => '3 months',
        '6months' => '6 months',
        '1year' => '1 year',
    ];
    return $map[$period] ?? $period;
}

function auto_renew_format_payment_description(string $tenantCode, ?string $period, string $entityType = 'company'): string
{
    $label = auto_renew_period_display_label($period);
    $code = strtoupper(trim($tenantCode));
    $prefix = auto_renew_normalize_entity_type($entityType) === 'group' ? 'Renew Group ' : 'Renew ';
    if ($label === '') {
        return $prefix . $code;
    }
    return $prefix . $code . ' | ' . $label;
}

/**
 * @return array{0:?string, 1:?string} [date_from, date_to] as Y-m-d
 */
function auto_renew_parse_list_date_range(?string $dateFrom, ?string $dateTo): array
{
    $from = trim((string) ($dateFrom ?? ''));
    $to = trim((string) ($dateTo ?? ''));
    if ($from === '' || $to === '') {
        return [null, null];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return [null, null];
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return [$from, $to];
}

function auto_renew_format_approval_row(array $row, PDO $pdo, int $c168Pk, array $accountsById): array
{
    $entityType = auto_renew_normalize_entity_type($row['entity_type'] ?? 'company');
    $companyCode = (string) ($row['company_code'] ?? '');
    $groupId = !empty($row['group_id']) ? (string) $row['group_id'] : null;
    $expirationDate = !empty($row['expiration_date']) ? (string) $row['expiration_date'] : null;
    $daysLeft = auto_renew_days_until($expirationDate);
    $requestStatus = (string) ($row['request_status'] ?? 'pending');
    $period = auto_renew_normalize_period($row['request_period'] ?? null);
    $savedPrice = null;
    if (!empty($row['request_price'])) {
        $savedPrice = money_out($row['request_price']);
    }
    if ($requestStatus !== 'pending' && $savedPrice !== null && $savedPrice !== '') {
        $price = money_normalize($savedPrice);
    } elseif ($period) {
        $price = auto_renew_resolve_price_for_period($pdo, $period, $entityType);
    } else {
        $price = null;
    }
    $fromId = !empty($row['from_account_id']) ? (int) $row['from_account_id'] : null;
    $toId = !empty($row['to_account_id']) ? (int) $row['to_account_id'] : null;
    $accountLookupCode = $companyCode;

    if (!$toId && $c168Pk > 0) {
        $toId = auto_renew_resolve_default_to_account($pdo, $c168Pk, (int) ($fromId ?? 0));
    }
    if (!$fromId && $c168Pk > 0) {
        $fromId = auto_renew_resolve_default_from_account($pdo, $c168Pk, $accountLookupCode, (int) ($toId ?? 0));
    }

    $defaultFrom = ($c168Pk > 0)
        ? auto_renew_resolve_default_from_account($pdo, $c168Pk, $accountLookupCode, (int) ($toId ?? 0))
        : null;
    $defaultTo = ($c168Pk > 0)
        ? auto_renew_resolve_default_to_account($pdo, $c168Pk, (int) ($fromId ?? 0))
        : null;

    $accountsResolved = $fromId > 0 && $toId > 0 && $fromId !== $toId;

    return [
        'request_id' => (int) ($row['request_id'] ?? 0),
        'entity_type' => $entityType,
        'deleted_payment_id' => !empty($row['deleted_payment_id']) ? (int) $row['deleted_payment_id'] : null,
        'is_payment_deleted' => !empty($row['is_payment_deleted']),
        'company_numeric_id' => (int) ($row['company_numeric_id'] ?? 0),
        'company_code' => $companyCode,
        'owner_name' => (string) ($row['owner_name'] ?? ''),
        'owner_id' => !empty($row['owner_id']) ? (int) $row['owner_id'] : null,
        'group_id' => $groupId,
        'price' => $price,
        'expiration_date' => $expirationDate,
        'expiration_snapshot' => !empty($row['expiration_snapshot']) ? (string) $row['expiration_snapshot'] : $expirationDate,
        'days_until_expiration' => $daysLeft,
        'expiration_status' => auto_renew_expiration_status($daysLeft),
        'status' => $requestStatus,
        'period' => $period,
        'from_account_id' => $fromId,
        'to_account_id' => $toId,
        'default_from_account_id' => $defaultFrom,
        'default_to_account_id' => $defaultTo,
        'from_account_code' => auto_renew_account_code_from_map($fromId, $accountsById),
        'to_account_code' => auto_renew_account_code_from_map($toId, $accountsById),
        'transaction_id' => !empty($row['transaction_id']) ? (int) $row['transaction_id'] : null,
        'new_expiration_date' => !empty($row['new_expiration_date']) ? (string) $row['new_expiration_date'] : null,
        'processed_by' => $row['processed_by'] ?? null,
        'processed_at' => $row['processed_at'] ?? null,
        'submitter' => $row['processed_by'] ?? null,
        'submitter_at' => $row['processed_at'] ?? null,
        'payment_description' => !empty($row['payment_description'])
            ? (string) $row['payment_description']
            : ($period ? auto_renew_format_payment_description($companyCode, $period, $entityType) : null),
        'reject_reason' => $row['reject_reason'] ?? null,
        'can_approve' => $requestStatus === 'pending'
            && empty($row['is_payment_deleted'])
            && $accountsResolved,
        'can_delete' => $requestStatus === 'approved'
            && (int) ($row['request_id'] ?? 0) > 0
            && !empty($row['transaction_id'])
            && empty($row['is_payment_deleted']),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function auto_renew_fetch_company_approval_raw_rows(
    PDO $pdo,
    string $filter,
    int $windowDays,
    int $historyDays,
    ?string $rangeFrom,
    ?string $rangeTo,
    bool $applyDateFilter
): array {
    $select = "
        SELECT
            r.id AS request_id,
            'company' AS entity_type,
            r.status AS request_status,
            r.period AS request_period,
            r.price AS request_price,
            r.from_account_id,
            r.to_account_id,
            r.transaction_id,
            r.new_expiration_date,
            r.expiration_snapshot,
            r.processed_by,
            r.processed_at,
            r.reject_reason,
            c.id AS company_numeric_id,
            c.company_id AS company_code,
            c.group_id,
            c.owner_id AS owner_id,
            c.expiration_date,
            COALESCE(o.name, '') AS owner_name
    ";

    if ($filter === 'approved' || $filter === 'rejected') {
        $sql = $select . "
            FROM company_auto_renew_request r
            INNER JOIN company c ON c.id = r.company_id
            LEFT JOIN owner o ON o.id = c.owner_id
            WHERE r.entity_type = 'company'
              AND r.status = ?
              AND UPPER(TRIM(c.company_id)) <> 'C168'
              AND r.processed_at >= DATE_SUB(NOW(), INTERVAL {$historyDays} DAY)
        ";
        $params = [$filter];
        if ($applyDateFilter) {
            $sql .= ' AND DATE(r.processed_at) >= ? AND DATE(r.processed_at) <= ?';
            $params[] = $rangeFrom;
            $params[] = $rangeTo;
        }
        $sql .= ' ORDER BY r.processed_at DESC, c.company_id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($filter === 'all') {
        $sql = $select . "
            FROM company c
            INNER JOIN company_auto_renew_request r
                ON r.company_id = c.id
               AND r.entity_type = 'company'
               AND r.expiration_snapshot = c.expiration_date
            LEFT JOIN owner o ON o.id = c.owner_id
            WHERE UPPER(TRIM(c.company_id)) <> 'C168'
              AND c.expiration_date IS NOT NULL
              AND (
                    (r.status = 'pending' AND DATEDIFF(c.expiration_date, CURDATE()) <= {$windowDays})
                    OR (
                        r.status IN ('approved','rejected')
                        AND r.processed_at >= DATE_SUB(NOW(), INTERVAL {$historyDays} DAY)
        ";
        $params = [];
        if ($applyDateFilter) {
            $sql .= ' AND DATE(r.processed_at) >= ? AND DATE(r.processed_at) <= ?';
            $params[] = $rangeFrom;
            $params[] = $rangeTo;
        }
        $sql .= "
                    )
              )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $pdo->query($select . "
        FROM company c
        INNER JOIN company_auto_renew_request r
            ON r.company_id = c.id
           AND r.entity_type = 'company'
           AND r.expiration_snapshot = c.expiration_date
        LEFT JOIN owner o ON o.id = c.owner_id
        WHERE UPPER(TRIM(c.company_id)) <> 'C168'
          AND c.expiration_date IS NOT NULL
          AND DATEDIFF(c.expiration_date, CURDATE()) <= {$windowDays}
          AND r.status = 'pending'
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @return list<array<string, mixed>>
 */
function auto_renew_fetch_group_approval_raw_rows(
    PDO $pdo,
    string $filter,
    int $windowDays,
    int $historyDays,
    ?string $rangeFrom,
    ?string $rangeTo,
    bool $applyDateFilter
): array {
    if (!auto_renew_has_groups_table($pdo)) {
        return [];
    }

    $select = "
        SELECT
            r.id AS request_id,
            'group' AS entity_type,
            r.status AS request_status,
            r.period AS request_period,
            r.price AS request_price,
            r.from_account_id,
            r.to_account_id,
            r.transaction_id,
            r.new_expiration_date,
            r.expiration_snapshot,
            r.processed_by,
            r.processed_at,
            r.reject_reason,
            g.id AS company_numeric_id,
            g.group_code AS company_code,
            NULL AS group_id,
            g.owner_id AS owner_id,
            g.expiration_date,
            COALESCE(o.name, '') AS owner_name
    ";

    if ($filter === 'approved' || $filter === 'rejected') {
        $sql = $select . "
            FROM company_auto_renew_request r
            INNER JOIN `groups` g ON g.id = r.group_id
            LEFT JOIN owner o ON o.id = g.owner_id
            WHERE r.entity_type = 'group'
              AND r.status = ?
              AND r.processed_at >= DATE_SUB(NOW(), INTERVAL {$historyDays} DAY)
        ";
        $params = [$filter];
        if ($applyDateFilter) {
            $sql .= ' AND DATE(r.processed_at) >= ? AND DATE(r.processed_at) <= ?';
            $params[] = $rangeFrom;
            $params[] = $rangeTo;
        }
        $sql .= ' ORDER BY r.processed_at DESC, g.group_code ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($filter === 'all') {
        $sql = $select . "
            FROM `groups` g
            INNER JOIN company_auto_renew_request r
                ON r.group_id = g.id
               AND r.entity_type = 'group'
               AND r.expiration_snapshot = g.expiration_date
            LEFT JOIN owner o ON o.id = g.owner_id
            WHERE g.expiration_date IS NOT NULL
              AND (
                    (r.status = 'pending' AND DATEDIFF(g.expiration_date, CURDATE()) <= {$windowDays})
                    OR (
                        r.status IN ('approved','rejected')
                        AND r.processed_at >= DATE_SUB(NOW(), INTERVAL {$historyDays} DAY)
        ";
        $params = [];
        if ($applyDateFilter) {
            $sql .= ' AND DATE(r.processed_at) >= ? AND DATE(r.processed_at) <= ?';
            $params[] = $rangeFrom;
            $params[] = $rangeTo;
        }
        $sql .= "
                    )
              )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $pdo->query($select . "
        FROM `groups` g
        INNER JOIN company_auto_renew_request r
            ON r.group_id = g.id
           AND r.entity_type = 'group'
           AND r.expiration_snapshot = g.expiration_date
        LEFT JOIN owner o ON o.id = g.owner_id
        WHERE g.expiration_date IS NOT NULL
          AND DATEDIFF(g.expiration_date, CURDATE()) <= {$windowDays}
          AND r.status = 'pending'
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function auto_renew_sort_merged_approval_raw_rows(array $rows, string $filter): array
{
    if ($filter === 'pending') {
        usort($rows, static function (array $a, array $b): int {
            $r = strcmp((string) ($a['expiration_date'] ?? ''), (string) ($b['expiration_date'] ?? ''));
            if ($r !== 0) {
                return $r;
            }
            $typeCmp = strcmp((string) ($a['entity_type'] ?? ''), (string) ($b['entity_type'] ?? ''));
            if ($typeCmp !== 0) {
                return $typeCmp;
            }
            return strcmp((string) ($a['company_code'] ?? ''), (string) ($b['company_code'] ?? ''));
        });
        return $rows;
    }
    if ($filter === 'all') {
        usort($rows, static function (array $a, array $b): int {
            $aPending = (($a['request_status'] ?? '') === 'pending') ? 0 : 1;
            $bPending = (($b['request_status'] ?? '') === 'pending') ? 0 : 1;
            if ($aPending !== $bPending) {
                return $aPending - $bPending;
            }
            $at = (string) ($a['processed_at'] ?? '9999-12-31');
            $bt = (string) ($b['processed_at'] ?? '9999-12-31');
            if ($at !== $bt) {
                return strcmp($bt, $at);
            }
            $r = strcmp((string) ($a['expiration_date'] ?? ''), (string) ($b['expiration_date'] ?? ''));
            if ($r !== 0) {
                return $r;
            }
            return strcmp((string) ($a['company_code'] ?? ''), (string) ($b['company_code'] ?? ''));
        });
        return $rows;
    }
    return $rows;
}

function auto_renew_count_window_requests(PDO $pdo, int $windowDays, ?string $entityType = null): array
{
    $entityFilter = $entityType !== null ? auto_renew_normalize_entity_type($entityType) : null;
    $counts = [
        'pending_cnt' => 0,
        'approved_cnt' => 0,
        'rejected_cnt' => 0,
        'total_cnt' => 0,
    ];

    if ($entityFilter !== null && $entityFilter !== 'company') {
        // skip company counts
    } else {
    $companyStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_cnt,
            SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_cnt,
            SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt,
            COUNT(*) AS total_cnt
        FROM company c
        INNER JOIN company_auto_renew_request r
            ON r.company_id = c.id
           AND r.entity_type = 'company'
           AND r.expiration_snapshot = c.expiration_date
        WHERE UPPER(TRIM(c.company_id)) <> 'C168'
          AND c.expiration_date IS NOT NULL
          AND DATEDIFF(c.expiration_date, CURDATE()) <= {$windowDays}
    ");
    $companyCounts = $companyStmt ? ($companyStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    foreach (['pending_cnt', 'approved_cnt', 'rejected_cnt', 'total_cnt'] as $key) {
        $counts[$key] = (int) ($companyCounts[$key] ?? 0);
    }
    }

    if ($entityFilter !== null && $entityFilter !== 'group') {
        return $counts;
    }

    if (auto_renew_has_groups_table($pdo)) {
        $groupStmt = $pdo->query("
            SELECT
                SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_cnt,
                SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_cnt,
                SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt,
                COUNT(*) AS total_cnt
            FROM `groups` g
            INNER JOIN company_auto_renew_request r
                ON r.group_id = g.id
               AND r.entity_type = 'group'
               AND r.expiration_snapshot = g.expiration_date
            WHERE g.expiration_date IS NOT NULL
              AND DATEDIFF(g.expiration_date, CURDATE()) <= {$windowDays}
        ");
        $groupCounts = $groupStmt ? ($groupStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        foreach (['pending_cnt', 'approved_cnt', 'rejected_cnt', 'total_cnt'] as $key) {
            $counts[$key] = (int) ($counts[$key] ?? 0) + (int) ($groupCounts[$key] ?? 0);
        }
    }

    return $counts;
}

/**
 * Deleted auto-renew PAYMENT rows (red-line history in same table).
 *
 * @return list<array<string, mixed>>
 */
function auto_renew_list_deleted_payment_rows(
    PDO $pdo,
    int $c168Pk,
    ?string $rangeFrom,
    ?string $rangeTo,
    bool $applyDateFilter,
    ?string $entityType = null
): array {
    $entityFilter = $entityType !== null ? auto_renew_normalize_entity_type($entityType) : null;
    if ($c168Pk <= 0) {
        return [];
    }
    try {
        payment_delete_ensure_transactions_deleted_table($pdo);
    } catch (Throwable $e) {
        return [];
    }

    $historyDays = (int) AUTO_RENEW_HISTORY_DAYS;
    $sql = "
        SELECT
            td.transaction_id,
            td.description,
            td.sms,
            td.amount,
            td.transaction_date,
            td.deleted_at,
            td.account_id,
            td.from_account_id,
            COALESCE(u.login_id, o.owner_code, '') AS deleted_by_login
        FROM transactions_deleted td
        LEFT JOIN user u ON u.id = td.deleted_by_user_id
        LEFT JOIN owner o ON o.id = td.deleted_by_owner_id
        WHERE td.company_id = ?
          AND td.transaction_type = 'PAYMENT'
          AND td.sms LIKE '[AUTO_RENEW|%'
    ";
    $params = [$c168Pk];
    if ($applyDateFilter && $rangeFrom && $rangeTo) {
        $sql .= ' AND DATE(td.deleted_at) >= ? AND DATE(td.deleted_at) <= ?';
        $params[] = $rangeFrom;
        $params[] = $rangeTo;
    } else {
        $sql .= " AND td.deleted_at >= DATE_SUB(NOW(), INTERVAL {$historyDays} DAY)";
    }
    $sql .= ' ORDER BY td.deleted_at DESC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deleted = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($deleted as $td) {
        $sms = (string) ($td['sms'] ?? '');
        $parsed = auto_renew_parse_fee_sms($sms);
        if ($parsed === null) {
            continue;
        }
        $rowEntityType = auto_renew_normalize_entity_type($parsed['entity_type']);
        if ($entityFilter !== null && $rowEntityType !== $entityFilter) {
            continue;
        }
        $tenantCode = $parsed['tenant_code'];
        $expSnapshot = $parsed['expiration_snapshot'];
        if ($tenantCode === '') {
            continue;
        }

        $companyRow = [];
        if ($rowEntityType === 'group' && auto_renew_has_groups_table($pdo)) {
            $groupStmt = $pdo->prepare("
                SELECT g.id, g.group_code, g.expiration_date, COALESCE(o.name, '') AS owner_name
                FROM `groups` g
                LEFT JOIN owner o ON o.id = g.owner_id
                WHERE UPPER(TRIM(g.group_code)) = ?
                LIMIT 1
            ");
            $groupStmt->execute([$tenantCode]);
            $groupRow = $groupStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $companyRow = [
                'id' => $groupRow['id'] ?? 0,
                'company_id' => $tenantCode,
                'group_id' => null,
                'expiration_date' => $groupRow['expiration_date'] ?? null,
                'owner_name' => $groupRow['owner_name'] ?? '',
            ];
        } else {
            $companyStmt = $pdo->prepare("
                SELECT c.id, c.company_id, c.group_id, c.expiration_date, COALESCE(o.name, '') AS owner_name
                FROM company c
                LEFT JOIN owner o ON o.id = c.owner_id
                WHERE UPPER(TRIM(c.company_id)) = ?
                LIMIT 1
            ");
            $companyStmt->execute([$tenantCode]);
            $companyRow = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $rowEntityType = 'company';
        }

        $period = null;
        $desc = trim((string) ($td['description'] ?? ''));
        if (preg_match('/^\s*Renew(?:\s+Group)?\s+[^|]+\|\s*(.+)$/i', $desc, $mPeriod)) {
            $periodLabel = trim((string) $mPeriod[1]);
            foreach (AUTO_RENEW_VALID_PERIODS as $p) {
                if (strcasecmp(auto_renew_period_display_label($p), $periodLabel) === 0) {
                    $period = $p;
                    break;
                }
            }
        }

        $amount = $td['amount'] ?? null;
        $displayCode = (string) ($companyRow['company_id'] ?? $tenantCode);
        $out[] = [
            'request_id' => 0,
            'entity_type' => $rowEntityType,
            'deleted_payment_id' => (int) ($td['transaction_id'] ?? 0),
            'is_payment_deleted' => true,
            'request_status' => 'approved',
            'request_period' => $period,
            'request_price' => ($amount !== null && $amount !== '') ? money_out($amount) : null,
            'from_account_id' => !empty($td['from_account_id']) ? (int) $td['from_account_id'] : null,
            'to_account_id' => !empty($td['account_id']) ? (int) $td['account_id'] : null,
            'transaction_id' => (int) ($td['transaction_id'] ?? 0),
            'new_expiration_date' => null,
            'expiration_snapshot' => $expSnapshot,
            'processed_by' => ($td['deleted_by_login'] ?? '') !== '' ? (string) $td['deleted_by_login'] : null,
            'processed_at' => $td['deleted_at'] ?? null,
            'reject_reason' => null,
            'company_numeric_id' => (int) ($companyRow['id'] ?? 0),
            'company_code' => $displayCode,
            'group_id' => $companyRow['group_id'] ?? null,
            'expiration_date' => !empty($companyRow['expiration_date']) ? (string) $companyRow['expiration_date'] : $expSnapshot,
            'owner_name' => (string) ($companyRow['owner_name'] ?? ''),
            'payment_description' => $desc !== '' ? $desc : auto_renew_format_payment_description($displayCode, $period, $rowEntityType),
        ];
    }
    return $out;
}

function auto_renew_history_status_count(PDO $pdo, string $status, int $historyDays, ?string $entityType = null): int
{
    $entityFilter = $entityType !== null ? auto_renew_normalize_entity_type($entityType) : null;
    $sql = "
        SELECT COUNT(*) FROM company_auto_renew_request
        WHERE status = ?
          AND processed_at >= DATE_SUB(NOW(), INTERVAL {$historyDays} DAY)
    ";
    $params = [$status];
    if ($entityFilter !== null) {
        $sql .= ' AND entity_type = ?';
        $params[] = $entityFilter;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * @return array{rows: list<array>, counts: array{pending:int, approved:int, rejected:int, total:int}}
 */
function auto_renew_list_approvals(
    PDO $pdo,
    ?string $statusFilter = null,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?string $entityType = null
): array {
    auto_renew_sync_window_requests($pdo);
    $c168Pk = auto_renew_get_c168_pk($pdo) ?? 0;
    $accounts = auto_renew_list_c168_accounts($pdo, $c168Pk);
    $accountsById = [];
    foreach ($accounts as $acc) {
        $accountsById[(int) $acc['id']] = $acc;
    }

    $entityFilter = $entityType !== null ? auto_renew_normalize_entity_type($entityType) : null;
    $windowDays = (int) AUTO_RENEW_WINDOW_DAYS;
    $historyDays = (int) AUTO_RENEW_HISTORY_DAYS;
    $filter = strtolower(trim((string) ($statusFilter ?? 'pending')));
    [$rangeFrom, $rangeTo] = auto_renew_parse_list_date_range($dateFrom, $dateTo);
    $applyDateFilter = $rangeFrom !== null && $rangeTo !== null && $filter !== 'pending';

    $rawRows = [];
    if ($entityFilter === null || $entityFilter === 'company') {
        $rawRows = array_merge(
            $rawRows,
            auto_renew_fetch_company_approval_raw_rows($pdo, $filter, $windowDays, $historyDays, $rangeFrom, $rangeTo, $applyDateFilter)
        );
    }
    if ($entityFilter === null || $entityFilter === 'group') {
        $rawRows = array_merge(
            $rawRows,
            auto_renew_fetch_group_approval_raw_rows($pdo, $filter, $windowDays, $historyDays, $rangeFrom, $rangeTo, $applyDateFilter)
        );
    }
    $rawRows = auto_renew_sort_merged_approval_raw_rows($rawRows, $filter);

    $rows = [];
    foreach ($rawRows as $row) {
        $rows[] = auto_renew_format_approval_row($row, $pdo, $c168Pk, $accountsById);
    }

    if ($filter === 'all') {
        $deletedRows = auto_renew_list_deleted_payment_rows($pdo, $c168Pk, $rangeFrom, $rangeTo, $applyDateFilter, $entityFilter);
        foreach ($deletedRows as $row) {
            $rows[] = auto_renew_format_approval_row($row, $pdo, $c168Pk, $accountsById);
        }
        usort($rows, static function (array $a, array $b): int {
            if (!empty($a['is_payment_deleted']) !== !empty($b['is_payment_deleted'])) {
                return !empty($a['is_payment_deleted']) ? 1 : -1;
            }
            $at = (string) ($a['submitter_at'] ?? $a['processed_at'] ?? '');
            $bt = (string) ($b['submitter_at'] ?? $b['processed_at'] ?? '');
            if ($at !== $bt) {
                return strcmp($bt, $at);
            }
            return strcmp((string) ($a['company_code'] ?? ''), (string) ($b['company_code'] ?? ''));
        });
    }

    $countsRow = auto_renew_count_window_requests($pdo, $windowDays, $entityFilter);

    $approvedHist = auto_renew_history_status_count($pdo, 'approved', $historyDays, $entityFilter);
    $rejectedHist = auto_renew_history_status_count($pdo, 'rejected', $historyDays, $entityFilter);

    $tabPendingCounts = auto_renew_count_pending_by_entity($pdo);

    return [
        'rows' => $rows,
        'accounts' => $accounts,
        'counts' => [
            'pending' => (int) ($countsRow['pending_cnt'] ?? 0),
            'approved' => max((int) ($countsRow['approved_cnt'] ?? 0), $approvedHist),
            'rejected' => max((int) ($countsRow['rejected_cnt'] ?? 0), $rejectedHist),
            'total' => (int) ($countsRow['total_cnt'] ?? 0),
        ],
        'tab_pending_counts' => $tabPendingCounts,
    ];
}

function auto_renew_request_status_is(?array $row, string $expected): bool
{
    if (!$row) {
        return false;
    }

    return strcasecmp(trim((string) ($row['status'] ?? '')), trim($expected)) === 0;
}

function auto_renew_select_request_row_sql(): string
{
    return "
        SELECT
            r.id,
            r.entity_type,
            r.company_id,
            r.group_id,
            r.expiration_snapshot,
            r.status,
            r.period,
            r.price,
            r.from_account_id,
            r.to_account_id,
            r.transaction_id,
            r.new_expiration_date,
            r.processed_by,
            r.processed_at,
            r.reject_reason,
            r.created_at,
            r.updated_at,
            CASE WHEN r.entity_type = 'group' THEN g.group_code ELSE c.company_id END AS company_code,
            CASE WHEN r.entity_type = 'group' THEN NULL ELSE c.group_id END AS tenant_group_label,
            CASE WHEN r.entity_type = 'group' THEN g.expiration_date ELSE c.expiration_date END AS expiration_date,
            CASE WHEN r.entity_type = 'group' THEN g.id ELSE c.id END AS company_numeric_id,
            COALESCE(o.name, '') AS owner_name
        FROM company_auto_renew_request r
        LEFT JOIN company c ON c.id = r.company_id AND r.entity_type = 'company'
        LEFT JOIN `groups` g ON g.id = r.group_id AND r.entity_type = 'group'
        LEFT JOIN owner o ON o.id = COALESCE(c.owner_id, g.owner_id)
    ";
}

function auto_renew_get_request_row(PDO $pdo, int $requestId): ?array
{
    if ($requestId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(auto_renew_select_request_row_sql() . '
        WHERE r.id = ?
        LIMIT 1
    ');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function auto_renew_find_request_row_by_transaction(PDO $pdo, int $transactionId, ?string $entityType = null): ?array
{
    if ($transactionId <= 0) {
        return null;
    }
    $sql = auto_renew_select_request_row_sql() . '
        WHERE r.transaction_id = ?
    ';
    $params = [$transactionId];
    if ($entityType !== null && $entityType !== '') {
        $sql .= ' AND r.entity_type = ?';
        $params[] = auto_renew_normalize_entity_type($entityType);
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array{0:int, 1:array<string, mixed>}
 */
function auto_renew_resolve_delete_target(PDO $pdo, int $requestId, array $input): array
{
    $entityType = auto_renew_normalize_entity_type($input['entity_type'] ?? 'company');
    $txnId = isset($input['transaction_id']) ? (int) $input['transaction_id'] : 0;

    if ($requestId > 0) {
        $row = auto_renew_get_request_row($pdo, $requestId);
        if ($row && auto_renew_request_status_is($row, 'approved')) {
            return [(int) $row['id'], $row];
        }
    }

    if ($txnId > 0) {
        $row = auto_renew_find_request_row_by_transaction($pdo, $txnId, $entityType);
        if ($row && auto_renew_request_status_is($row, 'approved')) {
            return [(int) $row['id'], $row];
        }
    }

    if ($requestId > 0) {
        $row = auto_renew_get_request_row($pdo, $requestId);
        if ($row) {
            return [$requestId, $row];
        }
    }

    throw new RuntimeException('Request not found');
}

function auto_renew_transaction_is_active(PDO $pdo, int $companyId, int $transactionId): bool
{
    if ($companyId <= 0 || $transactionId <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM transactions WHERE id = ? AND company_id = ? LIMIT 1');
        $st->execute([$transactionId, $companyId]);
        return $st->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

function auto_renew_prepare_payment_delete_environment(PDO $pdo): void
{
    // DDL must run before beginTransaction — MySQL implicit commit breaks nested commits otherwise.
    payment_delete_ensure_transactions_deleted_table($pdo);
    if (function_exists('bmp_ensureMaintenanceResendPendingTable')) {
        bmp_ensureMaintenanceResendPendingTable($pdo);
    }
}

function auto_renew_revert_approved_renewal(PDO $pdo, array $row, int $requestId, string $snapshot, string $entityType): void
{
    if ($entityType === 'group') {
        $updTenant = $pdo->prepare('UPDATE `groups` SET expiration_date = ? WHERE id = ?');
        $updTenant->execute([$snapshot, (int) $row['group_id']]);
    } else {
        $updTenant = $pdo->prepare('UPDATE company SET expiration_date = ? WHERE id = ?');
        $updTenant->execute([$snapshot, (int) $row['company_id']]);
    }

    $updReq = $pdo->prepare("
        UPDATE company_auto_renew_request
        SET status = 'pending',
            period = NULL,
            price = NULL,
            from_account_id = NULL,
            to_account_id = NULL,
            transaction_id = NULL,
            new_expiration_date = NULL,
            processed_by = NULL,
            processed_at = NULL,
            reject_reason = NULL
        WHERE id = ? AND status = 'approved'
    ");
    $updReq->execute([$requestId]);
    if ($updReq->rowCount() === 0) {
        throw new RuntimeException('Request was already changed');
    }
}

function auto_renew_validate_account_in_c168(PDO $pdo, int $c168Pk, int $accountId): bool
{
    if ($c168Pk <= 0 || $accountId <= 0) {
        return false;
    }
    $st = $pdo->prepare("
        SELECT 1 FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ? AND a.id = ?
          AND (a.status IS NULL OR LOWER(TRIM(a.status)) = 'active')
        LIMIT 1
    ");
    $st->execute([$c168Pk, $accountId]);
    return $st->fetchColumn() !== false;
}

/**
 * @return array{created:bool, transaction_id:?int, skipped_duplicate:bool, error:?string}
 */
function auto_renew_create_fee_payment(
    PDO $pdo,
    int $c168Pk,
    string $customerCompanyCode,
    string $expirationSnapshot,
    int $fromAccountId,
    int $toAccountId,
    string $amount,
    ?string $period,
    ?int $createdByUser,
    ?int $createdByOwner,
    string $entityType = 'company'
): array {
    $out = ['created' => false, 'transaction_id' => null, 'skipped_duplicate' => false, 'error' => null];
    $custCodeU = strtoupper(trim($customerCompanyCode));
    $feeSms = auto_renew_build_fee_sms($entityType, $custCodeU, $expirationSnapshot);
    $dupStmt = $pdo->prepare("
        SELECT id FROM transactions
        WHERE company_id = ? AND transaction_type = 'PAYMENT'
          AND (sms = ? OR sms LIKE ?)
        LIMIT 1
    ");
    $dupStmt->execute([$c168Pk, $feeSms, $feeSms . '|%']);
    if ($dupStmt->fetchColumn() !== false) {
        $out['skipped_duplicate'] = true;
        return $out;
    }
    if ($fromAccountId <= 0 || $toAccountId <= 0 || $fromAccountId === $toAccountId) {
        $out['error'] = 'invalid_accounts';
        return $out;
    }
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $desc = auto_renew_format_payment_description($custCodeU, $period, $entityType);
    $amountNorm = money_normalize($amount, 2);

    $hasCurrencyId = auto_renew_table_has_column($pdo, 'transactions', 'currency_id');
    $hasApprovalStatus = auto_renew_table_has_column($pdo, 'transactions', 'approval_status');
    $hasApprovedBy = auto_renew_table_has_column($pdo, 'transactions', 'approved_by');
    $hasApprovedByOwner = auto_renew_table_has_column($pdo, 'transactions', 'approved_by_owner');
    $hasApprovedAt = auto_renew_table_has_column($pdo, 'transactions', 'approved_at');
    $hasCreatedAt = auto_renew_table_has_column($pdo, 'transactions', 'created_at');
    $defaultTxnCurrencyId = $hasCurrencyId ? auto_renew_resolve_c168_default_currency_id($pdo, $c168Pk) : null;

    $insertCols = [
        'company_id' => $c168Pk,
        'transaction_type' => 'PAYMENT',
        'account_id' => $toAccountId,
        'from_account_id' => $fromAccountId,
        'amount' => $amountNorm,
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
    $sql = 'INSERT INTO transactions (`' . implode('`,`', $columns) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insertCols));
    $out['created'] = true;
    $out['transaction_id'] = (int) $pdo->lastInsertId();
    return $out;
}

function auto_renew_save_draft(PDO $pdo, int $requestId, array $input, array $session): array
{
    $row = auto_renew_get_request_row($pdo, $requestId);
    if (!$row) {
        throw new RuntimeException('Request not found');
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Only pending requests can be edited');
    }
    if (!auto_renew_can_edit($session, $pdo)) {
        throw new RuntimeException('Access denied');
    }

    $period = auto_renew_normalize_period($input['period'] ?? null);
    $fromId = isset($input['from_account_id']) ? (int) $input['from_account_id'] : null;
    $toId = isset($input['to_account_id']) ? (int) $input['to_account_id'] : null;
    $c168Pk = auto_renew_get_c168_pk($pdo) ?? 0;

    if ($fromId !== null && $fromId > 0 && !auto_renew_validate_account_in_c168($pdo, $c168Pk, $fromId)) {
        throw new RuntimeException('Invalid from account');
    }
    if ($toId !== null && $toId > 0 && !auto_renew_validate_account_in_c168($pdo, $c168Pk, $toId)) {
        throw new RuntimeException('Invalid to account');
    }

    $entityType = auto_renew_normalize_entity_type($row['entity_type'] ?? 'company');
    $price = $period
        ? auto_renew_resolve_price_for_period($pdo, $period, $entityType)
        : null;

    $upd = $pdo->prepare('
        UPDATE company_auto_renew_request
        SET period = ?, from_account_id = ?, to_account_id = ?, price = ?
        WHERE id = ? AND status = \'pending\'
    ');
    $upd->execute([
        $period,
        ($fromId && $fromId > 0) ? $fromId : null,
        ($toId && $toId > 0) ? $toId : null,
        $price,
        $requestId,
    ]);

    $updated = auto_renew_get_request_row($pdo, $requestId);
    $c168Pk = auto_renew_get_c168_pk($pdo) ?? 0;
    $formatted = auto_renew_format_approval_row([
        'request_id' => $updated['id'],
        'entity_type' => $updated['entity_type'] ?? $entityType,
        'request_status' => $updated['status'],
        'request_period' => $updated['period'],
        'from_account_id' => $updated['from_account_id'],
        'to_account_id' => $updated['to_account_id'],
        'transaction_id' => $updated['transaction_id'],
        'new_expiration_date' => $updated['new_expiration_date'],
        'expiration_snapshot' => $updated['expiration_snapshot'],
        'processed_by' => $updated['processed_by'],
        'processed_at' => $updated['processed_at'],
        'reject_reason' => $updated['reject_reason'],
        'company_numeric_id' => $updated['company_numeric_id'],
        'company_code' => $updated['company_code'],
        'group_id' => $updated['tenant_group_label'] ?? null,
        'expiration_date' => $updated['expiration_date'],
        'owner_name' => $updated['owner_name'],
    ], $pdo, $c168Pk, []);

    return $formatted;
}

function auto_renew_approve(PDO $pdo, int $requestId, array $input, array $session): array
{
    if (!auto_renew_can_edit($session, $pdo)) {
        throw new RuntimeException('Access denied');
    }
    $row = auto_renew_get_request_row($pdo, $requestId);
    if (!$row) {
        throw new RuntimeException('Request not found');
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Request is not pending');
    }

    $period = auto_renew_normalize_period($input['period'] ?? ($row['period'] ?? null));
    $fromId = isset($input['from_account_id']) ? (int) $input['from_account_id'] : (int) ($row['from_account_id'] ?? 0);
    $toId = isset($input['to_account_id']) ? (int) $input['to_account_id'] : (int) ($row['to_account_id'] ?? 0);
    $c168Pk = auto_renew_get_c168_pk($pdo);
    if (!$c168Pk) {
        throw new RuntimeException('C168 company not found');
    }

    $companyCode = (string) ($row['company_code'] ?? '');
    if ($toId <= 0) {
        $toId = (int) (auto_renew_resolve_default_to_account($pdo, $c168Pk, $fromId > 0 ? $fromId : 0) ?? 0);
    }
    if ($fromId <= 0) {
        $fromId = (int) (auto_renew_resolve_default_from_account(
            $pdo,
            $c168Pk,
            $companyCode,
            $toId > 0 ? $toId : 0
        ) ?? 0);
    }

    if (!$period) {
        throw new RuntimeException('Renewal period is required');
    }
    if ($fromId <= 0 || $toId <= 0) {
        throw new RuntimeException('From and To accounts are required');
    }
    if ($fromId === $toId) {
        throw new RuntimeException('From and To accounts must differ');
    }
    if (!auto_renew_validate_account_in_c168($pdo, $c168Pk, $fromId) || !auto_renew_validate_account_in_c168($pdo, $c168Pk, $toId)) {
        throw new RuntimeException('Invalid account selection');
    }

    $entityType = auto_renew_normalize_entity_type($row['entity_type'] ?? 'company');
    $price = auto_renew_resolve_price_for_period($pdo, $period, $entityType);
    if ($price === null || money_cmp($price, '0') <= 0) {
        throw new RuntimeException('Domain renewal price is not configured. Set it in Domain first.');
    }

    $baseExp = (string) ($row['expiration_date'] ?? $row['expiration_snapshot'] ?? '');
    $newExp = auto_renew_calculate_next_expiration($period, $baseExp);
    if (!$newExp) {
        throw new RuntimeException('Could not calculate new expiration date');
    }

    $processedBy = (string) ($session['login_id'] ?? 'system');
    $createdByUser = isset($session['user_id']) ? (int) $session['user_id'] : null;
    $createdByOwner = isset($session['owner_id']) ? (int) $session['owner_id'] : null;
    $companyCode = (string) ($row['company_code'] ?? '');
    $snapshot = (string) ($row['expiration_snapshot'] ?? '');

    $poolId = auto_renew_resolve_fee_pool_account_id($pdo, $c168Pk, $companyCode, $entityType, $toId);
    if ($poolId <= 0) {
        throw new RuntimeException('Renewal pool account is required');
    }
    if ($fromId === $poolId) {
        throw new RuntimeException('From and pool accounts must differ');
    }

    $pdo->beginTransaction();
    try {
        $pay = auto_renew_create_fee_payment(
            $pdo,
            $c168Pk,
            $companyCode,
            $snapshot,
            $fromId,
            $poolId,
            $price,
            $period,
            $createdByUser,
            $createdByOwner,
            $entityType
        );
        if ($pay['skipped_duplicate']) {
            throw new RuntimeException('Renewal payment already exists for this cycle');
        }
        if (!$pay['created']) {
            throw new RuntimeException('Failed to create renewal payment');
        }

        auto_renew_apply_share_billing_on_approve(
            $pdo,
            $c168Pk,
            $companyCode,
            $snapshot,
            $entityType,
            money_normalize($price),
            $poolId,
            $createdByUser,
            $createdByOwner
        );

        if ($entityType === 'group') {
            $updTenant = $pdo->prepare('UPDATE `groups` SET expiration_date = ? WHERE id = ?');
            $updTenant->execute([$newExp, (int) $row['group_id']]);
        } else {
            $updTenant = $pdo->prepare('UPDATE company SET expiration_date = ? WHERE id = ?');
            $updTenant->execute([$newExp, (int) $row['company_id']]);
        }

        $updReq = $pdo->prepare("
            UPDATE company_auto_renew_request
            SET status = 'approved',
                period = ?,
                price = ?,
                from_account_id = ?,
                to_account_id = ?,
                transaction_id = ?,
                new_expiration_date = ?,
                processed_by = ?,
                processed_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $updReq->execute([
            $period,
            money_normalize($price),
            $fromId,
            $poolId,
            $pay['transaction_id'],
            $newExp,
            $processedBy,
            $requestId,
        ]);

        if ($updReq->rowCount() === 0) {
            throw new RuntimeException('Request was already processed');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $updated = auto_renew_get_request_row($pdo, $requestId);
    return auto_renew_format_approval_row([
        'request_id' => $updated['id'],
        'entity_type' => $updated['entity_type'] ?? $entityType,
        'request_status' => $updated['status'],
        'request_period' => $updated['period'],
        'from_account_id' => $updated['from_account_id'],
        'to_account_id' => $updated['to_account_id'],
        'transaction_id' => $updated['transaction_id'],
        'new_expiration_date' => $updated['new_expiration_date'],
        'expiration_snapshot' => $updated['expiration_snapshot'],
        'processed_by' => $updated['processed_by'],
        'processed_at' => $updated['processed_at'],
        'reject_reason' => $updated['reject_reason'],
        'company_numeric_id' => $updated['company_numeric_id'],
        'company_code' => $updated['company_code'],
        'group_id' => $updated['tenant_group_label'] ?? null,
        'expiration_date' => $newExp,
        'owner_name' => $updated['owner_name'],
    ], $pdo, $c168Pk, []);
}

function auto_renew_reject(PDO $pdo, int $requestId, array $input, array $session): array
{
    if (!auto_renew_can_edit($session, $pdo)) {
        throw new RuntimeException('Access denied');
    }
    $row = auto_renew_get_request_row($pdo, $requestId);
    if (!$row) {
        throw new RuntimeException('Request not found');
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Request is not pending');
    }

    $upd = $pdo->prepare("
        UPDATE company_auto_renew_request
        SET period = NULL,
            from_account_id = NULL,
            to_account_id = NULL,
            price = NULL,
            reject_reason = NULL
        WHERE id = ? AND status = 'pending'
    ");
    $upd->execute([$requestId]);

    $updated = auto_renew_get_request_row($pdo, $requestId);
    $c168Pk = auto_renew_get_c168_pk($pdo) ?? 0;
    return auto_renew_format_approval_row([
        'request_id' => $updated['id'],
        'entity_type' => $updated['entity_type'] ?? 'company',
        'request_status' => $updated['status'],
        'request_period' => $updated['period'],
        'from_account_id' => $updated['from_account_id'],
        'to_account_id' => $updated['to_account_id'],
        'transaction_id' => $updated['transaction_id'],
        'new_expiration_date' => $updated['new_expiration_date'],
        'expiration_snapshot' => $updated['expiration_snapshot'],
        'processed_by' => $updated['processed_by'],
        'processed_at' => $updated['processed_at'],
        'reject_reason' => $updated['reject_reason'],
        'company_numeric_id' => $updated['company_numeric_id'],
        'company_code' => $updated['company_code'],
        'group_id' => $updated['tenant_group_label'] ?? null,
        'expiration_date' => $updated['expiration_date'],
        'owner_name' => $updated['owner_name'],
    ], $pdo, $c168Pk, []);
}

function auto_renew_delete(PDO $pdo, int $requestId, array $session, array $input = []): array
{
    if (!auto_renew_can_edit($session, $pdo)) {
        throw new RuntimeException('Access denied');
    }
    [$requestId, $row] = auto_renew_resolve_delete_target($pdo, $requestId, $input);
    if (!auto_renew_request_status_is($row, 'approved')) {
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status === 'pending') {
            throw new RuntimeException('This renewal is no longer approved. Refresh the page and try again.');
        }
        throw new RuntimeException('Only approved renewals can be deleted');
    }
    $txnId = (int) ($row['transaction_id'] ?? 0);
    if ($txnId <= 0) {
        throw new RuntimeException('No payment linked to this renewal');
    }

    $c168Pk = auto_renew_get_c168_pk($pdo);
    if (!$c168Pk) {
        throw new RuntimeException('C168 company not found');
    }

    $snapshot = (string) ($row['expiration_snapshot'] ?? '');
    if ($snapshot === '') {
        throw new RuntimeException('Missing expiration snapshot');
    }
    $entityType = auto_renew_normalize_entity_type($row['entity_type'] ?? 'company');
    $companyCode = (string) ($row['company_code'] ?? '');

    auto_renew_prepare_payment_delete_environment($pdo);

    $pdo->beginTransaction();
    try {
        $idsToDelete = auto_renew_collect_renewal_billing_transaction_ids(
            $pdo,
            $c168Pk,
            $txnId,
            $companyCode,
            $snapshot,
            $entityType
        );
        if ($idsToDelete !== []) {
            auto_renew_delete_c168_transaction_ids($pdo, $c168Pk, $idsToDelete, $session);
        }

        auto_renew_revert_approved_renewal($pdo, $row, $requestId, $snapshot, $entityType);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        payment_delete_clear_tx_search_cache();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $updated = auto_renew_get_request_row($pdo, $requestId);
    return auto_renew_format_approval_row([
        'request_id' => $updated['id'],
        'entity_type' => $updated['entity_type'] ?? $entityType,
        'request_status' => $updated['status'],
        'request_period' => $updated['period'],
        'from_account_id' => $updated['from_account_id'],
        'to_account_id' => $updated['to_account_id'],
        'transaction_id' => $updated['transaction_id'],
        'new_expiration_date' => $updated['new_expiration_date'],
        'expiration_snapshot' => $updated['expiration_snapshot'],
        'processed_by' => $updated['processed_by'],
        'processed_at' => $updated['processed_at'],
        'reject_reason' => $updated['reject_reason'],
        'company_numeric_id' => $updated['company_numeric_id'],
        'company_code' => $updated['company_code'],
        'group_id' => $updated['tenant_group_label'] ?? null,
        'expiration_date' => $snapshot,
        'owner_name' => $updated['owner_name'],
    ], $pdo, $c168Pk, []);
}
