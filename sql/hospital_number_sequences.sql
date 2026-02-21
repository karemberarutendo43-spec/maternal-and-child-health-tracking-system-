-- Auto-increment hospital numbers per clinic per year.
-- Format example: 1/26 (sequence/year)

CREATE TABLE IF NOT EXISTS hospital_number_sequences (
  clinic_id INT NOT NULL,
  year SMALLINT NOT NULL,
  last_number INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clinic_id, year)
);

-- Optional (recommended): allow non-digit hospital numbers like "1/26".
-- Only run if your column is numeric.
-- ALTER TABLE mothers MODIFY COLUMN hospital_number VARCHAR(20) NOT NULL;

