CREATE TABLE IF NOT EXISTS pnc_visits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mother_id INT NOT NULL,
  baby_id INT NULL,
  visit_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'COMPLETED',
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX (mother_id),
  INDEX (baby_id),
  CONSTRAINT fk_pnc_mother
    FOREIGN KEY (mother_id) REFERENCES mothers(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_pnc_baby
    FOREIGN KEY (baby_id) REFERENCES babies(id)
    ON DELETE SET NULL
);
