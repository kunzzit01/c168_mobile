-- Fix ownership history unique keys: allow multiple group-type rows (account_id=0)
-- distinguished by partner_group_id. Safe to re-run (DROP IF EXISTS).
--
-- If ADD still fails with "Duplicate key name", the index is already correct — verify with:
--   SHOW INDEX FROM group_ownership_history WHERE Key_name = 'uq_go_hist_month_account';
-- Expected columns: group_id, effective_month, account_id, owner_type, partner_group_id

ALTER TABLE `company_ownership_history` DROP INDEX IF EXISTS `uq_co_hist_month_account`;
ALTER TABLE `company_ownership_history`
  ADD UNIQUE KEY `uq_co_hist_month_account` (`company_id`, `effective_month`, `account_id`, `owner_type`, `partner_group_id`);

ALTER TABLE `group_ownership_history` DROP INDEX IF EXISTS `uq_go_hist_month_account`;
ALTER TABLE `group_ownership_history`
  ADD UNIQUE KEY `uq_go_hist_month_account` (`group_id`, `effective_month`, `account_id`, `owner_type`, `partner_group_id`);
