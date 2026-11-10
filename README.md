# PlayMoreCricket

Hire cricket nets by the hour and add a coach. Plain PHP 8 + MySQL/MariaDB, no framework.

- `public/` is the web root (point the domain here). `src/` and `db/` sit outside it.
- `db/schema.sql` then `db/seed.sql` create the tables and the Penshurst Park starter data.
- Settings come from private environment variables on the host, never from the code:
  either environment variables (`PMC_DB_DSN`, `PMC_DB_USER`, `PMC_DB_PASS`, `PMC_APP_URL`, `PMC_STRIPE_SECRET`, `PMC_STRIPE_WEBHOOK_SECRET`) or a `config.local.php` on the server (copy `config.local.php.example`; it is git-ignored).
- `bin/create-user.php` creates a login from the command line.
- `tests/` holds the checks (`php tests/stripe_test.php`).

Never commit passwords or API keys.
