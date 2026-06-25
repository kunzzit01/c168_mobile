-- Persist ownership row display order (drag-and-drop on Ownership page).
-- Safe to re-run; APIs also auto-add via ownership_ensure_sort_order_column().

ALTER TABLE `company_ownership` ADD COLUMN IF NOT EXISTS `sort_order` INT NOT NULL DEFAULT 0;
ALTER TABLE `group_ownership` ADD COLUMN IF NOT EXISTS `sort_order` INT NOT NULL DEFAULT 0;
