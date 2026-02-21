CREATE TABLE IF NOT EXISTS baby_weights (
  id INT AUTO_INCREMENT PRIMARY KEY,
  baby_id INT NOT NULL,
  weight_kg DECIMAL(5,2) NOT NULL,
  observed_date DATE NOT NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  INDEX (baby_id),
  CONSTRAINT fk_baby_weights_baby
    FOREIGN KEY (baby_id) REFERENCES babies(id)
    ON DELETE CASCADE
);
