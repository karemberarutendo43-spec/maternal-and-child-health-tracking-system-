-- Adds optional seen-tracking columns to mother_transfers for UI notifications.
--   nurse_seen_at: when the requesting nurse last opened/acknowledged a REJECTED transfer
--   mother_seen_at: when the mother last acknowledged an APPROVED transfer
--
-- Run this on your MySQL/MariaDB database.

SET @db := DATABASE();

-- mother_transfers.nurse_seen_at
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'mother_transfers'
      AND COLUMN_NAME = 'nurse_seen_at'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE mother_transfers ADD COLUMN nurse_seen_at DATETIME NULL AFTER approved_at',
    'SELECT \"mother_transfers.nurse_seen_at already exists\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- mother_transfers.mother_seen_at
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'mother_transfers'
      AND COLUMN_NAME = 'mother_seen_at'
);
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE mother_transfers ADD COLUMN mother_seen_at DATETIME NULL AFTER nurse_seen_at',
    'SELECT \"mother_transfers.mother_seen_at already exists\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

