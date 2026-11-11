-- Run once on a database that was created before coach availability existed (MySQL 8 / MariaDB 10.5+).
-- Fresh installs get this from schema.sql and do NOT need this file.
SET NAMES utf8mb4;

-- Hours a coach has marked as available (one row per hour, start time in UTC).
CREATE TABLE coach_availability (
  coach_id  BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL,
  PRIMARY KEY (coach_id, starts_at),
  CONSTRAINT avail_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A coach can hold only one live booking per hour (same trick as the net guard).
ALTER TABLE bookings
  ADD COLUMN coach_slot_key DATETIME GENERATED ALWAYS AS
    (CASE WHEN coach_id IS NOT NULL AND status IN ('pending_payment','confirmed') THEN starts_at ELSE NULL END) STORED,
  ADD UNIQUE KEY bookings_one_coach_per_slot (coach_id, coach_slot_key);
