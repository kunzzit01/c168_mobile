SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_code` VARCHAR(50) NOT NULL,
  `group_name` VARCHAR(100) NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `owner_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_groups_group_code` (`group_code`),
  KEY `idx_groups_owner_id` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `groups` (`group_code`, `group_name`, `owner_id`)
SELECT DISTINCT
  UPPER(TRIM(c.group_id)) AS group_code,
  UPPER(TRIM(c.group_id)) AS group_name,
  c.owner_id
FROM `company` c
WHERE c.group_id IS NOT NULL AND TRIM(c.group_id) <> ''
ON DUPLICATE KEY UPDATE
  `owner_id` = COALESCE(`groups`.`owner_id`, VALUES(`owner_id`));

CREATE TABLE IF NOT EXISTS `group_company_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `company_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_company` (`group_id`, `company_id`),
  KEY `idx_gcm_company_id` (`company_id`),
  CONSTRAINT `fk_gcm_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gcm_company_id` FOREIGN KEY (`company_id`) REFERENCES `company`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `group_company_map` (`group_id`, `company_id`)
SELECT g.id, c.id
FROM `company` c
JOIN `groups` g ON g.group_code = UPPER(TRIM(c.group_id))
WHERE c.group_id IS NOT NULL AND TRIM(c.group_id) <> '';

ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_transactions_scope_date` (`scope_type`, `scope_id`, `transaction_date`),
  ADD KEY `idx_transactions_scope_account` (`scope_type`, `scope_id`, `account_id`);

UPDATE `transactions`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

ALTER TABLE `currency`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_currency_scope_code` (`scope_type`, `scope_id`, `code`);

UPDATE `currency`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

ALTER TABLE `account_company`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_ac_scope` (`scope_type`, `scope_id`, `account_id`);

UPDATE `account_company`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

ALTER TABLE `user_company_map`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_ucm_scope` (`user_id`, `scope_type`, `scope_id`);

UPDATE `user_company_map`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

ALTER TABLE `data_captures`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_dc_scope_date` (`scope_type`, `scope_id`, `capture_date`);

UPDATE `data_captures`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

ALTER TABLE `data_capture_details`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_dcd_scope` (`scope_type`, `scope_id`, `account_id`, `currency_id`);

UPDATE `data_capture_details`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

ALTER TABLE `description`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_description_scope` (`scope_type`, `scope_id`, `name`);

UPDATE `description`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

ALTER TABLE `data_capture_templates`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_dct_scope` (`scope_type`, `scope_id`, `process_id`);

UPDATE `data_capture_templates`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

SET @has_deleted_transactions := (
  SELECT COUNT(*)
  FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name = 'deleted_transactions'
);
SET @sql_deleted_alter := IF(
  @has_deleted_transactions > 0,
  'ALTER TABLE `deleted_transactions` ADD COLUMN IF NOT EXISTS `scope_type` ENUM(''company'',''group'') NOT NULL DEFAULT ''company'' AFTER `company_id`, ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`, ADD KEY `idx_deleted_tx_scope_date` (`scope_type`, `scope_id`, `transaction_date`)',
  'SELECT 1'
);
PREPARE stmt_deleted_alter FROM @sql_deleted_alter;
EXECUTE stmt_deleted_alter;
DEALLOCATE PREPARE stmt_deleted_alter;

SET @sql_deleted_update := IF(
  @has_deleted_transactions > 0,
  'UPDATE `deleted_transactions` SET `scope_type` = ''company'', `scope_id` = `company_id` WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL',
  'SELECT 1'
);
PREPARE stmt_deleted_update FROM @sql_deleted_update;
EXECUTE stmt_deleted_update;
DEALLOCATE PREPARE stmt_deleted_update;

ALTER TABLE `transaction_entry`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_te_scope` (`scope_type`, `scope_id`, `account_id`, `currency_id`);

UPDATE `transaction_entry`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

CREATE TABLE IF NOT EXISTS `tenant_module_policy` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_type` ENUM('company','group') NOT NULL,
  `scope_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(50) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_module` (`scope_type`, `scope_id`, `module_key`),
  KEY `idx_tenant_module_scope` (`scope_type`, `scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tenant_module_policy` (`scope_type`, `scope_id`, `module_key`, `is_enabled`)
SELECT 'group', g.id, 'process', 0 FROM `groups` g
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

INSERT INTO `tenant_module_policy` (`scope_type`, `scope_id`, `module_key`, `is_enabled`)
SELECT 'group', g.id, 'bankprocess', 0 FROM `groups` g
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

INSERT INTO `tenant_module_policy` (`scope_type`, `scope_id`, `module_key`, `is_enabled`)
SELECT 'company', c.id, 'process', 1 FROM `company` c
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

INSERT INTO `tenant_module_policy` (`scope_type`, `scope_id`, `module_key`, `is_enabled`)
SELECT 'company', c.id, 'bankprocess', 1 FROM `company` c
ON DUPLICATE KEY UPDATE `is_enabled` = VALUES(`is_enabled`);

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
