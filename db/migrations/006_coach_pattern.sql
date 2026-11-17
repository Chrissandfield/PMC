-- A coach's standing weekly availability pattern, filled automatically into the coming weeks.
SET NAMES utf8mb4;
CREATE TABLE coach_pattern (
  coach_id BIGINT UNSIGNED NOT NULL,
  weekday  TINYINT UNSIGNED NOT NULL,
  hour     TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (coach_id, weekday, hour),
  CONSTRAINT pattern_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Weeks already settled for a coach: 'pattern' = filled from the pattern, 'manual' = the coach saved that week by hand.
CREATE TABLE coach_pattern_weeks (
  coach_id   BIGINT UNSIGNED NOT NULL,
  week_start DATE NOT NULL,
  source     VARCHAR(8) NOT NULL,
  PRIMARY KEY (coach_id, week_start),
  CONSTRAINT pweeks_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE coaches ADD COLUMN availability_reminded_on DATE NULL;
