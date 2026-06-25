-- Group-only Data Capture table drafts (shared across users/devices).
-- Key: group_id (AP / IG) + process_key (salary / commission / bonus).
-- draft_json stores { "tableData": {...}, "captureType": "1.Text", "savedAt": <ms> }

SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `data_capture_group_draft` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` VARCHAR(16) NOT NULL COMMENT 'Dashboard group code, e.g. AP, IG',
  `process_key` VARCHAR(32) NOT NULL COMMENT 'salary | commission | bonus',
  `draft_json` LONGTEXT NOT NULL COMMENT 'JSON: tableData + captureType + savedAt',
  `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Last editor user_id',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dc_group_draft_group_process` (`group_id`, `process_key`),
  KEY `idx_dc_group_draft_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
