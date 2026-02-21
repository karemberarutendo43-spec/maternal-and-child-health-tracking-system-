-- Cleanup: drop unused columns safely (optional)
-- Run this on your MySQL/MariaDB database.
-- This is conservative: only columns that are not used anywhere in the PHP codebase.

SET @db := DATABASE();

-- clinics.admin_code_hash (previously added as an optional future feature, but unused)
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'clinics'
      AND COLUMN_NAME = 'admin_code_hash'
);
SET @sql := IF(
    @col_exists > 0,
    'ALTER TABLE clinics DROP COLUMN admin_code_hash',
    'SELECT \"clinics.admin_code_hash does not exist (nothing to drop)\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

