-- Birth Confirmation Books (per-clinic serial allocation)
-- This supports real-world workflows where each clinic issues BCN numbers from a pre-printed book.
--
-- How it works:
-- - Admin configures an "active" book for each clinic (prefix + numeric range)
-- - Nurse can auto-assign BCN, which consumes the next number atomically
--
-- Run this on your MySQL/MariaDB database.

CREATE TABLE IF NOT EXISTS birth_confirmation_books (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  book_name VARCHAR(100) NULL,
  prefix VARCHAR(30) NOT NULL DEFAULT '',
  start_no INT NOT NULL,
  end_no INT NOT NULL,
  next_no INT NOT NULL,
  pad_len INT NOT NULL DEFAULT 4,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY idx_bcn_books_clinic (clinic_id),
  KEY idx_bcn_books_active (clinic_id, active)
);

-- Optional (recommended): ensure BCN column exists + uniqueness when present.
-- If you already ran sql/birth_confirmation_number.sql you can ignore this section.
-- ALTER TABLE babies ADD COLUMN birth_confirmation_number VARCHAR(50) NULL AFTER card_uid;
-- CREATE UNIQUE INDEX uq_babies_birth_confirmation_number ON babies(birth_confirmation_number);

