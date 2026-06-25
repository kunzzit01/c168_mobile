-- =============================================================================
-- DBeaver: 每次只选中下面 ONE 条 ALTER（含分号），Ctrl+Enter 单独执行。
-- 不要整文件 Execute Script（部分版本会把 SET/PREPARE 拆乱）。
-- 若报 Duplicate key name → 该索引已存在，跳过即可。
-- =============================================================================

ALTER TABLE `transactions`
  ADD INDEX `idx_txn_co_date_type` (`company_id`, `transaction_date`, `transaction_type`);

ALTER TABLE `transactions`
  ADD INDEX `idx_txn_co_curr_date` (`company_id`, `currency_id`, `transaction_date`);

ALTER TABLE `data_captures`
  ADD INDEX `idx_dc_co_capture_date` (`company_id`, `capture_date`);

ALTER TABLE `data_capture_details`
  ADD INDEX `idx_dcd_co_curr_capture` (`company_id`, `currency_id`, `capture_id`);
