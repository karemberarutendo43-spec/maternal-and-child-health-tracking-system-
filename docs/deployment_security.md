# Deployment & Security Notes

## Building Tailwind for Offline Use
- Run `npm install` (when internet is available) to download `tailwindcss`, `postcss`, and `autoprefixer`.
- Run `npm run build:css` to compile `assets/input.css` into `assets/output.css`. The header checks for this file and falls back to the CDN only when the local bundle is missing.
- For Firebase Hosting deployments, run `npm run build` to also copy the required static files into `firebase_public/mch_system/`.
- After class changes, rerun `npm run build:css` (local) or `npm run build` (Firebase deploy).
- Keep `assets/output.css` under version control if you expect others to pull the repo without installing Node dependencies.
- Periodically refresh compatibility data via `npx update-browserslist-db@latest` (also recommended by PostCSS).

## Administration & Password Handling
- `users.admin_level` now scopes clinic staff (`SUPER` + `DISTRICT` vs `CLINIC`). The clinic code hash column was removed as an unused feature.
- When a nurse forgets their password, the super admin should use the admin-reset flow (as described in documentation) and log the action in `audit_logs` for traceability.
- All passwords continue to use `password_hash()`/`password_verify()`; ensure any new reset feature also stores passwords hashed and marks `must_change_password` if you enforce it later.

## Security Checklist
1. Confirm encryption helpers (`config/crypto.php`) wrap AES-256 operations before storing sensitive fields.
2. Verify `audit_logs` records major actions (user resets, transfers, login failures) so you can answer “who saw what” during audits.
3. Keep an HTTPS tunnel (Ngrok or Let’s Encrypt) ready for demos that involve Google login, transfers, or encrypted submissions.
4. Document the admin reset procedure in your supervisor notes to show you considered identity verification even without email/SMS automations.
