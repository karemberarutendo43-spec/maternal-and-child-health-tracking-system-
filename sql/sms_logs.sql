CREATE TABLE IF NOT EXISTS sms_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  baby_id INT NOT NULL,
  vaccine VARCHAR(100) NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(10) NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (baby_id),
  CONSTRAINT fk_sms_logs_baby
    FOREIGN KEY (baby_id) REFERENCES babies(id)
    ON DELETE CASCADE
);
