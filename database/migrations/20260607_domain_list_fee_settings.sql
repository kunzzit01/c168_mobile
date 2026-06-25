-- domain_list_fee_settings: dual-tenant domain list fees (company vs group).
-- Also auto-applied by api/domain/domain_api.php (ensureDomainListFeePriceColumns) on first API call.
--
-- New columns (on top of legacy `price`):
--   group_price            — 6-month fallback fee for group tenants
--   company_price          — 6-month fallback fee for company tenants
--   company_period_prices  — JSON per-period company fees (7days|1month|3months|6months|1year)
--   group_period_prices    — JSON per-period group fees
--   period_prices          — unified JSON {company, group} for legacy readers
--
-- `price` remains for backward compatibility; API syncs it from company 6-month fee.

SET NAMES utf8mb4;
START TRANSACTION;

-- Ensure singleton settings row exists.
INSERT IGNORE INTO `domain_list_fee_settings` (`id`, `price`) VALUES (1, NULL);

-- Widen legacy price column (idempotent on already-migrated schemas).
ALTER TABLE `domain_list_fee_settings`
  MODIFY COLUMN `price` DECIMAL(25,8) NULL DEFAULT NULL
    COMMENT 'Legacy single price (synced from company 6-month)';

ALTER TABLE `domain_list_fee_settings`
  ADD COLUMN IF NOT EXISTS `group_price` DECIMAL(25,8) NULL DEFAULT NULL
    COMMENT 'Default fee for group tenants (6-month fallback)' AFTER `price`,
  ADD COLUMN IF NOT EXISTS `company_price` DECIMAL(25,8) NULL DEFAULT NULL
    COMMENT 'Default fee for company tenants (6-month fallback)' AFTER `group_price`,
  ADD COLUMN IF NOT EXISTS `company_period_prices` LONGTEXT NULL DEFAULT NULL
    COMMENT 'Company per-period prices JSON' AFTER `company_price`,
  ADD COLUMN IF NOT EXISTS `group_period_prices` LONGTEXT NULL DEFAULT NULL
    COMMENT 'Group per-period prices JSON' AFTER `company_period_prices`,
  ADD COLUMN IF NOT EXISTS `period_prices` LONGTEXT NULL DEFAULT NULL
    COMMENT 'Unified JSON {company,group} for legacy readers' AFTER `group_period_prices`;

-- Backfill company fallback from legacy single price.
UPDATE `domain_list_fee_settings`
SET `company_price` = `price`
WHERE `id` = 1
  AND `company_price` IS NULL
  AND `price` IS NOT NULL;

COMMIT;


-- bankprocess delete funct prob in db 'skipped' less a letter 'd' 