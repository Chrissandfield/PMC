-- Test-only PostgreSQL equivalent of db/migrations/001_coach_availability.sql
CREATE TABLE coach_availability (coach_id bigint NOT NULL REFERENCES coaches(id) ON DELETE CASCADE, starts_at timestamptz NOT NULL, PRIMARY KEY (coach_id, starts_at));
CREATE UNIQUE INDEX bookings_one_coach_per_slot ON bookings (coach_id, starts_at) WHERE coach_id IS NOT NULL AND status IN ('pending_payment','confirmed');
