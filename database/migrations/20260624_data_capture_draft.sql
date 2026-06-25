-- Unified Data Capture drafts (group + company scope).
-- Group payroll drafts use scope_type = 'group', company_id = NULL.
-- Legacy rows in data_capture_group_draft are copied on first draft API call (see group_capture_draft_api.php).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `data_capture_draft` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `scope_type` ENUM('group', 'company') NOT NULL,
  `group_id` VARCHAR(50) NULL,
  `company_id` INT NULL,
  `process_key` VARCHAR(64) NOT NULL,
  `currency_id` INT NOT NULL,
  `draft_json` LONGTEXT NOT NULL,
  `updated_by` INT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_process_currency` (`group_id`, `process_key`, `currency_id`),
  UNIQUE KEY `uk_company_process_currency` (`company_id`, `process_key`, `currency_id`),
  KEY `idx_scope_type` (`scope_type`),
  KEY `idx_group_id` (`group_id`),
  KEY `idx_company_id` (`company_id`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional manual legacy copy (only when data_capture_group_draft still exists):
-- INSERT INTO data_capture_draft
--   (scope_type, group_id, company_id, process_key, currency_id, draft_json, updated_by, updated_at)
-- SELECT 'group', group_id, NULL, process_key, currency_id, draft_json, updated_by, updated_at
-- FROM data_capture_group_draft
-- ON DUPLICATE KEY UPDATE
--   draft_json = VALUES(draft_json),
--   updated_by = VALUES(updated_by),
--   updated_at = VALUES(updated_at);
