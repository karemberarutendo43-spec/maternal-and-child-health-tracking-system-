-- Additional Zimbabwe MCH risk factors (safe migration)
-- Adds optional fields to `mothers` for better risk stratification.

SET @has_height := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'height_cm'
);
SET @sql := IF(@has_height = 0, "ALTER TABLE mothers ADD COLUMN height_cm DECIMAL(5,2) NULL AFTER dob", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_weight := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'booking_weight_kg'
);
SET @sql := IF(@has_weight = 0, "ALTER TABLE mothers ADD COLUMN booking_weight_kg DECIMAL(5,2) NULL AFTER height_cm", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_muac := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'muac_cm'
);
SET @sql := IF(@has_muac = 0, "ALTER TABLE mothers ADD COLUMN muac_cm DECIMAL(5,2) NULL AFTER booking_weight_kg", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_hiv := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'hiv_status'
);
SET @sql := IF(@has_hiv = 0, "ALTER TABLE mothers ADD COLUMN hiv_status VARCHAR(20) NULL AFTER muac_cm", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_malaria := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'malaria_status'
);
SET @sql := IF(@has_malaria = 0, "ALTER TABLE mothers ADD COLUMN malaria_status VARCHAR(20) NULL AFTER hiv_status", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_syph := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'syphilis_status'
);
SET @sql := IF(@has_syph = 0, "ALTER TABLE mothers ADD COLUMN syphilis_status VARCHAR(20) NULL AFTER malaria_status", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_blood := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'blood_group'
);
SET @sql := IF(@has_blood = 0, "ALTER TABLE mothers ADD COLUMN blood_group VARCHAR(3) NULL AFTER syphilis_status", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_rh := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'rh_factor'
);
SET @sql := IF(@has_rh = 0, "ALTER TABLE mothers ADD COLUMN rh_factor VARCHAR(3) NULL AFTER blood_group", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_dist := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'distance_km'
);
SET @sql := IF(@has_dist = 0, "ALTER TABLE mothers ADD COLUMN distance_km DECIMAL(6,2) NULL AFTER rh_factor", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_barriers := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mothers' AND COLUMN_NAME = 'care_barriers'
);
SET @sql := IF(@has_barriers = 0, "ALTER TABLE mothers ADD COLUMN care_barriers VARCHAR(255) NULL AFTER distance_km", "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

