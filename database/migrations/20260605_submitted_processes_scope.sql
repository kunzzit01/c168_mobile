SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE `submitted_processes`
  ADD COLUMN IF NOT EXISTS `scope_type` ENUM('company','group') NOT NULL DEFAULT 'company' AFTER `company_id`,
  ADD COLUMN IF NOT EXISTS `scope_id` BIGINT UNSIGNED NULL AFTER `scope_type`,
  ADD KEY `idx_sp_scope_date` (`scope_type`, `scope_id`, `capture_date`);

UPDATE `submitted_processes`
SET `scope_type` = 'company',
    `scope_id` = `company_id`
WHERE `scope_id` IS NULL AND `company_id` IS NOT NULL;

COMMIT;
