-- Generated from u857194726_count168_no_definer.sql
-- Target: u857194726_Games
-- Excluded: transactions*, transaction_*, bank_process*, process*, submitted_processes*
-- No procedures, events, triggers, INSERT data, or views.
-- NOTE: FK fk_data_captures_process -> process was omitted because process is excluded.
--       Column data_captures.process_id remains (integer); enforce in app or restore FK later.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- NOTE: Keep this schema database-agnostic.
-- Target database is selected by application provisioning logic.

-- Table structure for table `account`
--

CREATE TABLE `account` (
  `id` int(11) NOT NULL,
  `account_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` enum('active','inactive') NOT NULL,
  `created_source` varchar(50) DEFAULT NULL COMMENT 'Account source, e.g. domain_auto/manual',
  `last_login` datetime DEFAULT NULL,
  `role` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `payment_alert` tinyint(1) DEFAULT 0,
  `alert_day` varchar(20) DEFAULT NULL COMMENT 'Alert type: weekly, monthly, or number 1-31',
  `alert_specific_date` date DEFAULT NULL COMMENT 'Alert start date (YYYY-MM-DD)',
  `alert_amount` decimal(25,8) DEFAULT NULL,
  `remark` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `account_backup`
--

CREATE TABLE `account_backup` (
  `id` int(11) NOT NULL,
  `account_id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` enum('active','inactive','','') NOT NULL,
  `created_source` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `role` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `payment_alert` tinyint(4) NOT NULL,
  `alert_day` varchar(255) DEFAULT NULL,
  `alert_specific_date` date DEFAULT NULL,
  `alert_amount` decimal(25,8) DEFAULT NULL,
  `remark` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `account_company`
--

CREATE TABLE `account_company` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL COMMENT '账户ID',
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户-公司关联表 - 支持一个账户关联多个公司';
-- Table structure for table `account_company_backup`
--

CREATE TABLE `account_company_backup` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `company_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `account_currency`
--

CREATE TABLE `account_currency` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL COMMENT '账户ID',
  `currency_id` int(11) NOT NULL COMMENT '货币ID',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户-货币关联表 - 支持一个账户关联多个货币';
-- Table structure for table `account_currency_backup`
--

CREATE TABLE `account_currency_backup` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `currency_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `account_currency_display_order`
--

CREATE TABLE `account_currency_display_order` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL COMMENT '账户ID（关联 account.id）',
  `currency_order` text DEFAULT NULL COMMENT '货币代码显示顺序，JSON 数组如 ["JPY","MYR"]',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户货币显示顺序 - Member 页拖拽排序持久化';
-- Table structure for table `account_currency_display_order_backup`
--

CREATE TABLE `account_currency_display_order_backup` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `account_name` int(255) NOT NULL,
  `currency_order` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `account_link`
--

CREATE TABLE `account_link` (
  `id` int(11) NOT NULL,
  `account_id_1` int(11) NOT NULL COMMENT '账户1 ID（较小的ID）',
  `account_id_2` int(11) NOT NULL COMMENT '账户2 ID（较大的ID）',
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID（限制在同一公司）',
  `link_type` enum('bidirectional','unidirectional') NOT NULL DEFAULT 'bidirectional' COMMENT '连接类型：bidirectional=双向，unidirectional=单向',
  `source_account_id` int(11) DEFAULT NULL COMMENT '单向连接时的发起账户ID（双向连接时为NULL）',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户关联表 - 存储同一公司下账户之间的关联关系';
-- Table structure for table `account_link_backup`
--

CREATE TABLE `account_link_backup` (
  `id` int(11) NOT NULL,
  `account_id_1` int(11) NOT NULL COMMENT '	账户1 ID（较小的ID）',
  `account_name_1` varchar(255) NOT NULL,
  `account_id_2` int(11) NOT NULL COMMENT '账户2 ID（较大的ID）',
  `account_name_2` varchar(255) NOT NULL,
  `company_id` int(11) NOT NULL COMMENT '公司ID（限制在同一公司）',
  `company_name` varchar(255) NOT NULL,
  `link_type` enum('bidirectional','unidirectional','','') NOT NULL COMMENT '连接类型：bidirectional=双向，unidirectional=单向	',
  `source_account_id` int(11) DEFAULT NULL COMMENT '单向连接时的发起账户ID（双向连接时为NULL）	',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(500) NOT NULL COMMENT '公告标题',
  `content` text NOT NULL COMMENT '公告详细内容',
  `company_code` varchar(50) NOT NULL DEFAULT 'C168' COMMENT '公司代码，只有C168可见',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT '公告状态',
  `created_by` int(11) NOT NULL COMMENT '创建者用户ID',
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统公告表 - 存储所有公告信息';
-- Table structure for table `auto_login_credentials`
--

CREATE TABLE `auto_login_credentials` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID（关联company表）',
  `name` varchar(255) NOT NULL COMMENT '凭证名称/描述',
  `website_url` varchar(500) NOT NULL COMMENT '网站URL',
  `username` varchar(255) NOT NULL COMMENT '用户名',
  `encrypted_password` text NOT NULL COMMENT '加密后的密码',
  `encryption_key` varchar(64) NOT NULL COMMENT '加密密钥（用于存储密钥标识）',
  `has_2fa` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用二重认证：0=否，1=是',
  `encrypted_2fa_code` text DEFAULT NULL COMMENT '加密后的认证码（静态认证码或TOTP密钥）',
  `two_fa_type` enum('static','totp','sms','email') DEFAULT NULL COMMENT '认证码类型：static=静态码，totp=时间基础一次性密码，sms=短信，email=邮箱',
  `two_fa_instructions` text DEFAULT NULL COMMENT '认证码获取说明/提示',
  `auto_import_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用自动导入：0=否，1=是',
  `report_page_url` varchar(500) DEFAULT NULL COMMENT '报告页面URL（如果与登录URL不同，用于网页抓取模式）',
  `import_process_id` int(11) DEFAULT NULL COMMENT '导入流程ID（关联process表）',
  `import_capture_date` varchar(50) DEFAULT NULL COMMENT '导入日期规则：today=今天，yesterday=昨天，或具体日期格式如Y-m-d',
  `import_currency_id` int(11) DEFAULT NULL COMMENT '导入默认币别ID（关联currency表）',
  `import_field_mapping` text DEFAULT NULL COMMENT '导入字段映射配置（JSON格式）',
  `status` enum('active','inactive') DEFAULT 'active' COMMENT '状态：active=启用，inactive=停用',
  `remark` text DEFAULT NULL COMMENT '备注',
  `last_executed` datetime DEFAULT NULL COMMENT '最后执行时间',
  `last_result` text DEFAULT NULL COMMENT '最后执行结果',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间',
  `created_by` int(11) DEFAULT NULL COMMENT '创建人ID（关联user表）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自动登录凭证表';
-- Table structure for table `company`
--

CREATE TABLE `company` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` varchar(50) NOT NULL COMMENT 'External/business identifier for the company',
  `owner_id` int(10) UNSIGNED NOT NULL COMMENT 'FK to owner.id',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiration_date` date DEFAULT NULL COMMENT 'Company expiration date',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '公司权限设置，存储可访问的选项数组，如 ["Gambling", "Bank", "Loan", "Rate", "Money"]' CHECK (json_valid(`permissions`)),
  `fee_share_allocations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Sales/CS/IT fee share % by account' CHECK (json_valid(`fee_share_allocations`)),
  `group_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `company_backup`
--

CREATE TABLE `company_backup` (
  `id` int(10) UNSIGNED NOT NULL,
  `company_id` varchar(255) NOT NULL COMMENT 'External/business identifier for the company',
  `owner_id` int(10) UNSIGNED NOT NULL COMMENT 'FK to owner.id',
  `owner_name` varchar(255) DEFAULT NULL COMMENT '来自 owner.name 展示',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiration_date` date DEFAULT NULL,
  `permissions` longtext DEFAULT NULL COMMENT '公司权限设置，如 ["Gambling","Bank","Loan","Rate","Money"]',
  `fee_share_allocations` longtext DEFAULT NULL COMMENT 'Sales/CS/IT fee share % by account',
  `group_id` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='company 备份（含 owner_name）';
-- Table structure for table `company_countries`
--

CREATE TABLE `company_countries` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `country` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Company-level country list (persist added countries)';
-- Table structure for table `company_countries_backup`
--

CREATE TABLE `company_countries_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL COMMENT '来自 company.company_id 展示',
  `country` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='company_countries 备份（含 company_name）';
-- Table structure for table `company_ownership`
--

CREATE TABLE `company_ownership` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL COMMENT '被拥有的公司ID',
  `entity_type` varchar(50) DEFAULT 'account',
  `account_id` int(11) NOT NULL COMMENT '拥有者的账户ID',
  `group_id` varchar(50) DEFAULT NULL,
  `owner_type` enum('account','owner','user','group') NOT NULL DEFAULT 'account',
  `percentage` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT '拥有百分比',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `include_group` tinyint(1) DEFAULT 1,
  `partner_group_id` varchar(50) DEFAULT NULL,
  `read_only` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
-- Table structure for table `company_ownership_backup`
--

CREATE TABLE `company_ownership_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `entity_type` varchar(255) DEFAULT 'account',
  `account_id` int(11) NOT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `group_id` varchar(255) DEFAULT NULL,
  `include_group` tinyint(1) DEFAULT 1,
  `partner_group` varchar(255) DEFAULT NULL,
  `owner_type` enum('account','owner','user','group') DEFAULT 'account',
  `percentage` decimal(6,2) DEFAULT 0.00,
  `read_only` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
-- Table structure for table `company_selected_banks`
--

CREATE TABLE `company_selected_banks` (
  `company_id` int(10) UNSIGNED NOT NULL,
  `country` varchar(100) NOT NULL,
  `bank` varchar(200) NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `company_selected_bank_backup`
--

CREATE TABLE `company_selected_bank_backup` (
  `company_id` int(10) UNSIGNED NOT NULL,
  `country` varchar(100) NOT NULL,
  `bank` varchar(200) NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `company_selected_countries`
--

CREATE TABLE `company_selected_countries` (
  `company_id` int(10) UNSIGNED NOT NULL,
  `country` varchar(100) NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `company_selected_countries_backup`
--

CREATE TABLE `company_selected_countries_backup` (
  `company_id` int(10) UNSIGNED NOT NULL,
  `country` varchar(100) NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `country_bank`
--

CREATE TABLE `country_bank` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `country` varchar(100) NOT NULL COMMENT '国家名（如 AA）',
  `bank` varchar(100) NOT NULL COMMENT '银行名（如 CC）',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Country-Bank 关联：某 Country 下可选 Bank 列表';
-- Table structure for table `currency`
--

CREATE TABLE `currency` (
  `id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `currency_backup`
--

CREATE TABLE `currency_backup` (
  `id` int(11) NOT NULL,
  `code` varchar(255) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `company_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_captures`
--

CREATE TABLE `data_captures` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `capture_date` date NOT NULL,
  `process_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `remark` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_captures_backup`
--

CREATE TABLE `data_captures_backup` (
  `backup_id` int(11) NOT NULL COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(10) UNSIGNED NOT NULL,
  `capture_date` date NOT NULL,
  `process_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `remark` text DEFAULT NULL,
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_captures_deleted`
--

CREATE TABLE `data_captures_deleted` (
  `id` int(11) NOT NULL,
  `capture_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `process_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `capture_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `remark` text DEFAULT NULL,
  `deleted_by_user_id` int(11) DEFAULT NULL,
  `deleted_by_owner_id` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_capture_details`
--

CREATE TABLE `data_capture_details` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `capture_id` int(11) NOT NULL,
  `id_product_main` varchar(255) DEFAULT NULL,
  `description_main` varchar(255) DEFAULT NULL,
  `id_product_sub` varchar(255) DEFAULT NULL,
  `columns_value` text DEFAULT NULL,
  `description_sub` varchar(255) DEFAULT NULL,
  `product_type` enum('main','sub') NOT NULL DEFAULT 'main',
  `formula_variant` tinyint(4) NOT NULL DEFAULT 1,
  `id_product` varchar(255) NOT NULL,
  `account_id` varchar(50) DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `source_value` text DEFAULT NULL,
  `source_percent` varchar(255) DEFAULT '0',
  `enable_source_percent` tinyint(1) NOT NULL DEFAULT 1,
  `formula` text DEFAULT NULL,
  `processed_amount` decimal(25,8) DEFAULT NULL,
  `rate` decimal(25,8) DEFAULT NULL,
  `display_order` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_capture_details_backup`
--

CREATE TABLE `data_capture_details_backup` (
  `backup_id` int(11) NOT NULL COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(10) UNSIGNED NOT NULL,
  `capture_id` int(11) NOT NULL,
  `id_product_main` varchar(255) DEFAULT NULL,
  `description_main` varchar(255) DEFAULT NULL,
  `id_product_sub` varchar(255) DEFAULT NULL,
  `columns_value` text DEFAULT NULL,
  `description_sub` varchar(255) DEFAULT NULL,
  `product_type` enum('main','sub') NOT NULL DEFAULT 'main',
  `formula_variant` tinyint(4) NOT NULL DEFAULT 1,
  `id_product` varchar(255) NOT NULL,
  `account_id` varchar(50) DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `source_value` text DEFAULT NULL,
  `source_percent` varchar(255) DEFAULT '0',
  `enable_source_percent` tinyint(1) NOT NULL DEFAULT 1,
  `formula` text DEFAULT NULL,
  `processed_amount` decimal(15,6) DEFAULT NULL,
  `rate` decimal(15,4) DEFAULT NULL,
  `display_order` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_capture_submit_queue`
--

CREATE TABLE `data_capture_submit_queue` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'processing',
  `request_json` longtext NOT NULL,
  `capture_id` int(11) DEFAULT NULL,
  `rows_count` int(11) NOT NULL DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
-- Table structure for table `data_capture_submit_queue_backup`
--

CREATE TABLE `data_capture_submit_queue_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL COMMENT 'company.company_id，与其它 *_backup 一致',
  `user_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'processing',
  `request_json` longtext NOT NULL,
  `capture_id` int(11) DEFAULT NULL,
  `capture_name` varchar(512) DEFAULT NULL COMMENT 'COALESCE(description.name, process.process_id)，与维护页一致',
  `rows_count` int(11) NOT NULL DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_capture_summary_state`
--

CREATE TABLE `data_capture_summary_state` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `process_key` varchar(255) NOT NULL,
  `state_json` longtext NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
-- Table structure for table `data_capture_summary_state_backup`
--

CREATE TABLE `data_capture_summary_state_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL COMMENT 'company.company_id，与其它 *_backup 一致',
  `process_key` varchar(255) NOT NULL,
  `state_json` longtext NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_capture_templates`
--

CREATE TABLE `data_capture_templates` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `process_id` varchar(50) DEFAULT NULL,
  `source_columns` text DEFAULT NULL,
  `batch_selection` varchar(255) DEFAULT NULL,
  `columns_display` text DEFAULT NULL,
  `data_capture_id` int(11) DEFAULT NULL,
  `row_index` int(11) DEFAULT NULL,
  `sub_order` decimal(11,2) DEFAULT NULL,
  `id_product` varchar(255) NOT NULL,
  `product_type` enum('main','sub') NOT NULL DEFAULT 'main',
  `formula_variant` tinyint(4) NOT NULL DEFAULT 1,
  `parent_id_product` varchar(255) DEFAULT NULL,
  `template_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `description` varchar(255) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `account_display` varchar(255) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `currency_display` varchar(255) DEFAULT NULL,
  `formula_operators` text DEFAULT NULL,
  `input_method` varchar(100) DEFAULT NULL,
  `formula_display` varchar(255) DEFAULT NULL,
  `last_source_value` text DEFAULT NULL,
  `last_processed_amount` decimal(25,8) DEFAULT NULL,
  `source_percent` varchar(255) DEFAULT '0',
  `enable_source_percent` tinyint(1) DEFAULT 1,
  `enable_input_method` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `data_capture_templates_backup`
--

CREATE TABLE `data_capture_templates_backup` (
  `backup_id` int(11) NOT NULL COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(10) UNSIGNED NOT NULL,
  `process_id` varchar(50) DEFAULT NULL,
  `source_columns` text DEFAULT NULL,
  `batch_selection` varchar(255) DEFAULT NULL,
  `columns_display` text DEFAULT NULL,
  `data_capture_id` int(11) DEFAULT NULL,
  `row_index` int(11) DEFAULT NULL,
  `sub_order` decimal(11,2) DEFAULT NULL,
  `id_product` varchar(255) NOT NULL,
  `product_type` enum('main','sub') NOT NULL DEFAULT 'main',
  `formula_variant` tinyint(4) NOT NULL DEFAULT 1,
  `parent_id_product` varchar(255) DEFAULT NULL,
  `template_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `description` varchar(255) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `account_display` varchar(255) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `currency_display` varchar(255) DEFAULT NULL,
  `formula_operators` text DEFAULT NULL,
  `input_method` varchar(100) DEFAULT NULL,
  `formula_display` varchar(255) DEFAULT NULL,
  `last_source_value` text DEFAULT NULL,
  `last_processed_amount` decimal(18,4) DEFAULT 0.0000,
  `source_percent` varchar(255) DEFAULT '0',
  `enable_source_percent` tinyint(1) DEFAULT 1,
  `enable_input_method` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `day`
--

CREATE TABLE `day` (
  `id` int(11) NOT NULL,
  `day_name` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `deleted_logs`
--

CREATE TABLE `deleted_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `user` varchar(100) DEFAULT NULL,
  `company_id` varchar(50) DEFAULT NULL,
  `page` varchar(100) DEFAULT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` varchar(100) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL DEFAULT 'DELETE',
  `ip_address` varchar(45) DEFAULT NULL,
  `deleted_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deleted_data`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `description`
--

CREATE TABLE `description` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `description_backup`
--

CREATE TABLE `description_backup` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `company_name` varchar(255) DEFAULT NULL COMMENT 'company.company_id，与其它 *_backup 一致'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `domain_list_fee_settings`
--

CREATE TABLE `domain_list_fee_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `price` decimal(25,8) DEFAULT NULL,
  `maintenance_fee` decimal(14,4) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `group_ownership`
--

CREATE TABLE `group_ownership` (
  `id` int(11) NOT NULL,
  `group_id` varchar(50) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `owner_type` enum('owner','user','group') NOT NULL DEFAULT 'owner',
  `percentage` decimal(6,2) NOT NULL DEFAULT 0.00,
  `partner_group_id` varchar(50) DEFAULT NULL,
  `read_only` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
-- Table structure for table `group_ownership_backup`
--

CREATE TABLE `group_ownership_backup` (
  `id` int(11) NOT NULL,
  `group_id` varchar(50) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `owner_name` varchar(255) DEFAULT NULL COMMENT 'owner.name / owner_code',
  `account_id` int(11) NOT NULL,
  `account_name` varchar(255) DEFAULT NULL COMMENT '按 owner_type 解析，与 Group Earnings 下拉一致',
  `owner_type` enum('owner','user','group') NOT NULL DEFAULT 'owner',
  `percentage` decimal(6,2) NOT NULL DEFAULT 0.00,
  `partner_group_id` varchar(50) DEFAULT NULL,
  `read_only` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `maintenance_marquee`
--

CREATE TABLE `maintenance_marquee` (
  `id` int(11) NOT NULL,
  `content` text NOT NULL COMMENT '维护内容文本',
  `company_code` varchar(50) NOT NULL DEFAULT 'C168' COMMENT '公司代码，只有C168可见',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT '维护内容状态',
  `created_by` int(11) NOT NULL COMMENT '创建者用户ID',
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user' COMMENT '创建者类型：user 或 owner',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统维护跑马灯表 - 存储所有维护内容信息';
-- Table structure for table `owner`
--

CREATE TABLE `owner` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_code` varchar(50) NOT NULL COMMENT 'Business identifier for the owner',
  `name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Hashed password',
  `secondary_password` varchar(255) DEFAULT NULL COMMENT 'Hashed secondary password (6 digits)',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `owner_backup`
--

CREATE TABLE `owner_backup` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_code` varchar(50) NOT NULL COMMENT 'Business identifier for the owner',
  `name` varchar(150) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Hashed password',
  `secondary_password` varchar(255) DEFAULT NULL COMMENT 'Hashed secondary password (6 digits)',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `password_reset_tac`
--

CREATE TABLE `password_reset_tac` (
  `email` varchar(255) NOT NULL,
  `company_id` int(11) NOT NULL,
  `code` varchar(10) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `password_reset_tac_owner`
--

CREATE TABLE `password_reset_tac_owner` (
  `email` varchar(255) NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `code` varchar(10) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `id` int(11) NOT NULL,
  `login_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `secondary_password` varchar(255) DEFAULT NULL COMMENT 'Hashed secondary password (6 digits)',
  `email` varchar(100) NOT NULL,
  `role` enum('admin','manager','supervisor','accountant','audit','customer service','partnership') NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `remember_token` varchar(64) DEFAULT NULL,
  `remember_token_expires` datetime DEFAULT NULL,
  `read_only` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `user_backup`
--

CREATE TABLE `user_backup` (
  `id` int(11) NOT NULL,
  `login_id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `secondary_password` varchar(255) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('admin','manager','supervisor','accountant') NOT NULL,
  `permissions` longtext NOT NULL,
  `status` enum('active','inactive') NOT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `remember_token` varchar(64) DEFAULT NULL,
  `remember_token_expires` datetime DEFAULT NULL,
  `read_only` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `user_company_map`
--

CREATE TABLE `user_company_map` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
-- Table structure for table `user_company_map_backup`
--

CREATE TABLE `user_company_map_backup` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `user_company_permissions`
--

CREATE TABLE `user_company_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `account_permissions` text DEFAULT NULL COMMENT '账户权限 JSON 数组，null 表示未设置（默认全部），[] 表示已设置但为空，有值表示只选这些',
  `process_permissions` text DEFAULT NULL COMMENT '流程权限 JSON 数组，null 表示未设置（默认全部），[] 表示已设置但为空，有值表示只选这些',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户-公司权限表 - 存储每个用户在每个公司下的 account 和 process 权限';
-- Table structure for table `user_company_permission_backup`
--

CREATE TABLE `user_company_permission_backup` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `account_permissions` text DEFAULT NULL,
  `process_permissions` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account`
--
ALTER TABLE `account`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `account_backup`
--
ALTER TABLE `account_backup`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `account_company`
--
ALTER TABLE `account_company`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_account_company` (`account_id`,`company_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `account_company_backup`
--
ALTER TABLE `account_company_backup`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `account_currency`
--
ALTER TABLE `account_currency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_account_currency` (`account_id`,`currency_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `idx_currency_id` (`currency_id`);

--
-- Indexes for table `account_currency_backup`
--
ALTER TABLE `account_currency_backup`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `account_currency_display_order`
--
ALTER TABLE `account_currency_display_order`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_account` (`account_id`),
  ADD KEY `idx_account_id` (`account_id`);

--
-- Indexes for table `account_link`
--
ALTER TABLE `account_link`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_account_link` (`account_id_1`,`account_id_2`,`company_id`),
  ADD KEY `idx_account_id_1` (`account_id_1`),
  ADD KEY `idx_account_id_2` (`account_id_2`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_source_account_id` (`source_account_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_code` (`company_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_user_type_created_by` (`user_type`,`created_by`);

--
-- Indexes for table `auto_login_credentials`
--
ALTER TABLE `auto_login_credentials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `fk_auto_login_created_by` (`created_by`);

--
-- Indexes for table `company`
--
ALTER TABLE `company`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_id` (`company_id`),
  ADD KEY `idx_company_owner` (`owner_id`),
  ADD KEY `idx_company_expiration` (`expiration_date`);

--
-- Indexes for table `company_backup`
--
ALTER TABLE `company_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner_id` (`owner_id`);

--
-- Indexes for table `company_countries`
--
ALTER TABLE `company_countries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_country` (`company_id`,`country`),
  ADD KEY `idx_company_countries_company` (`company_id`);

--
-- Indexes for table `company_countries_backup`
--
ALTER TABLE `company_countries_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `company_ownership`
--
ALTER TABLE `company_ownership`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `company_ownership_backup`
--
ALTER TABLE `company_ownership_backup`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `company_selected_banks`
--
ALTER TABLE `company_selected_banks`
  ADD PRIMARY KEY (`company_id`,`country`,`bank`),
  ADD KEY `idx_company_selected_banks_company` (`company_id`),
  ADD KEY `idx_company_selected_banks_country` (`company_id`,`country`);

--
-- Indexes for table `company_selected_bank_backup`
--
ALTER TABLE `company_selected_bank_backup`
  ADD PRIMARY KEY (`company_id`,`country`,`bank`),
  ADD KEY `idx_company_selected_bank_backup_company` (`company_id`);

--
-- Indexes for table `company_selected_countries`
--
ALTER TABLE `company_selected_countries`
  ADD PRIMARY KEY (`company_id`,`country`),
  ADD KEY `idx_company_selected_countries_company` (`company_id`);

--
-- Indexes for table `company_selected_countries_backup`
--
ALTER TABLE `company_selected_countries_backup`
  ADD PRIMARY KEY (`company_id`,`country`),
  ADD KEY `idx_company_selected_countries_backup_company` (`company_id`);

--
-- Indexes for table `country_bank`
--
ALTER TABLE `country_bank`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_company_country_bank` (`company_id`,`country`,`bank`),
  ADD KEY `idx_country_bank_company_country` (`company_id`,`country`);

--
-- Indexes for table `currency`
--
ALTER TABLE `currency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_code_per_company` (`code`,`company_id`),
  ADD KEY `idx_currency_company` (`company_id`);

--
-- Indexes for table `currency_backup`
--
ALTER TABLE `currency_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `code` (`code`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `data_captures`
--
ALTER TABLE `data_captures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_process` (`process_id`),
  ADD KEY `idx_currency` (`currency_id`),
  ADD KEY `idx_capture_date` (`capture_date`),
  ADD KEY `idx_user_type_created_by` (`user_type`,`created_by`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `data_captures_backup`
--
ALTER TABLE `data_captures_backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_id` (`id`),
  ADD KEY `idx_backup_created_at` (`backup_created_at`),
  ADD KEY `idx_process` (`process_id`),
  ADD KEY `idx_currency` (`currency_id`),
  ADD KEY `idx_capture_date` (`capture_date`),
  ADD KEY `idx_user_type_created_by` (`user_type`,`created_by`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `data_captures_deleted`
--
ALTER TABLE `data_captures_deleted`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_date` (`company_id`,`capture_date`),
  ADD KEY `idx_capture_id` (`capture_id`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indexes for table `data_capture_details`
--
ALTER TABLE `data_capture_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_capture` (`capture_id`),
  ADD KEY `idx_account` (`account_id`),
  ADD KEY `idx_product_type` (`product_type`),
  ADD KEY `idx_formula_variant` (`capture_id`,`id_product`,`account_id`,`formula_variant`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `data_capture_details_backup`
--
ALTER TABLE `data_capture_details_backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_id` (`id`),
  ADD KEY `idx_backup_created_at` (`backup_created_at`),
  ADD KEY `idx_capture` (`capture_id`),
  ADD KEY `idx_account` (`account_id`),
  ADD KEY `idx_product_type` (`product_type`),
  ADD KEY `idx_formula_variant` (`capture_id`,`id_product`,`account_id`,`formula_variant`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `data_capture_submit_queue`
--
ALTER TABLE `data_capture_submit_queue`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `data_capture_submit_queue_backup`
--
ALTER TABLE `data_capture_submit_queue_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_status` (`company_id`,`status`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `data_capture_summary_state`
--
ALTER TABLE `data_capture_summary_state`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_process` (`company_id`,`process_key`);

--
-- Indexes for table `data_capture_summary_state_backup`
--
ALTER TABLE `data_capture_summary_state_backup`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_company_process` (`company_id`,`process_key`);

--
-- Indexes for table `data_capture_templates`
--
ALTER TABLE `data_capture_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `template_unique` (`process_id`,`product_type`,`data_capture_id`,`id_product`,`account_id`,`formula_variant`),
  ADD KEY `idx_data_capture_id` (`data_capture_id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_process_id` (`process_id`);

--
-- Indexes for table `data_capture_templates_backup`
--
ALTER TABLE `data_capture_templates_backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_id` (`id`),
  ADD KEY `idx_backup_created_at` (`backup_created_at`),
  ADD KEY `idx_data_capture_id` (`data_capture_id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_process_id` (`process_id`);

--
-- Indexes for table `day`
--
ALTER TABLE `day`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `deleted_logs`
--
ALTER TABLE `deleted_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deleted_logs_company_created` (`company_id`,`created_at`),
  ADD KEY `idx_deleted_logs_table` (`table_name`);

--
-- Indexes for table `description`
--
ALTER TABLE `description`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_description_company` (`company_id`);

--
-- Indexes for table `description_backup`
--
ALTER TABLE `description_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `domain_list_fee_settings`
--
ALTER TABLE `domain_list_fee_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `group_ownership`
--
ALTER TABLE `group_ownership`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `group_ownership_backup`
--
ALTER TABLE `group_ownership_backup`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maintenance_marquee`
--
ALTER TABLE `maintenance_marquee`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_code` (`company_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_user_type_created_by` (`user_type`,`created_by`);

--
-- Indexes for table `owner`
--
ALTER TABLE `owner`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_owner_status` (`status`);

--
-- Indexes for table `owner_backup`
--
ALTER TABLE `owner_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `password_reset_tac`
--
ALTER TABLE `password_reset_tac`
  ADD PRIMARY KEY (`email`,`company_id`);

--
-- Indexes for table `password_reset_tac_owner`
--
ALTER TABLE `password_reset_tac_owner`
  ADD PRIMARY KEY (`email`,`owner_id`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_backup`
--
ALTER TABLE `user_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_backup_login_id` (`login_id`),
  ADD KEY `idx_user_backup_name` (`name`),
  ADD KEY `idx_user_backup_email` (`email`),
  ADD KEY `idx_user_backup_role` (`role`),
  ADD KEY `idx_user_backup_status` (`status`),
  ADD KEY `idx_user_backup_created_by` (`created_by`),
  ADD KEY `idx_user_backup_created_at` (`created_at`),
  ADD KEY `idx_user_backup_last_login` (`last_login`),
  ADD KEY `idx_user_backup_remember_token` (`remember_token`),
  ADD KEY `idx_user_backup_remember_token_expires` (`remember_token_expires`),
  ADD KEY `idx_user_backup_read_only` (`read_only`);

--
-- Indexes for table `user_company_map`
--
ALTER TABLE `user_company_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_company` (`user_id`,`company_id`),
  ADD KEY `fk_uc_company` (`company_id`);

--
-- Indexes for table `user_company_map_backup`
--
ALTER TABLE `user_company_map_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_backup_user_id` (`user_id`),
  ADD KEY `idx_user_backup_user_name` (`user_name`),
  ADD KEY `idx_user_backup_company_id` (`company_id`);

--
-- Indexes for table `user_company_permissions`
--
ALTER TABLE `user_company_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_company` (`user_id`,`company_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_company_id` (`company_id`);

--
-- Indexes for table `user_company_permission_backup`
--
ALTER TABLE `user_company_permission_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_company_permission_backup_user_id` (`user_id`),
  ADD KEY `idx_user_company_permission_backup_user_name` (`user_name`),
  ADD KEY `idx_user_company_permission_backup_company_id` (`company_id`),
  ADD KEY `idx_user_company_permission_backup_company_name` (`company_name`),
  ADD KEY `idx_user_company_permission_backup_created_at` (`created_at`),
  ADD KEY `idx_user_company_permission_backup_updated_at` (`updated_at`),
  ADD KEY `idx_user_company_permission_backup_account_permission` (`account_permissions`(768)),
  ADD KEY `idx_user_company_permission_backup_process_permission` (`process_permissions`(768));

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account`
--
ALTER TABLE `account`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5127;

--
-- AUTO_INCREMENT for table `account_company`
--
ALTER TABLE `account_company`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6049;

--
-- AUTO_INCREMENT for table `account_currency`
--
ALTER TABLE `account_currency`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5486;

--
-- AUTO_INCREMENT for table `account_currency_display_order`
--
ALTER TABLE `account_currency_display_order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `account_link`
--
ALTER TABLE `account_link`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=189;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `auto_login_credentials`
--
ALTER TABLE `auto_login_credentials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `company`
--
ALTER TABLE `company`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=288;

--
-- AUTO_INCREMENT for table `company_countries`
--
ALTER TABLE `company_countries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `company_ownership`
--
ALTER TABLE `company_ownership`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=365;

--
-- AUTO_INCREMENT for table `country_bank`
--
ALTER TABLE `country_bank`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1458;

--
-- AUTO_INCREMENT for table `currency`
--
ALTER TABLE `currency`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=244;

--
-- AUTO_INCREMENT for table `currency_backup`
--
ALTER TABLE `currency_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=244;

--
-- AUTO_INCREMENT for table `data_captures`
--
ALTER TABLE `data_captures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9829;

--
-- AUTO_INCREMENT for table `data_captures_backup`
--
ALTER TABLE `data_captures_backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID', AUTO_INCREMENT=7367;

--
-- AUTO_INCREMENT for table `data_captures_deleted`
--
ALTER TABLE `data_captures_deleted`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2586;

--
-- AUTO_INCREMENT for table `data_capture_details`
--
ALTER TABLE `data_capture_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61292;

--
-- AUTO_INCREMENT for table `data_capture_details_backup`
--
ALTER TABLE `data_capture_details_backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID', AUTO_INCREMENT=54583;

--
-- AUTO_INCREMENT for table `data_capture_submit_queue`
--
ALTER TABLE `data_capture_submit_queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1514;

--
-- AUTO_INCREMENT for table `data_capture_submit_queue_backup`
--
ALTER TABLE `data_capture_submit_queue_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1514;

--
-- AUTO_INCREMENT for table `data_capture_summary_state`
--
ALTER TABLE `data_capture_summary_state`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3519;

--
-- AUTO_INCREMENT for table `data_capture_summary_state_backup`
--
ALTER TABLE `data_capture_summary_state_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3513;

--
-- AUTO_INCREMENT for table `data_capture_templates`
--
ALTER TABLE `data_capture_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29289;

--
-- AUTO_INCREMENT for table `data_capture_templates_backup`
--
ALTER TABLE `data_capture_templates_backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID', AUTO_INCREMENT=41768;

--
-- AUTO_INCREMENT for table `deleted_logs`
--
ALTER TABLE `deleted_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `description`
--
ALTER TABLE `description`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1937;

--
-- AUTO_INCREMENT for table `description_backup`
--
ALTER TABLE `description_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1937;

--
-- AUTO_INCREMENT for table `group_ownership`
--
ALTER TABLE `group_ownership`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=295;

--
-- AUTO_INCREMENT for table `group_ownership_backup`
--
ALTER TABLE `group_ownership_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=295;

--
-- AUTO_INCREMENT for table `maintenance_marquee`
--
ALTER TABLE `maintenance_marquee`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `owner`
--
ALTER TABLE `owner`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=144;

--
-- AUTO_INCREMENT for table `owner_backup`
--
ALTER TABLE `owner_backup`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=140;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=364;

--
-- AUTO_INCREMENT for table `user_backup`
--
ALTER TABLE `user_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=364;

--
-- AUTO_INCREMENT for table `user_company_map`
--
ALTER TABLE `user_company_map`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=783;

--
-- AUTO_INCREMENT for table `user_company_map_backup`
--
ALTER TABLE `user_company_map_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=783;

--
-- AUTO_INCREMENT for table `user_company_permissions`
--
ALTER TABLE `user_company_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11863;

--
-- AUTO_INCREMENT for table `user_company_permission_backup`
--
ALTER TABLE `user_company_permission_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11797;

-- --------------------------------------------------------

--
-- Constraints for dumped tables
--

--
-- Constraints for table `account_company`
--
ALTER TABLE `account_company`
    ADD CONSTRAINT `fk_account_company_account` FOREIGN KEY (`account_id`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_account_company_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `account_currency`
--
ALTER TABLE `account_currency`
    ADD CONSTRAINT `account_currency_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `account_currency_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currency` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `account_link`
--
ALTER TABLE `account_link`
    ADD CONSTRAINT `fk_account_link_account_1` FOREIGN KEY (`account_id_1`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_account_link_account_2` FOREIGN KEY (`account_id_2`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_account_link_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `auto_login_credentials`
--
ALTER TABLE `auto_login_credentials`
    ADD CONSTRAINT `fk_auto_login_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_auto_login_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;


--
-- Constraints for table `company`
--
ALTER TABLE `company`
    ADD CONSTRAINT `fk_company_owner` FOREIGN KEY (`owner_id`) REFERENCES `owner` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `currency`
--
ALTER TABLE `currency`
    ADD CONSTRAINT `fk_currency_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `data_capture_details`
--
ALTER TABLE `data_capture_details`
    ADD CONSTRAINT `fk_data_capture_details_capture` FOREIGN KEY (`capture_id`) REFERENCES `data_captures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `data_capture_templates`
--
ALTER TABLE `data_capture_templates`
    ADD CONSTRAINT `fk_data_capture_templates_data_capture` FOREIGN KEY (`data_capture_id`) REFERENCES `data_captures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;


--
-- Constraints for table `description`
--
ALTER TABLE `description`
    ADD CONSTRAINT `fk_description_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `user_company_map`
--
ALTER TABLE `user_company_map`
    ADD CONSTRAINT `fk_uc_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_uc_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


--
-- Constraints for table `user_company_permissions`
--
ALTER TABLE `user_company_permissions`
    ADD CONSTRAINT `fk_user_company_permissions_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_user_company_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;


SET FOREIGN_KEY_CHECKS = 1;
