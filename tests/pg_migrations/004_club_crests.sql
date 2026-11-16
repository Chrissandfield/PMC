-- Test-only PostgreSQL equivalent of db/migrations/004_club_crests.sql
CREATE TABLE club_crests (club_id bigint PRIMARY KEY REFERENCES clubs(id) ON DELETE CASCADE, mime varchar(30) NOT NULL, data_b64 text NOT NULL, uploaded_at timestamptz NOT NULL DEFAULT now(), permission_given_by varchar(160) NOT NULL);
