-- Add District/Super vs Clinic Admin support
-- Run this on your MySQL/MariaDB database.

-- 1) Users: admin_level
-- Default SUPER for backward compatibility (you can later set some admins to CLINIC).
ALTER TABLE users
  ADD COLUMN admin_level ENUM('SUPER','CLINIC') NOT NULL DEFAULT 'SUPER' AFTER role;

-- Suggested next steps (manual):
-- - Assign clinic admins:
--     UPDATE users SET admin_level='CLINIC', clinic_id=<clinic_id> WHERE id=<admin_user_id> AND role='admin';
-- - Keep only one super admin:
--     UPDATE users SET admin_level='SUPER' WHERE id=<super_admin_id> AND role='admin';
--
-- Note:
-- This project currently uses `users.admin_level` + `users.clinic_id` for scoping.
-- A per-clinic "admin code" feature is intentionally not included (removed as unused).
