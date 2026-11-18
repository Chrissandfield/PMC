-- Messages sent to opted-in players, and the queue of recipients (sent in small batches).
CREATE TABLE marketing_campaigns (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope      VARCHAR(20)  NOT NULL,
  subject    VARCHAR(150) NOT NULL,
  body       TEXT NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total      INT NOT NULL DEFAULT 0,
  KEY mc_scope_idx (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE marketing_queue (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL,
  name        VARCHAR(160) NOT NULL,
  status      VARCHAR(8) NOT NULL DEFAULT 'queued',
  done_at     DATETIME NULL,
  KEY mq_campaign_idx (campaign_id, status),
  CONSTRAINT mq_campaign_fk FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
