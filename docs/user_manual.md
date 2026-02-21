# MCHTS User Manual (Quick Guide)
## Mt Darwin District - 27 Clinics

This manual explains how to use the system as **Admin**, **Nurse**, and **Mother**.

---

## 0) Pilot Demo (For Submission)
If you are demonstrating for a submission, it’s recommended to **pilot with 2–3 clinics** of test data first (instead of going “live” across all 27 clinics immediately).

Suggested pilot facilities:
- `MC-BAND` — mc Bandimba Clinic
- `MC-KARA` — mc Karanda Mission Hospital
- `MC-MDDH` — mc Mount Darwin District Hospital

Optional seed file (adds only those 3 clinics): `sql/pilot_demo_clinics.sql`
More notes: `docs/pilot_demo.md`

---

## 1) How to Login
1. Open the login page: `auth/login.php`
2. Enter credentials:
   - **Nurse:** Username + Staff ID + Password
   - **Admin:** Username + Password
   - **Mother:** Phone number (or Username if enabled) + Password
3. Click **Login**.

Notes:
- If an admin reset your password, login using the **temporary password**, then you will be forced to change it.

---

## 2) Admin Portal Guide
### 2.1 Dashboard (District vs Clinic)
Page: `admin_portal/dashboard.php`
- **District/Super Admin** sees district-wide totals (all clinics).
- **Clinic Admin** sees only their clinic totals.
- Use the notification bell to quickly see pending actions (e.g., transfers pending).

### 2.2 Activation Queue (Approve / Activate)
Page: `admin_portal/activation_queue.php`
- Approve self-registered mothers
- Activate disabled nurse/mother accounts
- Clinic Admin actions apply only to their clinic

### 2.3 Transfers (Approve / Reject)
Page: `admin_portal/transfers.php`
- View pending transfer requests submitted by nurses
- Approve or reject transfer requests
- Approval updates the mother’s clinic assignment and triggers notifications

### 2.4 Users Management (Reset Password / Disable / Assign Clinics)
Page: `admin_portal/users.php`
- Assign clinic to nurse accounts
- Disable/activate users
- Reset password and provide the temporary password to the staff member

### 2.5 Branding & Background Images
Use the portal’s built-in branding area first, then fall back to custom CSS when you need tighter control:
1. **Portal admin settings (preferred)** – open `Configuration > Site Settings > Branding & Design` (or the equivalent Branding/Theme menu). Under the Admin/Nurse portal section upload your JPG or PNG into the Background Image field and save the configuration. For the Mother/Patient portal look under “Self-Service Portal Settings” or “Portal Login” to upload a dedicated background.
2. Confirm the upload by picking the image file and hitting **Save/Publish**, then refresh the portal to verify the new wallpaper renders for the intended role before cruising to the next change.
3. **Custom CSS (when avoiding gradients)** – if you still see gradients dimming the photo, apply the image via a simple `background-image: url(...);` rule, avoid wrapping it in `linear-gradient()`, and add a fallback `background-color` (e.g. `background-color: #0f3a50;`) so a solid color shows if the upload fails.
4. Always verify the portal accepts the file size/format (typically JPG/PNG and under 2–5 MB) so the upload doesn’t silently fail.

---

### 2.6 Profile Photo Uploads
- You can upload a profile photo for Admin, Nurse, and Mother accounts in each portal’s Profile or Settings page (the uploader is usually right next to the name/email fields). The control accepts JPG or PNG files between 0 and 5 MB, so trim large exports or re-save through an editor if it rejects the upload.
- When you save a new photo, refresh to ensure the role-specific portal picked up the new image, and re-upload any time the portal switches to dark mode so the avatar stays visible.

### Clinical Guidelines (Reference)
Pages:
- `admin_portal/guidelines.php`
- `nurse_portal/guidelines.php`

## 3) Nurse Portal Guide
### 3.1 Nurse Dashboard
Page: `nurse_portal/dashboard.php`
- Shows clinic operational stats and quick actions
- Key counters auto-refresh (reduces manual page refreshing)

### 3.2 Register Mother
Page: `nurse_portal/register_mother.php`
- Enter mother details and register
- System assigns and validates identifiers (e.g., hospital number rules as configured)
- Mother becomes linked to the nurse’s clinic scope

### 3.3 Register Baby + Auto Immunization Schedule
Page: `nurse_portal/register_baby.php`
- Select mother (limited to your clinic)
- Enter baby details (DOB, gender, birth weight)
- Immunization schedule is auto-created

### Clinical Guidelines (Reference)
Page: `nurse_portal/guidelines.php`
- Quick reference for routine workflows and system rules (defaulters follow-up, override reasons, documentation).

### 3.4 Find Baby (Fast Retrieval)
Page: `nurse_portal/find_baby.php`
Search by:
- **Hospital #**
- **Card ID**
- **Birth Confirmation #** (if enabled)

### 3.5 Baby Health Record
Page: `nurse_portal/baby_history.php`
- View immunization schedule (due/overdue)
- Record updates (where enabled)
- Access is restricted to the nurse’s clinic data

### 3.6 Mother History (ANC/PNC)
Page: `nurse_portal/mother_history.php`
- View mother profile and visit history
- Log delivery/birth where required
- Access is restricted to the nurse’s clinic data

### 3.7 Transfer Mother (Request)
Page: `nurse_portal/transfer_mother.php`
- Select destination clinic and submit reason
- Request remains **PENDING** until admin approves

---

## 4) Mother Portal Guide
### 4.1 Dashboard
Page: `mother_portal/dashboard.php`
- View next ANC visit date (if scheduled)
- View baby immunization status (given vs upcoming)
- See transfer approval message when admin approves

### 4.2 Forgot / Reset Password (Web Mothers)
Pages:
- `auth/forgot_password.php`
- `auth/reset_password.php`

Notes:
- Clinic-managed mothers may not use web reset (depends on how the account was created).

---

## 5) Screenshots Checklist (For Your Report)
Take screenshots to prove each requirement works. Recommended minimum set:

### Admin Evidence
- [ ] Admin dashboard (district totals + bell badge visible)
- [ ] Activation queue page with a pending mother
- [ ] Approve mother action result (success message)
- [ ] Transfers page with pending request
- [ ] Approve transfer action result
- [ ] Users page: reset nurse password and show the temporary password message

### Nurse Evidence
- [ ] Nurse dashboard (clinic-scoped stats)
- [ ] Register mother form + success result
- [ ] Register baby form + success result showing schedule created
- [ ] Find baby results (showing only clinic scope)
- [ ] Baby history page opened successfully
- [ ] Transfer request submitted (pending admin approval)

### Mother Evidence
- [ ] Mother dashboard showing next visit section
- [ ] Mother dashboard showing vaccine counts
- [ ] Transfer approved message on mother dashboard

---

## 6) Troubleshooting
- If you see “not assigned to a clinic” (nurse/clinic admin), Admin must set `clinic_id` for that user in `admin_portal/users.php`.
- If password reset pages say “not configured”, run the matching SQL migrations in `sql/`.
- If a POST action returns **403 Invalid request token**, refresh the page and try again (CSRF protection).



OK LETS DO THIS AND SEE WAT WE WILL HAVE

i want this picture fall in my nurse portal bacground make sure its aligning and not hidden in the sidebar also make sure in is there in lightand dark mode
