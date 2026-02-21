# Maternal and Child Health Tracking System (MCHTS)
## Mt Darwin District (27 Clinics) - Industrial Attachment Project Documentation

**Project focus:** District-level Maternal and Child Health information management for Mt Darwin District, supporting **27 sub-clinics** with appropriate role-based access (District Admin vs Clinic Admin vs Nurse vs Mother).

---

## 1. Introduction
Maternal and Child Health (MCH) services require accurate tracking of **ANC (Antenatal Care)**, **PNC (Postnatal Care)**, and **EPI immunizations** for babies. In district health operations, records are often fragmented across paper registers and separate clinic files. This reduces visibility for district supervisors, makes defaulter tracing difficult, delays clinical decision-making, and increases the risk of missed appointments and incomplete immunization schedules.

MCHTS is a district-focused health information system designed to digitize and coordinate MCH records across **Mt Darwin District** and its **27 clinics**, while keeping clinic workflows realistic and protecting patient privacy.

---

## 2. Problem Statement
At district level in Mt Darwin, Maternal and Child Health services are affected by operational gaps such as:
- **Paper-based registers and fragmented files** for ANC/PNC and child immunizations, which can lead to missing entries, duplication, and poor continuity of care.
- **Slow retrieval of patient records** during clinic visits (manual searching by name or register number), which increases waiting time and delays service delivery.
- **Weak defaulter monitoring** (ANC overdue visits and overdue immunizations) because it is difficult to calculate and maintain accurate defaulter lists consistently across 27 clinics.
- **Clinic-to-clinic movement (transfers)** that is hard to track, resulting in interrupted schedules and a risk of re-registering the same mother/baby at multiple facilities.
- **Limited district-level visibility** to quickly identify high-risk cases and compare clinic performance using the same indicators.
- **Security and accountability risks** common in manual or basic systems (shared passwords, uncontrolled access, and no reliable audit trail for who changed clinical records).

---

## 3. Objectives of the System
### 3.1 General Objective
To develop a district-level MCH tracking system for Mt Darwin District that supports **ANC/PNC for mothers**, **immunization tracking for babies**, and **multi-clinic management** across 27 clinics.

### 3.2 Specific Objectives
- Digitize **mother registration** and maintain ANC/PNC visit records with scheduled follow-up dates.
- Digitize **baby registration** linked to the mother and automatically create the baby's **Zimbabwe EPI immunization schedule** (due dates and completion status).
- Provide fast patient lookup using practical identifiers used in clinics: **Hospital #**, **Card ID**, and **Birth Confirmation #** (where enabled), to reduce retrieval time during visits.
- Produce operational lists that support clinical work:
  - ANC due today and ANC defaulters
  - Immunizations due today and immunization defaulters
- Support **clinic transfer requests** initiated by nurses and approved/rejected by admins, with clear notifications so transfers are not missed.
- Enforce **role-based and clinic-based access control** so nurses/clinic admins work only within their clinic, while district admins can view all 27 clinics.
- Improve accountability and security through **audit logging**, safe password reset workflow (temporary password + forced change), and **CSRF protection** for sensitive actions.
- Improve usability using dashboards and notification badges, including lightweight auto-refresh of key counters to reduce manual page refreshing.

---

## 4. Scope of the System
### 4.1 In Scope
- Mt Darwin District and its **27 clinics** (multi-clinic configuration).
- Users and roles:
  - **District/Super Admin:** view all clinics, manage users, approve transfers, see district reporting.
  - **Clinic Admin:** manage only their clinic users and view only clinic data.
  - **Nurse:** register mothers/babies, record ANC/PNC, manage clinic operations.
  - **Mother:** view own records (where web access is enabled).
- Modules:
  - Authentication & user management
  - Mother registration and ANC/PNC tracking
  - Baby registration and immunization tracking (EPI)
  - Transfers workflow
  - Dashboards and notifications
  - Security controls (password hashing, forced password change, CSRF tokens, audit logs, optional field encryption)

### 4.2 Out of Scope (Current Version)
- Live integration with national systems (e.g., DHIS2 servers) - only export/readiness approach.
- Full biometric/OCR scanning of physical cards (not practical without identifiers).
- Full province/national deployment (future enhancement; system designed to scale).

---

## 5. System Description
MCHTS is a PHP + MySQL district health system with separate portals:
- **Admin Portal:** district oversight and clinic administration.
- **Nurse Portal:** clinic operational workflow for MCH services.
- **Mother Portal:** patient-facing view of ANC schedule, baby immunizations, and notifications.

Key data relationships:
- A **mother** can have **multiple ANC visits** and **PNC visits**.
- A **mother** can have **one or more babies**.
- A **baby** has **multiple immunization schedule records** with due dates and completion status.
- Each record is linked back to its **clinic** through the authenticated user and clinic assignment.

---

## 6. System Architecture
### 6.1 High-Level Architecture
- **Presentation Layer:** PHP pages with Tailwind CSS UI components.
- **Application Layer:** PHP business logic for scheduling, transfers, notifications, security checks.
- **Data Layer:** MySQL database storing users, clinics, mothers, babies, visits, immunizations, transfers, and logs.

### 6.2 Access Control Model
- Role-based access enforced by session (`admin`, `nurse`, `mother`).
- Clinic scoping:
  - Nurses and clinic admins operate only within their assigned clinic.
  - District/super admins can access all clinics.

### 6.3 Notifications (Lightweight Auto-Refresh)
- A bell indicator shows counts for pending actions (e.g., transfer requests).
- Dashboard counters update periodically without full page reload to reduce manual refreshing.

---

## 7. Functional Requirements
### 7.1 Authentication & Accounts
- Users must login using correct role and credentials.
- Staff accounts (admin/nurse) require controlled password reset by admin.
- Mothers can use self-service reset where configured.

### 7.2 Clinic & User Management (Admin)
- Create/manage users and assign clinic(s) where applicable.
- Disable/activate users.
- Reset passwords and force user to change password at next login.

### 7.3 Mother Management (Nurse)
- Register mothers and assign hospital number.
- Record ANC visits and schedule follow-ups.
- Record PNC visits.
- View mother history (restricted to clinic scope).

### 7.4 Baby & Immunization Management (Nurse)
- Register baby linked to mother and record birth details.
- Auto-generate immunization schedule based on Zimbabwe EPI due dates.
- View baby health record and immunization history (restricted to clinic scope).

### 7.5 Transfers Workflow
- Nurse submits transfer request (from clinic A to clinic B).
- Admin approves or rejects.
- System notifies nurse on rejection and mother on approval.

### 7.6 Reporting and Defaulter Support
- List of ANC defaulters (overdue visits).
- List of immunization defaulters (overdue vaccines).
- District overview dashboards (district admins).

---

## 8. Non-Functional Requirements
- **Security:** role-based access, clinic scoping, password hashing, CSRF protection, optional field encryption, audit trail.
- **Performance:** avoid heavy "list all" pages for nurses; use limits/pagination; lightweight dashboard auto-refresh.
- **Usability:** clear dashboards, quick search (hospital/card/BCN), and consistent navigation.
- **Availability (prototype):** runs on local XAMPP/Apache + MySQL.
- **Maintainability:** modular config helpers for scoping, crypto, audit, and tokens.

---

## 9. Users of the System
- **District/Super Admin:** overall district oversight, access to all clinics.
- **Clinic Admin:** manages one clinic operations and users.
- **Nurse:** clinical staff capturing visits and immunizations within a clinic.
- **Mother:** views own health schedule, baby immunizations, and transfer approvals.

---

## 10. Security and Data Protection
MCHTS applies layered security controls suitable for a district prototype:
- **Password hashing:** passwords stored using `password_hash()` (not plain text).
- **Admin reset workflow:** admin issues a temporary password and user must change it on next login.
- **CSRF protection:** sensitive POST actions include CSRF tokens to prevent forged requests.
- **Audit logging:** key actions can be logged for accountability.
- **Field-level encryption (optional):** selected sensitive fields (e.g., notes/address) can be encrypted at rest using AES-GCM when a crypto key is configured.
- **Clinic scoping:** prevents cross-clinic access to patient records (privacy).

> Note for supervisors: This attachment prototype demonstrates security awareness and controls. For production, security would be strengthened further (HTTPS, key management, backups, hardening, and compliance processes).

---

## 11. Limitations
- Runs in a local environment (XAMPP); full HTTPS and national integration are future-phase items.
- Some notifications and "advanced integrations" depend on database migration scripts being applied.
- Without dedicated email/SMS infrastructure, some messaging is simulated or "best effort".
- Paper card OCR/scanning is not implemented; system uses digital identifiers (Hospital # / Card ID / BCN).

---

## 12. Future Enhancements
- DHIS2-ready exports and automated monthly reporting per clinic.
- Stronger search (phonetic/name search) while keeping privacy controls.
- Offline-first sync for remote clinics (local cache + periodic sync).
- Stock/inventory module (vaccine cold-chain and drug supplies).
- Google login (optional) for mothers with proper OAuth setup and HTTPS domain.
- Advanced analytics dashboards comparing all 27 clinics.

---

## 13. Conclusion
MCHTS provides a practical district-level solution for Mt Darwin District by digitizing ANC/PNC and immunization workflows across **27 clinics**, improving visibility, reducing delays, supporting transfers, and applying key security principles. The system meets attachment requirements by solving a real operational problem at district level, while remaining scalable for future provincial/national rollout.

---

### Appendix A: Final Testing Evidence
Use: `docs/final_readiness_checklist.md`
