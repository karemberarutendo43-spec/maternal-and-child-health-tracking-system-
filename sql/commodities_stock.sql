-- Supply Chain / Stock Tracking (per clinic)
--
-- Purpose:
-- - Track essential MCH commodities at clinic level (receive, issue, adjust).
-- - Support low-stock alerts and basic decision support for procurement.
--
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS commodities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'unit',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_commodities_active (active)
);

CREATE TABLE IF NOT EXISTS commodity_stock_levels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  commodity_id INT NOT NULL,
  on_hand DECIMAL(10,2) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(10,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_levels_clinic_commodity (clinic_id, commodity_id),
  KEY idx_levels_clinic (clinic_id),
  KEY idx_levels_low (clinic_id, reorder_level, on_hand),
  CONSTRAINT fk_levels_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_levels_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS commodity_stock_tx (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  commodity_id INT NOT NULL,
  tx_type ENUM('RECEIVE','ISSUE','ADJUST') NOT NULL,
  qty DECIMAL(10,2) NOT NULL,
  tx_date DATE NOT NULL,
  reference VARCHAR(60) NULL,
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tx_clinic_date (clinic_id, tx_date),
  KEY idx_tx_commodity (commodity_id),
  CONSTRAINT fk_tx_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_tx_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE CASCADE
);

-- Optional seed list (editable) - keep small and practical for pilot.
INSERT INTO commodities (code, name, unit) VALUES
('OX', 'Oxytocin', 'vials'),
('FA', 'Folic Acid', 'tabs'),
('ORS', 'Oral Rehydration Salts', 'sachets'),
('IFA', 'Iron/Folic Acid', 'tabs'),
('AMOX', 'Amoxicillin', 'caps'),
('ZINC', 'Zinc Sulfate', 'tabs'),
('PARA', 'Paracetamol', 'tabs'),
('VITA', 'Vitamin A', 'caps'),
('TT', 'Tetanus Toxoid', 'doses'),
('BCG', 'BCG Vaccine', 'doses'),
('OPV', 'OPV Vaccine', 'doses'),
('PENTA', 'Pentavalent Vaccine', 'doses'),
('MR', 'Measles/Rubella Vaccine', 'doses')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  unit = VALUES(unit);

