CREATE TABLE mother_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mother_id INT NOT NULL,
  from_clinic_id INT NULL,
  to_clinic_id INT NOT NULL,
  requested_by INT NOT NULL,
  approved_by INT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  created_at DATETIME NOT NULL,
  approved_at DATETIME NULL,
  FOREIGN KEY (mother_id) REFERENCES mothers(id) ON DELETE CASCADE,
  FOREIGN KEY (from_clinic_id) REFERENCES clinics(id) ON DELETE SET NULL,
  FOREIGN KEY (to_clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);
