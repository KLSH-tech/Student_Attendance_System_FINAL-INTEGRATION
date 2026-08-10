<?php
// ============================================================================
// cron/auto_absent.php — RUNNABLE entry point for automatic absence marking
// ----------------------------------------------------------------------------
// Run it any of these ways:
//
//   1) Command line (recommended for production — Linux cron / Windows Task
//      Scheduler). No token needed on the CLI:
//          php "cron/auto_absent.php"
//      Example Linux crontab (every 5 minutes, school hours Mon–Sat):
//          */5 7-19 * * 1-6  php /path/to/cron/auto_absent.php >> /var/log/sams_absent.log 2>&1
//      Example Windows Task Scheduler action:
//          Program:  C:\xampp\php\php.exe
//          Argument: "C:\xampp\htdocs\Integration _ Final\cron\auto_absent.php"
//
//   2) Browser / HTTP (handy for XAMPP demos or a webhost without shell cron).
//      A token is REQUIRED over HTTP so the endpoint can't be triggered by
//      strangers:
//          http://localhost/Integration%20_%20Final/cron/auto_absent.php?token=CHANGE_ME_LONG_RANDOM
//      Point a free cron-ping service (e.g. cron-job.org) at that URL.
//
//   3) You usually DON'T need either for a demo: the scanner itself runs a
//      throttled sweep on each scan (see includes/absent_processor.php →
//      maybeRunAbsenceSweep), so absences fill in as students use the terminal.
//
// SIMULATION / TESTING flags (CLI only, ignored over HTTP):
//      php cron/auto_absent.php --as-of="2026-05-25 09:46:00"   # pretend it's that moment
//      php cron/auto_absent.php --schedule=8                    # only schedule 8
//      php cron/auto_absent.php --dry-run                       # report only, write nothing
// ============================================================================

require_once __DIR__ . '/../includes/absent_processor.php';

// ── Change this to a long random string and use it in the HTTP URL above. ───
const CRON_TOKEN = 'CHANGE_ME_LONG_RANDOM';

$isCli = (PHP_SAPI === 'cli');

// ── Auth: CLI is trusted; HTTP must present the token. ──────────────────────
if (!$isCli) {
    header('Content-Type: application/json');
    $given = $_GET['token'] ?? '';
    if (!hash_equals(CRON_TOKEN, (string) $given)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden: invalid or missing token.']);
        exit;
    }
}

// ── Parse optional CLI flags (testing aids). ────────────────────────────────
$asOf       = null;
$onlySched  = null;
$dryRun      = false;

if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--as-of=(.+)$/', $arg, $m)) {
            try { $asOf = new DateTime($m[1], new DateTimeZone(date_default_timezone_get())); }
            catch (\Throwable $e) { fwrite(STDERR, "Bad --as-of value: {$m[1]}\n"); exit(2); }
        } elseif (preg_match('/^--schedule=(\d+)$/', $arg, $m)) {
            $onlySched = (int) $m[1];
        } elseif ($arg === '--dry-run') {
            $dryRun = true;
        }
    }
}

// ── Dry run: count what WOULD be marked, without writing. ───────────────────
if ($dryRun) {
    try {
        $pdo  = db();
        $tz   = new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila');
        $now  = $asOf ?: new DateTime('now', $tz);
        $day  = $now->format('l');
        $date = $now->format('Y-m-d');
        $time = $now->format('H:i:s');

        $schedFilter = $onlySched !== null ? ' AND sch.schedule_id = :only ' : '';
        $sql = "
            SELECT COUNT(*) AS would_mark
            FROM student_schedule ss
            JOIN schedules sch ON sch.schedule_id = ss.schedule_id
            JOIN classes   c   ON c.class_id      = ss.class_id
            WHERE sch.day = :day {$schedFilter}
              AND TIME(:t) > ADDTIME(sch.start_time,
                      SEC_TO_TIME(COALESCE(c.grace_period_minutes,15) * 60))
              AND NOT EXISTS (
                    SELECT 1 FROM attendance_logs al
                    WHERE al.student_id  = ss.student_id
                      AND al.schedule_id = ss.schedule_id
                      AND al.class_id    = ss.class_id
                      AND DATE(al.logged_at) = :date )";
        $st = $pdo->prepare($sql);
        $bind = [':day' => $day, ':t' => $time, ':date' => $date];
        if ($onlySched !== null) { $bind[':only'] = $onlySched; }
        $st->execute($bind);
        $would = (int) $st->fetchColumn();

        $out = ['ok' => true, 'dry_run' => true, 'day' => $day, 'date' => $date,
                'time' => $time, 'would_mark_absent' => $would];
        echo $isCli ? json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL : json_encode($out);
        exit;
    } catch (\Throwable $e) {
        $out = ['ok' => false, 'dry_run' => true, 'error' => $e->getMessage()];
        echo $isCli ? json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL : json_encode($out);
        exit(1);
    }
}

// ── Real run. ───────────────────────────────────────────────────────────────
$summary = processAutomaticAbsences($asOf, $onlySched);

if ($isCli) {
    echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($summary['ok'] ? 0 : 1);
}
echo json_encode($summary);