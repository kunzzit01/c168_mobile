-- Auto renew manual approval requests (one row per company per expiration cycle).
-- Also auto-created by api/includes/auto_renew.php on list.

CREATE TABLE IF NOT EXISTS `company_auto_renew_request` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT 'FK company.id (client company)',
  `expiration_snapshot` date NOT NULL COMMENT 'expiration_date when request was opened',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `period` varchar(20) DEFAULT NULL,
  `price` decimal(25,8) DEFAULT NULL,
  `from_account_id` int(11) DEFAULT NULL COMMENT 'C168 payer account',
  `to_account_id` int(11) DEFAULT NULL COMMENT 'C168 receiver account',
  `transaction_id` int(11) DEFAULT NULL,
  `new_expiration_date` date DEFAULT NULL,
  `processed_by` varchar(50) DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auto_renew_company_exp` (`company_id`,`expiration_snapshot`),
  KEY `idx_auto_renew_status` (`status`),
  KEY `idx_auto_renew_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
