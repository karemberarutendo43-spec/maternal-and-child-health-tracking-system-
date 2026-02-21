-- Deliveries outcome/method migration (MoHCC-aligned)
-- Adds Breech/Forceps to delivery_type and adds FSB/MSB outcomes.

SET @has_tbl := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deliveries'
);

SET @has_delivery_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deliveries'
    AND COLUMN_NAME = 'delivery_type'
);

SET @has_outcome := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deliveries'
    AND COLUMN_NAME = 'outcome'
);

-- Expand delivery_type enum
SET @sql := IF(
  @has_tbl = 1 AND @has_delivery_type = 1,
  "ALTER TABLE deliveries MODIFY COLUMN delivery_type ENUM('Vaginal','C-Section','Vacuum','Breech','Forceps','Other') NOT NULL DEFAULT 'Vaginal'",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Expand outcome enum (FSB/MSB as distinct outcomes)
SET @sql := IF(
  @has_tbl = 1 AND @has_outcome = 1,
  "ALTER TABLE deliveries MODIFY COLUMN outcome ENUM('LIVE','FSB','MSB','STILLBIRTH','NEONATAL_DEATH','MATERNAL_DEATH','OTHER') NOT NULL DEFAULT 'LIVE'",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
