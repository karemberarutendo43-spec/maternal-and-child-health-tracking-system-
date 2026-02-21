-- Delivery Records
-- Stores delivery type + C-section details for MoHCC/IMPAC-style reporting.

CREATE TABLE IF NOT EXISTS deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mother_id INT NOT NULL,
  delivery_date DATE NOT NULL,
  -- Delivery method (how baby was delivered)
  delivery_type ENUM('Vaginal','C-Section','Vacuum','Breech','Forceps','Other') NOT NULL DEFAULT 'Vaginal',
  -- Birth outcome (state at birth). Fresh/Macerated stillbirth are outcomes (MoHCC-aligned).
  outcome ENUM('LIVE','FSB','MSB','STILLBIRTH','NEONATAL_DEATH','MATERNAL_DEATH','OTHER') NOT NULL DEFAULT 'LIVE',
  complications VARCHAR(255) NULL,

  -- C-section specific (nullable for non C-section)
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
);
