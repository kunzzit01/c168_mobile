-- 02_bank_process.sql
-- Run once on an existing DB (skip statements already applied).

-- ========== from: add_bank_process_remark.sql ==========
-- Add remark column to bank_process for Add Process modal (run once; skip if column already exists)
ALTER TABLE bank_process
  ADD COLUMN remark VARCHAR(500) NULL DEFAULT NULL AFTER insurance;


-- ========== from: add_bank_process_sop.sql ==========
-- Add sop column to bank_process so SOP and Remark can be stored separately (run once; skip if column already exists)
ALTER TABLE bank_process
  ADD COLUMN sop TEXT NULL AFTER insurance;


-- ========== from: add_bank_process_issue_flag.sql ==========
-- Add issue_flag column to bank_process for Process List status dropdown (run once; skip if column already exists)
ALTER TABLE bank_process
  ADD COLUMN issue_flag VARCHAR(20) NULL DEFAULT NULL AFTER status;


-- ========== from: add_bank_process_maintenance_resend_pending.sql ==========
-- 首次在 Maintenance 删除 Bank process 入账交易时会自动建表；也可手动执行本脚本。
CREATE TABLE IF NOT EXISTS bank_process_maintenance_resend_pending (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    bank_process_id INT NOT NULL,
    process_accounting_posted_id INT NULL,
    period_type VARCHAR(64) NOT NULL DEFAULT 'monthly',
    transaction_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bmp_resend_pap (process_accounting_posted_id),
    UNIQUE KEY uq_bmp_resend_fallback (company_id, bank_process_id, period_type, transaction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========== from: add_bank_process_accounting_resend_schedule.sql ==========
-- Resend 弹窗日程：仅在 accounting_resend_relax_created_floor=1 期间参与 Inbox/入账；入账成功后与 relax 一并清空。
-- 若线上已由 PHP bmp_ensureBankProcessAccountingResendScheduleColumns 自动添加，可跳过本脚本。

ALTER TABLE bank_process
    ADD COLUMN accounting_resend_schedule_day_start DATE NULL COMMENT 'Resend 弹窗 day_start，仅 relax 期间',
    ADD COLUMN accounting_resend_schedule_day_end DATE NULL COMMENT 'Resend 弹窗 day_end，仅 relax 期间',
    ADD COLUMN accounting_resend_schedule_frequency VARCHAR(40) NULL COMMENT 'monthly 或 1st_of_every_month，仅 relax 期间';


-- ========== from: add_bank_process_accounting_resend_relax_created_floor.sql ==========
-- Resend → Accounting Due：放宽「旧数据不拿」创建日门槛（与 day_start 取 min），入账成功后清零。
ALTER TABLE bank_process
    ADD COLUMN accounting_resend_relax_created_floor TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=最近一次 Maintenance+Resend 后 Inbox 用 min(创建日,day_start) 作门槛';


-- ========== from: add_source_bank_process_period_type.sql ==========
-- 每笔 Bank process 入账交易单独记录 period_type，使同一天 monthly / inactive / partial_first_month 分开显示
-- 执行前请备份数据库。若使用 transactions_backup 触发器，需先给 transactions_backup 表加同名列并更新触发器。

ALTER TABLE `transactions`
  ADD COLUMN `source_bank_process_period_type` VARCHAR(32) NULL DEFAULT NULL
  COMMENT 'Bank 入账类型：monthly / partial_first_month / manual_inactive'
  AFTER `source_bank_process_id`;

-- 可选：若存在 transactions_backup 表且需备份此列，可执行：
-- ALTER TABLE `transactions_backup`
--   ADD COLUMN `source_bank_process_period_type` VARCHAR(32) NULL DEFAULT NULL
--   COMMENT 'Bank 入账类型：monthly / partial_first_month / manual_inactive'
--   AFTER `source_bank_process_id`;
-- 并修改 trg_transactions_backup_insert / trg_transactions_backup_update 触发器，在 INSERT 列表中加入 source_bank_process_period_type。


