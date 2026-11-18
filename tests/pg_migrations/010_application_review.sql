-- Test-only PostgreSQL equivalent of db/migrations/010_application_review.sql
ALTER TABLE clubs ADD COLUMN web_link varchar(250);
ALTER TABLE clubs ADD COLUMN declined_at timestamp;
ALTER TABLE clubs ADD COLUMN decline_reason varchar(500);
