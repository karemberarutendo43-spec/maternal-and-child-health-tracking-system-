-- Clinical Decision Support Status (optional)
-- Adds a numeric status to ANC visits and immunizations:
--   0 = Scheduled
--   1 = Completed / Given
--   2 = Strictly Pending (overdue / action required)
--
-- NOTE: The application still keeps the existing string statuses for backward compatibility.
-- Run this on your MySQL/MariaDB database.

SET @db := DATABASE();

-- anc_visits.clinical_status
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'anc_visits'
      AND COLUMN_NAME = 'clinical_status'
  );
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE anc_visits ADD COLUMN clinical_status TINYINT NOT NULL DEFAULT 0 AFTER status',
    'SELECT \"anc_visits.clinical_status already exists\"'
  );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- immunizations.clinical_status
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'immunizations'
      AND COLUMN_NAME = 'clinical_status'
  );
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE immunizations ADD COLUMN clinical_status TINYINT NOT NULL DEFAULT 0 AFTER status',
    'SELECT \"immunizations.clinical_status already exists\"'
  );
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

