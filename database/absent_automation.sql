-- ============================================================================
-- database/absent_automation.sql — Additive migration for AUTOMATIC ABSENCE
-- marking. SAFE on existing data: it only WIDENS two enums and ADDS one index.
-- It deletes nothing and changes no existing row.
--
-- Run AFTER student_attendance_system.sql (and email_upgrade.sql) on the SAME
-- database, e.g. phpMyAdmin → select `student_attendance_system` → SQL tab.
-- Safe to run more than once.
-- ============================================================================
USE `student_attendance_system`;

-- ── 1) Let the log row carry the new system-generated values ────────────────
--    The auto-marker writes scanned_by='system_auto' and action='absent'.
--    We only ADD these to the existing enums; every current value stays valid,
--    so the scanner (which writes 'student_terminal' / 'in' / 'out') is
--    completely unaffected.  attendance_logs.attendance_status already has
--    'absent', and attendance.status already has 'Absent' — no change needed.
ALTER TABLE `attendance_logs`
  MODIFY `scanned_by`
    ENUM('teacher','student','student_terminal','teacher_terminal','system_auto')
    NOT NULL DEFAULT 'student';

ALTER TABLE `attendance_logs`
  MODIFY `action`
    ENUM('in','out','absent')
    DEFAULT 'in';

-- ── 2) Index that makes the "did this student already have a row today?"
--        duplicate-prevention check (and the per-schedule/day lookups) fast,
--        even with many students × schedules × days.
--    Guarded so a re-run won't error if it already exists.
SET @ix := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'attendance_logs'
              AND INDEX_NAME   = 'idx_log_sched_dedup');
SET @sql := IF(@ix = 0,
  'ALTER TABLE `attendance_logs`
     ADD INDEX `idx_log_sched_dedup` (`schedule_id`,`class_id`,`student_id`,`logged_at`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Notes ───────────────────────────────────────────────────────────────────
-- • The `attendance` summary table already has UNIQUE(student_id,class_id,date)
--   (`uq_attendance`); the marker uses INSERT IGNORE against it so it can never
--   duplicate or overwrite a summary row. No schema change required there.
-- • MySQL 8 users: the syntax above is standard and needs no edits.
