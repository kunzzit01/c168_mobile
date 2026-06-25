-- Auto Renew: support group tenants alongside company tenants.
-- Run once on existing DBs (skip statements that error if already applied).
-- api/includes/auto_renew.php also applies these changes on first API call.

ALTER TABLE `company_auto_renew_request`
  ADD COLUMN `entity_type` ENUM('company','group') NOT NULL DEFAULT 'company'
    COMMENT 'Tenant type: company or group'
    AFTER `id`;

ALTER TABLE `company_auto_renew_request`
  ADD COLUMN `group_id` BIGINT UNSIGNED NULL
    COMMENT 'FK groups.id when entity_type=group'
    AFTER `company_id`;

-- FK fk_car_company uses an index on company_id; drop it before changing indexes.
ALTER TABLE `company_auto_renew_request`
  DROP FOREIGN KEY `fk_car_company`;

-- Ensure company_id stays indexed for FK re-create (skip if this index already exists).
ALTER TABLE `company_auto_renew_request`
  ADD KEY `idx_auto_renew_company` (`company_id`);

ALTER TABLE `company_auto_renew_request`
  MODIFY COLUMN `company_id` INT UNSIGNED NULL
    COMMENT 'FK company.id when entity_type=company';

ALTER TABLE `company_auto_renew_request`
  DROP INDEX `uq_auto_renew_company_exp`;

ALTER TABLE `company_auto_renew_request`
  ADD UNIQUE KEY `uq_auto_renew_company_exp` (`company_id`, `expiration_snapshot`);

ALTER TABLE `company_auto_renew_request`
  ADD UNIQUE KEY `uq_auto_renew_group_exp` (`group_id`, `expiration_snapshot`);

ALTER TABLE `company_auto_renew_request`
  ADD KEY `idx_auto_renew_group` (`group_id`);

ALTER TABLE `company_auto_renew_request`
  ADD CONSTRAINT `fk_car_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `company_auto_renew_request`
  ADD CONSTRAINT `fk_car_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE;
