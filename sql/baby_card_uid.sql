-- Baby Card UID (QR linking)
-- Run these statements on your `mch_system` database.

-- 1) Add a unique card UID to each baby record
SET @db := DATABASE();
SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'babies'
    AND COLUMN_NAME = 'card_uid'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE babies ADD COLUMN card_uid CHAR(16) NULL AFTER id',
  'SELECT \"babies.card_uid already exists\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'babies'
    AND INDEX_NAME = 'uq_babies_card_uid'
);
SET @sql := IF(
  @idx_exists = 0,
  'CREATE UNIQUE INDEX uq_babies_card_uid ON babies(card_uid)',
  'SELECT \"uq_babies_card_uid already exists\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Backfill existing babies (generates a 16-char hex ID from UUID)
UPDATE babies
SET card_uid = UPPER(SUBSTR(REPLACE(UUID(), '-', ''), 1, 16))
WHERE card_uid IS NULL OR card_uid = '';

-- 3) (Optional) enforce not-null once all rows are filled
-- ALTER TABLE babies MODIFY card_uid CHAR(16) NOT NULL;
