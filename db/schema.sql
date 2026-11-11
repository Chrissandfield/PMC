-- PlayMoreCricket database schema (MySQL 8 / MariaDB 10.5+, InnoDB, utf8mb4)
-- Money is stored in pence (integers). All date-times are stored in UTC (DATETIME);
-- the app converts to/from Europe/London. Run this once in phpMyAdmin (Import tab).
SET NAMES utf8mb4;

CREATE TABLE clubs (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug                   VARCHAR(80)  NOT NULL UNIQUE,
  name                   VARCHAR(160) NOT NULL,
  town                   VARCHAR(120) NOT NULL,
  county                 VARCHAR(80)  NOT NULL DEFAULT 'Kent',
  postcode               VARCHAR(12),
  lat                    DOUBLE,
  lng                    DOUBLE,
  phone                  VARCHAR(40),
  email                  VARCHAR(190),
  crest_path             VARCHAR(190),
  sponsored_partner      TINYINT(1)   NOT NULL DEFAULT 0,
  key_contact_user_id    BIGINT UNSIGNED NULL,
  safeguarding_name      VARCHAR(160),
  safeguarding_contact   VARCHAR(190),
  safeguarding_policy    TEXT,
  -- Stripe Connect: each club is paid directly into its own connected account.
  stripe_account_id      VARCHAR(80) UNIQUE,
  stripe_charges_enabled TINYINT(1)   NOT NULL DEFAULT 0,
  commission_pct         DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  commission_free_until  DATE,
  status                 VARCHAR(10)  NOT NULL DEFAULT 'pending',
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT clubs_commission_ck CHECK (commission_pct BETWEEN 0 AND 100),
  CONSTRAINT clubs_status_ck CHECK (status IN ('pending','active','paused'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id       BIGINT UNSIGNED NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,   -- case-insensitive collation
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(160) NOT NULL,
  phone         VARCHAR(40),
  role          VARCHAR(14)  NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT users_role_ck CHECK (role IN ('admin','club_contact','coach')),
  CONSTRAINT users_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clubs ADD CONSTRAINT clubs_key_contact_fk FOREIGN KEY (key_contact_user_id) REFERENCES users(id);

CREATE TABLE coaches (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id                BIGINT UNSIGNED NOT NULL,
  user_id                BIGINT UNSIGNED NULL,
  name                   VARCHAR(160) NOT NULL,
  hourly_price_pence     INT UNSIGNED NOT NULL,
  qualification          VARCHAR(160) NOT NULL,
  dbs_checked            TINYINT(1)   NOT NULL DEFAULT 0,
  dbs_checked_on         DATE,
  stripe_account_id      VARCHAR(80) UNIQUE,
  stripe_charges_enabled TINYINT(1)   NOT NULL DEFAULT 0,
  commission_pct         DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  commission_free_until  DATE,
  active                 TINYINT(1)   NOT NULL DEFAULT 1,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT coaches_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  CONSTRAINT coaches_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT coaches_commission_ck CHECK (commission_pct BETWEEN 0 AND 100),
  CONSTRAINT coaches_dbs_ck CHECK (dbs_checked = 0 OR dbs_checked_on IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nets (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id                   BIGINT UNSIGNED NOT NULL,
  name                      VARCHAR(120) NOT NULL,
  kind                      VARCHAR(10)  NOT NULL DEFAULT 'outdoor',
  member_price_pence        INT UNSIGNED NOT NULL DEFAULT 0,
  nonmember_price_pence     INT UNSIGNED NOT NULL,
  machine_price_pence       INT UNSIGNED NULL,
  machine_coach_only        TINYINT(1)   NOT NULL DEFAULT 1,
  active                    TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT nets_club_fk FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  CONSTRAINT nets_kind_ck CHECK (kind IN ('outdoor','indoor','both'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per weekday (0 = Monday ... 6 = Sunday). Times are club local hours.
CREATE TABLE opening_hours (
  net_id     BIGINT UNSIGNED NOT NULL,
  weekday    TINYINT UNSIGNED NOT NULL,
  is_closed  TINYINT(1) NOT NULL DEFAULT 0,
  open_hour  TINYINT UNSIGNED NOT NULL DEFAULT 8,
  close_hour TINYINT UNSIGNED NOT NULL DEFAULT 20,
  PRIMARY KEY (net_id, weekday),
  CONSTRAINT hours_net_fk FOREIGN KEY (net_id) REFERENCES nets(id) ON DELETE CASCADE,
  CONSTRAINT hours_ck CHECK (weekday <= 6 AND open_hour <= 23 AND close_hour BETWEEN 1 AND 24
                             AND (is_closed = 1 OR close_hour > open_hour))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference                  VARCHAR(20)  NOT NULL UNIQUE,
  net_id                     BIGINT UNSIGNED NOT NULL,
  coach_id                   BIGINT UNSIGNED NULL,
  booking_type               VARCHAR(10)  NOT NULL,
  starts_at                  DATETIME     NOT NULL,   -- UTC
  ends_at                    DATETIME     NOT NULL,   -- UTC
  with_machine               TINYINT(1)   NOT NULL DEFAULT 0,
  status                     VARCHAR(16)  NOT NULL DEFAULT 'pending_payment',
  customer_name              VARCHAR(160),
  customer_email             VARCHAR(190),
  customer_phone             VARCHAR(40),
  is_junior                  TINYINT(1)   NOT NULL DEFAULT 0,
  parent_confirmed           TINYINT(1)   NOT NULL DEFAULT 0,
  child_first_name           VARCHAR(80),
  child_age                  TINYINT UNSIGNED NULL,
  note                       VARCHAR(500),
  price_pence                INT UNSIGNED NOT NULL DEFAULT 0,
  commission_pence           INT UNSIGNED NOT NULL DEFAULT 0,
  stripe_account_id          VARCHAR(80),
  stripe_checkout_session_id VARCHAR(120) UNIQUE,
  stripe_payment_intent_id   VARCHAR(120) UNIQUE,
  paid_at                    DATETIME NULL,
  refunded_at                DATETIME NULL,
  refund_pence               INT UNSIGNED NULL,
  expires_at                 DATETIME NULL,
  cancelled_at               DATETIME NULL,
  cancel_reason              VARCHAR(300),
  created_by_user_id         BIGINT UNSIGNED NULL,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- MySQL has no partial unique index, so this column carries the start time only while the
  -- booking holds the slot (pending or confirmed) and is NULL otherwise. NULLs never clash,
  -- so the unique key below allows exactly one live booking per net per hour.
  slot_key DATETIME GENERATED ALWAYS AS
    (CASE WHEN status IN ('pending_payment','confirmed') THEN starts_at ELSE NULL END) STORED,
  UNIQUE KEY bookings_one_per_slot (net_id, slot_key),
  -- The same idea for coaches: one live booking per coach per hour.
  coach_slot_key DATETIME GENERATED ALWAYS AS
    (CASE WHEN coach_id IS NOT NULL AND status IN ('pending_payment','confirmed') THEN starts_at ELSE NULL END) STORED,
  UNIQUE KEY bookings_one_coach_per_slot (coach_id, coach_slot_key),
  KEY bookings_by_start (starts_at),
  KEY bookings_by_email (customer_email),
  CONSTRAINT bookings_net_fk   FOREIGN KEY (net_id) REFERENCES nets(id),
  CONSTRAINT bookings_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id),
  CONSTRAINT bookings_user_fk  FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT bookings_type_ck   CHECK (booking_type IN ('member','coach','nonmember','blocked')),
  CONSTRAINT bookings_status_ck CHECK (status IN ('pending_payment','confirmed','cancelled','refunded','expired')),
  CONSTRAINT bookings_time_ck   CHECK (ends_at > starts_at),
  CONSTRAINT bookings_coach_ck  CHECK (booking_type <> 'coach' OR coach_id IS NOT NULL),
  CONSTRAINT bookings_age_ck    CHECK (child_age IS NULL OR child_age BETWEEN 4 AND 17),
  CONSTRAINT bookings_junior_ck CHECK (is_junior = 0 OR (parent_confirmed = 1 AND child_first_name IS NOT NULL AND child_age IS NOT NULL)),
  CONSTRAINT bookings_contact_ck CHECK (booking_type NOT IN ('nonmember','coach')
      OR (customer_email IS NOT NULL AND customer_phone IS NOT NULL) OR created_by_user_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hours a coach has marked as available (one row per hour, start time in UTC).
CREATE TABLE coach_availability (
  coach_id  BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  PRIMARY KEY (coach_id, starts_at),
  CONSTRAINT avail_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe sends each event at least once: keep ids so a repeat is ignored.
CREATE TABLE stripe_events (
  id           VARCHAR(120) NOT NULL PRIMARY KEY,
  type         VARCHAR(120) NOT NULL,
  received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emails to send (confirmation, receipt, cancellation). A job sends and marks them.
CREATE TABLE email_outbox (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  to_email    VARCHAR(190) NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body        TEXT NOT NULL,
  booking_id  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(500),
  CONSTRAINT outbox_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id     BIGINT UNSIGNED NULL,
  action      VARCHAR(60) NOT NULL,
  detail      VARCHAR(500),
  CONSTRAINT audit_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
