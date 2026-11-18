-- Stripe status refresh and reminders, and when the club accepted the club terms.
ALTER TABLE clubs ADD COLUMN stripe_checked_at DATETIME NULL;
ALTER TABLE clubs ADD COLUMN stripe_reminded_at DATETIME NULL;
ALTER TABLE clubs ADD COLUMN stripe_reminders INT NOT NULL DEFAULT 0;
ALTER TABLE clubs ADD COLUMN terms_accepted_at DATETIME NULL;
