-- maintenance_marquee: add editable prefix for login marquee label
SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE `maintenance_marquee`
  ADD COLUMN IF NOT EXISTS `prefix` VARCHAR(100) NULL DEFAULT NULL
    COMMENT 'Marquee label prefix, e.g. 系统维护中:' AFTER `content`;

COMMIT;
