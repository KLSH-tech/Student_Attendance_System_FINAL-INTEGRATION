-- ============================================================================
-- compat_views.sql — Compatibility view layer (Phase 2 integration)
-- ----------------------------------------------------------------------------
-- Re-presents the OLD `attendance_system_db` column names on top of the new
-- normalized tables, so subsystem READ queries keep working with only a
-- one-word table-name change (students → v_students, etc.).
-- Used by: Profiles, Scanner, Notification.
-- Run this AFTER student_attendance_system.sql, on the same database.
-- ============================================================================
USE `student_attendance_system`;

-- ── v_students : legacy `students` shape (had student_id + parent_* columns) ──
CREATE OR REPLACE VIEW `v_students` AS
SELECT
    s.id,
    s.student_number          AS student_id,      -- legacy name
    s.full_name,
    s.gender,
    s.course,
    s.year_level,
    s.section,
    s.contact,
    s.email,
    p.parent_name,                                -- joined from parents
    p.contact_number          AS parent_contact   -- joined from parents
FROM students s
LEFT JOIN parents p ON p.student_id = s.id;

-- ── v_classes : legacy `classes` shape (had subject_code + subject_name) ─────
CREATE OR REPLACE VIEW `v_classes` AS
SELECT
    c.class_id,
    c.class_code,
    c.section,
    c.course_code             AS subject_code,    -- legacy name
    sub.subject_name                              -- joined from subjects
FROM classes c
LEFT JOIN subjects sub ON sub.course_code = c.course_code;

-- ── v_schedules : legacy `schedules` shape (had a single `time` + instructor) ─
CREATE OR REPLACE VIEW `v_schedules` AS
SELECT
    sch.schedule_id,
    sch.class_id,
    sch.day,
    CONCAT(
        TIME_FORMAT(sch.start_time, '%l:%i %p'), ' - ',
        TIME_FORMAT(sch.end_time,   '%l:%i %p')
    )                          AS time,           -- rebuilt from start/end
    sch.room,
    u.full_name                AS instructor      -- joined via class→teacher→user
FROM schedules sch
LEFT JOIN classes  c ON c.class_id   = sch.class_id
LEFT JOIN teachers t ON t.id         = c.teacher_id
LEFT JOIN users    u ON u.id         = t.user_id;

-- ============================================================================
-- Reads now work by changing only the FROM/JOIN table names in subsystem code:
--   FROM students  s   →  FROM v_students  s
--   JOIN classes   c   →  JOIN v_classes   c
--   JOIN schedules sch →  JOIN v_schedules sch
-- WRITES still target the REAL tables (students, parents, classes, schedules).
-- ============================================================================
