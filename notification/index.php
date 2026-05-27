<?php
/* ============================================================================
 *  NOTIFICATION CENTER  ·  index.php
 *  ---------------------------------------------------------------------------
 *  This subsystem now presents a premium, read-only Notification Center.
 *  The barcode-scanner / attendance-entry UI has been removed from the
 *  frontend (scanning is handled elsewhere in the parent Attendance System).
 *
 *  Backend is UNTOUCHED:
 *    • scanner.php is included only to reuse connectDB() and the timezone setup.
 *    • No DB structure is modified. Read/unread is stored client-side.
 *    • The parent system's nav + auth guard are wired in defensively so this
 *      page integrates when those files exist, yet still runs stand-alone.
 * ========================================================================== */

// ── Optional auth guard from the parent system (kept if present) ────────────


// ── Reuse backend helpers (connectDB, timezone). No scan is processed here. ──
require_once __DIR__ . '/scanner.php';

// Database name for the unified Attendance System. Change here if your install
// uses a different schema name (the legacy subsystem default was
// "attendance_system_db"; the unified system is "student_attendance_system").
$DB_NAME = 'student_attendance_system';

// ── Build the notification feed from real attendance activity ───────────────
//   Source of truth: attendance_logs (arrival / absent events), enriched with
//   delivery status from message_history (SMS) and, when present, email_log
//   (e-mail). Joined to students / classes / subjects / teachers.
//   Read-only. No DB structure is modified anywhere.
$notifications = [];

try {
    // Tolerant, catchable read-only connection so a DB hiccup renders a clean
    // empty state instead of a fatal die(). scanner.php is left untouched.
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = new mysqli("localhost", "root", "", $DB_NAME);
    if ($conn->connect_errno) { throw new \RuntimeException('db-unavailable'); }
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+08:00'");

    // Detect the optional e-mail audit table (added by email_upgrade.sql).
    $hasEmailLog = false;
    if ($r = $conn->query("SHOW TABLES LIKE 'email_log'")) {
        $hasEmailLog = $r->num_rows > 0; $r->free();
    }

    // Latest delivery status per log via correlated sub-queries (prevents the
    // row-multiplication a JOIN would cause when several sends share one log).
    $smsStatus = "(SELECT mh.status FROM message_history mh WHERE mh.log_id = al.log_id ORDER BY mh.sent_at DESC LIMIT 1)";
    $smsBody   = "(SELECT mh.message_body FROM message_history mh WHERE mh.log_id = al.log_id ORDER BY mh.sent_at DESC LIMIT 1)";
    $emailSel  = $hasEmailLog
        ? "(SELECT el.status FROM email_log el WHERE el.log_id = al.log_id ORDER BY el.sent_at DESC LIMIT 1) AS email_status,"
        : "NULL AS email_status,";

    $sql = "
        SELECT
            al.log_id,
            al.logged_at,
            al.action,
            al.attendance_status,
            al.scanned_by,
            al.notification_sent,
            al.sms_sent,
            s.full_name,
            s.student_number,
            s.section          AS student_section,
            c.class_code,
            c.section          AS class_section,
            sub.subject_name,
            COALESCE(t.name, u.full_name) AS instructor,
            $emailSel
            $smsStatus AS sms_status,
            $smsBody   AS message_body
        FROM attendance_logs al
        LEFT JOIN students s   ON s.id = al.student_id
        LEFT JOIN classes  c   ON c.class_id = al.class_id
        LEFT JOIN subjects sub ON sub.course_code = c.course_code
        LEFT JOIN teachers t   ON t.id = c.teacher_id
        LEFT JOIN users    u   ON u.id = t.user_id
        WHERE al.action IN ('in','absent')
        ORDER BY al.logged_at DESC
        LIMIT 500
    ";

    $res = $conn->query($sql);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            // Attendance type → drives icon, colour, priority on the client.
            $status = strtolower((string)($row['attendance_status'] ?? ''));
            if (($row['action'] ?? '') === 'absent' || $status === 'absent') {
                $type = 'absent';
            } elseif ($status === 'late') {
                $type = 'late';
            } else {
                $type = 'ontime';   // covers 'on_time' and legacy blank values
            }

            // Delivery status across both channels.
            $sms   = strtolower((string)($row['sms_status']   ?? ''));
            $email = strtolower((string)($row['email_status'] ?? ''));
            $sentFlags = ((int)($row['sms_sent'] ?? 0) === 1)
                       || ((int)($row['notification_sent'] ?? 0) === 1);
            if ($sms === 'sent' || $email === 'sent' || $sentFlags) {
                $delivery = 'sent';
            } elseif ($sms === 'failed' || $email === 'failed') {
                $delivery = 'failed';
            } else {
                $delivery = 'none';
            }
            $channel = ($email !== '') ? 'email' : 'sms';

            // Subject + section labels (fall back gracefully).
            $subject = $row['subject_name'] ?: ($row['class_code'] ?: 'General');
            $section = $row['class_section'] ?: ($row['student_section'] ?? '');

            // Message: prefer the real sent body; otherwise generate a clean one.
            $name = $row['full_name'] ?: 'Student';
            $when = date('g:i A', strtotime($row['logged_at'] ?? 'now'));
            $message = $row['message_body'];
            if (!$message) {
                if ($type === 'absent') {
                    $message = "{$name} was marked absent for {$subject}.";
                } elseif ($type === 'late') {
                    $message = "{$name} arrived late for {$subject} at {$when}.";
                } else {
                    $message = "{$name} checked in on time for {$subject} at {$when}.";
                }
            }

            $tsSec = strtotime($row['logged_at'] ?? 'now');

            // School-local (Asia/Manila, set by scanner.php) display fields so
            // every viewer sees the same wall-clock time and date grouping,
            // independent of their own browser timezone.
            $todayMid = strtotime('today');
            $logMid   = strtotime(date('Y-m-d', $tsSec));
            $diffDays = (int) floor(($todayMid - $logMid) / 86400);
            if     ($diffDays <= 0) { $bk = 'today';     $bl = 'Today'; }
            elseif ($diffDays == 1) { $bk = 'yesterday'; $bl = 'Yesterday'; }
            elseif ($diffDays < 7)  { $bk = 'week';      $bl = 'This Week'; }
            elseif ($diffDays < 30) { $bk = 'month';     $bl = 'Earlier This Month'; }
            else                    { $bk = 'older';     $bl = 'Older'; }

            $notifications[] = [
                'id'          => 'n' . $row['log_id'],   // stable client read/unread key
                'type'        => $type,
                'delivery'    => $delivery,
                'channel'     => $channel,
                'student'     => $name,
                'studentNo'   => $row['student_number'] ?? '',
                'message'     => $message,
                'subject'     => $subject,
                'section'     => $section,
                'sender'      => $row['scanned_by'] ?: 'student_terminal',
                'instructor'  => $row['instructor'] ?? '',
                'ts'          => $tsSec * 1000,            // absolute epoch (sort + "time ago")
                'timeLabel'   => date('g:i A', $tsSec),    // Manila wall-clock
                'hour'        => (int) date('G', $tsSec),  // Manila hour 0–23 (Schedule grouping)
                'bucket'      => $bk,                      // date group key
                'bucketLabel' => $bl,                      // date group label
            ];
        }
        $res->free();
    }
} catch (\Throwable $e) {
    // Stay graceful — the page renders a clean empty state instead of dying.
}

$NOTIF_JSON = json_encode($notifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notification Center · Student Attendance System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="ambient"></div>

<?php
// ── Parent-system navigation (kept if present) ──────────────────────────────
$__nav = __DIR__ . '/../includes/nav.php';
if (file_exists($__nav)) {
    require_once $__nav;
    if (function_exists('renderNav')) { renderNav('notifications'); }
}
?>

<!-- ===================== STICKY GLASS HEADER ===================== -->
<header class="nc-header">
  <div class="nc-header-inner">
    <div class="nc-title-block">
      <div class="nc-bell" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span class="nc-bell-badge" id="bellBadge">0</span>
      </div>
      <div>
        <h1 class="nc-title">Notifications</h1>
        <p class="nc-subtitle" id="ncSubtitle">Loading activity…</p>
      </div>
    </div>

    <div class="nc-header-actions">
      <div class="nc-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" id="searchInput" placeholder="Search notifications…" autocomplete="off">
        <button class="nc-search-clear" id="searchClear" aria-label="Clear search" hidden>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>

      <button class="nc-icon-btn" id="markAllBtn" title="Mark all as read">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>
        <span>Mark all read</span>
      </button>

      <button class="nc-icon-btn nc-icon-only" id="themeToggle" title="Toggle theme" aria-label="Toggle theme">
        <svg class="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        <svg class="ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
      </button>
    </div>
  </div>

  <!-- TABS -->
  <nav class="nc-tabs" id="tabs">
    <button class="nc-tab active" data-tab="all">All<span class="nc-tab-count" data-count="all">0</span></button>
    <button class="nc-tab" data-tab="unread">Unread<span class="nc-tab-count" data-count="unread">0</span></button>
    <button class="nc-tab" data-tab="important">Important<span class="nc-tab-count" data-count="important">0</span></button>
    <button class="nc-tab" data-tab="subjects">Subjects<span class="nc-tab-count" data-count="subjects">0</span></button>
    <button class="nc-tab" data-tab="schedule">Schedule<span class="nc-tab-count" data-count="schedule">0</span></button>
    <span class="nc-tab-pill" id="tabPill"></span>
  </nav>

  <!-- TOOLBAR -->
  <div class="nc-toolbar">
    <div class="nc-chiprow" id="quickFilters">
      <button class="nc-chip active" data-filter="all">All status</button>
      <button class="nc-chip" data-filter="ontime"><i class="dot dot-ontime"></i>On-Time</button>
      <button class="nc-chip" data-filter="late"><i class="dot dot-late"></i>Late</button>
      <button class="nc-chip" data-filter="absent"><i class="dot dot-absent"></i>Absent</button>
    </div>
    <div class="nc-sort">
      <label for="sortSelect">Sort</label>
      <div class="nc-select">
        <select id="sortSelect">
          <option value="newest">Newest first</option>
          <option value="oldest">Oldest first</option>
          <option value="priority">Priority</option>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
      </div>
    </div>
  </div>
</header>

<!-- ===================== FEED ===================== -->
<main class="nc-feed" id="feed"></main>

<!-- EMPTY STATE TEMPLATE -->
<template id="emptyTpl">
  <div class="nc-empty">
    <div class="nc-empty-art">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
    </div>
    <h3 class="nc-empty-title"></h3>
    <p class="nc-empty-text"></p>
  </div>
</template>

<footer class="nc-footer">
  <a href="attendance_report.php" class="nc-footer-link">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
    View Attendance Reports
  </a>
</footer>

<script>
/* Server-rendered notification feed (from message_history). */
window.NOTIFICATIONS = <?php echo $NOTIF_JSON ?: '[]'; ?>;
</script>
<script src="notifications.js"></script>
</body>
</html>