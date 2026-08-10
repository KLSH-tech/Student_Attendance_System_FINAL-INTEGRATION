-- ============================================================================
-- database/absent_lifecycle.sql — Additive migration for the intelligent
-- attendance lifecycle (CASE 1/2/3) + ABSENT e-mail notifications.
-- SAFE on existing data: it only WIDENS one enum. Deletes/changes nothing.
--
-- Run AFTER student_attendance_system.sql, email_upgrade.sql and
-- absent_automation.sql, on the SAME database. Safe to run more than once.
-- ============================================================================
USE `student_attendance_system`;

-- Absence e-mails are logged through the SAME pipeline as Present/Late
-- (notifyParentByEmail → email_log). Allow 'absent' in email_log.action so the
-- audit row and the de-dup bucket are stored cleanly. ('in'/'out' stay valid.)
ALTER TABLE `email_log`
  MODIFY `action` ENUM('in','out','absent') NOT NULL DEFAULT 'in';

-- Notes
-- • No other schema change is needed:
--     - the absent→late CONVERSION reuses the existing attendance_logs row
--       (action 'absent'→'in', attendance_status 'absent'→'late') — both values
--       are already valid after absent_automation.sql widened those enums;
--     - the summary update reuses attendance.status='Late' (already valid).
-- • If you have NOT yet run absent_automation.sql, run it first — it adds
--   'system_auto'/'absent' to attendance_logs and the dedup index.
