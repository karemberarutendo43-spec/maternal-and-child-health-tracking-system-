-- Baby Bio-Data Sensitive Field Migration
-- Expands columns that may be encrypted by config/crypto.php.
-- Encryption format ENCv1:<base64(...)> requires more space than plain IDs.

SET @has_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'baby_bio_data'
);

SET @has_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'baby_bio_data'
    AND COLUMN_NAME = 'mother_national_id'
);

SET @sql := IF(
  @has_table = 1 AND @has_col = 1,
  "ALTER TABLE baby_bio_data MODIFY COLUMN mother_national_id VARCHAR(255) NULL",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

