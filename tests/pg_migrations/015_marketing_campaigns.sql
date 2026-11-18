-- Test-only PostgreSQL equivalent of db/migrations/015_marketing_campaigns.sql
CREATE TABLE marketing_campaigns (id bigserial PRIMARY KEY, scope varchar(20) NOT NULL, subject varchar(150) NOT NULL, body text NOT NULL, created_by bigint, created_at timestamptz NOT NULL DEFAULT now(), total integer NOT NULL DEFAULT 0);
CREATE TABLE marketing_queue (id bigserial PRIMARY KEY, campaign_id bigint NOT NULL REFERENCES marketing_campaigns(id) ON DELETE CASCADE, email varchar(190) NOT NULL, name varchar(160) NOT NULL, status varchar(8) NOT NULL DEFAULT 'queued', done_at timestamptz);
CREATE INDEX mq_campaign_idx ON marketing_queue (campaign_id, status);
