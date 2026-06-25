-- 01_owner_and_company.sql
-- Incremental changes on an existing DB (owner/company DDL is in schema/easycount_schema.sql or dumps/).
-- Run once; skip statements already applied.

-- ========== from: deleted_logs_upgrade.sql ==========
-- deleted_logs: 统一删除审计 + 恢复元数据（新环境执行本文件即可）

CREATE TABLE IF NOT EXISTS `deleted_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user` VARCHAR(100) NULL,
  `company_id` VARCHAR(50) NULL,
  `page` VARCHAR(100) NULL,
  `table_name` VARCHAR(100) NOT NULL,
  `record_id` VARCHAR(100) NULL,
  `action_type` VARCHAR(50) NOT NULL DEFAULT 'DELETE',
  `ip_address` VARCHAR(45) NULL,
  `deleted_data` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_deleted_logs_company_created` (`company_id`, `created_at`),
  KEY `idx_deleted_logs_table` (`table_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 若线上已有旧版 deleted_logs 表且缺少下列列，请手工执行（列已存在则跳过对应行）:
--
-- ALTER TABLE deleted_logs ADD COLUMN user VARCHAR(100);
-- ALTER TABLE deleted_logs ADD COLUMN company_id VARCHAR(50);
-- ALTER TABLE deleted_logs ADD COLUMN page VARCHAR(100);
-- ALTER TABLE deleted_logs ADD COLUMN table_name VARCHAR(100);
-- ALTER TABLE deleted_logs ADD COLUMN record_id VARCHAR(100);
-- ALTER TABLE deleted_logs ADD COLUMN action_type VARCHAR(50) DEFAULT 'DELETE';
-- ALTER TABLE deleted_logs ADD COLUMN ip_address VARCHAR(45);
-- ALTER TABLE deleted_logs ADD COLUMN deleted_data JSON;
-- ALTER TABLE deleted_logs ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP;


-- ========== from: company_selected_banks.sql ==========
-- ===============================================
-- company_selected_banks: 每个公司每个 Country 在下拉中显示的已选 Bank 列表（持久化，登出/换设备/隔几小时后仍保持）
-- ===============================================
-- Run: mysql -u <user> -p <database> < database/company_selected_banks.sql

CREATE TABLE IF NOT EXISTS company_selected_banks (
    company_id INT UNSIGNED NOT NULL,
    country VARCHAR(100) NOT NULL,
    bank VARCHAR(200) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, country, bank),
    INDEX idx_company_selected_banks_company (company_id),
    INDEX idx_company_selected_banks_country (company_id, country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========== from: company_selected_countries.sql ==========
-- ===============================================
-- company_selected_countries: 每个公司在下拉中显示的已选 Country 列表（持久化，登出/换设备/隔几小时后仍保持）
-- ===============================================
-- Run: mysql -u <user> -p <database> < database/company_selected_countries.sql

CREATE TABLE IF NOT EXISTS company_selected_countries (
    company_id INT UNSIGNED NOT NULL,
    country VARCHAR(100) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (company_id, country),
    INDEX idx_company_selected_countries_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========== from: drop_owner_code_unique.sql ==========
-- 允许 owner 表存在多个相同 owner_code（Domain 可创建重复 Owner Code）
-- 执行一次即可。若索引不存在会报错，可忽略。
-- 执行方式：mysql -u 用户名 -p 数据库名 < database/drop_owner_code_unique.sql

ALTER TABLE owner DROP INDEX owner_code;


-- ========== from: add_account_currency_display_order.sql ==========
-- 按账号永久化货币显示顺序（Member Win/Loss 页可拖拽排序）
-- 执行一次即可；若表已存在可跳过

CREATE TABLE IF NOT EXISTS `account_currency_display_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL COMMENT '账户ID（关联 account.id）',
  `currency_order` text DEFAULT NULL COMMENT '货币代码显示顺序，JSON 数组如 ["JPY","MYR"]',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account` (`account_id`),
  KEY `idx_account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户货币显示顺序 - Member 页拖拽排序持久化';


