-- Add optional anthropometry columns for growth monitoring
-- Run once on your database (will error if columns already exist).
ALTER TABLE baby_weights
ADD COLUMN length_cm DECIMAL(5, 2) NULL
AFTER weight_kg,
  ADD COLUMN head_circumference_cm DECIMAL(5, 2) NULL
AFTER length_cm;