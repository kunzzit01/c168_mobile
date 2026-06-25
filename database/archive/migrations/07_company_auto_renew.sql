-- Auto renew subscription columns on company (for payment gateway integration later).
-- Also auto-applied by api/includes/auto_renew.php on first API call.

ALTER TABLE `company`
  ADD COLUMN IF NOT EXISTS `auto_renew_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether subscription auto renew is enabled',
  ADD COLUMN IF NOT EXISTS `auto_renew_period` VARCHAR(20) NULL DEFAULT NULL COMMENT '7days|1month|3months|6months|1year',
  ADD COLUMN IF NOT EXISTS `payment_customer_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Payment gateway customer id',
  ADD COLUMN IF NOT EXISTS `payment_subscription_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Payment gateway subscription id',
  ADD COLUMN IF NOT EXISTS `auto_renew_updated_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `auto_renew_updated_by` VARCHAR(50) NULL DEFAULT NULL;
