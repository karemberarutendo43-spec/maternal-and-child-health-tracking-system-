-- ANC SMS Logs
-- Tracks ANC reminder messages sent to mothers.

CREATE TABLE IF NOT EXISTS anc_sms_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mother_id INT NOT NULL,
  next_visit_date DATE NULL,
  status VARCHAR(10) NOT NULL, -- SENT | FAILED
  message TEXT NOT NULL,
  created_by_user_id INT NULL,
  seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_anc_sms_mother (mother_id),
  INDEX idx_anc_sms_seen (seen_at),
  INDEX idx_anc_sms_created (created_at),
  CONSTRAINT fk_anc_sms_mother
    FOREIGN KEY (mother_id) REFERENCES mothers(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_anc_sms_user
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
    ON DELETE SET NULL
);
