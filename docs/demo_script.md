# MCHTS Demo Script (Supervisor Walkthrough)

This is a short, reliable sequence you can follow during your attachment presentation to demonstrate the system end-to-end.

## 1) Login + Role Redirect

1. Open `http://<server-ip>/mch_system/auth/login.php`
2. Login with each role:
   - **Super Admin** → redirects to Admin dashboard
   - **Clinical Admin** → redirects to Admin dashboard (clinic-scoped)
   - **Nurse** → redirects to Nurse dashboard (clinic-scoped)
   - **Mother** → redirects to Mother portal (or Pending until activated)

What to say:
- “Users do not choose roles; the system detects their role from the database and redirects securely.”

## 2) Super Admin (District View)

Pages to show (left sidebar):
- **Users → Manage / Create**
- **Queues → Activation / Booking / Transfer Requests**
- **Defaulters → ANC / Immunization**
- **Reports → Monthly Reports / Coverage / Audit Logs**
- **Registry → Birth Confirmation Books / Stock & Commodities / EPI Backfill**

Key demo steps:
1. **Create a Nurse**: `Admin → Users → Create User`
2. **Coverage comparison**: `Admin → Reports → Clinic Coverage`
3. **Defaulters oversight**: `Admin → Defaulters → ANC Defaulters` and `Immunization Defaulters`
   - Use **Assign Task** to allocate follow-up work to a nurse (no SMS cost).
4. **Audit**: `Admin → Reports → Audit Logs` (show that important overrides/actions have reasons)

What to say:
- “Super Admin can see all clinics, compare performance, and supervise follow-ups via defaulters and audit logs.”

## 3) Clinical Admin (Clinic-Only View)

Login as a clinic admin and show:
- The same dashboards, but lists are **scoped to the assigned clinic**.

What to say:
- “Clinic admins cannot access or modify other clinics’ records.”

## 4) Nurse (Clinic Workflows)

### A) Register Mother (Booking)
1. Go to `Nurse → Register Mother`
2. Book a pregnancy, set **EDD**, and submit
3. Show the success message and open the mother’s history card

What to say:
- “Booking creates the mother record and the ANC schedule. Undo is available only for the most recent booking, within a short window, and only if no clinical activity exists.”

### B) Log ANC Visit (with early-override reason)
1. `Nurse → Log ANC Visit`
2. Choose mother and ANC #, record visit date
3. Try an early visit to trigger the override rule (requires reason)

What to say:
- “The system prevents recording visits before the expected schedule unless the nurse provides a reason, and the reason is audited.”

### C) Delivery → Register Baby → EPI Schedule
1. `Nurse → Log Delivery` (sets mother status to Postnatal and records delivery date)
2. `Nurse → Register Baby` (auto-creates Zimbabwe EPI schedule)
3. Open `Nurse → Baby History` for that baby

What to say:
- “Baby registration is blocked if the mother is still ‘Pregnant’/EDD is future, unless delivery is logged or an override reason is provided.”

### D) Immunizations + Growth
1. In `Baby History`, mark an immunization as **Given** (or toggle status)
2. Log a weight in the Growth section

What to say:
- “This becomes the baby’s digital card: immunization status plus growth tracking.”

### E) Defaulters + Reminders
1. `Nurse → Immunization Defaulters` and `Nurse → ANC Defaulters`
2. `Nurse → Vaccine Reminders` (optional; if SMS is configured)
3. `Nurse → Defaulters → Follow-up Tasks` (complete tasks after calling/seeing the client)

What to say:
- “Defaulters lists support proactive follow-up.”
- “Tasks provide accountability without paying for SMS automation.”

## 5) Mother Portal (Patient-Facing)

### A) Pending → Active
1. Login as a newly-registered mother (Pending)
2. Show that the mother is redirected to `Mother → Pending` until activated

### B) Active mother view
After activation:
1. `Mother → Dashboard` (summary, daily health tip, ANC overview)
2. `Mother → Babies & Immunizations` (vaccines + recent weights)
3. `Mother → My Handbook` and use **Open printable** / **Print / Save PDF**

What to say:
- “Mothers can see their own care timeline and baby immunizations, plus a printable summary for records.”

## 6) Mobile/Offline Demo (Optional)

1. Open the system on a phone on the same Wi‑Fi using your laptop IP.
2. Turn Wi‑Fi off and show the offline banner.

What to say:
- “The UI is mobile-friendly and shows an offline indicator; key nurse forms can use draft autosave.”
