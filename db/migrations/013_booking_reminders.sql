-- Day-before reminder emails for bookings: remember which bookings have been reminded.
ALTER TABLE bookings ADD COLUMN reminder_sent_at DATETIME NULL;
