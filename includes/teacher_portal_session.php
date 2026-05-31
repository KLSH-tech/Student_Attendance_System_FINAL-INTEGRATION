<?php
// ============================================================================
// includes/teacher_portal_session.php
// ----------------------------------------------------------------------------
// SINGLE source of truth for the teacher-portal session keys.
//
// Both entry points populate the SAME session (the shared SAMS_SESSION):
//   • transactions/config.php  → teacherLogin()        (username + password)
//   • scanner/attendance_scanner.php → teacher badge scan (teacher_number)
//
// Keeping the key-writing in one place means a teacher who is logged in by a
// barcode scan is indistinguishable from one who typed their credentials, so
// every portal page (dashboard, attendance, disputes, reports, manual marking)
// behaves identically. Defining only this one guarded function keeps the file
// safe to require from either bootstrap without redeclaration conflicts.
// ============================================================================

if (!function_exists('setTeacherPortalSession')) {
    /**
     * Write the canonical teacher-portal session keys.
     *
     * @param array $u Normalized teacher identity. Recognized keys:
     *   teacher_db_id  (int|null)  teachers.id — becomes $_SESSION['t_id']
     *   user_id        (int|null)  users.id
     *   teacher_name   (string|null) teachers.name (may be NULL)
     *   full_name      (string|null) users.full_name (display fallback)
     *   teacher_number (string)
     *   department     (string)
     *   role           (string)    'teacher' | 'admin' | 'super_admin'
     *   username       (string)
     *   email          (string)
     */
    function setTeacherPortalSession(array $u): void
    {
        $_SESSION['teacher_auth'] = true;
        $_SESSION['t_id']         = $u['teacher_db_id'] ?? $u['user_id'] ?? 0;
        $_SESSION['t_user_id']    = $u['user_id'] ?? null;
        // teachers.name is NULL for user-linked teacher rows, so fall back to the
        // user's full name (handles both NULL and empty string).
        $_SESSION['t_name']       = ($u['teacher_name'] ?? null) ?: ($u['full_name'] ?? 'Teacher');
        $_SESSION['t_number']     = $u['teacher_number'] ?? '';
        $_SESSION['t_dept']       = $u['department'] ?? '';
        $_SESSION['t_role']       = $u['role'] ?? 'teacher';
        $_SESSION['t_username']   = $u['username'] ?? '';
        $_SESSION['t_email']      = $u['email'] ?? '';
        $_SESSION['t_login_time'] = time();
    }
}
