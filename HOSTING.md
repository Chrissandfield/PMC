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
Expected: A ok, **B fails**, C ok, D ok, E ok. Please send us the exact error text from B,
and any error from any other step.

## 5. First logins
Logins are created from the command line, one per person:
`php bin/create-user.php email@example.com "Full Name" admin`
(roles: `admin`, `club_contact`, `coach`; the last two also need the club slug, `penshurst-park`).
It asks for a password. If the server has no shell access, tell us and we will provide another way.

## 6. Smoke test
- `https://pic.219design.co.uk/` shows "Find a cricket net" with Penshurst Park Cricket Club.
- Clicking the club shows the coaches and a weekly diary.
- HTTPS works with no browser warnings.
- A page that does not exist shows "That page does not exist", not a server error.

## Coming later
- Email sending (confirmations) from hello@playmorecricket.com: SMTP settings for that mailbox.
- A scheduled job (cron) every few minutes.
- A Stripe webhook address, which we will supply.
