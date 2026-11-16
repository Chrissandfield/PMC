-- Club applications and safeguarding verification (decided 16 Nov 2026).
-- A new club applies on the site, its safeguarding officer confirms its coaches' DBS checks by a private link,
-- and an administrator approves it. No DBS certificate numbers are stored anywhere.
SET NAMES utf8mb4;

ALTER TABLE clubs
  ADD COLUMN key_contact_name VARCHAR(160) NULL,
  ADD COLUMN key_contact_detail VARCHAR(190) NULL,
  ADD COLUMN submitted_at DATETIME NULL,
  ADD COLUMN safeguarding_confirmed_at DATETIME NULL,
  ADD COLUMN safeguarding_confirmed_by VARCHAR(160) NULL,
  ADD COLUMN approved_at DATETIME NULL,
  ADD COLUMN approved_by_user_id BIGINT UNSIGNED NULL;

ALTER TABLE coaches
  ADD COLUMN dbs_method VARCHAR(20) NULL,
  ADD COLUMN dbs_confirmed_by VARCHAR(160) NULL,
  ADD COLUMN is_safeguarding_officer TINYINT(1) NOT NULL DEFAULT 0;

-- Private links. Only a hash of each link is kept. role: 'officer' (policy and coaches) or 'second' (named coaches only).
CREATE TABLE verification_tokens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL UNIQUE,
  role        VARCHAR(10) NOT NULL,
  person_name VARCHAR(160) NOT NULL,
  sent_to     VARCHAR(190) NOT NULL,
  coach_ids   VARCHAR(200) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  reminded_at DATETIME NULL,
  last_used_at DATETIME NULL,
  CONSTRAINT vt_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  CONSTRAINT vt_role_ck CHECK (role IN ('officer','second'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A record each time someone confirms a coach's DBS check: who, how and when. Never the certificate number.
CREATE TABLE dbs_confirmations (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  coach_id           BIGINT UNSIGNED NOT NULL,
  token_id           BIGINT UNSIGNED NULL,
  confirmed_by       VARCHAR(160) NOT NULL,
  confirmed_by_email VARCHAR(190) NULL,
  method             VARCHAR(20) NOT NULL,
  checked_on         DATE NOT NULL,
  recorded_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT dc_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE,
  CONSTRAINT dc_method_ck CHECK (method IN ('certificate','update_service','by_hand'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
