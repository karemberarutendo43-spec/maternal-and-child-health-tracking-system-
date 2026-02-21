-- Baby Birth Confirmation Number
-- Adds a unique, Zimbabwe-wide identifier (allowing NULL for unknown).
SET @db := DATABASE();
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'babies'
      AND COLUMN_NAME = 'birth_confirmation_number'
  );
SET @sql := IF(
    @col_exists = 0,
    'ALTER TABLE babies ADD COLUMN birth_confirmation_number VARCHAR(50) NULL AFTER card_uid',
    'SELECT \"babies.birth_confirmation_number already exists\"'
  );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @idx_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'babies'
      AND INDEX_NAME = 'uq_babies_birth_confirmation_number'
  );
SET @sql := IF(
    @idx_exists = 0,
    'CREATE UNIQUE INDEX uq_babies_birth_confirmation_number ON babies(birth_confirmation_number)',
    'SELECT \"uq_babies_birth_confirmation_number already exists\"'
  );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;