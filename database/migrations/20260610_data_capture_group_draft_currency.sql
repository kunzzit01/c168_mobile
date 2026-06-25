-- Extend group-only Data Capture drafts: key = group_id + process_key + currency_id.
-- Option B: discard legacy rows keyed only by group + process (no currency).

SET NAMES utf8mb4;
START TRANSACTION;

DELETE FROM `data_capture_group_draft`;

ALTER TABLE `data_capture_group_draft`
  DROP INDEX `uk_dc_group_draft_group_process`,
  ADD COLUMN `currency_id` INT UNSIGNED NOT NULL COMMENT 'Currency FK (matches capture form currency_id)' AFTER `process_key`,
  ADD UNIQUE KEY `uk_dc_group_draft_group_process_currency` (`group_id`, `process_key`, `currency_id`);

COMMIT;
