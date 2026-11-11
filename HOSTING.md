# Hosting notes for PlayMoreCricket

For the person running the server. Nothing secret lives in this repo. Passwords and keys are
set on the server only.

## What it is
A small PHP 8.1+ website with a MySQL/MariaDB database. No framework, no Composer packages.
Needs these PHP extensions: `pdo_mysql`, `curl`, `mbstring`, `openssl`. Apache with `.htaccess`
(mod_rewrite) enabled.

## Folder layout
| Folder | What it is |
|---|---|
| `public/` | **The web root.** The domain must point here. Only this folder is served. |
| `src/`, `db/`, `bin/`, `tests/`, `config.php` | Code and data files. These must NOT be served. |

## 1. Document root
Set the document root of `pic.219design.co.uk` to the **`public`** folder inside the repo.
If it can't be changed, tell us and we will add a root rewrite instead.

## 2. Private settings
(The host now sets the database password as an environment variable, so `config.local.php` is optional.)
Copy `config.local.example.php` to `config.local.php` (same folder as `config.php`) and fill in
the real database name, user and password. That file is ignored by git and must never be committed.
Environment variables also work instead: `PMC_DB_DSN`, `PMC_DB_USER`, `PMC_DB_PASS`, `PMC_APP_URL`.
Later we will add two more: `PMC_STRIPE_SECRET` and `PMC_STRIPE_WEBHOOK_SECRET`
(also settable as `stripe_secret` / `stripe_webhook_secret` in `config.local.php`).

## 3. Database
`db/schema.sql` creates the tables, then `db/seed.sql` adds the first club (Penshurst Park).
Import them once, in that order, into an empty database. Do not re-import over live data.
Needs MySQL 8.0.16+ or MariaDB 10.5+ (for CHECK constraints and the stored generated column).
Please tell us the exact version shown on the phpMyAdmin home page.

### 3b. Database updates (automatic, no SQL needed)
When a deploy brings new files in `db/migrations/`, the site applies them itself on the next page visit
(it takes a lock, runs each file once, logs it in `schema_migrations` and `audit_log`, and never stops
the site if one fails; the error goes to the server error log). Set `PMC_AUTO_MIGRATE=0` to turn that off.
Later database changes live in `db/migrations/` and are applied from the site itself: an administrator
opens `/admin` and clicks **Apply updates**. If the database was imported before 11 Nov 2026 that button
adds coach availability and a second guard so a coach cannot hold two bookings in the same hour.
(A fresh import of the current `schema.sql` already includes everything, so nothing will be pending.)

## 4. Check the double-booking guard (phpMyAdmin → SQL tab)
This confirms two people cannot hold the same hour. Run the statements one at a time and note
what happens.

```sql
-- A: should succeed
INSERT INTO bookings (reference, net_id, booking_type, starts_at, ends_at, status)
VALUES ('TEST-0001', 1, 'blocked', '2030-01-01 10:00:00', '2030-01-01 11:00:00', 'confirmed');

-- B: must FAIL with "Duplicate entry ... for key 'bookings_one_per_slot'"
INSERT INTO bookings (reference, net_id, booking_type, starts_at, ends_at, status)
VALUES ('TEST-0002', 1, 'blocked', '2030-01-01 10:00:00', '2030-01-01 11:00:00', 'confirmed');

-- C: free the hour
UPDATE bookings SET status = 'cancelled' WHERE reference = 'TEST-0001';

-- D: should now succeed
INSERT INTO bookings (reference, net_id, booking_type, starts_at, ends_at, status)
VALUES ('TEST-0002', 1, 'blocked', '2030-01-01 10:00:00', '2030-01-01 11:00:00', 'confirmed');

-- E: clean up
DELETE FROM bookings WHERE reference LIKE 'TEST-%';
```
Expected: A ok, **B fails**, C ok, D ok, E ok.

Then the same check for coaches (after the admin has clicked Apply updates). Replace `COACH_ID` with a real id from the `coaches` table
and `USER_ID` with a real id from `users`:
```sql
INSERT INTO bookings (reference, net_id, coach_id, booking_type, starts_at, ends_at, status, created_by_user_id)
VALUES ('TEST-0003', 1, COACH_ID, 'coach', '2030-01-02 10:00:00', '2030-01-02 11:00:00', 'confirmed', USER_ID);
-- must FAIL "Duplicate entry ... bookings_one_coach_per_slot" (different net, same coach and hour):
INSERT INTO bookings (reference, net_id, coach_id, booking_type, starts_at, ends_at, status, created_by_user_id)
VALUES ('TEST-0004', 2, COACH_ID, 'coach', '2030-01-02 10:00:00', '2030-01-02 11:00:00', 'confirmed', USER_ID);
DELETE FROM bookings WHERE reference LIKE 'TEST-%';
```
(Skip this one if there is only one net. The second insert needs a second net with id 2.) Please send us the exact error text from B,
and any error from any other step.

## 5. First login
Open `https://pic.219design.co.uk/setup` and create the administrator login. That page only works while
there are no users and then switches itself off. All other logins (club contacts, coaches) are then
added by the administrator from `/admin`. No command line is needed.

## 6. Smoke test
- `https://pic.219design.co.uk/` shows "Find a cricket net" with Penshurst Park Cricket Club.
- Clicking the club shows the coaches and a weekly diary.
- HTTPS works with no browser warnings.
- A page that does not exist shows "That page does not exist", not a server error.

## 7. Online payments (Stripe) and emails
Settings (environment variables, or the same names in `config.local.php`):
- `PMC_STRIPE_SECRET`: the Stripe secret key (`sk_test_...` for testing, `sk_live_...` when live).
- `PMC_STRIPE_WEBHOOK_SECRET`: the signing secret (`whsec_...`) of the webhook below.
- `PMC_APP_SECRET`: any long random string (32+ characters). It signs the links in customers' emails.
  Changing it later breaks old email links, so set it once.
- `PMC_APP_URL`: `https://pic.219design.co.uk` for now, then the real domain.

Webhook (Stripe Dashboard → Developers → Webhooks → Add endpoint):
- URL: `https://<site>/stripe/webhook`
- **Listen to events on Connected accounts** (this matters: clubs are connected accounts).
- Events: `checkout.session.completed`, `checkout.session.expired`, `account.updated`.

Do not set `PMC_STRIPE_FAKE` or `PMC_MAIL_FAKE` on the live server; they exist for automated tests only.

Email: confirmations are sent with PHP's `mail()` from `hello@playmorecricket.com`. It needs the server's mail
sending to work and that mailbox to exist. Failed sends are kept in the `email_outbox` table with the error.
Tell us if `mail()` is not available and we will switch to SMTP.

The club connects its own Stripe account from the club page (logged in as the club contact): **Set up payments**.
Until that is finished, visitors see "online booking coming soon" and cannot reserve hours.

## Coming later
- A scheduled job (cron) to retry failed emails, and SMTP if `mail()` is not suitable.
