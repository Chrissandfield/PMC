-- Test-only PostgreSQL equivalent of db/migrations/012_password_links_login_limit.sql
CREATE TABLE password_tokens (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE, token_hash char(64) NOT NULL UNIQUE, purpose varchar(8) NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), expires_at timestamptz NOT NULL, used_at timestamptz);
CREATE TABLE login_failures (id bigserial PRIMARY KEY, email varchar(190) NOT NULL, ip varchar(45) NOT NULL, attempted_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX lf_email_idx ON login_failures (email, attempted_at);
CREATE INDEX lf_ip_idx ON login_failures (ip, attempted_at);
