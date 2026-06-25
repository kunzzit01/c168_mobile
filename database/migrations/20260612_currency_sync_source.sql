-- Track whether a group-ledger currency was auto-synced from subsidiaries.
-- subsidiary rows cannot be deleted until reconciled to manual (no subsidiary holds the code).

ALTER TABLE `currency`
  ADD COLUMN `sync_source` ENUM('manual','subsidiary') NOT NULL DEFAULT 'manual' AFTER `scope_id`;
