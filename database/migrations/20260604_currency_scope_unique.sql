-- Group ledger currencies share company_id (anchor FK) with subsidiaries; the old
-- UNIQUE(code, company_id) blocked scope_type=group rows when MYR already exists
-- on the anchor company. Scope-aware uniqueness separates tenants.

ALTER TABLE `currency` DROP INDEX `unique_code_per_company`;

ALTER TABLE `currency`
  ADD UNIQUE KEY `uk_currency_scope_code` (`scope_type`, `scope_id`, `code`);
