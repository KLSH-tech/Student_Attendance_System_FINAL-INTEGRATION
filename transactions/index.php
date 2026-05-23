<?php
// ============================================================
// index.php — Main Landing / Student Check-In Page
// Transaction / Request Management Subsystem · Group 7
// Southland College
// ============================================================
require_once __DIR__ . '/config.php';

// Redirect teachers already logged in
if (isTeacher()) {
    header('Location: dashboard.php');
    exit;
}

// ── Attendance window logic ────────────────────────────────────────────────────
$now        = new DateTime();
$classStart = new DateTime(date('Y-m-d') . ' ' . CLASS_START_TIME);
$lateEnd    = (clone $classStart)->modify('+' . LATE_WINDOW_MINUTES . ' minutes');
$todayStr   = date('Y-m-d');

// Determine session state
$sessionOpen   = ($now >= $classStart && $now <= $lateEnd);
$sessionEnded  = ($now > $lateEnd);
$sessionPre    = ($now < $classStart);
$minsUntilOpen = $sessionPre ? (int)round(($classStart->getTimestamp() - $now->getTimestamp()) / 60) : 0;

$successMsg = '';
$errorMsg   = '';
$checkedIn  = false;
$myRecord   = null;

// ── Student check-in POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkin'])) {
    if (!verifyCsrf($_POST['csrf'] ?? '')) {
        $errorMsg = 'Security token mismatch. Please refresh and try again.';
    } else {
        $studentNum = trim($_POST['student_number'] ?? '');
        $password   = $_POST['password'] ?? '';

        if (empty($studentNum) || empty($password)) {
            $errorMsg = 'Please enter your student number and password.';
        } else {
            try {
                $pdo    = db();
                $stmt   = $pdo->prepare("SELECT * FROM students WHERE student_number = ? LIMIT 1");
                $stmt->execute([$studentNum]);
                $student = $stmt->fetch();

                if (!$student || !password_verify($password, $student['password'])) {
                    $errorMsg = 'Invalid student number or password.';
                } else {
                    // Check duplicate
                    $dup = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ? LIMIT 1");
                    $dup->execute([$student['user_id'], $todayStr]);
                    $existing = $dup->fetch();

                    if ($existing) {
                        $errorMsg  = 'You have already marked attendance for today.';
                        $myRecord  = $existing;
                        $checkedIn = true;
                    } elseif (!$sessionOpen && !$sessionEnded) {
                        $errorMsg = 'The attendance window has not opened yet. Class starts at ' . date('g:i A', strtotime(CLASS_START_TIME)) . '.';
                    } elseif ($sessionEnded) {
                        $errorMsg = 'The attendance window has closed. Please submit a dispute request if needed.';
                    } else {
                        // Classify status
                        $presentEnd = (clone $classStart)->modify('+' . PRESENT_WINDOW_MINUTES . ' minutes');
                        $status = ($now <= $presentEnd) ? 'Present' : 'Late';

                        $ins = $pdo->prepare(
                            "INSERT INTO attendance (user_id, date, time, status) VALUES (?, ?, NOW(), ?)"
                        );
                        $ins->execute([$student['user_id'], $todayStr, $status]);
                        $attId     = $pdo->lastInsertId();
                        $checkedIn = true;

                        // Re-fetch
                        $myRecord  = $pdo->prepare("SELECT * FROM attendance WHERE attendance_id = ?")->execute([$attId]) ? null : null;
                        $myRecord  = ['status' => $status, 'time' => date('H:i:s'), 'date' => $todayStr];
                        $successMsg = "Attendance marked as <strong>$status</strong>! Recorded at " . date('g:i:s A') . ".";
                    }
                }
            } catch (PDOException $e) {
                $errorMsg = 'A database error occurred. Please try again.';
                error_log('[index.php] Check-in error: ' . $e->getMessage());
            }
        }
    }
}

// ── Live stats for display ────────────────────────────────────────────────────
try {
    $pdo         = db();
    $presentTdy  = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE date=CURDATE() AND status='Present'")->fetchColumn();
    $lateTdy     = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE date=CURDATE() AND status='Late'")->fetchColumn();
    $totalStu    = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $totalMarked = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE date=CURDATE()")->fetchColumn();
} catch (PDOException) {
    $presentTdy = $lateTdy = $totalStu = $totalMarked = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Check-In · Attendance System · Southland College</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
/* ── Landing-page overrides ─────────────────────────────────────────────────── */
html, body { height: 100%; }

body {
    display: flex;
    flex-direction: column;
    background: #f0f9ff;
    padding: 0;
    margin: 0;
}

/* ── TOP NAV ─────────────────────────────────────────────────────────────────── */
.landing-nav {
    position: sticky;
    top: 0;
    z-index: 200;
    background: #0c4a6e;
    border-bottom: 1px solid rgba(255,255,255,.1);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 36px;
    height: 60px;
    box-shadow: 0 2px 12px rgba(3,105,161,.2);
}

.nav-brand {
    display: flex;
    align-items: center;
    gap: 14px;
}
.nav-brand-icon {
    width: 38px; height: 38px;
    background: rgba(255,255,255,.15);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}
.nav-brand-text {
    display: flex;
    flex-direction: column;
}
.nav-brand-name {
    font-size: 14px;
    font-weight: 800;
    color: white;
    letter-spacing: -.02em;
    line-height: 1;
}
.nav-brand-sub {
    font-size: 10px;
    color: rgba(255,255,255,.55);
    margin-top: 2px;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.nav-center {
    display: flex;
    align-items: center;
    gap: 8px;
}
.nav-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 20px;
    padding: 6px 14px;
    font-size: 12px;
    color: rgba(255,255,255,.75);
    font-weight: 500;
}
.nav-pill .dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #4ade80;
    animation: blink 2s ease-in-out infinite;
}
.nav-pill .dot.closed { background: #f87171; animation: none; }
.nav-pill .dot.pre    { background: #fbbf24; animation: none; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

.nav-right {
    display: flex;
    align-items: center;
    gap: 12px;
}
.nav-time {
    font-family: 'JetBrains Mono', monospace;
    font-size: 13px;
    color: rgba(255,255,255,.6);
    background: rgba(255,255,255,.07);
    padding: 5px 12px;
    border-radius: 7px;
    border: 1px solid rgba(255,255,255,.1);
}
.teacher-portal-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    background: rgba(255,255,255,.12);
    color: white;
    border: 1.5px solid rgba(255,255,255,.22);
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
    font-family: 'Inter', sans-serif;
    text-decoration: none;
    letter-spacing: .02em;
    transition: all .2s;
    cursor: pointer;
}
.teacher-portal-btn:hover {
    background: white;
    color: #0c4a6e;
    border-color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(0,0,0,.2);
}
.teacher-portal-btn .lock-icon { font-size: 13px; }

/* ── HERO STRIP ──────────────────────────────────────────────────────────────── */
.hero {
    background: linear-gradient(160deg, #0c4a6e 0%, #0369a1 55%, #0ea5e9 100%);
    padding: 52px 36px 44px;
    position: relative;
    overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute;
    top: -100px; right: -100px;
    width: 380px; height: 380px;
    background: rgba(255,255,255,.04);
    border-radius: 50%;
}
.hero::after {
    content: '';
    position: absolute;
    bottom: -80px; left: 30%;
    width: 260px; height: 260px;
    background: rgba(255,255,255,.03);
    border-radius: 50%;
}
.hero-inner {
    max-width: 1100px;
    margin: 0 auto;
    position: relative; z-index: 1;
}
.hero-date-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    color: rgba(255,255,255,.85);
    font-size: 12px;
    font-weight: 600;
    padding: 6px 15px;
    border-radius: 20px;
    margin-bottom: 18px;
    letter-spacing: .04em;
}
.hero-title {
    font-size: 36px;
    font-weight: 900;
    color: white;
    letter-spacing: -.04em;
    line-height: 1.1;
    margin-bottom: 10px;
}
.hero-subtitle {
    font-size: 15px;
    color: rgba(255,255,255,.7);
    max-width: 480px;
    line-height: 1.6;
}

/* Window status banner */
.window-status {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-top: 22px;
    padding: 11px 20px;
    border-radius: 11px;
    font-size: 13px;
    font-weight: 600;
    border: 1.5px solid;
}
.window-open   { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.35); color: #6ee7b7; }
.window-closed { background: rgba(220,38,38,.13);  border-color: rgba(220,38,38,.3);  color: #fca5a5; }
.window-pre    { background: rgba(245,158,11,.13); border-color: rgba(245,158,11,.3); color: #fcd34d; }

/* ── MAIN LAYOUT ─────────────────────────────────────────────────────────────── */
.main-wrap {
    max-width: 1100px;
    margin: 36px auto;
    padding: 0 36px;
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 28px;
    flex: 1;
}

/* ── CHECK-IN CARD ───────────────────────────────────────────────────────────── */
.checkin-card {
    background: white;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 8px 32px rgba(3,105,161,.1);
    overflow: hidden;
    position: sticky;
    top: 80px;
    align-self: start;
}
.checkin-card-header {
    background: linear-gradient(135deg, #0c4a6e, #0369a1);
    padding: 22px 28px;
    color: white;
}
.checkin-card-header h2 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -.03em;
}
.checkin-card-header p {
    font-size: 12px;
    opacity: .75;
    margin-top: 3px;
}
.checkin-card-body {
    padding: 28px;
}

/* Session timing info */
.session-info-row {
    display: flex;
    gap: 10px;
    margin-bottom: 22px;
}
.session-info-box {
    flex: 1;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    text-align: center;
}
.session-info-box .lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #64748b; }
.session-info-box .val { font-family: 'JetBrains Mono', monospace; font-size: 15px; font-weight: 700; color: #0f172a; margin-top: 3px; }

/* Success state */
.success-banner {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 32px 24px;
    background: #d1fae5;
    border-radius: 12px;
    margin-bottom: 20px;
}
.success-banner .s-icon  { font-size: 48px; margin-bottom: 12px; }
.success-banner .s-title { font-size: 20px; font-weight: 800; color: #064e3b; }
.success-banner .s-sub   { font-size: 13px; color: #065f46; margin-top: 5px; line-height: 1.5; }
.success-banner.late-banner { background: #fef3c7; }
.success-banner.late-banner .s-title { color: #78350f; }
.success-banner.late-banner .s-sub   { color: #92400e; }

/* Form internals */
.field-group { margin-bottom: 18px; }
.field-label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #64748b;
    margin-bottom: 7px;
}
.field-wrap { position: relative; }
.field-icon {
    position: absolute;
    left: 14px; top: 50%;
    transform: translateY(-50%);
    font-size: 15px;
    color: #94a3b8;
    pointer-events: none;
}
.field-input {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    background: #f8fafc;
    color: #0f172a;
    transition: all .2s;
}
.field-input:focus {
    outline: none;
    border-color: #0369a1;
    background: white;
    box-shadow: 0 0 0 3px rgba(3,105,161,.1);
}
.field-input::placeholder { color: #94a3b8; }

.checkin-btn {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, #0369a1, #0ea5e9);
    color: white;
    border: none;
    border-radius: 11px;
    font-size: 15px;
    font-weight: 700;
    font-family: 'Inter', sans-serif;
    cursor: pointer;
    transition: all .22s;
    letter-spacing: -.01em;
    margin-top: 4px;
}
.checkin-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(3,105,161,.3);
}
.checkin-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
    transform: none;
}

.error-box {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 18px;
}

.dispute-hint {
    margin-top: 16px;
    padding: 12px 16px;
    background: #fefce8;
    border: 1px solid #fde047;
    border-radius: 10px;
    font-size: 12px;
    color: #713f12;
    line-height: 1.5;
    text-align: center;
}

/* ── RIGHT PANEL ─────────────────────────────────────────────────────────────── */
.right-panel { display: flex; flex-direction: column; gap: 20px; }

/* Live stats */
.live-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}
.live-stat {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 20px 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    position: relative;
    overflow: hidden;
}
.live-stat::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--lsc, #0369a1);
}
.live-stat.green { --lsc: #059669; }
.live-stat.amber { --lsc: #d97706; }
.live-stat.slate { --lsc: #475569; }
.live-stat .ls-num   { font-size: 34px; font-weight: 800; color: #0f172a; font-family: 'JetBrains Mono', monospace; letter-spacing: -.04em; }
.live-stat .ls-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #64748b; margin-top: 4px; }
.live-stat .ls-icon  { position: absolute; top: 16px; right: 16px; font-size: 26px; opacity: .1; }

/* Info cards */
.info-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}
.info-card-header {
    padding: 16px 22px;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.info-card-header h3 {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #0f172a;
}
.info-card-body { padding: 20px 22px; }

/* Schedule table */
.sched-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 11px 0;
    border-bottom: 1px solid #f1f5f9;
}
.sched-row:last-child { border-bottom: none; }
.sched-time {
    font-family: 'JetBrains Mono', monospace;
    font-size: 12px;
    color: #64748b;
    min-width: 110px;
}
.sched-dot {
    width: 9px; height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
}
.sched-label { font-size: 13px; font-weight: 600; color: #0f172a; }
.sched-sub   { font-size: 11px; color: #64748b; margin-top: 1px; }

/* How-to steps */
.steps { display: flex; flex-direction: column; gap: 14px; }
.step  { display: flex; align-items: flex-start; gap: 14px; }
.step-num {
    width: 30px; height: 30px;
    border-radius: 8px;
    background: #0369a1;
    color: white;
    font-size: 13px;
    font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    font-family: 'JetBrains Mono', monospace;
}
.step-text strong { font-size: 13px; font-weight: 700; color: #0f172a; }
.step-text p      { font-size: 12px; color: #64748b; margin-top: 2px; line-height: 1.5; }

/* Announcement */
.announce-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 13px 0;
    border-bottom: 1px solid #f1f5f9;
}
.announce-item:last-child { border-bottom: none; }
.announce-icon {
    width: 34px; height: 34px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.announce-icon.blue   { background: #dbeafe; }
.announce-icon.yellow { background: #fef3c7; }
.announce-icon.green  { background: #d1fae5; }
.announce-title { font-size: 13px; font-weight: 600; color: #0f172a; }
.announce-sub   { font-size: 12px; color: #64748b; margin-top: 2px; }

/* ── FOOTER ──────────────────────────────────────────────────────────────────── */
.landing-footer {
    background: white;
    border-top: 1px solid #e2e8f0;
    padding: 18px 36px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    color: #94a3b8;
    margin-top: auto;
}
.footer-link {
    color: #0369a1;
    font-weight: 600;
    text-decoration: none;
}
.footer-link:hover { text-decoration: underline; }

/* ── RESPONSIVE ──────────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
    .main-wrap { grid-template-columns: 1fr; padding: 0 20px; }
    .checkin-card { position: static; }
    .live-stats { grid-template-columns: repeat(4, 1fr); }
    .hero { padding: 36px 20px 32px; }
    .hero-title { font-size: 26px; }
}
@media (max-width: 600px) {
    .landing-nav { padding: 0 16px; }
    .nav-center { display: none; }
    .nav-brand-sub { display: none; }
    .live-stats { grid-template-columns: repeat(2, 1fr); }
    .landing-footer { flex-direction: column; gap: 6px; text-align: center; }
}
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════════
     TOP NAVIGATION
════════════════════════════════════════════════════════════════ -->
<nav class="landing-nav">
    <!-- Brand -->
    <div class="nav-brand">
        <div class="nav-brand-icon">🏫</div>
        <div class="nav-brand-text">
            <div class="nav-brand-name">AttendTrack</div>
            <div class="nav-brand-sub">Southland College · Group 7</div>
        </div>
    </div>

    <!-- Session status pill -->
    <div class="nav-center">
        <?php if ($sessionOpen): ?>
            <div class="nav-pill">
                <span class="dot"></span>
                Attendance Window Open
            </div>
        <?php elseif ($sessionPre): ?>
            <div class="nav-pill">
                <span class="dot pre"></span>
                Opens at <?php echo date('g:i A', strtotime(CLASS_START_TIME)); ?>
                (<?php echo $minsUntilOpen; ?> min)
            </div>
        <?php else: ?>
            <div class="nav-pill">
                <span class="dot closed"></span>
                Session Closed for Today
            </div>
        <?php endif; ?>
        <div class="nav-pill" style="background:transparent;border:none;padding:0;">
            <span style="font-family:'JetBrains Mono',monospace;font-size:12px;color:rgba(255,255,255,.55);">
                <?php echo date('D, M j, Y'); ?>
            </span>
        </div>
    </div>

    <!-- Right side: clock + teacher portal button -->
    <div class="nav-right">
        <div class="nav-time" id="navClock"><?php echo date('g:i:s A'); ?></div>
        <a href="teacher/index.php" class="teacher-portal-btn" title="Access the Teacher Portal">
            <span class="lock-icon">🔐</span>
            Teacher Portal
            <span style="font-size:11px;opacity:.7;">→</span>
        </a>
    </div>
</nav>

<!-- ══════════════════════════════════════════════════════════════
     HERO
════════════════════════════════════════════════════════════════ -->
<section class="hero">
    <div class="hero-inner">
        <div class="hero-date-badge">
            📅 <?php echo date('l, F j, Y'); ?>
        </div>
        <div class="hero-title">Student Attendance<br>Check-In</div>
        <div class="hero-subtitle">
            Log in with your student credentials to mark your attendance for today's class session.
        </div>

        <?php if ($sessionOpen): ?>
            <div class="window-status window-open">
                ✅ &nbsp; Attendance window is <strong>OPEN</strong> &mdash;
                closes at <?php echo date('g:i A', $lateEnd->getTimestamp()); ?>
            </div>
        <?php elseif ($sessionPre): ?>
            <div class="window-status window-pre">
                ⏳ &nbsp; Window opens at <strong><?php echo date('g:i A', strtotime(CLASS_START_TIME)); ?></strong>
                &mdash; <?php echo $minsUntilOpen; ?> minute<?php echo $minsUntilOpen !== 1 ? 's' : ''; ?> remaining
            </div>
        <?php else: ?>
            <div class="window-status window-closed">
                ⛔ &nbsp; Attendance window has <strong>CLOSED</strong> for today.
                Contact your teacher if you have a concern.
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════════════
     MAIN CONTENT
════════════════════════════════════════════════════════════════ -->
<div class="main-wrap">

    <!-- ── LEFT: CHECK-IN FORM ─────────────────────────────────────────────── -->
    <div class="checkin-card">
        <div class="checkin-card-header">
            <h2>Mark Your Attendance</h2>
            <p><?php echo date('l, F j, Y'); ?> &nbsp;·&nbsp; Class at <?php echo date('g:i A', strtotime(CLASS_START_TIME)); ?></p>
        </div>
        <div class="checkin-card-body">

            <!-- Session timing info -->
            <div class="session-info-row">
                <div class="session-info-box">
                    <div class="lbl">Class Start</div>
                    <div class="val"><?php echo date('g:i A', strtotime(CLASS_START_TIME)); ?></div>
                </div>
                <div class="session-info-box">
                    <div class="lbl">Present Until</div>
                    <div class="val"><?php echo date('g:i A', strtotime(CLASS_START_TIME) + PRESENT_WINDOW_MINUTES * 60); ?></div>
                </div>
                <div class="session-info-box">
                    <div class="lbl">Late Until</div>
                    <div class="val"><?php echo date('g:i A', $lateEnd->getTimestamp()); ?></div>
                </div>
            </div>

            <!-- Success state -->
            <?php if ($checkedIn && $successMsg): ?>
                <?php $isLate = strpos($successMsg, 'Late') !== false; ?>
                <div class="success-banner <?php echo $isLate ? 'late-banner' : ''; ?>">
                    <div class="s-icon"><?php echo $isLate ? '⚠️' : '✅'; ?></div>
                    <div class="s-title">Marked <?php echo $isLate ? 'Late' : 'Present'; ?>!</div>
                    <div class="s-sub"><?php echo $successMsg; ?></div>
                </div>
                <?php if ($isLate): ?>
                    <div class="dispute-hint">
                        💡 If you believe this is incorrect, please see your teacher to submit a dispute request.
                    </div>
                <?php endif; ?>

            <?php elseif ($checkedIn && $errorMsg): ?>
                <!-- Already marked today -->
                <div class="success-banner <?php echo $myRecord && $myRecord['status'] === 'Late' ? 'late-banner' : ''; ?>">
                    <div class="s-icon">ℹ️</div>
                    <div class="s-title">Already Checked In</div>
                    <div class="s-sub">
                        You have already marked attendance today.
                        <?php if ($myRecord): ?>
                            Status: <strong><?php echo htmlspecialchars($myRecord['status']); ?></strong>
                            at <?php echo date('g:i A', strtotime($myRecord['time'])); ?>.
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <!-- Error message (non-duplicate) -->
                <?php if ($errorMsg && !$checkedIn): ?>
                    <div class="error-box">⚠ <?php echo $errorMsg; ?></div>
                <?php endif; ?>

                <!-- Check-in form -->
                <form method="POST" autocomplete="off">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="checkin" value="1">

                    <div class="field-group">
                        <label class="field-label">Student Number</label>
                        <div class="field-wrap">
                            <span class="field-icon">🎓</span>
                            <input type="text" name="student_number" class="field-input"
                                   placeholder="e.g. STU-2024-001"
                                   value="<?php echo htmlspecialchars($_POST['student_number'] ?? ''); ?>"
                                   required autofocus>
                        </div>
                    </div>

                    <div class="field-group">
                        <label class="field-label">Password</label>
                        <div class="field-wrap">
                            <span class="field-icon">🔒</span>
                            <input type="password" name="password" class="field-input"
                                   placeholder="Enter your password" required>
                        </div>
                    </div>

                    <button type="submit" class="checkin-btn"
                            <?php echo (!$sessionOpen) ? 'disabled title="Attendance window is not open."' : ''; ?>>
                        <?php if ($sessionOpen): ?>
                            ✅ &nbsp; Submit Attendance
                        <?php elseif ($sessionPre): ?>
                            ⏳ &nbsp; Window Not Open Yet
                        <?php else: ?>
                            ⛔ &nbsp; Window Closed
                        <?php endif; ?>
                    </button>

                    <?php if ($sessionEnded): ?>
                        <div class="dispute-hint">
                            The window has closed. If you were present, please contact your teacher for a dispute request.
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <!-- ── RIGHT: INFO PANEL ───────────────────────────────────────────────── -->
    <div class="right-panel">

        <!-- Live today stats -->
        <div class="live-stats">
            <div class="live-stat">
                <div class="ls-icon">👥</div>
                <div class="ls-num"><?php echo $totalStu; ?></div>
                <div class="ls-label">Total Students</div>
            </div>
            <div class="live-stat green">
                <div class="ls-icon">✅</div>
                <div class="ls-num"><?php echo $presentTdy; ?></div>
                <div class="ls-label">Present Today</div>
            </div>
            <div class="live-stat amber">
                <div class="ls-icon">⚠️</div>
                <div class="ls-num"><?php echo $lateTdy; ?></div>
                <div class="ls-label">Late Today</div>
            </div>
            <div class="live-stat slate">
                <div class="ls-icon">📋</div>
                <div class="ls-num"><?php echo $totalStu - $totalMarked; ?></div>
                <div class="ls-label">Not Yet Marked</div>
            </div>
        </div>

        <!-- Daily schedule -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>📅 Today's Schedule</h3>
                <span style="font-size:11px;color:#94a3b8;"><?php echo date('l'); ?></span>
            </div>
            <div class="info-card-body">
                <?php
                $schedItems = [
                    ['7:30 AM – 8:00 AM',  'Registration / Entry Period', 'Arrive and prepare for class', '#f0f9ff',  '#93c5fd'],
                    ['8:00 AM – 8:10 AM',  'On-Time Window (Present)',    'Mark attendance before cut-off', '#d1fae5', '#6ee7b7'],
                    ['8:00 AM – 8:15 AM',  'Late Window',                 'Attendance marked as Late',     '#fef3c7',  '#fcd34d'],
                    ['8:15 AM onward',      'Window Closed',               'No check-in accepted',         '#fee2e2',  '#fca5a5'],
                ];
                foreach ($schedItems as [$time, $label, $sub, $bg, $dot]): ?>
                    <div class="sched-row">
                        <div class="sched-time"><?php echo $time; ?></div>
                        <div class="sched-dot" style="background:<?php echo $dot; ?>;"></div>
                        <div>
                            <div class="sched-label"><?php echo $label; ?></div>
                            <div class="sched-sub"><?php echo $sub; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- How to check in -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>❓ How to Check In</h3>
            </div>
            <div class="info-card-body">
                <div class="steps">
                    <div class="step">
                        <div class="step-num">1</div>
                        <div class="step-text">
                            <strong>Enter your Student Number</strong>
                            <p>Use your official school-issued ID number (e.g. STU-2024-001).</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">2</div>
                        <div class="step-text">
                            <strong>Enter your Password</strong>
                            <p>Use the password provided by your teacher or administrator.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">3</div>
                        <div class="step-text">
                            <strong>Click Submit Attendance</strong>
                            <p>The server will timestamp your entry and classify it as Present or Late automatically.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">4</div>
                        <div class="step-text">
                            <strong>Receive Confirmation</strong>
                            <p>A confirmation with your status will appear immediately. You can only check in once per day.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Announcements -->
        <div class="info-card">
            <div class="info-card-header">
                <h3>📢 Reminders</h3>
            </div>
            <div class="info-card-body">
                <div class="announce-item">
                    <div class="announce-icon blue">⏰</div>
                    <div>
                        <div class="announce-title">Attendance window closes at <?php echo date('g:i A', $lateEnd->getTimestamp()); ?></div>
                        <div class="announce-sub">Submissions after this time will not be accepted.</div>
                    </div>
                </div>
                <div class="announce-item">
                    <div class="announce-icon yellow">⚠️</div>
                    <div>
                        <div class="announce-title">Disputes must be raised to your teacher</div>
                        <div class="announce-sub">If you believe your status is incorrect, inform your teacher for a review.</div>
                    </div>
                </div>
                <div class="announce-item">
                    <div class="announce-icon green">✅</div>
                    <div>
                        <div class="announce-title">One check-in per day per student</div>
                        <div class="announce-sub">The system allows only one submission per student per school day.</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.right-panel -->
</div><!-- /.main-wrap -->

<!-- ══════════════════════════════════════════════════════════════
     FOOTER
════════════════════════════════════════════════════════════════ -->
<footer class="landing-footer">
    <span>
        &copy; <?php echo date('Y'); ?> &nbsp;·&nbsp;
        Group 7 — Transaction / Request Management Subsystem &nbsp;·&nbsp; Southland College
    </span>
    <span>
    <a href="teacher-login.php" class="footer-link">🔐 Teacher Portal</a>        <span style="color:#cbd5e1;">System v1.0 &nbsp;·&nbsp; <?php echo date('g:i A'); ?></span>
    </span>
</footer>

<!-- ══════════════════════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════════════════════════ -->
<script>
// Live clock
(function tick() {
    const el = document.getElementById('navClock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-US', {
        hour: 'numeric', minute: '2-digit', second: '2-digit'
    });
    setTimeout(tick, 1000);
})();
</script>

</body>
</html>