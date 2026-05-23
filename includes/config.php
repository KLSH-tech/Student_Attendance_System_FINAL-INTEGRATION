<?php
// ============================================================================
// includes/config.php — Centralized configuration (Phase 1 foundation)
// The ONLY place that defines DB credentials, app constants, and the SMS key.
// ============================================================================

// ── Database (ONE database for the whole app) ───────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'student_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('SMS_SENDER_NAME', 'SCBSIT');  // Gamitin ang na-approve na sender name

// ── Application ──────────────────────────────────────────────────────────────
define('APP_NAME', 'Student Attendance Monitoring System');

// ── BASE_URL — AUTO-DETECTED so it works no matter what the folder is called ─
// (e.g. "Integration _ Final", "sams", anything — spaces handled too).
// It maps the project root (the parent of /includes) onto the web document root.
(function () {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $appRoot = str_replace('\\', '/', dirname(__DIR__));   // project root = parent of /includes
    $path    = '';
    if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
        $path = substr($appRoot, strlen($docRoot));        // e.g. "/Integration _ Final"
    }
    $path = str_replace('%2F', '/', rawurlencode($path));  // encode spaces, keep slashes
    define('BASE_URL', $scheme . '://' . $host . $path);
})();

// ── Attendance rules ─────────────────────────────────────────────────────────
define('LATE_WINDOW_MINUTES', 15);

// ── SMS (iProgSMS) — moved OUT of scanner source code ───────────────────────
define('SMS_API_URL',   'https://www.iprogsms.com/api/v1/sms_messages');
define('SMS_API_TOKEN', 'f698db38ba9a33f90c9c7b6266bdd4123f0b04db');

// ── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

