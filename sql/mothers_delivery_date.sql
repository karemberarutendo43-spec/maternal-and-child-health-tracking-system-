-- Mothers: delivery_date
-- Stores the date of delivery to trigger PNC scheduling/defaulter logic.
-- Run this on your MySQL/MariaDB database.

SET @db := DATABASE();
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'mothers'
      AND COLUMN_NAME = 'delivery_date'
  );
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE mothers ADD COLUMN delivery_date DATE NULL AFTER pregnancy_status',
    'SELECT \"mothers.delivery_date already exists\"'
  );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

