-- Test-only PostgreSQL equivalent of db/migrations/011_stripe_followup_terms.sql
ALTER TABLE clubs ADD COLUMN stripe_checked_at timestamp;
ALTER TABLE clubs ADD COLUMN stripe_reminded_at timestamp;
ALTER TABLE clubs ADD COLUMN stripe_reminders integer NOT NULL DEFAULT 0;
ALTER TABLE clubs ADD COLUMN terms_accepted_at timestamp;
