CREATE TABLE IF NOT EXISTS family_planning (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mother_id INT NOT NULL,
  method VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  INDEX (mother_id),
  CONSTRAINT fk_fp_mother
    FOREIGN KEY (mother_id) REFERENCES mothers(id)
    ON DELETE CASCADE
);
