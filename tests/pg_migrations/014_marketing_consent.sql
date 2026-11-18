-- Test-only PostgreSQL equivalent of db/migrations/014_marketing_consent.sql
CREATE TABLE marketing_optins (id bigserial PRIMARY KEY, email varchar(190) NOT NULL, name varchar(160) NOT NULL, scope varchar(20) NOT NULL, wording varchar(300) NOT NULL, consented_at timestamptz NOT NULL, withdrawn_at timestamptz, UNIQUE (email, scope));
CREATE INDEX mk_scope_idx ON marketing_optins (scope, withdrawn_at);
