-- ============================================================================
-- profiles_compat.sql — make the unified `teachers` table accept the
-- Profile-Management teacher form (name / subject / email) without a redesign.
-- Run once on student_attendance_system. (XAMPP/MariaDB supports IF NOT EXISTS;
-- on MySQL 8 just remove the "IF NOT EXISTS" words.)
-- ============================================================================
USE `student_attendance_system`;

ALTER TABLE `teachers`
  ADD COLUMN IF NOT EXISTS `name`    VARCHAR(100) NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `subject` VARCHAR(100) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `email`   VARCHAR(100) NULL AFTER `subject`;

-- Allow profile-only teachers (no login account yet): user_id may be NULL.
ALTER TABLE `teachers` MODIFY COLUMN `user_id` INT NULL;

-- Backfill name/email for the seeded teachers (lorie, dan, christine) from users.
UPDATE `teachers` t
JOIN `users` u ON u.id = t.user_id
SET t.name  = COALESCE(t.name,  u.full_name),
    t.email = COALESCE(t.email, u.email);
