# MCHTS Final Readiness Checklist (Mt Darwin District – 27 Clinics)

Use this as your “evidence” before submission: tick items, add screenshots, and record the test account used.

## 1) Accounts & Setup
- [ ] Database imports applied (run your `sql/*.sql` migrations that are required by your features).
- [ ] Clinics loaded (27 clinics exist in `clinics` table).
- [ ] At least 1 **District/Super Admin** account exists.
- [ ] At least 1 **Clinic Admin** account exists and has `clinic_id` set.
- [ ] At least 1 **Nurse** account exists and has `clinic_id` + `staff_id` set.
- [ ] At least 1 **Mother** (web) account exists and can login.
- [ ] At least 1 **Clinic-managed Mother** account exists (created by nurse) and appears under the correct clinic.

## 2) Role Scope (Privacy – 27 Clinic Safety)
- [ ] Nurse can only view/search mothers/babies from **their clinic**.
- [ ] Clinic Admin can only view/manage records from **their clinic**.
- [ ] District/Super Admin can view **all clinics**.
- [ ] No page shows “all babies” or “all mothers” to a nurse across the district.

Evidence screenshots:
- Nurse “Find Baby” showing only clinic results.
- Nurse dashboard counts + recent lists showing only clinic data.

## 3) Nurse Portal (Core Workflows)
- [ ] Register Mother (hospital number generated/validated).
- [ ] Register Baby (linked to mother; DOB; gender; immunization schedule created).
- [ ] Log ANC visit + view ANC schedule.
- [ ] Log PNC visit.
- [ ] Find Baby by: Card ID / Hospital # / Birth Confirmation # (if enabled).
- [ ] Baby history page loads and blocks cross-clinic access.
- [ ] Mother history page loads and blocks cross-clinic access.

## 4) Transfers (Clinic-to-Clinic)
- [ ] Nurse can request mother transfer.
- [ ] Admin can approve/reject.
- [ ] Admin sees pending transfers quickly (bell badge).
- [ ] Nurse sees rejected transfer notification (bell badge / transfer requests page).
- [ ] Mother sees approved transfer notification on dashboard.

## 5) Admin Portal (District Oversight)
- [ ] Dashboard stats render (district or clinic-scoped depending on admin level).
- [ ] Activation queue works (approve/activate).
- [ ] Users management works (create/disable, and password reset if enabled).
- [ ] Birth confirmation books (if used) can be created/activated per clinic.
- [ ] Audit logs record key actions (if enabled).

## 6) Security (What to Explain to Supervisor)
- [ ] Passwords stored using `password_hash()` (not plain text).
- [ ] Admin reset password uses temporary password + forces change on next login.
- [ ] Sensitive notes/address fields can be field-encrypted (if key configured).
- [ ] Role-based access checks exist on admin/nurse/mother portals.

Recommended evidence:
- Screenshot: Admin resets nurse password → nurse login → forced change-password screen.
- Screenshot: Audit log entry for reset/change password.

## 7) Performance & UX
- [ ] “Find” pages have search + limits (no huge tables by default).
- [ ] Dashboards refresh key numbers (bell + stats) without full reload.
- [ ] Pages load within a few seconds on local XAMPP.

## 8) Submission Pack
- [ ] User manual (how to login + workflows).
- [ ] Technical documentation (schema + architecture + security).
- [ ] Screenshots of every major module.
- [ ] Sample data (2–3 clinics worth) to demonstrate district reporting.

