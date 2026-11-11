#!/bin/bash
# Local test setup (PostgreSQL stand-in for MySQL): fresh database, test logins, PHP server on :8099 with fake Stripe and mail.
cd "$(dirname "$0")/.."
S=/tmp/claude-0/-home-claude/c5fd9415-2e83-52f3-94be-1ff1ef169e09/scratchpad/site
export PGHOST=/tmp/pgtest PGPORT=5544 PGUSER=postgres PMC_DB_DSN="pgsql:host=/tmp/pgtest;port=5544;dbname=pmc_test" PMC_DB_USER=postgres PMC_DB_PASS="" PMC_APP_URL=http://127.0.0.1:8099 PMC_MIGRATIONS_DIR=$PWD/tests/pg_migrations PMC_NEW_PASSWORD="correct-horse-1" PMC_STRIPE_FAKE=1 PMC_MAIL_FAKE=1 PMC_STRIPE_WEBHOOK_SECRET=whsec_test_secret PMC_APP_SECRET=test-app-secret
psql -tAc "select 1" -d postgres >/dev/null 2>&1 || { su postgres -c "/usr/lib/postgresql/16/bin/pg_ctl -D /tmp/pgtest/data -o '-p 5544 -k /tmp/pgtest' -l /tmp/pgtest/log start" >/dev/null 2>&1; sleep 4; }
psql -q -d postgres -c "DROP DATABASE IF EXISTS pmc_test" -c "CREATE DATABASE pmc_test"
for f in $S/db/pg_old/schema.sql $S/db/pg_old/seed.sql; do psql -q -d pmc_test -f $f 2>&1 | grep -i error; done
pkill -f "php -S 127.0.0.1:8099"; sleep 1; (cd public && nohup php -S 127.0.0.1:8099 index.php >/tmp/php_srv.log 2>&1 &); sleep 1; curl -s -o /dev/null http://127.0.0.1:8099/
php bin/create-user.php admin@test.local "Chris Admin" admin >/dev/null; php bin/create-user.php jake@test.local "Jake Cheeseman" club_contact penshurst-park >/dev/null
php bin/create-user.php trevor@test.local "Trevor Bailey" coach penshurst-park >/dev/null; php bin/create-user.php adam@test.local "Adam Ramson" coach penshurst-park >/dev/null
psql -q -d pmc_test -c "UPDATE coaches SET user_id=(SELECT id FROM users WHERE email='trevor@test.local') WHERE name='Trevor Bailey'" -c "UPDATE coaches SET user_id=(SELECT id FROM users WHERE email='adam@test.local') WHERE name='Adam Ramson'" -c "INSERT INTO nets (club_id,name,kind,member_price_pence,nonmember_price_pence,machine_price_pence,machine_coach_only) VALUES (1,'Net 2','outdoor',0,1000,1200,true)" -c "INSERT INTO opening_hours SELECT 2, weekday, is_closed, open_hour, close_hour FROM opening_hours WHERE net_id=1"
