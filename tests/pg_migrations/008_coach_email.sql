-- Test-only PostgreSQL equivalent of db/migrations/008_coach_email.sql
ALTER TABLE coaches ADD COLUMN email varchar(190);
