-- Mother profile fields needed for birth record verification.
-- Safe migration (checks existence before adding).

SET @has_national := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'national_id_number'
);
SET @sql := IF(@has_national = 0,
  "ALTER TABLE mothers ADD COLUMN national_id_number VARCHAR(30) NULL AFTER phone",
  "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_marital := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'marital_status'
);
SET @sql := IF(@has_marital = 0,
  "ALTER TABLE mothers ADD COLUMN marital_status VARCHAR(30) NULL AFTER national_id_number",
  "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_pob := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'mothers'
    AND COLUMN_NAME = 'place_of_birth'
);
SET @sql := IF(@has_pob = 0,
  "ALTER TABLE mothers ADD COLUMN place_of_birth VARCHAR(120) NULL AFTER marital_status",
  "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

