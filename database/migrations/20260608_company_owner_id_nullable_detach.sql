-- Allow detaching a company from Domain (owner) without deleting ledger rows.
-- Run once on production before using Domain "remove company" on companies with data.

ALTER TABLE `company` DROP FOREIGN KEY `fk_company_owner`;

ALTER TABLE `company`
  MODIFY `owner_id` int UNSIGNED NULL COMMENT 'FK to owner.id; NULL = detached from domain, ledger retained';

ALTER TABLE `company`
  ADD CONSTRAINT `fk_company_owner` FOREIGN KEY (`owner_id`) REFERENCES `owner` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
