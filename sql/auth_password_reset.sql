-- Password reset + forced change support
-- Adds users.must_change_password so admins can reset a password and force user to change it on next login.
-- Run this on your MySQL/MariaDB database.

SET @db := DATABASE();

-- users.must_change_password
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'must_change_password'
  );
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash',
    'SELECT \"users.must_change_password already exists\"'
  );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

