-- Test-only PostgreSQL equivalent of db/migrations/005_club_documents.sql
CREATE TABLE club_documents (id bigserial PRIMARY KEY, club_id bigint NOT NULL REFERENCES clubs(id) ON DELETE CASCADE, kind varchar(20) NOT NULL, filename varchar(190) NOT NULL, mime varchar(100) NOT NULL, data_b64 text NOT NULL, uploaded_at timestamptz NOT NULL DEFAULT now());
