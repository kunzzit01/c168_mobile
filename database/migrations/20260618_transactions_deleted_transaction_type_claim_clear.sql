-- Align transactions_deleted.transaction_type ENUM with transactions (add CLAIM, CLEAR if missing).
-- Run once on existing DB (idempotent).

DROP PROCEDURE IF EXISTS sync_transactions_deleted_transaction_type_enum;

DELIMITER //
CREATE PROCEDURE sync_transactions_deleted_transaction_type_enum()
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
       AND LOWER(v_column_type) LIKE 'enum(%' THEN
        IF v_column_type NOT LIKE '%''CLAIM''%' THEN
            SET v_column_type = CONCAT(LEFT(v_column_type, CHAR_LENGTH(v_column_type) - 1), ',''CLAIM'')');
        END IF;
        IF v_column_type NOT LIKE '%''CLEAR''%' THEN
            SET v_column_type = CONCAT(LEFT(v_column_type, CHAR_LENGTH(v_column_type) - 1), ',''CLEAR'')');
        END IF;
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

CALL sync_transactions_deleted_transaction_type_enum();

DROP PROCEDURE IF EXISTS sync_transactions_deleted_transaction_type_enum;
