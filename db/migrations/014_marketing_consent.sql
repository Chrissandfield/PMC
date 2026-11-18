-- Optional marketing consent: one row per email address and list ('pmc' or 'club-<id>').
CREATE TABLE marketing_optins (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(190) NOT NULL,
  name         VARCHAR(160) NOT NULL,
  scope        VARCHAR(20)  NOT NULL,
  wording      VARCHAR(300) NOT NULL,
  consented_at DATETIME NOT NULL,
  withdrawn_at DATETIME NULL,
  UNIQUE KEY mk_email_scope (email, scope),
  KEY mk_scope_idx (scope, withdrawn_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
