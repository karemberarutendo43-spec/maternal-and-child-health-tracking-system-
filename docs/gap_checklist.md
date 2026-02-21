# MCHTS – Gap Checklist (MoHCC / Zimbabwe-wide readiness)

This checklist compares the requested scope against what is currently implemented in this repo, and what is recommended to add next (low complexity / high value).

## 1) Baby Immunization Tracking (Zimbabwe EPI)

### Already in the system
- Immunization table is used throughout for baby vaccine scheduling + defaulter tracking.
- Immunization schedules are auto-created in multiple flows:
  - Nurse baby registration (`nurse_portal/register_baby.php`)
  - Nurse log delivery (`nurse_portal/log_delivery.php`)
  - Mother history birth logging (`nurse_portal/mother_history.php`)
  - Baby save endpoint (`babies/save_baby.php`)
- Due-soon and defaulter views exist (e.g. Nurse dashboard + `immunizations/defaulters.php`).
- Baby history supports marking vaccines as GIVEN and Undo.

### Gaps to close
- The EPI schedule is not centralized (multiple inconsistent schedules and naming variants).
- Missing/needs alignment with requested schedule:
  - Rotavirus 1 & 2 (currently inconsistent across flows)
  - MR1/MR2 naming (currently “Measles 1/2”)
  - TCV at 9 months (missing)
  - 5-year boosters (DTP/Polio) (missing)

### Recommended next integration (low complexity)
- Centralize the schedule into one config/module and use it everywhere.
- Add vaccine “code” mapping so reporting is stable even if labels change.

## 2) Antenatal Care (ANC) – 8 Contacts + Key Interventions

### Already in the system
- ANC visits exist (`anc_visits`) with visit numbers and next-visit scheduling.
- Basic visit creation endpoints/pages exist.
- ANC defaulters exist (dashboard + defaulter pages).

### Gaps to close (to match MoHCC protocol)
- Structured capture for:
  - Early booking (< 12 weeks)
  - TT doses
  - Iron/Folate
  - HIV testing/PMTCT
  - Vital signs: BP, weight, fetal heart rate
  - Birth plan (intended facility)

### Recommended next integration (low complexity)
- Add structured fields gradually (prefer separate table linked to `anc_visits` to avoid heavy migrations).

## 3) Postnatal Care (PNC)

### Already in the system
- PNC visit logging exists (`pnc_visits`) and a nurse form to log visits.
- Nurse dashboard includes PNC stats.

### Gaps to close
- “Expected PNC schedule” tracking for 48 hours, 2 weeks, 6 weeks.
- Defaulter logic for missed PNC milestones (even if visits are manually logged).
- HIV+ integration: Infant DNA-PCR at 6 weeks (requires mother HIV status + infant test record).

### Recommended next integration (low complexity)
- Add an “Expected PNC Milestones” view computed from baby DOB (or delivery date), and flag upcoming/missed.

## 4) Scaling to Provincial / National Level

### Already in the system
- Defaulter tracking exists for immunizations and ANC.
- Digital Child Health Card modules exist (baby bio-data, birth history, risk factors, milestones).
- A Baby Card ID (`babies.card_uid`) exists (optional migration) for faster lookup.

### Gaps to close
- Unique identifier per baby for “Zimbabwe-wide”: add Birth Confirmation Number as a unique searchable field.
- Movement between clinics/provinces: requires transfer workflow or shared identifier + lookup across clinics.

### Recommended next integration (low complexity)
- Add `babies.birth_confirmation_number` (unique index; allow NULL) + lookup page.

## 5) DHIS2 / T5 Reporting

### Already in the system
- Admin reports UI exists and at least ANC monthly PDF exists.

### Gaps to close
- Immunization monthly aggregates export in a DHIS2/T5-friendly format.

### Recommended next integration (low complexity)
- Add a CSV export for monthly vaccine totals per clinic + vaccine code (stable mapping).

