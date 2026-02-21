# Firebase Hosting (Front Door) + Cloud Run (PHP Backend)

This repo is a PHP + MySQL app written to run under the `/mch_system/` path. Firebase Hosting can provide TLS + CDN for static assets and forward dynamic requests to a Cloud Run service.

## How requests flow

- Browser requests `https://YOUR_DOMAIN/mch_system/...`
- Firebase Hosting:
  - serves static files from `firebase_public/mch_system/**` (CDN)
  - rewrites everything else under `/mch_system/**` to Cloud Run (`firebase.json`)
- Cloud Run (Apache + PHP):
  - receives the same path (`/mch_system/...`)
  - strips the `/mch_system/` prefix via `.htaccess` so it maps to the repo filesystem

## What you need to edit

- `firebase.json`: set Cloud Run `serviceId` + `region`
- Cloud Run: set environment variables (below)

## Build static files for Hosting

Run:

- `npm run build`

This:
- builds `assets/output.css`
- copies static assets into `firebase_public/mch_system/` (CSS + PWA files) via `tools/copy_to_firebase_public.js`

## Cloud Run container

- `Dockerfile` builds an Apache + PHP container
- `deploy/docker-entrypoint.sh` makes Apache listen on `$PORT` (Cloud Run requirement)
- `.htaccess` strips `/mch_system/` to avoid changing all hard-coded links

## Required environment variables

### Database (Cloud SQL MySQL recommended)

Use either a Unix socket (recommended) or host/port.

- `MCH_DB_NAME`
- `MCH_DB_USER`
- `MCH_DB_PASS`

One of:
- `MCH_DB_SOCKET` (example: `/cloudsql/PROJECT:REGION:INSTANCE`)
or:
- `MCH_DB_HOST`
- `MCH_DB_PORT` (optional)

### Sessions (stateless Cloud Run)

Default PHP file sessions work for single-instance demos, but for production use Redis (Memorystore) so nurses don’t get logged out when Cloud Run scales.

- `MCH_SESSION_TTL` (seconds, default 28800)

Redis (either form works):
- `MCH_REDIS_URL` (recommended)
or:
- `MCH_REDIS_HOST`
- `MCH_REDIS_PORT` (default 6379)
- `MCH_REDIS_PASSWORD` (optional)

#### Memorystore (recommended) requirements

Cloud Memorystore for Redis uses a **private IP**, so Cloud Run must be attached to a **Serverless VPC Access connector** in the same region to reach it.

High-level steps:

1. Create a Serverless VPC Access connector (same region as Cloud Run + Memorystore).
2. Create the Memorystore Redis instance (same region, same VPC).
3. Deploy/update Cloud Run with the VPC connector enabled.
4. Set `MCH_REDIS_HOST` + `MCH_REDIS_PORT` from the Memorystore instance IP/port (or build `MCH_REDIS_URL`).

Example `gcloud` (adjust project/region/network/names):

- `gcloud compute networks vpc-access connectors create mch-connector --region=us-central1 --network=default --range=10.8.0.0/28`
- `gcloud redis instances create mch-redis --region=us-central1 --tier=basic --size=1 --redis-version=redis_6_x --network=default`
- `gcloud run services update mch-system-php --region=us-central1 --vpc-connector=mch-connector --vpc-egress=private-ranges-only`

### Encryption key

- `MCH_CRYPTO_KEY`

### Uploads

Cloud Run’s filesystem is ephemeral. This repo now supports configuring the uploads base directory:

- `MCH_UPLOADS_DIR` (defaults to `uploads/` inside the container)
- `MCH_UPLOADS_URL_BASE` (defaults to `/mch_system/uploads`)

In production, prefer a persistent solution (e.g. Cloud Run volume mount) and point `MCH_UPLOADS_DIR` at that mount path.

### Cron (auto reminders)

The existing CLI cron script is exposed as a **token-protected** HTTP endpoint:

- `MCH_CRON_TOKEN`

Endpoint:
- `POST /mch_system/cron/http_send_auto_reminders.php`
  - header `X-Cron-Token: ...`
  - optional query params: `date=YYYY-MM-DD`, `clinic_id=INT`, `max=INT`, `dry=1`

### OAuth (optional)

If you use Google login:
- `MCH_GOOGLE_OAUTH_CLIENT_ID`
- `MCH_GOOGLE_OAUTH_CLIENT_SECRET`
- `MCH_GOOGLE_OAUTH_REDIRECT_URI`

## Deploy order (high level)

1. Create Cloud SQL (MySQL) + migrate tables (run your `sql/*.sql` as needed, including `sql/follow_up_tasks.sql`).
2. Deploy Cloud Run service from this repo (builds the `Dockerfile`).
3. Set Cloud Run env vars above.
4. Run `npm run build` locally.
5. Deploy Firebase Hosting (uses `firebase_public/` + `firebase.json` rewrite).
6. Add Cloud Scheduler job to `POST` the cron HTTP endpoint daily with `X-Cron-Token`.
