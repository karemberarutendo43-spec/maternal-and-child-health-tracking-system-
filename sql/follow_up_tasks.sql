-- Follow-up Tasks
-- Internal (no-cost) assignment workflow for defaulters and clinical follow-ups.

CREATE TABLE IF NOT EXISTS follow_up_tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT NOT NULL,
  task_type VARCHAR(30) NOT NULL, -- e.g. 'ANC_FOLLOWUP', 'IMMUN_FOLLOWUP'
  subject_type VARCHAR(20) NOT NULL, -- 'mother' | 'baby'
  subject_id INT NOT NULL,
  due_date DATE NULL,
  priority ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
  status ENUM('OPEN','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
  assigned_to INT NULL, -- users.id (nurse)
  created_by INT NULL,  -- users.id (admin/nurse)
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  KEY idx_tasks_clinic_status (clinic_id, status),
  KEY idx_tasks_assigned_status (assigned_to, status),
  KEY idx_tasks_subject (subject_type, subject_id),
  KEY idx_tasks_created (created_at)
);

