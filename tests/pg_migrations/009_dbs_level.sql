-- Test-only PostgreSQL equivalent of db/migrations/009_dbs_level.sql
ALTER TABLE coaches ADD COLUMN dbs_enhanced boolean NOT NULL DEFAULT false;
ALTER TABLE dbs_confirmations ADD COLUMN enhanced_barred boolean NOT NULL DEFAULT false;
