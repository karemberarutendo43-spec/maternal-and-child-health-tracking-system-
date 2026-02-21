-- Deliveries C-section migration (safe for existing installations)
-- Creates the table if missing and adds new columns/indexes if needed.

-- Create table if missing
SET @has_tbl := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deliveries'
);
SET @sql := IF(@has_tbl = 0,
  "CREATE TABLE deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mother_id INT NOT NULL,
    delivery_date DATE NOT NULL,
    delivery_type ENUM('Vaginal','C-Section','Vacuum','Breech','Forceps','Other') NOT NULL DEFAULT 'Vaginal',
    outcome ENUM('LIVE','FSB','MSB','STILLBIRTH','NEONATAL_DEATH','MATERNAL_DEATH','OTHER') NOT NULL DEFAULT 'LIVE',
    complications VARCHAR(255) NULL,
    cs_type ENUM('Elective','Emergency') NULL,
    cs_indication VARCHAR(100) NULL,
    decision_to_incision_minutes INT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_delivery_mother_date (mother_id, delivery_date),
    KEY idx_delivery_type_date (delivery_type, delivery_date),
    KEY idx_delivery_mother (mother_id),
    CONSTRAINT fk_deliveries_mother FOREIGN KEY (mother_id) REFERENCES mothers(id) ON DELETE CASCADE
  )",
  "SELECT 1"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Add C-section columns if missing
SET @has_cs_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'cs_type'
);
SET @sql := IF(@has_cs_type = 0, "ALTER TABLE deliveries ADD COLUMN cs_type ENUM('Elective','Emergency') NULL AFTER complications", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_ind := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'cs_indication'
);
SET @sql := IF(@has_ind = 0, "ALTER TABLE deliveries ADD COLUMN cs_indication VARCHAR(100) NULL AFTER cs_type", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_d2i := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'decision_to_incision_minutes'
);
SET @sql := IF(@has_d2i = 0, "ALTER TABLE deliveries ADD COLUMN decision_to_incision_minutes INT NULL AFTER cs_indication", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Ensure unique key on (mother_id, delivery_date)
SET @has_uq := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deliveries'
    AND INDEX_NAME = 'uq_delivery_mother_date'
);
SET @sql := IF(@has_uq = 0, "ALTER TABLE deliveries ADD UNIQUE KEY uq_delivery_mother_date (mother_id, delivery_date)", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
