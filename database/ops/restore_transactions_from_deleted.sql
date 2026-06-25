-- Restore transactions from transactions_deleted
-- Purpose:
-- 1) Check missing account_id / from_account_id before restore.
-- 2) Restore transaction headers from transactions_deleted to transactions.
-- 3) Keep old->new transaction id mapping for audit and optional entry restore.
--
-- IMPORTANT:
-- - Run on target DB after full DB backup.
-- - This script restores headers only (transactions table).
-- - transaction_entry restore is OPTIONAL and depends on your own backup table.
--
-- ==========================================================
-- 0) Parameters (edit these first)
-- ==========================================================
SET @company_id = 139;
SET @deleted_from = '2026-04-01 00:00:00';
SET @deleted_to   = '2026-04-30 23:59:59';

-- Company 139 safe-mode: restore only the 13 confirmed deleted ids.
-- You can comment this block out if you later want to restore by date range only.
CREATE TEMPORARY TABLE tmp_target_ids (old_transaction_id INT PRIMARY KEY);
INSERT INTO tmp_target_ids (old_transaction_id) VALUES
  (7769), (7770), (7768), (7757), (7756),
  (6084), (6883), (6082), (6881),
  (5667), (5666), (4378), (4293);

-- ==========================================================
-- 1) Build candidate set to restore
-- ==========================================================
DROP TEMPORARY TABLE IF EXISTS tmp_restore_candidates;
CREATE TEMPORARY TABLE tmp_restore_candidates AS
SELECT
    td.id AS deleted_log_id,
    td.transaction_id AS old_transaction_id,
    td.company_id,
    td.transaction_type,
    td.account_id,
    td.from_account_id,
    td.amount,
    td.currency_id,
    td.transaction_date,
    td.description,
    td.sms,
    td.created_by,
    td.created_by_owner,
    td.created_at,
    td.source_bank_process_id,
    td.source_bank_process_period_type
FROM transactions_deleted td
WHERE td.company_id = @company_id
  AND td.deleted_at BETWEEN @deleted_from AND @deleted_to;

-- Filter by manual ID list (enabled by default for company 139 safe-mode).
DELETE rc
FROM tmp_restore_candidates rc
LEFT JOIN tmp_target_ids t ON t.old_transaction_id = rc.old_transaction_id
WHERE t.old_transaction_id IS NULL;

-- Deduplicate: if same old_transaction_id appears multiple times, keep latest deleted_log_id.
DROP TEMPORARY TABLE IF EXISTS tmp_restore_candidates_latest;
CREATE TEMPORARY TABLE tmp_restore_candidates_latest AS
SELECT rc.*
FROM tmp_restore_candidates rc
JOIN (
    SELECT old_transaction_id, MAX(deleted_log_id) AS keep_deleted_log_id
    FROM tmp_restore_candidates
    GROUP BY old_transaction_id
) k
  ON k.old_transaction_id = rc.old_transaction_id
 AND k.keep_deleted_log_id = rc.deleted_log_id;

DROP TEMPORARY TABLE IF EXISTS tmp_restore_candidates;
CREATE TEMPORARY TABLE tmp_restore_candidates AS
SELECT * FROM tmp_restore_candidates_latest;

DROP TEMPORARY TABLE IF EXISTS tmp_restore_candidates_latest;

-- ==========================================================
-- 2) Check missing accounts (this is the "lost acc" check)
-- ==========================================================
DROP TEMPORARY TABLE IF EXISTS tmp_missing_accounts;
CREATE TEMPORARY TABLE tmp_missing_accounts AS
SELECT
    rc.old_transaction_id,
    rc.account_id,
    rc.from_account_id,
    CASE
      WHEN a_to.id IS NULL THEN 'TO_ACCOUNT_MISSING'
      WHEN rc.from_account_id IS NOT NULL AND a_from.id IS NULL THEN 'FROM_ACCOUNT_MISSING'
      ELSE 'OK'
    END AS issue_type
FROM tmp_restore_candidates rc
LEFT JOIN account a_to ON a_to.id = rc.account_id
LEFT JOIN account a_from ON a_from.id = rc.from_account_id
WHERE a_to.id IS NULL
   OR (rc.from_account_id IS NOT NULL AND a_from.id IS NULL);

-- Review missing account details:
SELECT * FROM tmp_missing_accounts ORDER BY old_transaction_id;

-- Summary:
SELECT
    COUNT(*) AS missing_account_rows
FROM tmp_missing_accounts;

-- If missing_account_rows > 0:
-- - either recreate those accounts first, or
-- - exclude affected old_transaction_id from restore.

-- ==========================================================
-- 3) Prepare rows that are safe to restore
-- ==========================================================
DROP TEMPORARY TABLE IF EXISTS tmp_restore_ready;
CREATE TEMPORARY TABLE tmp_restore_ready AS
SELECT rc.*
FROM tmp_restore_candidates rc
LEFT JOIN tmp_missing_accounts ma
  ON ma.old_transaction_id = rc.old_transaction_id
WHERE ma.old_transaction_id IS NULL;

-- Optional: skip rows that already have a live transaction with exactly same key fields
-- (to reduce accidental duplicates if restore ran before).
DROP TEMPORARY TABLE IF EXISTS tmp_restore_final;
CREATE TEMPORARY TABLE tmp_restore_final AS
SELECT rr.*
FROM tmp_restore_ready rr
LEFT JOIN transactions t
  ON t.company_id = rr.company_id
 AND t.transaction_type = rr.transaction_type
 AND t.account_id = rr.account_id
 AND ( (t.from_account_id IS NULL AND rr.from_account_id IS NULL)
       OR t.from_account_id = rr.from_account_id )
 AND t.amount = rr.amount
 AND ( (t.currency_id IS NULL AND rr.currency_id IS NULL)
       OR t.currency_id = rr.currency_id )
 AND t.transaction_date = rr.transaction_date
 AND COALESCE(t.description, '') = COALESCE(rr.description, '')
 AND COALESCE(t.sms, '') = COALESCE(rr.sms, '')
WHERE t.id IS NULL;

SELECT COUNT(*) AS rows_to_restore FROM tmp_restore_final;

-- ==========================================================
-- 4) Restore header rows and build old->new mapping
-- ==========================================================
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_restore_id_map;
CREATE TEMPORARY TABLE tmp_restore_id_map (
    old_transaction_id INT NOT NULL PRIMARY KEY,
    new_transaction_id INT NULL
);

INSERT INTO tmp_restore_id_map (old_transaction_id)
SELECT old_transaction_id
FROM tmp_restore_final;

-- Insert into live transactions.
INSERT INTO transactions (
    company_id,
    transaction_type,
    account_id,
    from_account_id,
    amount,
    currency_id,
    transaction_date,
    description,
    sms,
    created_by,
    created_by_owner,
    created_at,
    source_bank_process_id,
    source_bank_process_period_type
)
SELECT
    rf.company_id,
    rf.transaction_type,
    rf.account_id,
    rf.from_account_id,
    rf.amount,
    rf.currency_id,
    rf.transaction_date,
    rf.description,
    rf.sms,
    rf.created_by,
    rf.created_by_owner,
    COALESCE(rf.created_at, NOW()),
    rf.source_bank_process_id,
    rf.source_bank_process_period_type
FROM tmp_restore_final rf
ORDER BY rf.old_transaction_id;

-- Fill mapping by matching inserted rows back to tmp_restore_final.
-- Note: this assumes key fields together identify the inserted row for this restore batch.
UPDATE tmp_restore_id_map m
JOIN tmp_restore_final rf
  ON rf.old_transaction_id = m.old_transaction_id
JOIN transactions t
  ON t.company_id = rf.company_id
 AND t.transaction_type = rf.transaction_type
 AND t.account_id = rf.account_id
 AND ( (t.from_account_id IS NULL AND rf.from_account_id IS NULL)
       OR t.from_account_id = rf.from_account_id )
 AND t.amount = rf.amount
 AND ( (t.currency_id IS NULL AND rf.currency_id IS NULL)
       OR t.currency_id = rf.currency_id )
 AND t.transaction_date = rf.transaction_date
 AND COALESCE(t.description, '') = COALESCE(rf.description, '')
 AND COALESCE(t.sms, '') = COALESCE(rf.sms, '')
 AND COALESCE(t.created_at, '1970-01-01 00:00:00') = COALESCE(rf.created_at, COALESCE(t.created_at, '1970-01-01 00:00:00'))
SET m.new_transaction_id = t.id;

SELECT
    COUNT(*) AS mapped_rows,
    SUM(CASE WHEN new_transaction_id IS NULL THEN 1 ELSE 0 END) AS unmapped_rows
FROM tmp_restore_id_map;

-- If unmapped_rows > 0, review before COMMIT:
SELECT * FROM tmp_restore_id_map WHERE new_transaction_id IS NULL;

COMMIT;

-- ==========================================================
-- 5) Result set for audit
-- ==========================================================
SELECT
    m.old_transaction_id,
    m.new_transaction_id
FROM tmp_restore_id_map m
ORDER BY m.old_transaction_id;

-- ==========================================================
-- 6) OPTIONAL: restore transaction_entry (only if you have backup table)
-- ==========================================================
-- Example if you have transaction_entry_deleted(header_id, company_id, account_id, currency_id, amount, entry_type, description):
--
-- INSERT INTO transaction_entry (header_id, company_id, account_id, currency_id, amount, entry_type, description)
-- SELECT
--   m.new_transaction_id AS header_id,
--   ted.company_id,
--   ted.account_id,
--   ted.currency_id,
--   ted.amount,
--   ted.entry_type,
--   ted.description
-- FROM transaction_entry_deleted ted
-- JOIN tmp_restore_id_map m
--   ON m.old_transaction_id = ted.header_id
-- WHERE m.new_transaction_id IS NOT NULL;
