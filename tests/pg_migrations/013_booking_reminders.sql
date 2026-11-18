-- Test-only PostgreSQL equivalent of db/migrations/013_booking_reminders.sql
ALTER TABLE bookings ADD COLUMN reminder_sent_at timestamptz;
