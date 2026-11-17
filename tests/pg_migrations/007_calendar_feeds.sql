-- Test-only PostgreSQL equivalent of db/migrations/007_calendar_feeds.sql
ALTER TABLE clubs   ADD COLUMN feed_token varchar(40);
ALTER TABLE coaches ADD COLUMN feed_token varchar(40);
CREATE UNIQUE INDEX clubs_feed_token_uq   ON clubs (feed_token);
CREATE UNIQUE INDEX coaches_feed_token_uq ON coaches (feed_token);
