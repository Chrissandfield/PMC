-- Club crests uploaded during enrolment. Stored in the database (as base64 text) so that
-- an automatic redeploy can never delete them.
SET NAMES utf8mb4;
CREATE TABLE club_crests (
  club_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  mime      VARCHAR(30) NOT NULL,
  data_b64  MEDIUMTEXT NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  permission_given_by VARCHAR(160) NOT NULL,
  CONSTRAINT crest_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
