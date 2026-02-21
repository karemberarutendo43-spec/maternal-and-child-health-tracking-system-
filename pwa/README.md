# MCH System PWA (React)

This folder is a starter React PWA frontend that calls the existing PHP backend through JSON endpoints under `/mch_system/api/*`.

## What’s included
- React (Vite) app in `pwa/src/`
- PWA manifest + service worker via `vite-plugin-pwa`
- Example API calls:
  - `GET /mch_system/api/session.php`
  - `GET /mch_system/api/nurse/fch_queue.php` (nurse-only; clinic-scoped)
  - `GET /mch_system/api/nurse/search.php?q=...`

## Dev setup (local)
1) Make sure Apache/PHP is running and the app loads at:
   - `/mch_system/auth/login.php`
2) Log in as a nurse in the normal web portal (so you have a PHP session cookie).
3) In a terminal:
   - `cd pwa`
   - `npm install`
   - `npm run dev`

The Vite dev server proxies `/mch_system/api/*` to `http://localhost` (see `pwa/vite.config.js`).

## Production build (served by Apache)
1) Build:
   - `cd pwa`
   - `npm install`
   - `npm run build`
2) Copy the output to a web-served folder. Recommended:
   - copy `pwa/dist/*` → `public/pwa/`
3) Open:
   - `/mch_system/public/pwa/`

If you want the PWA to be at `/mch_system/pwa/` (instead of `/mch_system/public/pwa/`), add an Apache alias/rewrite for that URL to point to `public/pwa/`.
