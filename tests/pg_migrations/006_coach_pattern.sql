-- Test-only PostgreSQL equivalent of db/migrations/006_coach_pattern.sql
CREATE TABLE coach_pattern (coach_id bigint NOT NULL REFERENCES coaches(id) ON DELETE CASCADE, weekday smallint NOT NULL, hour smallint NOT NULL, PRIMARY KEY (coach_id, weekday, hour));
CREATE TABLE coach_pattern_weeks (coach_id bigint NOT NULL REFERENCES coaches(id) ON DELETE CASCADE, week_start date NOT NULL, source varchar(8) NOT NULL, PRIMARY KEY (coach_id, week_start));
ALTER TABLE coaches ADD COLUMN availability_reminded_on date;
