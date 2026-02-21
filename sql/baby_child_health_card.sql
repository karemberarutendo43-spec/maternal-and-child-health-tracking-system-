-- Digitized Zimbabwe Child Health Card modules (Baby Card details)
-- Run these statements on your `mch_system` database.

CREATE TABLE IF NOT EXISTS baby_bio_data (
  id INT AUTO_INCREMENT PRIMARY KEY,
  baby_id INT NOT NULL UNIQUE,
  surname VARCHAR(120) NULL,
  health_centre VARCHAR(255) NULL,
  address VARCHAR(255) NULL,
  father_name VARCHAR(255) NULL,
  mother_name_on_card VARCHAR(255) NULL,
  -- NOTE: If field-level encryption (config/crypto.php) is enabled, encrypted values require more space.
  mother_national_id VARCHAR(255) NULL,
  birth_entry_number VARCHAR(50) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (baby_id),
  CONSTRAINT fk_bio_baby FOREIGN KEY (baby_id) REFERENCES babies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS baby_birth_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  baby_id INT NOT NULL UNIQUE,
  labour_duration_hours DECIMAL(5,1) NULL,
  mode_of_delivery VARCHAR(50) NULL,
  apgar_1min TINYINT NULL,
  apgar_5min TINYINT NULL,
  head_circumference_cm DECIMAL(5,2) NULL,
  birth_length_cm DECIMAL(5,2) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (baby_id),
  CONSTRAINT fk_birth_history_baby FOREIGN KEY (baby_id) REFERENCES babies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS baby_risk_factors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  baby_id INT NOT NULL UNIQUE,
  low_birth_weight TINYINT(1) NOT NULL DEFAULT 0,
  fifth_child_plus TINYINT(1) NOT NULL DEFAULT 0,
  multiple_births TINYINT(1) NOT NULL DEFAULT 0,
  mtct_pmtct TINYINT(1) NOT NULL DEFAULT 0,
  birth_interval_lt_2yrs TINYINT(1) NOT NULL DEFAULT 0,
  single_parent TINYINT(1) NOT NULL DEFAULT 0,
  maternal_death TINYINT(1) NOT NULL DEFAULT 0,
  severe_jaundice TINYINT(1) NOT NULL DEFAULT 0,
  birth_asphyxia TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (baby_id),
  CONSTRAINT fk_risk_baby FOREIGN KEY (baby_id) REFERENCES babies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS baby_milestones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  baby_id INT NOT NULL UNIQUE,
  solids_introduced_date DATE NULL,
  crawling_date DATE NULL,
  talking_date DATE NULL,
  breastfeeding_stopped_date DATE NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX (baby_id),
  CONSTRAINT fk_milestones_baby FOREIGN KEY (baby_id) REFERENCES babies(id) ON DELETE CASCADE
);
