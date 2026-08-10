<?php
// ============================================================================
// includes/config.php — Centralized configuration (Phase 1 foundation)
// ============================================================================

// ── Database ────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'student_attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SMS_SENDER_NAME', 'SCBSIT');

// ── Application ─────────────────────────────────────────────────────────────
define('APP_NAME', 'Student Attendance Monitoring System');

// ── BASE_URL AUTO DETECT ────────────────────────────────────────────────────
(function () {

    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $docRoot = str_replace(
        '\\',
        '/',
        rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/')
    );

    $appRoot = str_replace(
        '\\',
        '/',
        dirname(__DIR__)
    );

    $path = '';

    if ($docRoot !== '' && stripos($appRoot, $docRoot) === 0) {
        $path = substr($appRoot, strlen($docRoot));
    }

    $path = str_replace('%2F', '/', rawurlencode($path));

    define('BASE_URL', $scheme . '://' . $host . $path);

})();

// ── Attendance Rules ────────────────────────────────────────────────────────
define('LATE_WINDOW_MINUTES', 15);

define('SCAN_DEBOUNCE_SECONDS', 10);

// ── SMS API ─────────────────────────────────────────────────────────────────
define('SMS_API_URL', 'https://www.iprogsms.com/api/v1/sms_messages');

define(
    'SMS_API_TOKEN',
    'f698db38ba9a33f90c9c7b6266bdd4123f0b04db'
);

// ── EMAIL SMTP CONFIG ───────────────────────────────────────────────────────
define('MAIL_ENABLED', true);

define('MAIL_TEST_MODE', false);

// Gmail SMTP
define('SMTP_HOST', 'smtp.gmail.com');

// USE:
// 587 + tls
// OR
// 465 + ssl

define('SMTP_PORT', 587);

define('SMTP_SECURE', 'tls');

define('SMTP_AUTH', true);

// Gmail account
define('SMTP_USER', 'exampleponce0@gmail.com');

// Gmail App Password (NO SPACES)
define('SMTP_PASS', 'nimrnobqwguamhyq');

// Mail sender
define('MAIL_FROM', 'exampleponce0@gmail.com');

define('MAIL_FROM_NAME', APP_NAME);

define('MAIL_REPLY_TO', 'exampleponce0@gmail.com');

// Fallback options
define('MAIL_FALLBACK_TO_STUDENT', true);

define('MAIL_DEDUP_SECONDS', 120);

define('MAIL_TIMEOUT', 12);

// ── Timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');