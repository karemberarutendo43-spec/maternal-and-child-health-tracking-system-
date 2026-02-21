-- Maternal Mental Health (EPDS) - score capture
--
-- IMPORTANT:
-- EPDS question text is not included here. This table stores only the numeric scores (0–3) per question.
-- Use your facility's official EPDS paper/tool for wording and interpretation.

CREATE TABLE IF NOT EXISTS epds_screenings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mother_id INT NOT NULL,
  screening_date DATE NOT NULL,
  q1 TINYINT NOT NULL,
  q2 TINYINT NOT NULL,
  q3 TINYINT NOT NULL,
  q4 TINYINT NOT NULL,
  q5 TINYINT NOT NULL,
  q6 TINYINT NOT NULL,
  q7 TINYINT NOT NULL,
  q8 TINYINT NOT NULL,
  q9 TINYINT NOT NULL,
  q10 TINYINT NOT NULL,
  total_score TINYINT NOT NULL,
  risk_flag VARCHAR(20) NOT NULL DEFAULT 'LOW',
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_epds_mother_date (mother_id, screening_date),
  KEY idx_epds_created (created_at),
  CONSTRAINT fk_epds_mother FOREIGN KEY (mother_id) REFERENCES mothers(id) ON DELETE CASCADE
);

