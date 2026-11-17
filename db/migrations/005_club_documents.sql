-- Documents a club uploads when applying (its written safeguarding policy). Private: only administrators can download them.
-- Stored in the database as base64 text so an automatic redeploy can never delete them.
SET NAMES utf8mb4;
CREATE TABLE club_documents (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id     BIGINT UNSIGNED NOT NULL,
  kind        VARCHAR(20) NOT NULL,
  filename    VARCHAR(190) NOT NULL,
  mime        VARCHAR(100) NOT NULL,
  data_b64    MEDIUMTEXT NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT doc_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
