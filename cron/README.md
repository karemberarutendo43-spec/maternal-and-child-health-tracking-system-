# Automated Reminders (ANC + Immunizations)

This folder contains a cron-style script that can automatically send reminder SMS messages to mothers:

- **ANC** reminders based on `anc_visits.next_visit_date`
- **Immunization** reminders based on `immunizations.due_date`

The SMS sending function is currently a placeholder (`config/sms.php`) and writes to the PHP error log.

## Prerequisites (Database tables)

Run these once on your database to enable reminder tracking and rate limiting:

- `sql/anc_sms_logs.sql`
- `sql/sms_logs.sql`

## Run (Dry run)

Prints what would be sent:

`php cron/send_auto_reminders.php`

## Run (Send)

Actually sends (via `sendSms()`):

`php cron/send_auto_reminders.php --send`

## Useful options

- Run as if today is a specific date:
  - `php cron/send_auto_reminders.php --date=2026-02-13`
- Restrict to one clinic:
  - `php cron/send_auto_reminders.php --send --clinic-id=14`
- Increase/decrease limits:
  - `php cron/send_auto_reminders.php --send --max=500`
- Allow sending outside 08:00–18:00:
  - `php cron/send_auto_reminders.php --send --force-hours`

## Scheduling (Windows Task Scheduler)

Create a task that runs daily (recommended: 09:00) with action:

`C:\xampp\php\php.exe c:\xampp\htdocs\mch_system\cron\send_auto_reminders.php --send`

