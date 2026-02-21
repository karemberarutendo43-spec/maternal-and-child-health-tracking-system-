# Pilot Demo (Submission Setup)

For your submission, it’s a good practice to **pilot** the system with **2–3 clinics** of test data instead of making it “live” for the whole district immediately.

## Facilities (Pilot Clinics)

This repo already includes 27 facilities in `clinics_seed.sql`. For a small pilot/demo, use only these 3 clinics:

- `MC-BAND` — mc Bandimba Clinic
- `MC-KARA` — mc Karanda Mission Hospital
- `MC-MDDH` — mc Mount Darwin District Hospital

If your database doesn’t have the clinics yet, run:

- `sql/pilot_demo_clinics.sql` (adds only the 3 pilot clinics), or
- `clinics_seed.sql` (adds all 27 clinics)

## Supervisor Explanation (Scalability Statement)

Use a simple statement like:

> “The database and role-based access are built to support the full district (27 clinics), but for this submission I am piloting with 2–3 clinics of test data first. Once validated, scaling is a data step (adding the remaining clinics and user accounts), not a redesign.”

## What to Test in the Pilot

- Clinic-scoped access for Clinical Admins (cannot see other clinics).
- Nurse workflows: ANC, PNC, immunizations, baby registration rules.
- Admin reporting/coverage and defaulters follow-up (clinic-scoped vs district-wide).
- Audit logs show override reasons for exceptions (early ANC/Immun, EDD checks, etc.).

