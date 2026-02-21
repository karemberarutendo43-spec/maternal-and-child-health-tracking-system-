-- Pilot demo clinics (2–3 facilities)
-- Use this for a submission/demo where you are NOT going live across all 27 clinics yet.
--
-- Safe to run multiple times.
-- Assumes `clinics` table exists (see `clinics_seed.sql`).

INSERT INTO clinics (clinic_code, clinic_name, auth_code) VALUES
('MC-BAND', 'mc Bandimba Clinic', 'MCBAND-7624'),
('MC-KARA', 'mc Karanda Mission Hospital', 'MCKARA-6482'),
('MC-MDDH', 'mc Mount Darwin District Hospital', 'MCMDDH-5310')
ON DUPLICATE KEY UPDATE
  clinic_name = VALUES(clinic_name),
  auth_code = VALUES(auth_code);

