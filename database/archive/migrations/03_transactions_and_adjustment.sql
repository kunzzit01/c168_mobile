-- 03_transactions_and_adjustment.sql
-- Run once on an existing DB (skip statements already applied).

-- ========== from: add_adjustment_transaction_type.sql ==========
-- Add ADJUSTMENT to transaction_type columns only when the column is an ENUM.
-- If transaction_type is already VARCHAR or already contains ADJUSTMENT, this migration is a no-op.

DROP PROCEDURE IF EXISTS add_adjustment_transaction_type;

DELIMITER //
CREATE PROCEDURE add_adjustment_transaction_type()
BEGIN
    DECLARE v_column_type TEXT;
    DECLARE v_is_nullable VARCHAR(3);
    DECLARE v_default_value TEXT;
    DECLARE v_sql TEXT;

    SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
      INTO v_column_type, v_is_nullable, v_default_value
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'transactions'
       AND COLUMN_NAME = 'transaction_type'
     LIMIT 1;

    IF v_column_type IS NOT NULL
       AND LOWER(v_column_type) LIKE 'enum(%'
       AND v_column_type NOT LIKE '%''ADJUSTMENT''%' THEN
        SET v_column_type = CONCAT(LEFT(v_column_type, CHAR_LENGTH(v_column_type) - 1), ',''ADJUSTMENT'')');
        SET v_sql = CONCAT(
            'ALTER TABLE `transactions` MODIFY COLUMN `transaction_type` ',
            v_column_type,
            IF(v_is_nullable = 'NO', ' NOT NULL', ' NULL'),
            IF(v_default_value IS NULL, '', CONCAT(' DEFAULT ', QUOTE(v_default_value)))
        );
        SET @stmt = v_sql;
        PREPARE stmt FROM @stmt;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL add_adjustment_transaction_type();

DROP PROCEDURE IF EXISTS add_adjustment_transaction_type;

DROP PROCEDURE IF EXISTS add_adjustment_deleted_transaction_type;

DROP PROCEDURE IF EXISTS add_adjustment_backup_transaction_type;

DELIMITER //
CREATE PROCEDURE add_adjustment_backup_transaction_type()
BEGIN
    DECLARE v_column_type TEXT;
    DECLARE v_is_nullable VARCHAR(3);
    DECLARE v_default_value TEXT;
    DECLARE v_sql TEXT;

    SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
      INTO v_column_type, v_is_nullable, v_default_value
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'transactions_backup'
       AND COLUMN_NAME = 'transaction_type'
     LIMIT 1;

    IF v_column_type IS NOT NULL
       AND LOWER(v_column_type) LIKE 'enum(%'
       AND v_column_type NOT LIKE '%''ADJUSTMENT''%' THEN
        SET v_column_type = CONCAT(LEFT(v_column_type, CHAR_LENGTH(v_column_type) - 1), ',''ADJUSTMENT'')');
        SET v_sql = CONCAT(
            'ALTER TABLE `transactions_backup` MODIFY COLUMN `transaction_type` ',
            v_column_type,
            IF(v_is_nullable = 'NO', ' NOT NULL', ' NULL'),
            IF(v_default_value IS NULL, '', CONCAT(' DEFAULT ', QUOTE(v_default_value)))
        );
        SET @stmt = v_sql;
        PREPARE stmt FROM @stmt;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL add_adjustment_backup_transaction_type();

DROP PROCEDURE IF EXISTS add_adjustment_backup_transaction_type;

DELIMITER //
CREATE PROCEDURE add_adjustment_deleted_transaction_type()
BEGIN
    DECLARE v_column_type TEXT;
    DECLARE v_is_nullable VARCHAR(3);
    DECLARE v_default_value TEXT;
    DECLARE v_sql TEXT;

    SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
      INTO v_column_type, v_is_nullable, v_default_value
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'transactions_deleted'
       AND COLUMN_NAME = 'transaction_type'
     LIMIT 1;

    IF v_column_type IS NOT NULL
       AND LOWER(v_column_type) LIKE 'enum(%'
       AND v_column_type NOT LIKE '%''ADJUSTMENT''%' THEN
        SET v_column_type = CONCAT(LEFT(v_column_type, CHAR_LENGTH(v_column_type) - 1), ',''ADJUSTMENT'')');
        SET v_sql = CONCAT(
            'ALTER TABLE `transactions_deleted` MODIFY COLUMN `transaction_type` ',
            v_column_type,
            IF(v_is_nullable = 'NO', ' NOT NULL', ' NULL'),
            IF(v_default_value IS NULL, '', CONCAT(' DEFAULT ', QUOTE(v_default_value)))
        );
        SET @stmt = v_sql;
        PREPARE stmt FROM @stmt;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

CALL add_adjustment_deleted_transaction_type();

DROP PROCEDURE IF EXISTS add_adjustment_deleted_transaction_type;


-- ========== from: allow_adjustment_signed_amount.sql ==========
-- Allow ADJUSTMENT to carry a signed amount while keeping other transaction types positive.
-- This replaces legacy transactions BEFORE INSERT/UPDATE validation triggers that reject every amount <= 0.

DROP PROCEDURE IF EXISTS drop_transactions_before_validation_triggers;

DELIMITER //

CREATE PROCEDURE drop_transactions_before_validation_triggers()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE v_trigger_name VARCHAR(255);
    DECLARE cur CURSOR FOR
        SELECT TRIGGER_NAME
          FROM INFORMATION_SCHEMA.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND EVENT_OBJECT_TABLE = 'transactions'
           AND ACTION_TIMING = 'BEFORE'
           AND EVENT_MANIPULATION IN ('INSERT', 'UPDATE');
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_trigger_name;
        IF done = 1 THEN
            LEAVE read_loop;
        END IF;
        SET @drop_sql = CONCAT('DROP TRIGGER IF EXISTS `', REPLACE(v_trigger_name, '`', '``'), '`');
        PREPARE stmt FROM @drop_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;
END//

DELIMITER ;

CALL drop_transactions_before_validation_triggers();

DROP PROCEDURE IF EXISTS drop_transactions_before_validation_triggers;

DELIMITER //

CREATE TRIGGER before_transaction_insert
BEFORE INSERT ON transactions
FOR EACH ROW
BEGIN
    IF NEW.transaction_type = 'ADJUSTMENT' THEN
        IF NEW.amount = 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ADJUSTMENT amount cannot be 0';
        END IF;

        IF NEW.from_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ADJUSTMENT only supports one account';
        END IF;
    ELSE
        IF NEW.amount <= 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '金额必须大于 0';
        END IF;
    END IF;

    IF NEW.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'CLEAR') THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PAYMENT/RECEIVE/CONTRA/CLAIM/CLEAR 交易必须有 From Account';
        END IF;

        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
END//

CREATE TRIGGER before_transaction_update
BEFORE UPDATE ON transactions
FOR EACH ROW
BEGIN
    IF NEW.transaction_type = 'ADJUSTMENT' THEN
        IF NEW.amount = 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ADJUSTMENT amount cannot be 0';
        END IF;

        IF NEW.from_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ADJUSTMENT only supports one account';
        END IF;
    ELSE
        IF NEW.amount <= 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = '金额必须大于 0';
        END IF;
    END IF;

    IF NEW.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'CLEAR') THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PAYMENT/RECEIVE/CONTRA/CLAIM/CLEAR 交易必须有 From Account';
        END IF;

        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
END//

DELIMITER ;


-- ========== from: fix_tr_amount_guard_adjustment_signed.sql ==========
-- Repair tr_transactions_amount_guard_* after ensureTransactionsAllowZeroAmount()
-- incorrectly rejected negative ADJUSTMENT amounts. ADJUSTMENT matches submit_api /
-- allow_adjustment_signed_amount.sql (non-zero signed amount; from_account_id NULL).

DROP TRIGGER IF EXISTS `tr_transactions_amount_guard_bi`;
DROP TRIGGER IF EXISTS `tr_transactions_amount_guard_bu`;

DELIMITER //

CREATE TRIGGER `tr_transactions_amount_guard_bi`
BEFORE INSERT ON `transactions`
FOR EACH ROW
BEGIN
    IF NEW.transaction_type = 'ADJUSTMENT' THEN
        IF NEW.amount = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT 金额不能为 0';
        END IF;
        IF NEW.from_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT only supports one account';
        END IF;
    ELSE
        IF NEW.amount < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '金额不能小于 0';
        END IF;
    END IF;
END//

CREATE TRIGGER `tr_transactions_amount_guard_bu`
BEFORE UPDATE ON `transactions`
FOR EACH ROW
BEGIN
    IF NEW.transaction_type = 'ADJUSTMENT' THEN
        IF NEW.amount = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT 金额不能为 0';
        END IF;
        IF NEW.from_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ADJUSTMENT only supports one account';
        END IF;
    ELSE
        IF NEW.amount < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '金额不能小于 0';
        END IF;
    END IF;
END//

DELIMITER ;


-- ========== from: money_precision_decimal_25_8.sql ==========
-- Financial-grade money precision migration.
-- Run once before relying on string amount APIs and decimal.js/BC Math calculations.

ALTER TABLE transactions
    MODIFY COLUMN amount DECIMAL(25,8) NOT NULL;

ALTER TABLE transactions_deleted
    MODIFY COLUMN amount DECIMAL(25,8) NOT NULL;

ALTER TABLE transaction_entry
    MODIFY COLUMN amount DECIMAL(25,8) NOT NULL;

ALTER TABLE data_capture_details
    MODIFY COLUMN processed_amount DECIMAL(25,8) NULL,
    MODIFY COLUMN rate DECIMAL(25,8) NULL;

ALTER TABLE data_capture_templates
    MODIFY COLUMN last_processed_amount DECIMAL(25,8) NULL;

ALTER TABLE bank_process
    MODIFY COLUMN insurance DECIMAL(25,8) NULL,
    MODIFY COLUMN cost DECIMAL(25,8) NULL,
    MODIFY COLUMN price DECIMAL(25,8) NULL,
    MODIFY COLUMN profit DECIMAL(25,8) NULL;

ALTER TABLE account
    MODIFY COLUMN alert_amount DECIMAL(25,8) NULL;

ALTER TABLE domain_list_fee_settings
    MODIFY COLUMN price DECIMAL(25,8) NULL;


