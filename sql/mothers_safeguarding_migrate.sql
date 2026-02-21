-- Mothers Safeguarding Fields Migration
-- Adds columns used for underage safeguarding flag + acknowledgement workflow.

SET @has_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
);

-- safeguarding_flag
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'safeguarding_flag'
);
SET @sql := IF(
  @has_table = 1 AND @has_col = 0,
  "ALTER TABLE mothers ADD COLUMN safeguarding_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER risk_level",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- safeguarding_status
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'safeguarding_status'
);
SET @sql := IF(
  @has_table = 1 AND @has_col = 0,
  "ALTER TABLE mothers ADD COLUMN safeguarding_status VARCHAR(30) NULL AFTER safeguarding_flag",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- safeguarding_reason
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'safeguarding_reason'
);
SET @sql := IF(
  @has_table = 1 AND @has_col = 0,
  "ALTER TABLE mothers ADD COLUMN safeguarding_reason VARCHAR(255) NULL AFTER safeguarding_status",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- safeguarding_ack_by
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'safeguarding_ack_by'
);
SET @sql := IF(
  @has_table = 1 AND @has_col = 0,
  "ALTER TABLE mothers ADD COLUMN safeguarding_ack_by INT NULL AFTER safeguarding_reason",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- safeguarding_ack_at
SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'safeguarding_ack_at'
);
SET @sql := IF(
  @has_table = 1 AND @has_col = 0,
  "ALTER TABLE mothers ADD COLUMN safeguarding_ack_at DATETIME NULL AFTER safeguarding_ack_by",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

