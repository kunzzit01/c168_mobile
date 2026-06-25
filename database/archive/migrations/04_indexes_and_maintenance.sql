-- 04_indexes_and_maintenance.sql
-- Run once on an existing DB (skip statements already applied).

-- ========== from: maintenance_search_indexes.sql ==========
-- Optional indexes for Maintenance - Transaction search (run once on production DB).
-- Improves company + date range filters on transactions.

ALTER TABLE transactions
  ADD INDEX idx_maint_company_txn_date (company_id, transaction_date);


