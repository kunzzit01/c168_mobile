-- =============================================================================
-- DBeaver: copy ONE statement below, paste in new SQL tab, Ctrl+Enter ONLY that.
-- Do NOT run this whole file. Do NOT combine multiple statements.
-- If you see "near CREATE TABLE at line 2" → you ran 2 statements together.
-- =============================================================================

-- Step 1 — check (run alone)
-- SHOW TABLES LIKE 'user_group_map';

-- Step 2 — create user_group_map (run THIS ENTIRE statement alone, nothing above/below)
CREATE TABLE IF NOT EXISTS `user_group_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_group` (`user_id`, `group_id`),
  KEY `idx_ugm_group_id` (`group_id`),
  KEY `idx_ugm_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3 — create account_group_map (run alone; skip if not needed)
CREATE TABLE IF NOT EXISTS `account_group_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_group` (`account_id`, `group_id`),
  KEY `idx_agm_group_id` (`group_id`),
  KEY `idx_agm_account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 4 — backfill from old group assignments (run alone)
INSERT IGNORE INTO `user_group_map` (`user_id`, `group_id`)
SELECT ucm.user_id, ucm.scope_id
FROM `user_company_map` ucm
WHERE ucm.scope_type = 'group'
  AND ucm.scope_id IS NOT NULL
  AND ucm.scope_id > 0
  AND ucm.user_id > 0;

-- Step 5 — verify (run alone)
-- SELECT COUNT(*) FROM user_group_map;
-- SELECT u.login_id, g.group_code
-- FROM user_group_map ugm
-- JOIN `user` u ON u.id = ugm.user_id
-- JOIN `groups` g ON g.id = ugm.group_id;
