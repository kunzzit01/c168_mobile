-- Dashboard KPI / chart query paths: company ledger date-range scans.
-- MariaDB 10.5.2+ (ADD INDEX IF NOT EXISTS).
--
-- DBeaver: 每条 ALTER 单独选中 → Ctrl+Enter 执行一次；不要只选中 PREPARE 行。
-- 若报 Duplicate key name，说明索引已存在，跳过该条即可。

ALTER TABLE `transactions`
  ADD INDEX IF NOT EXISTS `idx_txn_co_date_type` (`company_id`, `transaction_date`, `transaction_type`);

ALTER TABLE `transactions`
  ADD INDEX IF NOT EXISTS `idx_txn_co_curr_date` (`company_id`, `currency_id`, `transaction_date`);

ALTER TABLE `data_captures`
  ADD INDEX IF NOT EXISTS `idx_dc_co_capture_date` (`company_id`, `capture_date`);

ALTER TABLE `data_capture_details`
  ADD INDEX IF NOT EXISTS `idx_dcd_co_curr_capture` (`company_id`, `currency_id`, `capture_id`);
