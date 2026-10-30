-- ASSUMPTION, NOT A MIGRATION TO RUN ON STAGING.
-- The live staging DB already has work_log.work_date (M5). Its exact DDL was not
-- supplied in the handover, so test databases recreate it as below. v0.9.0 only
-- needs the column to exist (Release check verifies this on the server).
ALTER TABLE work_log ADD COLUMN work_date DATE NULL AFTER ended_at;
