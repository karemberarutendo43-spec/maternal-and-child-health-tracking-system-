# MCHTS Supervisor Summary (Industrial Attachment)
## Mt Darwin District - 27 Clinics

### Project Title
**Maternal and Child Health Tracking System (MCHTS)** for **Mt Darwin District** and its **27 sub-clinics**.

### What Problem It Solves
Clinics and district teams face delays and inaccuracies due to paper-based registers and fragmented records. Common operational issues include:
- Difficulty identifying **ANC/PNC** and **immunization** defaulters in time
- Slow retrieval of patient records during visits
- Weak visibility for district oversight and comparison between clinics
- Poor transfer tracking when mothers move between clinics
- Security weaknesses in typical prototype systems (shared passwords, no audit trail)

### System Goal
Provide a district-ready system that digitizes **ANC, PNC, and baby immunizations (EPI)** across 27 clinics, while protecting privacy through role and clinic-based access control.

### Key Modules Delivered
- **Authentication & Roles**
  - District/Super Admin, Clinic Admin, Nurse, Mother portals
  - Role-based access checks + clinic scoping
- **Mothers (ANC/PNC)**
  - Mother registration and history
  - ANC visit schedule tracking and logging
  - PNC visit logging
- **Babies (EPI Immunizations)**
  - Baby registration linked to mother
  - Auto-generated immunization schedule (Zimbabwe EPI logic)
  - Baby health record viewing
- **Transfers**
  - Nurse requests transfer (clinic-to-clinic)
  - Admin approves/rejects (workflow + notifications)
- **Dashboards & Notifications**
  - Admin and nurse dashboards show operational stats
  - Notification bell badges to reduce delays (auto-refresh counters)

### District vs Clinic Access (27 Clinic Safety)
- **District/Super Admin:** views and manages data across all clinics.
- **Clinic Admin:** limited to their clinic's data and users.
- **Nurse:** limited to their clinic’s patient records and operations.
- **Mother:** limited to own records (where web access is enabled).

### Security Controls (What to Highlight in Marking)
- Passwords stored securely using `password_hash()`
- Admin reset-password produces a **temporary password** and forces change at next login
- **CSRF tokens** on sensitive POST actions (prevents forged approvals/resets)
- **Audit logging** support for accountability (if enabled/migrated)
- Optional **field-level encryption** for selected sensitive fields (notes/address)

### Assumptions / Prototype Notes
- Runs on local XAMPP + MySQL for demonstration.
- Some features depend on SQL migrations being applied (provided in `sql/`).
- National integration (DHIS2 server connectivity) is not deployed; system is designed to be export-ready as a future phase.

### Demonstration Plan (Recommended)
1. Login as **Nurse** (Clinic A): register mother → register baby → show auto schedule.
2. Show **Find Baby** by ID (Hospital # / Card ID / BCN if enabled).
3. Create a **transfer request** from Clinic A → Clinic B.
4. Login as **Admin**: approve transfer → show bell badge and status.
5. Login as **Mother**: show “transfer approved” message on dashboard.
6. Show security: admin resets nurse password → nurse forced to change password.

### Evidence Checklist
Use: `docs/final_readiness_checklist.md`
