-- Delivery Register (Daily Maternity Register replacement)
-- Creates a wide delivery_register table aligned to a typical ward delivery register book.
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS delivery_register (
  id INT AUTO_INCREMENT PRIMARY KEY,

  -- Linkage (optional but recommended)
  mother_id INT NOT NULL,
  baby_id INT NULL,
  clinic_id INT NULL,

  -- Book columns (snapshots + clinical)
  serial_no INT NULL,
  hospital_number VARCHAR(50) NULL,
  anc_no VARCHAR(50) NULL,

  mother_name VARCHAR(120) NOT NULL,
  mother_address VARCHAR(255) NOT NULL,
  mother_phone VARCHAR(30) NOT NULL,

  age_years INT NOT NULL,
  age_band VARCHAR(20) NOT NULL,

  ga_admission_weeks INT NOT NULL,
  booked_status ENUM('B','U') NOT NULL,
  gravida INT NOT NULL,
  parity INT NOT NULL,
  children_alive INT NOT NULL,
  past_obstetric_complications VARCHAR(255) NOT NULL,

  admission_datetime DATETIME NOT NULL,
  presentation_position VARCHAR(80) NOT NULL,
  abnormalities_on_admission VARCHAR(255) NOT NULL,

  onset_of_labour_datetime DATETIME NOT NULL,
  duration_of_stages VARCHAR(120) NOT NULL,
  drugs_given_in_labour VARCHAR(255) NOT NULL,

  delivery_datetime DATETIME NOT NULL,
  delivery_method ENUM('NVD','BREECH','CS','FORCEPS','VACUUM') NOT NULL,
  estimated_blood_loss_ml INT NOT NULL,
  perineum VARCHAR(80) NOT NULL,
  placenta_membranes ENUM('COMPLETE','INCOMPLETE','RAGGED') NOT NULL,

  delivered_by_qualification VARCHAR(120) NOT NULL,
  assisted_by VARCHAR(120) NOT NULL,

  birth_outcome ENUM('LIVE','FSB','MSB') NOT NULL,
  baby_sex ENUM('B','G') NOT NULL,
  birth_weight_grams INT NOT NULL,
  apgar_score VARCHAR(20) NOT NULL,
  tetanus_protected ENUM('YES','NO') NOT NULL,

  hiv_status_on_admission ENUM('1','0_NOT_DUE','0_DUE_RETEST','UNKNOWN') NOT NULL,
  tc_tested ENUM('YES','NO') NOT NULL,
  tc_result ENUM('1','0') NOT NULL,

  maternal_pmtct_arvs ENUM('TDF_3TC_EFV','TDF_3TC_NVP','SDNVP_AZT','OTHER','NONE') NOT NULL,
  maternal_pmtct_other VARCHAR(80) NULL,
  maternal_vitamin_a ENUM('YES','NO') NOT NULL,

  infant_feeding_method ENUM('EBF','ECF') NOT NULL,
  infant_breastfed_first_hour ENUM('YES','NO') NOT NULL,
  infant_teo_given ENUM('YES','NO') NOT NULL,

  comments TEXT NULL,

  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,

  KEY idx_delivery_register_date (delivery_datetime),
  KEY idx_delivery_register_mother (mother_id),
  CONSTRAINT fk_delivery_register_mother FOREIGN KEY (mother_id) REFERENCES mothers(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_register_baby FOREIGN KEY (baby_id) REFERENCES babies(id) ON DELETE SET NULL
);

