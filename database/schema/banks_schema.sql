-- Generated from u857194726_count168_no_definer.sql
-- Target: u857194726_Banks
-- Excluded: data_captures*, data_capture_*, process, process_backup, process_day*,
--           description, description_backup
-- Includes: transactions*, transaction_entry*, bank_process*, submitted_processes*, etc.
-- No procedures, events, triggers, INSERT data, or views.
-- NOTE: FK fk_submitted_processes_process -> process omitted (process excluded).
--       Column submitted_processes.process_id remains; enforce in app or restore FK later.

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
-- Table structure for table `bank_process`
--

CREATE TABLE `bank_process` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `country` varchar(100) DEFAULT NULL COMMENT '国家',
  `bank` varchar(100) DEFAULT NULL COMMENT '银行名称',
  `type` varchar(100) DEFAULT NULL COMMENT '类型',
  `name` varchar(255) DEFAULT NULL COMMENT '详情/名称',
  `card_merchant_id` int(11) DEFAULT NULL COMMENT '卡商账户ID（关联 account.id）',
  `customer_id` int(11) DEFAULT NULL COMMENT '顾客账户ID（关联 account.id）',
  `profit_account_id` int(11) DEFAULT NULL COMMENT '利润账户ID（关联 account.id）',
  `contract` varchar(20) DEFAULT NULL COMMENT '合约（如 1, 2, 3, 6 个月）',
  `insurance` decimal(25,8) DEFAULT NULL,
  `sop` text DEFAULT NULL,
  `remark` varchar(500) DEFAULT NULL,
  `cost` decimal(25,8) DEFAULT NULL,
  `price` decimal(25,8) DEFAULT NULL,
  `profit` decimal(25,8) DEFAULT NULL,
  `profit_sharing` text DEFAULT NULL COMMENT '利润分配（如 "BB - 4, AA - 10"）',
  `day_start` date DEFAULT NULL COMMENT 'Day start 日期',
  `day_start_frequency` varchar(30) NOT NULL DEFAULT '1st_of_every_month' COMMENT '1st_of_every_month=每月1号算账; monthly=每月(day_start日-1)号算账',
  `day_end` date DEFAULT NULL COMMENT '合同结束日期（Contract 到期日）',
  `status` enum('active','inactive','waiting') NOT NULL DEFAULT 'active' COMMENT '状态：active=启用，inactive=停用，waiting=等待中',
  `issue_flag` varchar(20) DEFAULT NULL,
  `dts_modified` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '最后更改时间',
  `modified_by` int(11) DEFAULT NULL COMMENT '最后修改人 user.id',
  `modified_by_type` enum('user','owner') DEFAULT 'user',
  `modified_by_owner_id` int(10) UNSIGNED DEFAULT NULL,
  `dts_created` datetime NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `created_by` int(11) DEFAULT NULL COMMENT '创建人 user.id',
  `created_by_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `created_by_owner_id` int(11) DEFAULT NULL,
  `accounting_resend_relax_created_floor` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Resend 后 Inbox 放宽创建日门槛并允许多账期',
  `accounting_resend_schedule_day_start` date DEFAULT NULL COMMENT 'Resend 弹窗 day_start，仅 relax 期间',
  `accounting_resend_schedule_day_end` date DEFAULT NULL COMMENT 'Resend 弹窗 day_end，仅 relax 期间',
  `accounting_resend_schedule_frequency` varchar(40) DEFAULT NULL COMMENT 'monthly 或 1st_of_every_month，仅 relax 期间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bank 流程记录表（与 process 用途类似，记录 Bank 专用字段）';
-- Table structure for table `bank_process_accounting_resend_daily_guard`
--

CREATE TABLE `bank_process_accounting_resend_daily_guard` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `bank_process_id` int(11) NOT NULL,
  `resend_day_start` date NOT NULL,
  `guard_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `bank_process_accounting_resend_daily_guard_backup`
--

CREATE TABLE `bank_process_accounting_resend_daily_guard_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL COMMENT '来自 company.company_id 展示',
  `bank_process_id` int(11) NOT NULL,
  `bank_process_name` varchar(255) NOT NULL,
  `resend_day_start` date NOT NULL,
  `guard_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='bank_process_accounting_resend_daily_guard 备份（含 company_name）';
-- Table structure for table `bank_process_backup`
--

CREATE TABLE `bank_process_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `company_name` varchar(255) DEFAULT NULL COMMENT '公司展示名（通常同步自 company.company_id）',
  `country` varchar(100) DEFAULT NULL COMMENT '国家',
  `bank` varchar(100) DEFAULT NULL COMMENT '银行名称',
  `type` varchar(100) DEFAULT NULL COMMENT '类型',
  `name` varchar(255) DEFAULT NULL COMMENT '详情/名称',
  `card_merchant_id` int(11) DEFAULT NULL COMMENT '卡商账户ID（关联 account.id）',
  `card_merchant_name` varchar(255) DEFAULT NULL COMMENT '卡商账户名（关联 account.name）',
  `customer_id` int(11) DEFAULT NULL COMMENT '顾客账户ID（关联 account.id）',
  `customer_name` varchar(255) DEFAULT NULL COMMENT '顾客账户名（关联 account.name）',
  `profit_account_id` int(11) DEFAULT NULL COMMENT '利润账户ID（关联 account.id）',
  `profit_account_name` varchar(255) DEFAULT NULL COMMENT '利润账户名（关联 account.name）',
  `contract` varchar(20) DEFAULT NULL COMMENT '合约（如 1, 2, 3, 6 个月）',
  `insurance` decimal(25,8) DEFAULT NULL,
  `sop` text DEFAULT NULL,
  `remark` varchar(500) DEFAULT NULL,
  `cost` decimal(25,8) DEFAULT NULL,
  `price` decimal(25,8) DEFAULT NULL,
  `profit` decimal(25,8) DEFAULT NULL,
  `profit_sharing` text DEFAULT NULL COMMENT '利润分配（如 "BB - 4, AA - 10"）',
  `day_start` date DEFAULT NULL COMMENT 'Day start 日期',
  `day_start_frequency` varchar(30) NOT NULL DEFAULT '1st_of_every_month' COMMENT '1st_of_every_month=每月1号算账; monthly=每月(day_start日-1)号算账',
  `day_end` date DEFAULT NULL COMMENT '合同结束日期（Contract 到期日）',
  `status` enum('active','inactive','waiting') NOT NULL DEFAULT 'active' COMMENT '状态：active=启用, inactive=停用, waiting=等待中',
  `issue_flag` varchar(20) DEFAULT NULL,
  `dts_modified` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '最后更改时间',
  `modified_by` int(11) DEFAULT NULL COMMENT '最后修改人 user.id',
  `modified_by_name` varchar(255) DEFAULT NULL COMMENT '最后修改人展示名（user.login_id / owner.owner_code 等）',
  `modified_by_type` enum('user','owner') DEFAULT 'user',
  `modified_by_owner_id` int(10) UNSIGNED DEFAULT NULL,
  `dts_created` datetime NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `created_by` int(11) DEFAULT NULL COMMENT '创建人 user.id',
  `created_by_name` varchar(255) DEFAULT NULL COMMENT '创建人展示名（user.login_id / owner.owner_code 等）',
  `created_by_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `created_by_owner_id` int(11) DEFAULT NULL,
  `accounting_resend_relax_created_floor` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Resend 后 Inbox 放宽创建门槛并允许多账期',
  `accounting_resend_schedule_day_start` date DEFAULT NULL COMMENT 'Resend 弹窗 day_start，仅 relax 期间',
  `accounting_resend_schedule_day_end` date DEFAULT NULL COMMENT 'Resend 弹窗 day_end，仅 relax 期间',
  `accounting_resend_schedule_frequency` varchar(40) DEFAULT NULL COMMENT 'monthly 或 1st_of_every_month，仅 relax 期间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bank 流程备份（含关联展示名字段）';
-- Table structure for table `bank_process_maintenance_resend_pending`
--

CREATE TABLE `bank_process_maintenance_resend_pending` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `bank_process_id` int(11) NOT NULL,
  `process_accounting_posted_id` int(11) DEFAULT NULL,
  `period_type` varchar(64) NOT NULL DEFAULT 'monthly',
  `transaction_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `bank_process_maintenance_resend_pending_backup`
--

CREATE TABLE `bank_process_maintenance_resend_pending_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `company_name` varchar(255) DEFAULT NULL COMMENT '来自 company.company_id 展示',
  `bank_process_id` int(11) NOT NULL,
  `bank_process_name` varchar(255) DEFAULT NULL COMMENT '来自 bank_process.name 展示',
  `process_accounting_posted_id` int(11) DEFAULT NULL,
  `period_type` varchar(255) NOT NULL DEFAULT 'monthly',
  `transaction_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='bank_process_maintenance_resend_pending 备份（含 company_name, bank_process_name）';
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
-- Table structure for table `process_accounting_posted`
--

CREATE TABLE `process_accounting_posted` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `process_id` int(11) NOT NULL,
  `posted_date` date NOT NULL,
  `period_type` varchar(32) NOT NULL DEFAULT 'monthly' COMMENT 'monthly = full month; partial_first_month = pro-rated from day_start to end of that month',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Records which bank_process was posted to transaction on which date (for Accounting Due inbox)';
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `submitted_processes`
--

CREATE TABLE `submitted_processes` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `process_id` int(11) NOT NULL,
  `date_submitted` date NOT NULL,
  `capture_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='记录用户提交process的简单历史记录';
-- Table structure for table `submitted_processes_backup`
--

CREATE TABLE `submitted_processes_backup` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `company_name` varchar(255) DEFAULT NULL COMMENT 'company.name',
  `user_id` int(11) DEFAULT NULL COMMENT 'user.id 或 owner.id（由 user_type 决定）',
  `user_name` varchar(255) DEFAULT NULL COMMENT 'user.login_id 或 owner.owner_code',
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user' COMMENT 'submitted_processes.user_type',
  `process_id` int(11) NOT NULL COMMENT 'process.id',
  `process_name` varchar(255) DEFAULT NULL COMMENT 'process.process_id 流程代号',
  `date_submitted` datetime NOT NULL COMMENT '提交日期',
  `capture_date` datetime DEFAULT NULL COMMENT '抓取/账务日',
  `created_at` datetime NOT NULL COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `transaction_type` enum('WIN','LOSE','PAYMENT','RECEIVE','CONTRA','CLAIM','RATE','CLEAR','ADJUSTMENT') NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `from_account_id` int(11) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL COMMENT 'Currency ID - 交易所属的货币',
  `amount` decimal(25,8) NOT NULL,
  `transaction_date` date NOT NULL COMMENT '交易日期',
  `description` varchar(500) DEFAULT NULL COMMENT '描述/备注',
  `sms` varchar(500) DEFAULT NULL COMMENT 'SMS 备注',
  `created_by` int(11) DEFAULT NULL COMMENT '创建者用户ID',
  `created_by_owner` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间',
  `approval_status` enum('APPROVED','PENDING') NOT NULL DEFAULT 'APPROVED',
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_owner` int(10) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `source_bank_process_id` int(11) DEFAULT NULL COMMENT 'Bank 流程入账来源：bank_process.id',
  `source_bank_process_period_type` varchar(32) DEFAULT NULL COMMENT 'Bank 入账类型：monthly / partial_first_month / manual_inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交易记录表 - 记录所有 WIN/LOSE/PAYMENT/RECEIVE/CONTRA 操作';
-- Table structure for table `transactions_backup`
--

CREATE TABLE `transactions_backup` (
  `backup_id` int(11) NOT NULL COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(11) DEFAULT NULL,
  `transaction_type` enum('WIN','LOSE','PAYMENT','RECEIVE','CONTRA','CLAIM','RATE','ADJUSTMENT') NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `from_account_id` int(11) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL COMMENT 'Currency ID - 交易所属的货币',
  `amount` decimal(15,2) NOT NULL COMMENT '交易金额',
  `transaction_date` date NOT NULL COMMENT '交易日期',
  `description` varchar(500) DEFAULT NULL COMMENT '描述/备注',
  `sms` varchar(500) DEFAULT NULL COMMENT 'SMS 备注',
  `created_by` int(11) DEFAULT NULL COMMENT '创建者用户ID',
  `created_by_owner` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间',
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交易记录备份表';
-- Table structure for table `transactions_deleted`
--

CREATE TABLE `transactions_deleted` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `transaction_type` enum('WIN','LOSE','PAYMENT','RECEIVE','CONTRA','CLAIM','RATE','CLEAR','ADJUSTMENT') NOT NULL,
  `account_id` int(11) NOT NULL COMMENT 'To Account - 接收方账户',
  `from_account_id` int(11) DEFAULT NULL COMMENT 'From Account - 发送方账户',
  `amount` decimal(25,8) NOT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `transaction_date` date NOT NULL COMMENT '交易日期',
  `description` varchar(500) DEFAULT NULL COMMENT '描述/备注',
  `sms` varchar(500) DEFAULT NULL COMMENT 'SMS 备注',
  `created_by` int(11) DEFAULT NULL COMMENT '创建者用户ID',
  `created_by_owner` int(11) DEFAULT NULL COMMENT '创建者 Owner ID',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `deleted_by_user_id` int(11) DEFAULT NULL COMMENT '删除者用户ID',
  `deleted_by_owner_id` int(11) DEFAULT NULL COMMENT '删除者 Owner ID',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '删除时间',
  `source_bank_process_id` int(11) DEFAULT NULL,
  `source_bank_process_period_type` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment Maintenance 已删除交易记录日志表';
-- Table structure for table `transactions_rate`
--

CREATE TABLE `transactions_rate` (
  `id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL COMMENT '关联到 transactions 表的主记录',
  `company_id` int(11) DEFAULT NULL,
  `rate_group_id` varchar(50) NOT NULL COMMENT 'RATE 交易组 ID（同一笔 RATE 交易的所有记录共享）',
  `rate_from_account_id` int(11) NOT NULL COMMENT '第一行 From Account ID (account1)',
  `rate_to_account_id` int(11) NOT NULL COMMENT '第一行 To Account ID (account2)',
  `rate_from_currency_id` int(11) NOT NULL COMMENT '第一个 Currency ID (SGD)',
  `rate_from_amount` decimal(15,2) NOT NULL COMMENT '第一个 Currency Amount (100)',
  `rate_to_currency_id` int(11) NOT NULL COMMENT '第二个 Currency ID (MYR)',
  `rate_to_amount` decimal(15,2) NOT NULL COMMENT '第二个 Currency Amount (320，扣除 middle-man 后)',
  `exchange_rate` decimal(15,6) NOT NULL COMMENT 'Exchange Rate (3.3)',
  `rate_transfer_from_account_id` int(11) DEFAULT NULL COMMENT '第二行 From Account ID (account3)',
  `rate_transfer_to_account_id` int(11) DEFAULT NULL COMMENT '第二行 To Account ID (account4)',
  `rate_transfer_from_amount` decimal(15,2) DEFAULT NULL COMMENT 'Transfer From Amount (330，原价 = from_amount × exchange_rate)',
  `rate_transfer_to_amount` decimal(15,2) DEFAULT NULL COMMENT 'Transfer To Amount (320，扣除 middle-man 后)',
  `rate_middleman_account_id` int(11) DEFAULT NULL COMMENT 'Middle-Man Account ID (account5)',
  `rate_middleman_rate` decimal(15,6) DEFAULT NULL COMMENT 'Middle-Man Rate Multiplier (0.1)',
  `rate_middleman_amount` decimal(15,2) DEFAULT NULL COMMENT 'Middle-Man Amount (10)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RATE 交易扩展表 - 存储 RATE 类型的详细信息';
-- Table structure for table `transactions_rate_backup`
--

CREATE TABLE `transactions_rate_backup` (
  `backup_id` int(11) NOT NULL COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `transaction_id` int(11) NOT NULL COMMENT '关联到 transactions 表的主记录',
  `company_id` int(11) DEFAULT NULL,
  `rate_group_id` varchar(50) NOT NULL COMMENT 'RATE 交易组 ID（同一笔 RATE 交易的所有记录共享）',
  `rate_from_account_id` int(11) NOT NULL COMMENT '第一行 From Account ID (account1)',
  `rate_to_account_id` int(11) NOT NULL COMMENT '第一行 To Account ID (account2)',
  `rate_from_currency_id` int(11) NOT NULL COMMENT '第一个 Currency ID (SGD)',
  `rate_from_amount` decimal(15,2) NOT NULL COMMENT '第一个 Currency Amount (100)',
  `rate_to_currency_id` int(11) NOT NULL COMMENT '第二个 Currency ID (MYR)',
  `rate_to_amount` decimal(15,2) NOT NULL COMMENT '第二个 Currency Amount (320，扣除 middle-man 后)',
  `exchange_rate` decimal(15,6) NOT NULL COMMENT 'Exchange Rate (3.3)',
  `rate_transfer_from_account_id` int(11) DEFAULT NULL COMMENT '第二行 From Account ID (account3)',
  `rate_transfer_to_account_id` int(11) DEFAULT NULL COMMENT '第二行 To Account ID (account4)',
  `rate_transfer_from_amount` decimal(15,2) DEFAULT NULL COMMENT 'Transfer From Amount (330，原价 = from_amount × exchange_rate)',
  `rate_transfer_to_amount` decimal(15,2) DEFAULT NULL COMMENT 'Transfer To Amount (320，扣除 middle-man 后)',
  `rate_middleman_account_id` int(11) DEFAULT NULL COMMENT 'Middle-Man Account ID (account5)',
  `rate_middleman_rate` decimal(15,6) DEFAULT NULL COMMENT 'Middle-Man Rate Multiplier (0.1)',
  `rate_middleman_amount` decimal(15,2) DEFAULT NULL COMMENT 'Middle-Man Amount (10)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RATE 交易扩展备份表';
-- Table structure for table `transactions_rate_details`
--

CREATE TABLE `transactions_rate_details` (
  `id` int(11) NOT NULL,
  `rate_group_id` varchar(50) NOT NULL COMMENT 'RATE 交易组 ID',
  `transaction_id` int(11) NOT NULL COMMENT '关联到 transactions 表的记录',
  `company_id` int(11) DEFAULT NULL,
  `record_type` enum('first_from','first_to','transfer_from','transfer_to','middleman') NOT NULL,
  `account_id` int(11) NOT NULL COMMENT 'Account ID',
  `from_account_id` int(11) DEFAULT NULL COMMENT 'From Account ID（用于关联扣除）',
  `amount` decimal(15,2) NOT NULL COMMENT 'Amount（正数，符号由 record_type 决定）',
  `currency_id` int(11) NOT NULL COMMENT 'Currency ID',
  `description` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RATE 交易详细记录表 - 存储 RATE 交易的每条详细记录';
-- Table structure for table `transactions_rate_details_backup`
--

CREATE TABLE `transactions_rate_details_backup` (
  `backup_id` int(11) NOT NULL COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `rate_group_id` varchar(50) NOT NULL COMMENT 'RATE 交易组 ID',
  `transaction_id` int(11) NOT NULL COMMENT '关联到 transactions 表的记录',
  `company_id` int(11) DEFAULT NULL,
  `record_type` enum('first_from','first_to','transfer_from','transfer_to','middleman') NOT NULL,
  `account_id` int(11) NOT NULL COMMENT 'Account ID',
  `from_account_id` int(11) DEFAULT NULL COMMENT 'From Account ID（用于关联扣除）',
  `amount` decimal(15,2) NOT NULL COMMENT 'Amount（正数，符号由 record_type 决定）',
  `currency_id` int(11) NOT NULL COMMENT 'Currency ID',
  `description` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RATE 交易详细记录备份表';
-- Table structure for table `transaction_entry`
--

CREATE TABLE `transaction_entry` (
  `id` int(11) NOT NULL,
  `header_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `amount` decimal(25,8) NOT NULL,
  `entry_type` enum('NORMAL_FROM','NORMAL_TO','RATE_FIRST_FROM','RATE_FIRST_TO','RATE_TRANSFER_FROM','RATE_TRANSFER_TO','RATE_MIDDLEMAN','RATE_FEE') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
-- Table structure for table `transaction_entry_backup`
--

CREATE TABLE `transaction_entry_backup` (
  `backup_id` int(11) NOT NULL COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `header_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `entry_type` enum('NORMAL_FROM','NORMAL_TO','RATE_FIRST_FROM','RATE_FIRST_TO','RATE_TRANSFER_FROM','RATE_TRANSFER_TO','RATE_MIDDLEMAN','RATE_FEE') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
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
-- Indexes for table `bank_process`
--
ALTER TABLE `bank_process`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bank_process_company` (`company_id`),
  ADD KEY `idx_bank_process_status` (`status`),
  ADD KEY `idx_bank_process_card_merchant` (`card_merchant_id`),
  ADD KEY `idx_bank_process_customer` (`customer_id`),
  ADD KEY `idx_bank_process_modified_by` (`modified_by`),
  ADD KEY `idx_bank_process_created_by` (`created_by`),
  ADD KEY `idx_bank_process_profit_account` (`profit_account_id`);

--
-- Indexes for table `bank_process_accounting_resend_daily_guard`
--
ALTER TABLE `bank_process_accounting_resend_daily_guard`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bp_resend_daily_guard` (`company_id`,`bank_process_id`,`resend_day_start`,`guard_date`);

--
-- Indexes for table `bank_process_accounting_resend_daily_guard_backup`
--
ALTER TABLE `bank_process_accounting_resend_daily_guard_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_bank_process_id` (`bank_process_id`),
  ADD KEY `idx_resend_day_start` (`resend_day_start`),
  ADD KEY `idx_guard_date` (`guard_date`);

--
-- Indexes for table `bank_process_backup`
--
ALTER TABLE `bank_process_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_card_merchant_id` (`card_merchant_id`),
  ADD KEY `idx_customer_id` (`customer_id`),
  ADD KEY `idx_profit_account_id` (`profit_account_id`);

--
-- Indexes for table `bank_process_maintenance_resend_pending`
--
ALTER TABLE `bank_process_maintenance_resend_pending`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bmp_resend_pap` (`process_accounting_posted_id`),
  ADD UNIQUE KEY `uq_bmp_resend_fallback` (`company_id`,`bank_process_id`,`period_type`,`transaction_date`);

--
-- Indexes for table `bank_process_maintenance_resend_pending_backup`
--
ALTER TABLE `bank_process_maintenance_resend_pending_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_bank_process_id` (`bank_process_id`);

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
-- Indexes for table `process_accounting_posted`
--
ALTER TABLE `process_accounting_posted`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_company_process_date_type` (`company_id`,`process_id`,`posted_date`,`period_type`),
  ADD KEY `idx_posted_date` (`posted_date`),
  ADD KEY `idx_company_date` (`company_id`,`posted_date`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `submitted_processes`
--
ALTER TABLE `submitted_processes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`date_submitted`),
  ADD KEY `idx_process` (`process_id`),
  ADD KEY `idx_user_type_id` (`user_type`,`user_id`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_capture_date` (`capture_date`);

--
-- Indexes for table `submitted_processes_backup`
--
ALTER TABLE `submitted_processes_backup`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_submitted_processes_backup_company_id` (`company_id`),
  ADD KEY `idx_submitted_processes_backup_user_id` (`user_id`),
  ADD KEY `idx_submitted_processes_backup_process_id` (`process_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_account_date` (`account_id`,`transaction_date`),
  ADD KEY `idx_from_account_date` (`from_account_id`,`transaction_date`),
  ADD KEY `idx_transaction_date` (`transaction_date`),
  ADD KEY `idx_transaction_type` (`transaction_type`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_created_by_owner` (`created_by_owner`),
  ADD KEY `idx_currency_id` (`currency_id`),
  ADD KEY `idx_transactions_company` (`company_id`),
  ADD KEY `idx_contra_approval` (`company_id`,`transaction_type`,`approval_status`,`transaction_date`),
  ADD KEY `idx_source_bank_process` (`source_bank_process_id`),
  ADD KEY `idx_company_account_date` (`company_id`,`account_id`,`transaction_date`),
  ADD KEY `idx_company_from_account_date` (`company_id`,`from_account_id`,`transaction_date`),
  ADD KEY `idx_maint_company_txn_date` (`company_id`,`transaction_date`);

--
-- Indexes for table `transactions_backup`
--
ALTER TABLE `transactions_backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_id` (`id`),
  ADD KEY `idx_backup_created_at` (`backup_created_at`),
  ADD KEY `idx_account_date` (`account_id`,`transaction_date`),
  ADD KEY `idx_from_account_date` (`from_account_id`,`transaction_date`),
  ADD KEY `idx_transaction_date` (`transaction_date`),
  ADD KEY `idx_transaction_type` (`transaction_type`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_created_by_owner` (`created_by_owner`),
  ADD KEY `idx_currency_id` (`currency_id`),
  ADD KEY `idx_transactions_company` (`company_id`);

--
-- Indexes for table `transactions_deleted`
--
ALTER TABLE `transactions_deleted`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_company_date` (`company_id`,`transaction_date`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indexes for table `transactions_rate`
--
ALTER TABLE `transactions_rate`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rate_transfer_from_account_id` (`rate_transfer_from_account_id`),
  ADD KEY `rate_transfer_to_account_id` (`rate_transfer_to_account_id`),
  ADD KEY `rate_middleman_account_id` (`rate_middleman_account_id`),
  ADD KEY `rate_from_currency_id` (`rate_from_currency_id`),
  ADD KEY `rate_to_currency_id` (`rate_to_currency_id`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_rate_group_id` (`rate_group_id`),
  ADD KEY `idx_rate_from_account` (`rate_from_account_id`),
  ADD KEY `idx_rate_to_account` (`rate_to_account_id`),
  ADD KEY `idx_rate_company` (`company_id`);

--
-- Indexes for table `transactions_rate_backup`
--
ALTER TABLE `transactions_rate_backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_id` (`id`),
  ADD KEY `idx_backup_created_at` (`backup_created_at`),
  ADD KEY `rate_transfer_from_account_id` (`rate_transfer_from_account_id`),
  ADD KEY `rate_transfer_to_account_id` (`rate_transfer_to_account_id`),
  ADD KEY `rate_middleman_account_id` (`rate_middleman_account_id`),
  ADD KEY `rate_from_currency_id` (`rate_from_currency_id`),
  ADD KEY `rate_to_currency_id` (`rate_to_currency_id`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_rate_group_id` (`rate_group_id`),
  ADD KEY `idx_rate_from_account` (`rate_from_account_id`),
  ADD KEY `idx_rate_to_account` (`rate_to_account_id`),
  ADD KEY `idx_rate_company` (`company_id`);

--
-- Indexes for table `transactions_rate_details`
--
ALTER TABLE `transactions_rate_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `from_account_id` (`from_account_id`),
  ADD KEY `currency_id` (`currency_id`),
  ADD KEY `idx_rate_group_id` (`rate_group_id`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `idx_rate_details_company` (`company_id`);

--
-- Indexes for table `transactions_rate_details_backup`
--
ALTER TABLE `transactions_rate_details_backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_id` (`id`),
  ADD KEY `idx_backup_created_at` (`backup_created_at`),
  ADD KEY `from_account_id` (`from_account_id`),
  ADD KEY `currency_id` (`currency_id`),
  ADD KEY `idx_rate_group_id` (`rate_group_id`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `idx_rate_details_company` (`company_id`);

--
-- Indexes for table `transaction_entry`
--
ALTER TABLE `transaction_entry`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_header` (`header_id`),
  ADD KEY `idx_account_currency_date` (`account_id`,`currency_id`,`created_at`),
  ADD KEY `fk_entry_currency` (`currency_id`),
  ADD KEY `idx_entry_company` (`company_id`);

--
-- Indexes for table `transaction_entry_backup`
--
ALTER TABLE `transaction_entry_backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `idx_id` (`id`),
  ADD KEY `idx_backup_created_at` (`backup_created_at`),
  ADD KEY `idx_header` (`header_id`),
  ADD KEY `idx_account_currency_date` (`account_id`,`currency_id`,`created_at`),
  ADD KEY `fk_entry_currency` (`currency_id`),
  ADD KEY `idx_entry_company` (`company_id`);

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
-- AUTO_INCREMENT for table `bank_process`
--
ALTER TABLE `bank_process`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=430;

--
-- AUTO_INCREMENT for table `bank_process_accounting_resend_daily_guard`
--
ALTER TABLE `bank_process_accounting_resend_daily_guard`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `bank_process_accounting_resend_daily_guard_backup`
--
ALTER TABLE `bank_process_accounting_resend_daily_guard_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `bank_process_backup`
--
ALTER TABLE `bank_process_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=428;

--
-- AUTO_INCREMENT for table `bank_process_maintenance_resend_pending`
--
ALTER TABLE `bank_process_maintenance_resend_pending`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=695;

--
-- AUTO_INCREMENT for table `bank_process_maintenance_resend_pending_backup`
--
ALTER TABLE `bank_process_maintenance_resend_pending_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=678;

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
-- AUTO_INCREMENT for table `deleted_logs`
--
ALTER TABLE `deleted_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `process_accounting_posted`
--
ALTER TABLE `process_accounting_posted`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1145;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `submitted_processes`
--
ALTER TABLE `submitted_processes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7041;

--
-- AUTO_INCREMENT for table `submitted_processes_backup`
--
ALTER TABLE `submitted_processes_backup`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7041;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8405;

--
-- AUTO_INCREMENT for table `transactions_backup`
--
ALTER TABLE `transactions_backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID', AUTO_INCREMENT=7773;

--
-- AUTO_INCREMENT for table `transactions_deleted`
--
ALTER TABLE `transactions_deleted`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3895;

--
-- AUTO_INCREMENT for table `transactions_rate`
--
ALTER TABLE `transactions_rate`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=225;

--
-- AUTO_INCREMENT for table `transactions_rate_backup`
--
ALTER TABLE `transactions_rate_backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID', AUTO_INCREMENT=190;

--
-- AUTO_INCREMENT for table `transactions_rate_details`
--
ALTER TABLE `transactions_rate_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1036;

--
-- AUTO_INCREMENT for table `transactions_rate_details_backup`
--
ALTER TABLE `transactions_rate_details_backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID', AUTO_INCREMENT=840;

--
-- AUTO_INCREMENT for table `transaction_entry`
--
ALTER TABLE `transaction_entry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=828;

--
-- AUTO_INCREMENT for table `transaction_entry_backup`
--
ALTER TABLE `transaction_entry_backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID', AUTO_INCREMENT=2309;

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
-- Constraints for table `bank_process`
--
ALTER TABLE `bank_process`
    ADD CONSTRAINT `fk_bank_process_card_merchant` FOREIGN KEY (`card_merchant_id`) REFERENCES `account` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_bank_process_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_bank_process_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_bank_process_customer` FOREIGN KEY (`customer_id`) REFERENCES `account` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_bank_process_modified_by` FOREIGN KEY (`modified_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    ADD CONSTRAINT `fk_bank_process_profit_account` FOREIGN KEY (`profit_account_id`) REFERENCES `account` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;


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
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
    ADD CONSTRAINT `fk_transactions_created_by_owner` FOREIGN KEY (`created_by_owner`) REFERENCES `owner` (`id`),

    ADD CONSTRAINT `fk_transactions_currency` FOREIGN KEY (`currency_id`) REFERENCES `currency` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`from_account_id`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,

    ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`);


--
-- Constraints for table `transactions_rate`
--
ALTER TABLE `transactions_rate`
    ADD CONSTRAINT `transactions_rate_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,

    ADD CONSTRAINT `transactions_rate_ibfk_2` FOREIGN KEY (`rate_from_account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `transactions_rate_ibfk_3` FOREIGN KEY (`rate_to_account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `transactions_rate_ibfk_4` FOREIGN KEY (`rate_transfer_from_account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `transactions_rate_ibfk_5` FOREIGN KEY (`rate_transfer_to_account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `transactions_rate_ibfk_6` FOREIGN KEY (`rate_middleman_account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `transactions_rate_ibfk_7` FOREIGN KEY (`rate_from_currency_id`) REFERENCES `currency` (`id`),

    ADD CONSTRAINT `transactions_rate_ibfk_8` FOREIGN KEY (`rate_to_currency_id`) REFERENCES `currency` (`id`);


--
-- Constraints for table `transactions_rate_details`
--
ALTER TABLE `transactions_rate_details`
    ADD CONSTRAINT `transactions_rate_details_ibfk_1` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,

    ADD CONSTRAINT `transactions_rate_details_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `transactions_rate_details_ibfk_3` FOREIGN KEY (`from_account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `transactions_rate_details_ibfk_4` FOREIGN KEY (`currency_id`) REFERENCES `currency` (`id`);


--
-- Constraints for table `transaction_entry`
--
ALTER TABLE `transaction_entry`
    ADD CONSTRAINT `fk_entry_account` FOREIGN KEY (`account_id`) REFERENCES `account` (`id`),

    ADD CONSTRAINT `fk_entry_currency` FOREIGN KEY (`currency_id`) REFERENCES `currency` (`id`),

    ADD CONSTRAINT `fk_entry_header` FOREIGN KEY (`header_id`) REFERENCES `transactions` (`id`);


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
